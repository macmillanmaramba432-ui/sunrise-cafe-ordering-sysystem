
<?php
session_start();

require_once dirname(__DIR__) . '/config/database.php';

/*
|--------------------------------------------------------------------------
| Require customer login
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];
$orders = [];
$error = '';

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function ordersEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ordersStatusClass($status): string
{
    $status = strtolower(trim((string) $status));

    if (in_array($status, ['completed', 'paid', 'approved'], true)) {
        return 'status-success';
    }

    if (in_array($status, ['cancelled', 'failed', 'hidden'], true)) {
        return 'status-danger';
    }

    if (in_array($status, ['preparing', 'ready'], true)) {
        return 'status-progress';
    }

    return 'status-pending';
}

/*
|--------------------------------------------------------------------------
| Load orders belonging to this customer only
|--------------------------------------------------------------------------
*/

try {
    $stmt = $pdo->prepare(
        'SELECT
            id,
            order_number,
            order_type,
            delivery_address,
            payment_method,
            payment_status,
            order_status,
            subtotal,
            delivery_fee,
            total,
            notes,
            created_at
         FROM orders
         WHERE customer_id = ?
         ORDER BY created_at DESC, id DESC'
    );

    $stmt->execute([$customerId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    | Load items for each order.
    | The customer ID restriction above ensures only their own orders appear.
    */

    $itemStmt = $pdo->prepare(
        'SELECT
            product_name,
            price,
            quantity,
            subtotal
         FROM order_items
         WHERE order_id = ?
         ORDER BY id ASC'
    );

    foreach ($orders as &$order) {
        $itemStmt->execute([(int) $order['id']]);
        $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($order);

} catch (PDOException $e) {
    error_log('Customer order history error: ' . $e->getMessage());
    $error = 'We could not load your orders. Please refresh the page.';
}

/*
|--------------------------------------------------------------------------
| Calculate summary statistics
|--------------------------------------------------------------------------
*/

$totalOrders = count($orders);
$activeOrders = 0;
$completedOrders = 0;
$totalOrderValue = 0;

foreach ($orders as $order) {
    $status = strtolower(trim((string) $order['order_status']));

    if (in_array($status, ['completed'], true)) {
        $completedOrders++;
    }

    if (!in_array($status, ['completed', 'cancelled'], true)) {
        $activeOrders++;
    }

    $totalOrderValue += (float) $order['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Orders | Sunrise Café</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f7f4ef;
            color: #332820;
            line-height: 1.6;
        }

        a {
            text-decoration: none;
        }

        .topbar {
            background: #4b2e1e;
            color: white;
            padding: 17px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .brand {
            color: white;
            font-size: 24px;
            font-weight: bold;
        }

        .navigation {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }

        .navigation a {
            color: white;
            font-size: 14px;
            font-weight: bold;
        }

        .navigation a:hover {
            color: #f4ca87;
        }

        .container {
            width: 92%;
            max-width: 1150px;
            margin: 35px auto;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: #4b2e1e;
            margin: 0 0 5px;
            font-size: 30px;
        }

        .page-heading p {
            color: #786b60;
            margin: 0;
        }

        .button {
            display: inline-block;
            border: none;
            border-radius: 7px;
            padding: 10px 15px;
            background: #8a4b2a;
            color: white;
            font-weight: bold;
            font-size: 13px;
            text-align: center;
            cursor: pointer;
        }

        .button:hover {
            background: #65351e;
        }

        .button-outline {
            background: white;
            border: 1px solid #8a4b2a;
            color: #754126;
        }

        .button-outline:hover {
            background: #f7eee7;
        }

        .button-muted {
            background: #6d6258;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 11px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .summary-label {
            display: block;
            color: #786b60;
            font-size: 13px;
        }

        .summary-value {
            display: block;
            color: #4b2e1e;
            font-size: 23px;
            font-weight: bold;
            margin-top: 5px;
            overflow-wrap: anywhere;
        }

        .order-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .order-header {
            background: #fbf6f0;
            border-bottom: 1px solid #eee4d9;
            padding: 20px 23px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            flex-wrap: wrap;
        }

        .order-number {
            font-size: 18px;
            font-weight: bold;
            color: #4b2e1e;
            overflow-wrap: anywhere;
        }

        .order-date {
            color: #786b60;
            font-size: 13px;
            margin-top: 4px;
        }

        .order-total {
            text-align: right;
        }

        .order-total small {
            display: block;
            color: #786b60;
        }

        .order-total strong {
            color: #4b2e1e;
            font-size: 20px;
        }

        .order-body {
            padding: 22px 23px;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .detail-label {
            display: block;
            font-size: 12px;
            color: #85786d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .detail-value {
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-success {
            background: #e2f5e7;
            color: #226735;
        }

        .status-danger {
            background: #fde8e7;
            color: #972c26;
        }

        .status-progress {
            background: #e4efff;
            color: #24558f;
        }

        .status-pending {
            background: #fff0d6;
            color: #80521b;
        }

        .items-heading {
            font-size: 16px;
            color: #4b2e1e;
            margin: 0 0 10px;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 10px 0;
            border-bottom: 1px solid #f0e9e2;
        }

        .item-row:last-child {
            border-bottom: none;
        }

        .item-name {
            font-weight: bold;
        }

        .item-description {
            color: #786b60;
            font-size: 13px;
        }

        .item-price {
            font-weight: bold;
            white-space: nowrap;
        }

        .cost-breakdown {
            max-width: 360px;
            margin: 15px 0 0 auto;
            padding-top: 12px;
            border-top: 1px solid #eee4d9;
        }

        .cost-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 4px 0;
            font-size: 14px;
        }

        .cost-row.total {
            font-size: 17px;
            font-weight: bold;
            color: #4b2e1e;
            border-top: 1px solid #eee4d9;
            padding-top: 10px;
            margin-top: 5px;
        }

        .order-notes {
            margin-top: 18px;
            padding: 12px;
            background: #faf5ef;
            border-radius: 7px;
            font-size: 13px;
        }

        .order-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .empty-state {
            text-align: center;
            background: white;
            padding: 55px 20px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .empty-state h2 {
            color: #4b2e1e;
        }

        .empty-state p {
            color: #786b60;
            margin-bottom: 22px;
        }

        .error-message {
            padding: 14px 17px;
            background: #fde8e7;
            color: #8b1e18;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .footer {
            text-align: center;
            color: #7d7167;
            padding: 25px;
            font-size: 13px;
        }

        @media (max-width: 850px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .details-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            .topbar {
                padding: 16px 4%;
            }

            .navigation {
                gap: 12px;
            }

            .container {
                width: 94%;
                margin: 22px auto;
            }

            .page-heading h1 {
                font-size: 25px;
            }

            .summary-grid {
                gap: 10px;
            }

            .summary-card {
                padding: 14px;
            }

            .summary-value {
                font-size: 19px;
            }

            .order-header,
            .order-body {
                padding: 17px;
            }

            .details-grid {
                grid-template-columns: 1fr;
                gap: 13px;
            }

            .order-total {
                text-align: left;
            }
        }
    </style>
</head>
<body>

<header class="topbar">
    <a href="index.php" class="brand">Sunrise Café</a>

    <nav class="navigation">
        <a href="index.php">Home</a>
        <a href="menu.php">Menu</a>
        <a href="cart.php">Cart</a>
        <a href="my_account.php">My Account</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main class="container">

    <div class="page-heading">
        <div>
            <h1>My Orders</h1>
            <p>View your purchases, payment details and order progress.</p>
        </div>

        <a href="menu.php" class="button">Place a New Order</a>
    </div>

    <?php if ($error !== ''): ?>

        <div class="error-message">
            <?= ordersEscape($error) ?>
        </div>

    <?php else: ?>

        <section class="summary-grid">
            <div class="summary-card">
                <span class="summary-label">Total Orders</span>
                <span class="summary-value"><?= $totalOrders ?></span>
            </div>

            <div class="summary-card">
                <span class="summary-label">Active Orders</span>
                <span class="summary-value"><?= $activeOrders ?></span>
            </div>

            <div class="summary-card">
                <span class="summary-label">Completed Orders</span>
                <span class="summary-value"><?= $completedOrders ?></span>
            </div>

            <div class="summary-card">
                <span class="summary-label">Total Order Value</span>
                <span class="summary-value">
                    KSh <?= number_format($totalOrderValue, 2) ?>
                </span>
            </div>
        </section>

        <?php if (empty($orders)): ?>

            <section class="empty-state">
                <h2>You Have No Orders Yet</h2>

                <p>
                    Once you place an order, its details and status will
                    appear here.
                </p>

                <a href="menu.php" class="button">
                    Explore Our Menu
                </a>
            </section>

        <?php else: ?>

            <?php foreach ($orders as $order): ?>

                <article class="order-card">

                    <div class="order-header">
                        <div>
                            <div class="order-number">
                                Order #<?= ordersEscape($order['order_number']) ?>
                            </div>

                            <div class="order-date">
                                Placed on
                                <?php if (!empty($order['created_at'])): ?>
                                    <?= ordersEscape(date(
                                        'd M Y, h:i A',
                                        strtotime($order['created_at'])
                                    )) ?>
                                <?php else: ?>
                                    Date unavailable
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="order-total">
                            <small>Order Total</small>
                            <strong>
                                KSh <?= number_format((float) $order['total'], 2) ?>
                            </strong>
                        </div>
                    </div>

                    <div class="order-body">

                        <div class="details-grid">

                            <div>
                                <span class="detail-label">Order Status</span>
                                <span class="status <?= ordersEscape(
                                    ordersStatusClass($order['order_status'])
                                ) ?>">
                                    <?= ordersEscape($order['order_status']) ?>
                                </span>
                            </div>

                            <div>
                                <span class="detail-label">Payment Status</span>
                                <span class="status <?= ordersEscape(
                                    ordersStatusClass($order['payment_status'])
                                ) ?>">
                                    <?= ordersEscape($order['payment_status']) ?>
                                </span>
                            </div>

                            <div>
                                <span class="detail-label">Order Type</span>
                                <span class="detail-value">
                                    <?= ordersEscape(ucfirst($order['order_type'])) ?>
                                </span>
                            </div>

                            <div>
                                <span class="detail-label">Payment Method</span>
                                <span class="detail-value">
                                    <?= ordersEscape($order['payment_method']) ?>
                                </span>
                            </div>

                            <?php if (
                                strtolower((string) $order['order_type']) === 'delivery'
                                && !empty($order['delivery_address'])
                            ): ?>
                                <div>
                                    <span class="detail-label">Delivery Address</span>
                                    <span class="detail-value">
                                        <?= ordersEscape($order['delivery_address']) ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                        </div>

                        <h3 class="items-heading">Items Ordered</h3>

                        <?php if (!empty($order['items'])): ?>

                            <?php foreach ($order['items'] as $item): ?>
                                <div class="item-row">
                                    <div>
                                        <div class="item-name">
                                            <?= ordersEscape($item['product_name']) ?>
                                        </div>

                                        <div class="item-description">
                                            KSh <?= number_format((float) $item['price'], 2) ?>
                                            × <?= (int) $item['quantity'] ?>
                                        </div>
                                    </div>

                                    <div class="item-price">
                                        KSh <?= number_format((float) $item['subtotal'], 2) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                        <?php else: ?>

                            <p class="item-description">
                                Item details are not available for this order.
                            </p>

                        <?php endif; ?>

                        <div class="cost-breakdown">
                            <div class="cost-row">
                                <span>Subtotal</span>
                                <span>
                                    KSh <?= number_format((float) $order['subtotal'], 2) ?>
                                </span>
                            </div>

                            <div class="cost-row">
                                <span>Delivery Fee</span>
                                <span>
                                    KSh <?= number_format((float) $order['delivery_fee'], 2) ?>
                                </span>
                            </div>

                            <div class="cost-row total">
                                <span>Total</span>
                                <span>
                                    KSh <?= number_format((float) $order['total'], 2) ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($order['notes'])): ?>
                            <div class="order-notes">
                                <strong>Your Order Notes:</strong><br>
                                <?= nl2br(ordersEscape($order['notes'])) ?>
                            </div>
                        <?php endif; ?>

                        <div class="order-actions">
                            <a
                                class="button"
                                href="track_order.php?order_number=<?= urlencode($order['order_number']) ?>"
                            >
                                Track This Order
                            </a>

                            <a href="menu.php" class="button button-outline">
                                Order Again
                            </a>

                            <a href="my_account.php" class="button button-muted">
                                My Account
                            </a>
                        </div>

                    </div>
                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>

</main>

<footer class="footer">
    &copy; <?= date('Y') ?> Sunrise Café. All rights reserved.
</footer>

</body>
</html>
