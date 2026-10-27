
<?php
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['inventory_csrf'])) {
    $_SESSION['inventory_csrf'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectInventory(string $message, string $type = 'success'): void
{
    $_SESSION['inventory_message'] = [
        'text' => $message,
        'type' => $type
    ];

    header('Location: inventory.php');
    exit;
}

$message = '';
$messageType = 'success';

if (isset($_SESSION['inventory_message'])) {
    $message = $_SESSION['inventory_message']['text'];
    $messageType = $_SESSION['inventory_message']['type'];
    unset($_SESSION['inventory_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['inventory_csrf'], $token)) {
        redirectInventory('Security check failed. Please try again.', 'error');
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? 'General');
            $unit = trim($_POST['unit'] ?? 'pcs');
            $supplier = trim($_POST['supplier'] ?? '');

            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
            $minimum = filter_var($_POST['min_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
            $cost = filter_var($_POST['unit_cost'] ?? null, FILTER_VALIDATE_FLOAT);

            if (
                $name === '' || $category === '' || $unit === '' ||
                $quantity === false || $quantity === null || $quantity < 0 ||
                $minimum === false || $minimum === null || $minimum < 0 ||
                $cost === false || $cost === null || $cost < 0
            ) {
                redirectInventory('Enter valid item details. Numbers cannot be negative.', 'error');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO inventory_items
                (name, category, unit, quantity, min_quantity, unit_cost, supplier)
                VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $name, $category, $unit, $quantity, $minimum, $cost,
                $supplier !== '' ? $supplier : null
            ]);

            $itemId = (int)$pdo->lastInsertId();

            if ($quantity > 0) {
                $movement = $pdo->prepare(
                    "INSERT INTO stock_movements
                    (inventory_item_id, movement_type, quantity, note, admin_username)
                    VALUES (?, 'Stock In', ?, 'Opening stock', ?)"
                );

                $movement->execute([
                    $itemId,
                    $quantity,
                    $_SESSION['admin_username'] ?? 'admin'
                ]);
            }

            $pdo->commit();
            redirectInventory('Inventory item added successfully.');
        }

        if ($action === 'edit') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $unit = trim($_POST['unit'] ?? '');
            $supplier = trim($_POST['supplier'] ?? '');

            $minimum = filter_var($_POST['min_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
            $cost = filter_var($_POST['unit_cost'] ?? null, FILTER_VALIDATE_FLOAT);

            if (
                !$id || $name === '' || $category === '' || $unit === '' ||
                $minimum === false || $minimum === null || $minimum < 0 ||
                $cost === false || $cost === null || $cost < 0
            ) {
                redirectInventory('Enter valid item details.', 'error');
            }

            $stmt = $pdo->prepare(
                'UPDATE inventory_items
                 SET name = ?, category = ?, unit = ?,
                     min_quantity = ?, unit_cost = ?, supplier = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $name, $category, $unit, $minimum, $cost,
                $supplier !== '' ? $supplier : null, $id
            ]);

            redirectInventory('Inventory item updated successfully.');
        }

        if ($action === 'movement') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $type = $_POST['movement_type'] ?? '';
            $quantity = filter_var(
                $_POST['movement_quantity'] ?? null,
                FILTER_VALIDATE_FLOAT
            );
            $note = trim($_POST['note'] ?? '');

            if (
                !$id ||
                !in_array($type, ['Stock In', 'Stock Out', 'Adjustment'], true) ||
                $quantity === false || $quantity === null || $quantity <= 0
            ) {
                redirectInventory('Select an item and enter a valid movement quantity.', 'error');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT quantity FROM inventory_items WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                $pdo->rollBack();
                redirectInventory('Inventory item was not found.', 'error');
            }

            $current = (float)$item['quantity'];

            if ($type === 'Stock In') {
                $newQuantity = $current + $quantity;
                $movementQuantity = $quantity;
            } elseif ($type === 'Stock Out') {
                if ($quantity > $current) {
                    $pdo->rollBack();
                    redirectInventory('Stock out cannot exceed available stock.', 'error');
                }

                $newQuantity = $current - $quantity;
                $movementQuantity = $quantity;
            } else {
                // Adjustment quantity is the new total quantity.
                $newQuantity = $quantity;
                $movementQuantity = abs($newQuantity - $current);
            }

            $update = $pdo->prepare(
                'UPDATE inventory_items SET quantity = ? WHERE id = ?'
            );
            $update->execute([$newQuantity, $id]);

            if ($type !== 'Adjustment' || $movementQuantity > 0) {
                $movement = $pdo->prepare(
                    'INSERT INTO stock_movements
                    (inventory_item_id, movement_type, quantity, note, admin_username)
                    VALUES (?, ?, ?, ?, ?)'
                );

                $movement->execute([
                    $id,
                    $type,
                    $movementQuantity,
                    $note !== '' ? $note : null,
                    $_SESSION['admin_username'] ?? 'admin'
                ]);
            }

            $pdo->commit();
            redirectInventory('Stock movement saved successfully.');
        }

        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

            if (!$id) {
                redirectInventory('Invalid inventory item.', 'error');
            }

            $check = $pdo->prepare(
                'SELECT COUNT(*) FROM stock_movements WHERE inventory_item_id = ?'
            );
            $check->execute([$id]);

            if ((int)$check->fetchColumn() > 0) {
                redirectInventory(
                    'This item has stock history and cannot be deleted.',
                    'error'
                );
            }

            $stmt = $pdo->prepare('DELETE FROM inventory_items WHERE id = ?');
            $stmt->execute([$id]);

            redirectInventory('Inventory item deleted successfully.');
        }

        redirectInventory('Unknown operation.', 'error');

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Inventory error: ' . $exception->getMessage());
        redirectInventory(
            'The operation failed. Check the inventory tables and server error log.',
            'error'
        );
    }
}

$editItem = null;

if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editItem = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$items = [];
$movements = [];
$totalItems = 0;
$lowStockItems = 0;
$totalStockValue = 0;

try {
    $totalItems = (int)$pdo->query(
        'SELECT COUNT(*) FROM inventory_items'
    )->fetchColumn();

    $lowStockItems = (int)$pdo->query(
        'SELECT COUNT(*) FROM inventory_items WHERE quantity <= min_quantity'
    )->fetchColumn();

    $totalStockValue = (float)$pdo->query(
        'SELECT COALESCE(SUM(quantity * unit_cost), 0) FROM inventory_items'
    )->fetchColumn();

    $items = $pdo->query(
        'SELECT * FROM inventory_items ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $movements = $pdo->query(
        'SELECT sm.*, ii.name AS item_name, ii.unit
         FROM stock_movements sm
         INNER JOIN inventory_items ii ON ii.id = sm.inventory_item_id
         ORDER BY sm.created_at DESC, sm.id DESC
         LIMIT 30'
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $exception) {
    error_log('Inventory loading error: ' . $exception->getMessage());
    $message = 'Unable to load inventory. Check that both inventory tables exist.';
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory Management | Sunrise Café</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f4f6f9;
    color: #263238;
}

.topbar {
    background: #442c1b;
    color: white;
    padding: 18px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.topbar h1 { margin: 0; font-size: 23px; }

.topbar a {
    color: white;
    text-decoration: none;
    margin-left: 14px;
}

.container {
    max-width: 1400px;
    margin: 25px auto;
    padding: 0 18px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}

.stat-card, .panel {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}

.stat-card p { color: #667085; margin: 0 0 10px; }
.stat-card h2 { margin: 0; font-size: 27px; }
.panel { margin-bottom: 24px; overflow: hidden; }
.panel h2 { margin-top: 0; font-size: 20px; }

.form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
}

label {
    display: block;
    font-size: 14px;
    font-weight: bold;
    margin-bottom: 6px;
}

input, select {
    width: 100%;
    padding: 11px;
    border: 1px solid #ccd2d9;
    border-radius: 6px;
    font: inherit;
    background: white;
}

input[readonly] { background: #f1f3f5; }

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 17px;
}

.btn {
    display: inline-block;
    border: 0;
    border-radius: 6px;
    padding: 10px 14px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    font-size: 13px;
}

.btn-primary { background: #7b4b27; color: white; }
.btn-secondary { background: #e9edf2; color: #263238; }
.btn-danger { background: #b42318; color: white; }
.btn-green { background: #157347; color: white; }

.alert {
    padding: 13px 16px;
    border-radius: 7px;
    margin-bottom: 20px;
}

.alert.success { background: #dff6e8; color: #146c43; }
.alert.error { background: #fde7e7; color: #a61b1b; }

.table-wrap { overflow-x: auto; }

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th, td {
    padding: 12px 10px;
    text-align: left;
    border-bottom: 1px solid #e7e9ed;
    font-size: 13px;
}

th { background: #f7f8fa; color: #4b5563; }

.status {
    display: inline-block;
    border-radius: 20px;
    padding: 5px 9px;
    font-size: 12px;
    font-weight: bold;
}

.status.ok { background: #dcfce7; color: #166534; }
.status.low { background: #fee2e2; color: #991b1b; }
.inline-form { display: inline; }
.inline-form .btn { margin: 2px 0; }
.muted { color: #6b7280; font-size: 13px; }

@media (max-width: 800px) {
    .stats, .form-grid { grid-template-columns: 1fr; }
    .topbar { align-items: flex-start; }
    .topbar a { margin: 0 12px 0 0; }
}
</style>
</head>

<body>
<header class="topbar">
    <h1>Sunrise Café — Inventory</h1>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="menu.php">Menu</a>
        <a href="orders.php">Orders</a>
        <a href="reports.php">Reports</a>
    </nav>
</header>

<main class="container">

<?php if ($message !== ''): ?>
    <div class="alert <?= e($messageType) ?>"><?= e($message) ?></div>
<?php endif; ?>

<section class="stats">
    <div class="stat-card">
        <p>Total inventory items</p>
        <h2><?= number_format($totalItems) ?></h2>
    </div>

    <div class="stat-card">
        <p>Items at or below minimum stock</p>
        <h2><?= number_format($lowStockItems) ?></h2>
    </div>

    <div class="stat-card">
        <p>Estimated stock value</p>
        <h2>KSh <?= number_format($totalStockValue, 2) ?></h2>
    </div>
</section>

<section class="panel" id="item-form">
    <h2><?= $editItem ? 'Edit Inventory Item' : 'Add New Inventory Item' ?></h2>
    <p class="muted">Use consistent units such as kg, litres, packets, bottles or pieces.</p>

    <form method="POST" action="inventory.php">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['inventory_csrf']) ?>">
        <input type="hidden" name="action" value="<?= $editItem ? 'edit' : 'add' ?>">

        <?php if ($editItem): ?>
            <input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
            <div>
                <label for="name">Item name *</label>
                <input id="name" name="name" maxlength="150" required
                       value="<?= e($editItem['name'] ?? '') ?>"
                       placeholder="e.g. Coffee Beans">
            </div>

            <div>
                <label for="category">Category *</label>
                <input id="category" name="category" maxlength="100" required
                       value="<?= e($editItem['category'] ?? 'General') ?>">
            </div>

            <div>
                <label for="unit">Unit *</label>
                <input id="unit" name="unit" maxlength="30" required
                       value="<?= e($editItem['unit'] ?? 'pcs') ?>"
                       placeholder="e.g. kg, litres, pcs">
            </div>

            <?php if (!$editItem): ?>
            <div>
                <label for="quantity">Opening quantity *</label>
                <input id="quantity" type="number" name="quantity"
                       min="0" step="0.01" required value="0">
            </div>
            <?php endif; ?>

            <div>
                <label for="min_quantity">Minimum stock level *</label>
                <input id="min_quantity" type="number" name="min_quantity"
                       min="0" step="0.01" required
                       value="<?= e($editItem['min_quantity'] ?? '5') ?>">
            </div>

            <div>
                <label for="unit_cost">Cost per unit (KSh) *</label>
                <input id="unit_cost" type="number" name="unit_cost"
                       min="0" step="0.01" required
                       value="<?= e($editItem['unit_cost'] ?? '0') ?>">
            </div>

            <div>
                <label for="supplier">Supplier</label>
                <input id="supplier" name="supplier" maxlength="150"
                       value="<?= e($editItem['supplier'] ?? '') ?>"
                       placeholder="Supplier name">
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">
                <?= $editItem ? 'Save Changes' : 'Add Inventory Item' ?>
            </button>

            <?php if ($editItem): ?>
                <a class="btn btn-secondary" href="inventory.php">Cancel Edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Current Inventory</h2>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Item</th>
            <th>Category</th>
            <th>Quantity</th>
            <th>Minimum</th>
            <th>Unit Cost</th>
            <th>Stock Value</th>
            <th>Supplier</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        </thead>

        <tbody>
        <?php if (!$items): ?>
            <tr><td colspan="9">No inventory items yet. Add your first item above.</td></tr>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <?php
                $isLow = (float)$item['quantity'] <= (float)$item['min_quantity'];
                $stockValue = (float)$item['quantity'] * (float)$item['unit_cost'];
                ?>
                <tr>
                    <td>
                        <strong><?= e($item['name']) ?></strong><br>
                        <span class="muted"><?= e($item['unit']) ?></span>
                    </td>
                    <td><?= e($item['category']) ?></td>
                    <td><?= number_format((float)$item['quantity'], 2) ?></td>
                    <td><?= number_format((float)$item['min_quantity'], 2) ?></td>
                    <td>KSh <?= number_format((float)$item['unit_cost'], 2) ?></td>
                    <td>KSh <?= number_format($stockValue, 2) ?></td>
                    <td><?= e($item['supplier'] ?: '—') ?></td>
                    <td>
                        <span class="status <?= $isLow ? 'low' : 'ok' ?>">
                            <?= $isLow ? 'Low Stock' : 'In Stock' ?>
                        </span>
                    </td>
                    <td>
                        <a class="btn btn-secondary"
                           href="inventory.php?edit=<?= (int)$item['id'] ?>#item-form">Edit</a>

                        <a class="btn btn-green" href="#stock-form"
                           onclick="selectInventoryItem(<?= (int)$item['id'] ?>); return true;">
                            Stock
                        </a>

                        <form class="inline-form" method="POST" action="inventory.php"
                              onsubmit="return confirm('Delete this inventory item? Items with stock history cannot be deleted.');">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['inventory_csrf']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                            <button class="btn btn-danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="panel" id="stock-form">
    <h2>Record Stock Movement</h2>
    <p class="muted">
        Stock In adds to current quantity. Stock Out deducts from it.
        Adjustment sets a new total quantity.
    </p>

    <form method="POST" action="inventory.php">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['inventory_csrf']) ?>">
        <input type="hidden" name="action" value="movement">

        <div class="form-grid">
            <div>
                <label for="movement_item_id">Select Inventory Item *</label>
                <select name="id" id="movement_item_id" required>
                    <option value="">-- Select an item --</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?= (int)$item['id'] ?>">
                            <?= e($item['name']) ?>
                            (<?= number_format((float)$item['quantity'], 2) ?>
                            <?= e($item['unit']) ?> available)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="movement_type">Movement Type *</label>
                <select id="movement_type" name="movement_type" required>
                    <option value="Stock In">Stock In — received</option>
                    <option value="Stock Out">Stock Out — used</option>
                    <option value="Adjustment">Adjustment — set new total</option>
                </select>
            </div>

            <div>
                <label for="movement_quantity">Quantity *</label>
                <input id="movement_quantity" type="number"
                       name="movement_quantity" min="0.01" step="0.01"
                       required placeholder="Enter quantity">
            </div>

            <div>
                <label for="note">Reason / Note</label>
                <input id="note" name="note" maxlength="255"
                       placeholder="e.g. Weekly supplier delivery">
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Save Stock Movement</button>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Recent Stock Movements</h2>

    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Item</th>
            <th>Movement</th>
            <th>Quantity</th>
            <th>Note</th>
            <th>Recorded By</th>
        </tr>
        </thead>

        <tbody>
        <?php if (!$movements): ?>
            <tr><td colspan="6">No stock movements recorded yet.</td></tr>
        <?php else: ?>
            <?php foreach ($movements as $movement): ?>
                <tr>
                    <td><?= e($movement['created_at']) ?></td>
                    <td><?= e($movement['item_name']) ?></td>
                    <td><?= e($movement['movement_type']) ?></td>
                    <td>
                        <?= number_format((float)$movement['quantity'], 2) ?>
                        <?= e($movement['unit']) ?>
                    </td>
                    <td><?= e($movement['note'] ?: '—') ?></td>
                    <td><?= e($movement['admin_username'] ?: 'Admin') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</section>

</main>

<script>
function selectInventoryItem(id) {
    const dropdown = document.getElementById('movement_item_id');
    dropdown.value = String(id);

    document.getElementById('stock-form').scrollIntoView({
        behavior: 'smooth',
        block: 'start'
    });

    dropdown.focus();
}
</script>

</body>
</html>
