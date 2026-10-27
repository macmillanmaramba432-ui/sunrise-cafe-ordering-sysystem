
<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

$message = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Save a multi-item purchase or receive a pending purchase
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | SAVE MULTI-ITEM PURCHASE
        |--------------------------------------------------------------------------
        */
        if ($action === 'save_purchase') {
            $supplierId = (int)($_POST['supplier_id'] ?? 0);
            $invoiceNumber = trim($_POST['invoice_number'] ?? '');
            $purchaseDate = $_POST['purchase_date'] ?? '';
            $paymentStatus = $_POST['payment_status'] ?? 'Unpaid';
            $purchaseStatus = $_POST['purchase_status'] ?? 'Pending';
            $notes = trim($_POST['notes'] ?? '');

            $itemIds = $_POST['inventory_item_id'] ?? [];
            $quantities = $_POST['quantity'] ?? [];
            $unitCosts = $_POST['unit_cost'] ?? [];

            $validDate = false;

            if (is_string($purchaseDate)) {
                $dateObject = DateTime::createFromFormat(
                    '!Y-m-d',
                    $purchaseDate
                );

                $validDate = $dateObject &&
                    $dateObject->format('Y-m-d') === $purchaseDate;
            }

            if ($supplierId <= 0) {
                $error = 'Please select a supplier.';
            } elseif (!$validDate) {
                $error = 'Please enter a valid purchase date.';
            } elseif (
                !in_array($paymentStatus, [
                    'Unpaid', 'Partially Paid', 'Paid'
                ], true)
            ) {
                $error = 'Invalid payment status.';
            } elseif (
                !in_array($purchaseStatus, [
                    'Pending', 'Received'
                ], true)
            ) {
                $error = 'Invalid purchase status.';
            } elseif (
                !is_array($itemIds) ||
                !is_array($quantities) ||
                !is_array($unitCosts) ||
                count($itemIds) < 1 ||
                count($itemIds) > 100 ||
                count($itemIds) !== count($quantities) ||
                count($itemIds) !== count($unitCosts)
            ) {
                $error = 'Please add between 1 and 100 valid item rows.';
            } else {
                $items = [];
                $totalAmount = 0;

                // Validate every item before starting the transaction.
                foreach ($itemIds as $index => $rawItemId) {
                    $inventoryId = filter_var(
                        $rawItemId,
                        FILTER_VALIDATE_INT
                    );

                    $rawQuantity = $quantities[$index] ?? null;
                    $rawUnitCost = $unitCosts[$index] ?? null;

                    $quantity = is_scalar($rawQuantity)
                        ? filter_var($rawQuantity, FILTER_VALIDATE_FLOAT)
                        : false;

                    $unitCost = is_scalar($rawUnitCost)
                        ? filter_var($rawUnitCost, FILTER_VALIDATE_FLOAT)
                        : false;

                    if (!$inventoryId || $inventoryId <= 0) {
                        $error = 'Please select an inventory item for every row.';
                        break;
                    }

                    if (
                        $quantity === false ||
                        !is_finite((float)$quantity) ||
                        $quantity <= 0
                    ) {
                        $error = 'Every item must have a quantity greater than zero.';
                        break;
                    }

                    if (
                        $unitCost === false ||
                        !is_finite((float)$unitCost) ||
                        $unitCost < 0
                    ) {
                        $error = 'Unit costs must be valid non-negative numbers.';
                        break;
                    }

                    $quantity = round((float)$quantity, 2);
                    $unitCost = round((float)$unitCost, 2);
                    $subtotal = round($quantity * $unitCost, 2);

                    $items[] = [
                        'inventory_item_id' => (int)$inventoryId,
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'subtotal' => $subtotal
                    ];

                    $totalAmount = round($totalAmount + $subtotal, 2);
                }

                if ($error === '') {
                    try {
                        $pdo->beginTransaction();

                        // Check supplier.
                        $stmt = $pdo->prepare(
                            "SELECT id FROM suppliers WHERE id = ?"
                        );
                        $stmt->execute([$supplierId]);

                        if (!$stmt->fetch()) {
                            throw new RuntimeException(
                                'The selected supplier does not exist.'
                            );
                        }

                        // Lock each inventory item in a consistent order.
                        $uniqueIds = array_values(array_unique(
                            array_column($items, 'inventory_item_id')
                        ));
                        sort($uniqueIds, SORT_NUMERIC);

                        $placeholders = implode(
                            ',',
                            array_fill(0, count($uniqueIds), '?')
                        );

                        $stmt = $pdo->prepare(
                            "SELECT id FROM inventory_items
                             WHERE id IN ($placeholders)
                             ORDER BY id
                             FOR UPDATE"
                        );
                        $stmt->execute($uniqueIds);

                        $existingIds = array_map(
                            'intval',
                            $stmt->fetchAll(PDO::FETCH_COLUMN)
                        );

                        if (count($existingIds) !== count($uniqueIds)) {
                            throw new RuntimeException(
                                'One or more selected inventory items do not exist.'
                            );
                        }

                        // Create one purchase header for the entire invoice.
                        $stmt = $pdo->prepare(
                            "INSERT INTO purchase_orders
                             (supplier_id, invoice_number, purchase_date,
                              total_amount, payment_status, purchase_status,
                              notes)
                             VALUES (?, ?, ?, ?, ?, ?, ?)"
                        );

                        $stmt->execute([
                            $supplierId,
                            $invoiceNumber !== '' ? $invoiceNumber : null,
                            $purchaseDate,
                            $totalAmount,
                            $paymentStatus,
                            $purchaseStatus,
                            $notes !== '' ? $notes : null
                        ]);

                        $purchaseId = (int)$pdo->lastInsertId();

                        // Store every item against the same purchase ID.
                        $stmtItem = $pdo->prepare(
                            "INSERT INTO purchase_items
                             (purchase_id, inventory_item_id,
                              quantity, unit_cost, subtotal)
                             VALUES (?, ?, ?, ?, ?)"
                        );

                        $stmtStock = $pdo->prepare(
                            "UPDATE inventory_items
                             SET quantity = quantity + ?
                             WHERE id = ?"
                        );

                        $stmtMovement = $pdo->prepare(
                            "INSERT INTO stock_movements
                             (inventory_item_id, movement_type, quantity,
                              note, admin_username)
                             VALUES (?, 'Stock In', ?, ?, ?)"
                        );

                        $adminUsername = $_SESSION['admin_username']
                            ?? $_SESSION['username']
                            ?? 'admin';

                        foreach ($items as $item) {
                            $stmtItem->execute([
                                $purchaseId,
                                $item['inventory_item_id'],
                                $item['quantity'],
                                $item['unit_cost'],
                                $item['subtotal']
                            ]);

                            // Pending goods are not added to inventory.
                            if ($purchaseStatus === 'Received') {
                                $stmtStock->execute([
                                    $item['quantity'],
                                    $item['inventory_item_id']
                                ]);

                                $movementNote = 'Purchase #' . $purchaseId;

                                if ($invoiceNumber !== '') {
                                    $movementNote .= ' - Invoice ' .
                                        $invoiceNumber;
                                }

                                $stmtMovement->execute([
                                    $item['inventory_item_id'],
                                    $item['quantity'],
                                    $movementNote,
                                    $adminUsername
                                ]);
                            }
                        }

                        $pdo->commit();

                        $message = $purchaseStatus === 'Received'
                            ? 'Purchase invoice saved. All item stocks have been updated.'
                            : 'Purchase invoice saved as Pending. Inventory has not changed.';

                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error = $e instanceof RuntimeException
                            ? $e->getMessage()
                            : 'Unable to save the purchase. Please check the database and try again.';
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | RECEIVE EXISTING PENDING PURCHASE
        |--------------------------------------------------------------------------
        */
        elseif ($action === 'receive_purchase') {
            $purchaseId = (int)($_POST['purchase_id'] ?? 0);

            if ($purchaseId <= 0) {
                $error = 'Invalid purchase selected.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        "SELECT id, purchase_status, invoice_number
                         FROM purchase_orders
                         WHERE id = ?
                         FOR UPDATE"
                    );
                    $stmt->execute([$purchaseId]);
                    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$purchase) {
                        throw new RuntimeException(
                            'Purchase record not found.'
                        );
                    }

                    if ($purchase['purchase_status'] !== 'Pending') {
                        throw new RuntimeException(
                            'Only Pending purchases can be received.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        "SELECT inventory_item_id, quantity
                         FROM purchase_items
                         WHERE purchase_id = ?
                         ORDER BY inventory_item_id
                         FOR UPDATE"
                    );
                    $stmt->execute([$purchaseId]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!$items) {
                        throw new RuntimeException(
                            'This purchase has no recorded items.'
                        );
                    }

                    $adminUsername = $_SESSION['admin_username']
                        ?? $_SESSION['username']
                        ?? 'admin';

                    $movementNote = 'Purchase #' . $purchaseId;

                    if (!empty($purchase['invoice_number'])) {
                        $movementNote .= ' - Invoice ' .
                            $purchase['invoice_number'];
                    }

                    foreach ($items as $item) {
                        $inventoryId = (int)$item['inventory_item_id'];
                        $quantity = (float)$item['quantity'];

                        $stmt = $pdo->prepare(
                            "UPDATE inventory_items
                             SET quantity = quantity + ?
                             WHERE id = ?"
                        );
                        $stmt->execute([$quantity, $inventoryId]);

                        $check = $pdo->prepare(
                            "SELECT id FROM inventory_items WHERE id = ?"
                        );
                        $check->execute([$inventoryId]);

                        if (!$check->fetch()) {
                            throw new RuntimeException(
                                'An inventory item in this purchase no longer exists.'
                            );
                        }

                        $stmt = $pdo->prepare(
                            "INSERT INTO stock_movements
                             (inventory_item_id, movement_type, quantity,
                              note, admin_username)
                             VALUES (?, 'Stock In', ?, ?, ?)"
                        );
                        $stmt->execute([
                            $inventoryId,
                            $quantity,
                            $movementNote,
                            $adminUsername
                        ]);
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE purchase_orders
                         SET purchase_status = 'Received'
                         WHERE id = ? AND purchase_status = 'Pending'"
                    );
                    $stmt->execute([$purchaseId]);

                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Purchase status changed. Refresh and try again.'
                        );
                    }

                    $pdo->commit();

                    $message = 'Purchase received successfully. Stock has been updated for every item.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : 'Unable to receive the purchase. Please try again.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Dashboard totals and dropdown data
|--------------------------------------------------------------------------
*/
$suppliers = $pdo->query(
    "SELECT id, name FROM suppliers ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$inventoryItems = $pdo->query(
    "SELECT id, name, unit, quantity
     FROM inventory_items
     ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$totalPurchases = (int)$pdo->query(
    "SELECT COUNT(*) FROM purchase_orders"
)->fetchColumn();

$pendingPurchases = (int)$pdo->query(
    "SELECT COUNT(*) FROM purchase_orders
     WHERE purchase_status = 'Pending'"
)->fetchColumn();

$totalPurchaseValue = (float)$pdo->query(
    "SELECT COALESCE(SUM(total_amount), 0)
     FROM purchase_orders
     WHERE purchase_status != 'Cancelled'"
)->fetchColumn();

$purchaseRows = $pdo->query(
    "SELECT p.id, p.invoice_number, p.purchase_date,
            p.total_amount, p.payment_status, p.purchase_status,
            s.name AS supplier_name,
            GROUP_CONCAT(
                CONCAT(
                    i.name, ' (',
                    pi.quantity, ' ', i.unit, ')'
                )
                ORDER BY i.name SEPARATOR ', '
            ) AS items_summary
     FROM purchase_orders p
     INNER JOIN suppliers s ON s.id = p.supplier_id
     LEFT JOIN purchase_items pi ON pi.purchase_id = p.id
     LEFT JOIN inventory_items i ON i.id = pi.inventory_item_id
     GROUP BY p.id, p.invoice_number, p.purchase_date,
              p.total_amount, p.payment_status, p.purchase_status,
              s.name
     ORDER BY p.id DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Purchase Records | Sunrise Café</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f6f8;
    color: #25313b;
}

header {
    background: #49301f;
    color: white;
    padding: 22px 30px;
}

header h1 { margin: 0 0 7px; }
header p { margin: 0; color: #eadfd4; }

.container {
    max-width: 1350px;
    margin: 25px auto;
    padding: 0 20px;
}

.nav {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 22px;
}

.nav a {
    background: white;
    color: #49301f;
    padding: 11px 14px;
    border: 1px solid #e1e5e9;
    border-radius: 6px;
    text-decoration: none;
    font-weight: bold;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.stat, .panel {
    background: white;
    border: 1px solid #e1e5e9;
    border-radius: 10px;
    padding: 20px;
}

.stat strong {
    display: block;
    font-size: 26px;
    margin-top: 9px;
    color: #70482d;
}

.panel { margin-bottom: 22px; }
.panel h2 { margin-top: 0; font-size: 20px; }

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 18px;
}

label {
    display: block;
    margin: 13px 0 6px;
    font-weight: bold;
    font-size: 14px;
}

input, select, textarea {
    width: 100%;
    padding: 11px;
    border: 1px solid #ccd3da;
    border-radius: 6px;
    font: inherit;
    background: white;
}

textarea { min-height: 75px; resize: vertical; }
.full { grid-column: 1 / -1; }

.btn {
    display: inline-block;
    border: none;
    border-radius: 6px;
    padding: 10px 14px;
    cursor: pointer;
    text-decoration: none;
    font-weight: bold;
    font-size: 13px;
}

.primary { background: #70482d; color: white; }
.secondary { background: #e7ebef; color: #25313b; }
.receive { background: #176534; color: white; }

.alert {
    padding: 13px 15px;
    border-radius: 6px;
    margin-bottom: 18px;
}

.success { background: #e3f7e9; color: #176534; }
.failure { background: #fde8e7; color: #a11b1b; }

.item-table-wrap { overflow-x: auto; margin-top: 12px; }

.item-table {
    width: 100%;
    min-width: 650px;
    border-collapse: collapse;
}

.item-table th, .item-table td {
    padding: 10px;
    border-bottom: 1px solid #e8ebee;
    text-align: left;
}

.item-table th { background: #f8f5f2; font-size: 13px; }
.item-table input, .item-table select { min-width: 100px; }

.remove-btn {
    background: #b42318;
    color: white;
    border: 0;
    border-radius: 5px;
    padding: 9px 11px;
    cursor: pointer;
}

.invoice-total {
    margin-top: 18px;
    text-align: right;
    font-size: 22px;
    font-weight: bold;
    color: #70482d;
}

.table-wrap { overflow-x: auto; }

.history-table {
    width: 100%;
    min-width: 950px;
    border-collapse: collapse;
}

.history-table th, .history-table td {
    text-align: left;
    padding: 12px;
    border-bottom: 1px solid #e8ebee;
    vertical-align: top;
}

.history-table th { background: #f8f5f2; font-size: 13px; }
.history-table td { font-size: 14px; }

.status {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 12px;
    background: #edf0f3;
    font-size: 12px;
    white-space: nowrap;
}

.status.received, .status.paid {
    background: #e3f7e9;
    color: #176534;
}

.status.pending, .status.unpaid {
    background: #fff1cf;
    color: #795400;
}

.muted { color: #697681; font-size: 13px; }
.empty { padding: 25px; text-align: center; color: #697681; }

@media (max-width: 750px) {
    .stats { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
    .full { grid-column: auto; }
    .container { padding: 0 12px; }
    header { padding: 20px; }
}
</style>
</head>

<body>
<header>
    <h1>Sunrise Café</h1>
    <p>Supplier Purchases and Stock Receiving</p>
</header>

<main class="container">

<nav class="nav">
    <a href="index.php">Dashboard</a>
    <a href="inventory.php">Inventory</a>
    <a href="suppliers.php">Suppliers</a>
    <a href="reports.php">Reports</a>
    <a href="logout.php">Logout</a>
</nav>

<?php if ($message !== ''): ?>
    <div class="alert success"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert failure"><?= h($error) ?></div>
<?php endif; ?>

<section class="stats">
    <div class="stat">
        Total Purchases
        <strong><?= $totalPurchases ?></strong>
    </div>

    <div class="stat">
        Pending Purchases
        <strong><?= $pendingPurchases ?></strong>
    </div>

    <div class="stat">
        Purchase Value
        <strong>KSh <?= number_format($totalPurchaseValue, 2) ?></strong>
    </div>
</section>

<section class="panel">
    <h2>Record a Supplier Invoice</h2>

    <?php if (empty($suppliers)): ?>
        <div class="alert failure">
            Add a supplier before recording a purchase.
            <a href="suppliers.php">Manage suppliers</a>.
        </div>
    <?php elseif (empty($inventoryItems)): ?>
        <div class="alert failure">
            Add an inventory item before recording a purchase.
            <a href="inventory.php">Manage inventory</a>.
        </div>
    <?php else: ?>

    <form method="POST" action="purchases.php" id="purchaseForm">
        <input type="hidden" name="csrf_token"
               value="<?= h($csrfToken) ?>">
        <input type="hidden" name="action" value="save_purchase">

        <div class="form-grid">
            <div>
                <label for="supplier_id">Supplier *</label>
                <select id="supplier_id" name="supplier_id" required>
                    <option value="">Select supplier</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= (int)$supplier['id'] ?>">
                            <?= h($supplier['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="invoice_number">Invoice Number</label>
                <input id="invoice_number" name="invoice_number"
                       maxlength="100" placeholder="e.g. INV-001">
            </div>

            <div>
                <label for="purchase_date">Purchase Date *</label>
                <input id="purchase_date" name="purchase_date"
                       type="date" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div>
                <label for="payment_status">Payment Status *</label>
                <select id="payment_status" name="payment_status" required>
                    <option value="Unpaid">Unpaid</option>
                    <option value="Partially Paid">Partially Paid</option>
                    <option value="Paid">Paid</option>
                </select>
            </div>

            <div>
                <label for="purchase_status">Delivery Status *</label>
                <select id="purchase_status" name="purchase_status" required>
                    <option value="Pending">Pending - Not Received</option>
                    <option value="Received">Received - Add to Stock</option>
                </select>
            </div>
        </div>

        <h3>Invoice Items</h3>
        <p class="muted">
            Add every inventory item included on this supplier invoice.
        </p>

        <div class="item-table-wrap">
            <table class="item-table">
                <thead>
                <tr>
                    <th>Inventory Item</th>
                    <th>Quantity</th>
                    <th>Unit Cost (KSh)</th>
                    <th>Subtotal (KSh)</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody id="itemsBody"></tbody>
            </table>
        </div>

        <button type="button" class="btn secondary"
                id="addItemBtn">
            + Add Another Item
        </button>

        <div class="invoice-total">
            Invoice Total: KSh <span id="invoiceTotal">0.00</span>
        </div>

        <div class="form-grid">
            <div class="full">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes"
                          placeholder="Optional invoice details"></textarea>
            </div>
        </div>

        <p class="muted">
            Each line subtotal is quantity × unit cost. Stock is updated
            only when the invoice is marked Received.
        </p>

        <button type="submit" class="btn primary">
            Save Supplier Invoice
        </button>
    </form>

    <?php endif; ?>
</section>

<section class="panel">
    <h2>Purchase History</h2>

    <?php if (empty($purchaseRows)): ?>
        <div class="empty">No purchase records yet.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="history-table">
            <thead>
            <tr>
                <th>Purchase</th>
                <th>Date</th>
                <th>Supplier</th>
                <th>Invoice Items</th>
                <th>Total</th>
                <th>Payment</th>
                <th>Delivery</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($purchaseRows as $purchase): ?>
                <tr>
                    <td>
                        <strong>#<?= (int)$purchase['id'] ?></strong>
                        <div class="muted">
                            <?= h($purchase['invoice_number'] ?: 'No invoice') ?>
                        </div>
                    </td>

                    <td><?= h($purchase['purchase_date']) ?></td>
                    <td><?= h($purchase['supplier_name']) ?></td>
                    <td><?= h($purchase['items_summary'] ?: 'No items') ?></td>

                    <td>
                        KSh <?= number_format(
                            (float)$purchase['total_amount'], 2
                        ) ?>
                    </td>

                    <td>
                        <span class="status <?= strtolower(
                            str_replace(' ', '-', $purchase['payment_status'])
                        ) ?>">
                            <?= h($purchase['payment_status']) ?>
                        </span>
                    </td>

                    <td>
                        <span class="status <?= strtolower(
                            $purchase['purchase_status']
                        ) ?>">
                            <?= h($purchase['purchase_status']) ?>
                        </span>
                    </td>

                    <td>
                        <?php if ($purchase['purchase_status'] === 'Pending'): ?>
                            <form method="POST" action="purchases.php"
                                  onsubmit="return confirm('Confirm that all goods on this invoice have arrived? This will update stock for every item.');">
                                <input type="hidden" name="csrf_token"
                                       value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action"
                                       value="receive_purchase">
                                <input type="hidden" name="purchase_id"
                                       value="<?= (int)$purchase['id'] ?>">

                                <button type="submit" class="btn receive">
                                    Receive Stock
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="muted">No action needed</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted">Showing up to 100 recent purchase invoices.</p>
    <?php endif; ?>
</section>

</main>

<script>
const inventoryOptions = <?= json_encode(
    array_map(
        function ($item) {
            return [
                'id' => (int)$item['id'],
                'name' => $item['name'],
                'unit' => $item['unit'],
                'quantity' => (float)$item['quantity']
            ];
        },
        $inventoryItems
    ),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const itemsBody = document.getElementById('itemsBody');
const addItemBtn = document.getElementById('addItemBtn');
const invoiceTotal = document.getElementById('invoiceTotal');

function addItemRow() {
    if (itemsBody.children.length >= 100) {
        alert('You can add up to 100 items per invoice.');
        return;
    }

    const row = document.createElement('tr');

    const itemCell = document.createElement('td');
    const itemSelect = document.createElement('select');
    itemSelect.name = 'inventory_item_id[]';
    itemSelect.required = true;

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select item';
    itemSelect.appendChild(placeholder);

    inventoryOptions.forEach(item => {
        const option = document.createElement('option');
        option.value = String(item.id);
        option.textContent =
            `${item.name} (Stock: ${item.quantity} ${item.unit})`;
        itemSelect.appendChild(option);
    });

    itemCell.appendChild(itemSelect);

    const quantityCell = document.createElement('td');
    const quantityInput = document.createElement('input');
    quantityInput.type = 'number';
    quantityInput.name = 'quantity[]';
    quantityInput.min = '0.01';
    quantityInput.step = '0.01';
    quantityInput.placeholder = 'Quantity';
    quantityInput.required = true;
    quantityCell.appendChild(quantityInput);

    const costCell = document.createElement('td');
    const costInput = document.createElement('input');
    costInput.type = 'number';
    costInput.name = 'unit_cost[]';
    costInput.min = '0';
    costInput.step = '0.01';
    costInput.placeholder = 'Unit cost';
    costInput.required = true;
    costCell.appendChild(costInput);

    const subtotalCell = document.createElement('td');
    subtotalCell.textContent = '0.00';

    const actionCell = document.createElement('td');
    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'remove-btn';
    removeButton.textContent = 'Remove';

    removeButton.addEventListener('click', () => {
        row.remove();

        if (itemsBody.children.length === 0) {
            addItemRow();
        }

        updateTotals();
    });

    actionCell.appendChild(removeButton);

    row.append(
        itemCell,
        quantityCell,
        costCell,
        subtotalCell,
        actionCell
    );

    itemsBody.appendChild(row);

    quantityInput.addEventListener('input', updateTotals);
    costInput.addEventListener('input', updateTotals);
}

function updateTotals() {
    let total = 0;

    Array.from(itemsBody.children).forEach(row => {
        const quantityInput = row.querySelector(
            'input[name="quantity[]"]'
        );

        const costInput = row.querySelector(
            'input[name="unit_cost[]"]'
        );

        const quantity = Number(quantityInput.value) || 0;
        const cost = Number(costInput.value) || 0;
        const subtotal = quantity * cost;

        row.children[3].textContent = subtotal.toLocaleString(
            'en-KE',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 }
        );

        total += subtotal;
    });

    invoiceTotal.textContent = total.toLocaleString(
        'en-KE',
        { minimumFractionDigits: 2, maximumFractionDigits: 2 }
    );
}

if (addItemBtn && itemsBody) {
    addItemBtn.addEventListener('click', addItemRow);

    document.getElementById('purchaseForm').addEventListener(
        'submit',
        function (event) {
            if (itemsBody.children.length === 0) {
                event.preventDefault();
                alert('Please add at least one invoice item.');
            }
        }
    );

    addItemRow();
}
</script>

</body>
</html>
