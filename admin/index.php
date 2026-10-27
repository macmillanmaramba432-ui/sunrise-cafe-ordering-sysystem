
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Protect the dashboard from unauthorized visitors.
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Dashboard statistics.
$totalOrders = (int)$pdo->query(
    "SELECT COUNT(*) FROM orders"
)->fetchColumn();

$pendingOrders = (int)$pdo->query(
    "SELECT COUNT(*) FROM orders WHERE order_status = 'Pending'"
)->fetchColumn();

$completedOrders = (int)$pdo->query(
    "SELECT COUNT(*) FROM orders WHERE order_status = 'Completed'"
)->fetchColumn();

$totalRevenue = (float)$pdo->query(
    "SELECT COALESCE(SUM(total), 0)
     FROM orders
     WHERE order_status = 'Completed'"
)->fetchColumn();

$totalProducts = (int)$pdo->query(
    "SELECT COUNT(*) FROM menu_items"
)->fetchColumn();

$availableProducts = (int)$pdo->query(
    "SELECT COUNT(*) FROM menu_items WHERE available = 1"
)->fetchColumn();

// Recent orders.
$recentStmt = $pdo->query(
    "SELECT o.id, o.order_number, o.total, o.order_status,
            o.payment_status, o.created_at,
            c.name AS customer_name
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     ORDER BY o.created_at DESC
     LIMIT 8"
);
$recentOrders = $recentStmt->fetchAll();

// Most frequently ordered products.
$popularStmt = $pdo->query(
    "SELECT product_name, SUM(quantity) AS quantity_sold
     FROM order_items
     GROUP BY product_name
     ORDER BY quantity_sold DESC
     LIMIT 5"
);
$popularProducts = $popularStmt->fetchAll();

// Reviews awaiting approval.
$pendingReviews = (int)$pdo->query(
    "SELECT COUNT(*) FROM reviews WHERE status = 'Pending'"
)->fetchColumn();

$adminName = $_SESSION['admin_username'] ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard | Sunrise Café</title>

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: #f5f3f0;
    color: #33251e;
}

.sidebar {
    width: 245px;
    background: #432719;
    color: white;
    position: fixed;
    inset: 0 auto 0 0;
    padding: 25px 16px;
    overflow-y: auto;
}

.brand {
    font-size: 22px;
    font-weight: bold;
    padding: 8px 10px 28px;
}

.brand span {
    color: #f3b562;
}

.sidebar a {
    display: block;
    padding: 13px 12px;
    color: #f8e9dc;
    text-decoration: none;
    border-radius: 8px;
    margin-bottom: 7px;
}

.sidebar a:hover,
.sidebar a.active {
    background: #75452d;
    color: white;
}

.sidebar .logout {
    margin-top: 25px;
    color: #ffd2c8;
}

.main {
    margin-left: 245px;
    padding: 28px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 28px;
}

.topbar h1 {
    font-size: 27px;
    margin-bottom: 7px;
}

.muted {
    color: #7b6d64;
    font-size: 14px;
}

.admin-badge {
    background: white;
    padding: 12px 16px;
    border-radius: 10px;
    box-shadow: 0 3px 15px #00000008;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 17px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    border-radius: 13px;
    padding: 21px;
    box-shadow: 0 3px 15px #00000008;
    border-bottom: 3px solid #b77b46;
}

.stat-card .label {
    color: #7b6d64;
    font-size: 13px;
    margin-bottom: 13px;
}

.stat-card .number {
    font-size: 27px;
    font-weight: bold;
    overflow-wrap: anywhere;
}

.stat-card .note {
    font-size: 12px;
    color: #7b6d64;
    margin-top: 9px;
}

.content-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(260px, 1fr);
    gap: 20px;
}

.panel {
    background: white;
    border-radius: 13px;
    padding: 21px;
    margin-bottom: 22px;
    box-shadow: 0 3px 15px #00000008;
    min-width: 0;
}

.panel h2 {
    font-size: 18px;
    margin-bottom: 18px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    min-width: 530px;
}

th, td {
    padding: 13px 10px;
    text-align: left;
    border-bottom: 1px solid #eee7e1;
}

th {
    background: #fbf7f2;
    color: #6d584a;
}

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #f2e9df;
    font-size: 11px;
    font-weight: bold;
    white-space: nowrap;
}

.status.completed {
    background: #e0f4e5;
    color: #216b37;
}

.status.pending {
    background: #fff0d2;
    color: #875b08;
}

.status.cancelled {
    background: #fde6e4;
    color: #a12e25;
}

.status.preparing,
.status.ready {
    background: #e4edff;
    color: #2857a1;
}

.view-link {
    display: inline-block;
    margin-top: 16px;
    color: #8b4513;
    text-decoration: none;
    font-weight: bold;
    font-size: 13px;
}

.view-link:hover {
    text-decoration: underline;
}

.popular-item {
    padding: 14px 0;
    border-bottom: 1px solid #eee7e1;
    display: flex;
    justify-content: space-between;
    gap: 12px;
}

.popular-item:last-child {
    border-bottom: none;
}

.quantity {
    color: #8b4513;
    font-weight: bold;
    white-space: nowrap;
}

.quick-links {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}

.quick-link {
    display: block;
    background: white;
    color: #432719;
    text-decoration: none;
    border-radius: 12px;
    padding: 18px;
    box-shadow: 0 3px 15px #00000008;
    font-weight: bold;
}

.quick-link span {
    display: block;
    font-size: 13px;
    color: #7b6d64;
    font-weight: normal;
    margin-top: 8px;
    line-height: 1.5;
}

.quick-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 7px 18px #00000010;
}

.empty {
    color: #7b6d64;
    padding: 10px 0;
    font-size: 14px;
}

@media (max-width: 1100px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .sidebar {
        position: static;
        width: 100%;
        padding: 14px;
    }

    .brand {
        padding-bottom: 15px;
    }

    .sidebar nav {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 4px;
    }

    .sidebar a {
        margin: 0;
        font-size: 13px;
    }

    .sidebar .logout {
        margin-top: 5px;
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }

    .topbar h1 {
        font-size: 23px;
    }

    .stats {
        gap: 10px;
    }

    .stat-card {
        padding: 15px;
    }

    .stat-card .number {
        font-size: 23px;
    }

    .quick-links {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<aside class="sidebar">
    <div class="brand">☕ Sunrise <span>Café</span></div>

    <nav>
        <a href="index.php" class="active">▦ Dashboard</a>
        <a href="orders.php">▤ Manage Orders</a>
        <a href="menu.php">☕ Manage Menu</a>
        <a href="reviews.php">★ Customer Reviews</a>
        <a href="../sunrise_cafe_system/index.php" target="_blank">
            ↗ Customer Website
        </a>
        <a href="reports.php">Sales &amp; Revenue Reports</a>
        <?php
            if (empty($_SESSION['logout_csrf'])) {
                $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
            }
            ?>

            <form method="POST" action="logout.php">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['logout_csrf'], ENT_QUOTES, 'UTF-8') ?>"
                >
                <button type="submit" class="logout-button">↪ Logout</button>
            </form>
    </nav>
</aside>

<main class="main">

    <header class="topbar">
        <div>
            <h1>Admin Dashboard</h1>
            <p class="muted">
                Welcome back, <?= h($adminName) ?>.
                Here's your café overview.
            </p>
        </div>

        <div class="admin-badge">
            ☕ Sunrise Café Administration
        </div>
    </header>

    <section class="stats">
        <article class="stat-card">
            <div class="label">Total Orders</div>
            <div class="number"><?= number_format($totalOrders) ?></div>
            <div class="note">All recorded orders</div>
        </article>

        <article class="stat-card">
            <div class="label">Pending Orders</div>
            <div class="number"><?= number_format($pendingOrders) ?></div>
            <div class="note">Awaiting processing</div>
        </article>

        <article class="stat-card">
            <div class="label">Completed Order Sales</div>
            <div class="number">
                KSh <?= number_format($totalRevenue, 2) ?>
            </div>
            <div class="note">
                Total value of completed orders; not necessarily confirmed payments
            </div>
        </article>

        <article class="stat-card">
            <div class="label">Available Menu Items</div>
            <div class="number">
                <?= number_format($availableProducts) ?>
            </div>
            <div class="note">
                <?= number_format($totalProducts) ?> items in the menu
            </div>
        </article>
    </section>

    <section class="quick-links">
        <a href="orders.php" class="quick-link">
            📦 Manage Orders
            <span>Review orders and update their status.</span>
        </a>

        <a href="menu.php" class="quick-link">
            🍰 Manage Menu
            <span>Add products, change prices, and update availability.</span>
        </a>

        <a href="reviews.php" class="quick-link">
            ⭐ Review Moderation
            <span><?= number_format($pendingReviews) ?> reviews awaiting approval.</span>
        </a>
    </section>

    <section class="content-grid">
        <div class="panel">
            <h2>Recent Orders</h2>

            <?php if (!$recentOrders): ?>
                <p class="empty">No orders have been placed yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Order Number</th>
                                <th>Customer</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentOrders as $order): ?>
                            <?php
                            $statusClass = strtolower(
                                (string)$order['order_status']
                            );
                            ?>
                            <tr>
                                <td><?= h($order['order_number']) ?></td>
                                <td>
                                    <?= h($order['customer_name'] ?: 'Customer') ?>
                                </td>
                                <td>
                                    KSh <?= number_format((float)$order['total'], 2) ?>
                                </td>
                                <td>
                                    <span class="status <?= h($statusClass) ?>">
                                        <?= h($order['order_status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= h(date(
                                        'd M Y',
                                        strtotime($order['created_at'])
                                    )) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <a href="orders.php" class="view-link">
                    View all orders →
                </a>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h2>Popular Menu Items</h2>

            <?php if (!$popularProducts): ?>
                <p class="empty">Popular products will appear after orders are placed.</p>
            <?php else: ?>
                <?php foreach ($popularProducts as $product): ?>
                    <div class="popular-item">
                        <span><?= h($product['product_name']) ?></span>
                        <span class="quantity">
                            <?= number_format((int)$product['quantity_sold']) ?> sold
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <a href="menu.php" class="view-link">
                Manage menu items →
            </a>
        </div>
    </section>

    <footer class="muted">
        Sunrise Café Ordering System &copy; <?= date('Y') ?>
    </footer>

</main>
</body>
</html>
