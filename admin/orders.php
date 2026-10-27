
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| Admin access
|--------------------------------------------------------------------------
| This page expects your admin login to set:
| $_SESSION['admin_logged_in'] = true;
*/
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['orders_csrf'])) {
    $_SESSION['orders_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['orders_csrf'];
$message = '';
$error = '';

$allowedStatuses = [
    'Pending',
    'Preparing',
    'Ready',
    'Completed',
    'Cancelled'
];

/*
|--------------------------------------------------------------------------
| Update order status and deduct recipe ingredients when completed
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        $error = 'Invalid security token. Refresh and try again.';
    } else {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = $_POST['order_status'] ?? '';

        if (!$orderId || !in_array($newStatus, $allowedStatuses, true)) {
            $error = 'Invalid order or order status.';
        } else {
            try {
                $pdo->beginTransaction();

                // Lock the order so simultaneous updates cannot deduct stock twice.
                $orderStmt = $pdo->prepare(
                    'SELECT id, order_number, order_status FROM orders WHERE id = ? FOR UPDATE'
                );
                $orderStmt->execute([$orderId]);
                $currentOrder = $orderStmt->fetch(PDO::FETCH_ASSOC);

                if (!$currentOrder) {
                    throw new RuntimeException('Order not found.');
                }

                if ($currentOrder['order_status'] === $newStatus) {
                    $pdo->commit();
                    $message = 'The order already has that status.';
                } else {
                    // Only deduct when an order is being changed to Completed.
                    if ($newStatus === 'Completed') {
                        $deductionCheck = $pdo->prepare(
                            'SELECT id FROM order_stock_deductions WHERE order_id = ? LIMIT 1'
                        );
                        $deductionCheck->execute([$orderId]);
                        $existingDeduction = $deductionCheck->fetchColumn();

                        if (!$existingDeduction) {
                            $orderedItemsStmt = $pdo->prepare(
                                'SELECT menu_item_id, product_name, quantity
                                 FROM order_items WHERE order_id = ? ORDER BY id ASC'
                            );
                            $orderedItemsStmt->execute([$orderId]);
                            $orderedItems = $orderedItemsStmt->fetchAll(PDO::FETCH_ASSOC);

                            if (!$orderedItems) {
                                throw new RuntimeException('This order has no items, so it cannot be completed.');
                            }

                            $requiredStock = [];
                            $recipeStmt = $pdo->prepare(
                                'SELECT mii.inventory_item_id, mii.quantity_required,
                                        ii.name AS ingredient_name, ii.unit, ii.quantity AS stock_quantity
                                 FROM menu_item_ingredients mii
                                 INNER JOIN inventory_items ii ON ii.id = mii.inventory_item_id
                                 WHERE mii.menu_item_id = ?
                                 ORDER BY ii.id ASC
                                 FOR UPDATE'
                            );

                            foreach ($orderedItems as $orderedItem) {
                                if (empty($orderedItem['menu_item_id'])) {
                                    throw new RuntimeException(
                                        'The order item "' . $orderedItem['product_name'] . '" is not linked to a menu item. Stock was not changed.'
                                    );
                                }

                                $recipeStmt->execute([(int)$orderedItem['menu_item_id']]);
                                $ingredients = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);

                                if (!$ingredients) {
                                    throw new RuntimeException(
                                        'No recipe is configured for "' . $orderedItem['product_name'] . '". Add its ingredients in Recipe Management before completing this order.'
                                    );
                                }

                                foreach ($ingredients as $ingredient) {
                                    $inventoryId = (int)$ingredient['inventory_item_id'];
                                    $needed = (float)$ingredient['quantity_required'] * (int)$orderedItem['quantity'];

                                    if (!isset($requiredStock[$inventoryId])) {
                                        $requiredStock[$inventoryId] = [
                                            'name' => $ingredient['ingredient_name'],
                                            'unit' => $ingredient['unit'],
                                            'available' => (float)$ingredient['stock_quantity'],
                                            'needed' => 0.0
                                        ];
                                    }
                                    $requiredStock[$inventoryId]['needed'] += $needed;
                                }
                            }

                            // Validate all ingredients before changing any stock.
                            foreach ($requiredStock as $ingredient) {
                                if ($ingredient['available'] + 0.0000001 < $ingredient['needed']) {
                                    throw new RuntimeException(
                                        'Not enough stock for ' . $ingredient['name'] . '. Required: ' .
                                        rtrim(rtrim(number_format($ingredient['needed'], 3, '.', ''), '0'), '.') . ' ' .
                                        $ingredient['unit'] . '; available: ' .
                                        rtrim(rtrim(number_format($ingredient['available'], 3, '.', ''), '0'), '.') . ' ' .
                                        $ingredient['unit'] . '. Stock was not changed.'
                                    );
                                }
                            }

                            $adminUsername = $_SESSION['admin_username'] ?? 'admin';
                            $headerStmt = $pdo->prepare(
                                'INSERT INTO order_stock_deductions (order_id, deducted_by) VALUES (?, ?)'
                            );
                            $headerStmt->execute([$orderId, $adminUsername]);
                            $deductionId = (int)$pdo->lastInsertId();

                            $deductionItemStmt = $pdo->prepare(
                                'INSERT INTO order_stock_deduction_items
                                 (deduction_id, inventory_item_id, quantity_deducted)
                                 VALUES (?, ?, ?)'
                            );
                            $stockUpdateStmt = $pdo->prepare(
                                'UPDATE inventory_items SET quantity = quantity - ? WHERE id = ? AND quantity >= ?'
                            );
                            $movementStmt = $pdo->prepare(
                                'INSERT INTO stock_movements
                                 (inventory_item_id, movement_type, quantity, note, admin_username)
                                 VALUES (?, \'Stock Out\', ?, ?, ?)'
                            );

                            foreach ($requiredStock as $inventoryId => $ingredient) {
                                $needed = $ingredient['needed'];
                                $stockUpdateStmt->execute([$needed, $inventoryId, $needed]);
                                if ($stockUpdateStmt->rowCount() !== 1) {
                                    throw new RuntimeException(
                                        'Stock for ' . $ingredient['name'] . ' changed during processing. Please try again.'
                                    );
                                }
                                $deductionItemStmt->execute([$deductionId, $inventoryId, $needed]);
                                $movementStmt->execute([
                                    $inventoryId,
                                    $needed,
                                    'Ingredients used for completed order ' . $currentOrder['order_number'],
                                    $adminUsername
                                ]);
                            }
                        }
                    }

                    $updateStmt = $pdo->prepare('UPDATE orders SET order_status = ? WHERE id = ?');
                    $updateStmt->execute([$newStatus, $orderId]);
                    $pdo->commit();

                    if ($newStatus === 'Completed') {
                        $message = 'Order marked Completed. Recipe ingredient stock has been deducted (or was already deducted previously).';
                    } else {
                        $message = 'Order status updated successfully.';
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof RuntimeException) {
                    $error = $e->getMessage();
                } else {
                    error_log($e->getMessage());
                    $error = 'A database error occurred while updating the order. Confirm that the stock deduction tables have been created.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Search and filter
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$where = [];
$params = [];

if ($statusFilter !== '') {
    $where[] = 'o.order_status = ?';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(
        o.order_number LIKE ?
        OR c.name LIKE ?
        OR c.phone LIKE ?
        OR c.email LIKE ?
    )';

    $term = '%' . $search . '%';

    array_push($params, $term, $term, $term, $term);
}

$sql = '
    SELECT
        o.id,
        o.order_number,
        o.order_type,
        o.delivery_address,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.subtotal,
        o.delivery_fee,
        o.total,
        o.notes,
        o.created_at,
        c.name AS customer_name,
        c.email AS customer_email,
        c.phone AS customer_phone
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY o.created_at DESC, o.id DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    // Fetch all order items in one query.
    $orderItems = [];

    if ($orders) {
        $orderIds = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        $itemsStmt = $pdo->prepare(
            "SELECT order_id, product_name, price, quantity, subtotal
             FROM order_items
             WHERE order_id IN ($placeholders)
             ORDER BY id ASC"
        );

        $itemsStmt->execute($orderIds);

        foreach ($itemsStmt->fetchAll() as $item) {
            $orderItems[$item['order_id']][] = $item;
        }
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $orders = [];
    $orderItems = [];
    $error = 'Could not load orders. Check your database tables and columns.';
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return 'KSh ' . number_format((float)$amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders | Sunrise Café</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .orders-page { max-width: 1200px; margin: auto; }
        .filter-form {
            display: flex; flex-wrap: wrap; gap: 10px;
            align-items: center; margin: 20px 0;
        }
        .filter-form input, .filter-form select,
        .filter-form button, .status-form select,
        .status-form button {
            padding: 9px; border-radius: 5px;
        }
        .filter-form input { min-width: 200px; }
        .order-card {
            background: white; border: 1px solid #e5e7eb;
            border-radius: 10px; padding: 18px;
            margin-bottom: 18px; overflow-wrap: anywhere;
        }
        .order-header {
            display: flex; flex-wrap: wrap;
            justify-content: space-between; gap: 12px;
        }
        .order-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 12px; margin: 18px 0;
        }
        .detail-box { background: #f8fafc; padding: 12px; border-radius: 7px; }
        .order-card table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        .order-card th, .order-card td {
            text-align: left; padding: 9px;
            border-bottom: 1px solid #e5e7eb;
        }
        .order-card th { background: #f8fafc; }
        .status-form { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .notice { padding: 12px; border-radius: 6px; margin: 12px 0; }
        .success { background: #dcfce7; color: #166534; }
        .failure { background: #fee2e2; color: #991b1b; }
        .muted { color: #64748b; }
        @media (max-width: 600px) {
            .order-card { padding: 12px; }
            .order-card th, .order-card td { padding: 6px; font-size: 13px; }
        }
    </style>
</head>
<body>

<div class="admin-layout">
    <aside class="sidebar">
        <h2>Sunrise Café</h2>
        <p>Administration</p>
        <nav>
            <a href="index.php">Dashboard</a>
            <a href="menu.php">Manage Menu</a>
            <a href="orders.php" class="active">Manage Orders</a>
        </nav>
    </aside>

    <main class="main-content orders-page">
        <h1>Customer Orders</h1>
        <p class="muted">View customer purchases and manage order progress.</p>

        <?php if ($message !== ''): ?>
            <div class="notice success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="notice failure"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="GET" class="filter-form">
            <input
                type="search"
                name="search"
                placeholder="Order number, customer, phone..."
                value="<?= e($search) ?>"
            >

            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($allowedStatuses as $status): ?>
                    <option
                        value="<?= e($status) ?>"
                        <?= $statusFilter === $status ? 'selected' : '' ?>
                    >
                        <?= e($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Search Orders</button>
            <a href="orders.php">Clear filters</a>
        </form>

        <p><strong>Orders found:</strong> <?= count($orders) ?></p>

        <?php if (!$orders): ?>
            <div class="order-card">
                <p>No orders found.</p>
                <p class="muted">
                    New customer orders will appear here once checkout saves
                    them to the database.
                </p>
            </div>
        <?php endif; ?>

        <?php foreach ($orders as $order): ?>
            <section class="order-card">
                <div class="order-header">
                    <div>
                        <h2><?= e($order['order_number']) ?></h2>
                        <p class="muted">
                            Placed:
                            <?= e($order['created_at']) ?>
                        </p>
                    </div>

                    <div>
                        <strong><?= money($order['total']) ?></strong>
                        <p>
                            Status:
                            <strong><?= e($order['order_status']) ?></strong>
                        </p>
                    </div>
                </div>

                <div class="order-details">
                    <div class="detail-box">
                        <strong>Customer</strong><br>
                        <?= e($order['customer_name']) ?><br>
                        <?= e($order['customer_phone']) ?><br>
                        <?= e($order['customer_email']) ?>
                    </div>

                    <div class="detail-box">
                        <strong>Order type</strong><br>
                        <?= e($order['order_type']) ?><br><br>

                        <?php if ($order['order_type'] === 'Delivery'): ?>
                            <strong>Delivery address</strong><br>
                            <?= e($order['delivery_address']) ?>
                        <?php else: ?>
                            <span class="muted">Pickup order</span>
                        <?php endif; ?>
                    </div>

                    <div class="detail-box">
                        <strong>Payment</strong><br>
                        Method: <?= e($order['payment_method']) ?><br>
                        Payment status: <?= e($order['payment_status']) ?>
                    </div>
                </div>

                <h3>Items ordered</h3>

                <?php $items = $orderItems[$order['id']] ?? []; ?>

                <?php if ($items): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Qty</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= e($item['product_name']) ?></td>
                                    <td><?= money($item['price']) ?></td>
                                    <td><?= (int)$item['quantity'] ?></td>
                                    <td><?= money($item['subtotal']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="muted">No item details were found for this order.</p>
                <?php endif; ?>

                <div class="order-details">
                    <div>
                        <strong>Subtotal:</strong>
                        <?= money($order['subtotal']) ?>
                    </div>
                    <div>
                        <strong>Delivery fee:</strong>
                        <?= money($order['delivery_fee']) ?>
                    </div>
                    <div>
                        <strong>Total:</strong>
                        <?= money($order['total']) ?>
                    </div>
                </div>

                <?php if (!empty($order['notes'])): ?>
                    <p><strong>Customer notes:</strong> <?= e($order['notes']) ?></p>
                <?php endif; ?>

                <form method="POST" class="status-form">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">

                    <label>
                        Update status
                        <select name="order_status" required>
                            <?php foreach ($allowedStatuses as $status): ?>
                                <option
                                    value="<?= e($status) ?>"
                                    <?= $order['order_status'] === $status ? 'selected' : '' ?>
                                >
                                    <?= e($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <button type="submit">Save Status</button>
                </form>
            </section>
        <?php endforeach; ?>
    </main>
</div>

</body>
</html>
