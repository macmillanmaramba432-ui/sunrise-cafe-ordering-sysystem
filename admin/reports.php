
<?php
session_start();

/* Protect this page from unauthorised access. */
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

/* Safely display database values in HTML. */
function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/* Format currency in Kenyan shillings. */
function money($amount): string
{
    return 'KSh ' . number_format((float)$amount, 2);
}

/* Validate date input in YYYY-MM-DD format. */
function validDate($date): bool
{
    if (!is_string($date)) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false
        && $parsed->format('Y-m-d') === $date;
}

$today = date('Y-m-d');
$error = '';

$startDate = $_GET['start_date'] ?? $today;
$endDate = $_GET['end_date'] ?? $today;

/* Validate the selected reporting period. */
if (!validDate($startDate) || !validDate($endDate)) {
    $startDate = $today;
    $endDate = $today;
    $error = 'Please select valid start and end dates.';
} elseif ($startDate > $endDate) {
    $startDate = $today;
    $endDate = $today;
    $error = 'The start date cannot be after the end date.';
}

/* Include the entire end date in the report. */
$startBoundary = $startDate . ' 00:00:00';

$endBoundary = (new DateTimeImmutable($endDate))
    ->modify('+1 day')
    ->format('Y-m-d') . ' 00:00:00';

$summary = [
    'total_orders' => 0,
    'completed_orders' => 0,
    'pending_orders' => 0,
    'completed_order_value' => 0,
    'paid_revenue' => 0
];

$dailySales = [];
$bestSellers = [];
$orders = [];

try {
    /*
     * 1. SALES SUMMARY
     *
     * Cancelled orders are excluded from the order count.
     * Completed order value includes completed orders.
     * Paid revenue uses recognised successful payment statuses.
     */
    $summarySql = "
        SELECT
            COUNT(*) AS total_orders,

            SUM(
                CASE
                    WHEN LOWER(order_status) = 'completed'
                    THEN 1 ELSE 0
                END
            ) AS completed_orders,

            SUM(
                CASE
                    WHEN LOWER(order_status) = 'pending'
                    THEN 1 ELSE 0
                END
            ) AS pending_orders,

            COALESCE(
                SUM(
                    CASE
                        WHEN LOWER(order_status) = 'completed'
                        THEN total ELSE 0
                    END
                ), 0
            ) AS completed_order_value,

            COALESCE(
                SUM(
                    CASE
                        WHEN LOWER(COALESCE(payment_status, ''))
                             IN ('paid', 'success', 'successful')
                        THEN total ELSE 0
                    END
                ), 0
            ) AS paid_revenue

        FROM orders
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND LOWER(order_status) != 'cancelled'
    ";

    $stmt = $pdo->prepare($summarySql);
    $stmt->execute([
        'start_date' => $startBoundary,
        'end_date' => $endBoundary
    ]);

    $result = $stmt->fetch();

    if ($result) {
        $summary = array_merge($summary, $result);
    }

    /*
     * 2. DAILY SALES AND ORDER ACTIVITY
     *
     * Only completed orders are counted in these charts.
     */
    $dailySql = "
        SELECT
            DATE(created_at) AS sales_date,
            COUNT(*) AS order_count,
            COALESCE(SUM(total), 0) AS sales_value
        FROM orders
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND LOWER(order_status) = 'completed'
        GROUP BY DATE(created_at)
        ORDER BY sales_date ASC
    ";

    $stmt = $pdo->prepare($dailySql);
    $stmt->execute([
        'start_date' => $startBoundary,
        'end_date' => $endBoundary
    ]);

    $dailySales = $stmt->fetchAll();

    $maxDailySales = 0;
    $maxDailyOrders = 0;

    foreach ($dailySales as $day) {
        $maxDailySales = max(
            $maxDailySales,
            (float)$day['sales_value']
        );

        $maxDailyOrders = max(
            $maxDailyOrders,
            (int)$day['order_count']
        );
    }

    /*
     * 3. BEST-SELLING PRODUCTS
     *
     * Product quantities and values from completed orders.
     */
    $productsSql = "
        SELECT
            oi.product_name,
            SUM(oi.quantity) AS quantity_sold,
            SUM(oi.subtotal) AS sales_value
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        WHERE o.created_at >= :start_date
          AND o.created_at < :end_date
          AND LOWER(o.order_status) = 'completed'
        GROUP BY oi.product_name
        ORDER BY quantity_sold DESC, sales_value DESC
        LIMIT 10
    ";

    $stmt = $pdo->prepare($productsSql);
    $stmt->execute([
        'start_date' => $startBoundary,
        'end_date' => $endBoundary
    ]);

    $bestSellers = $stmt->fetchAll();

    /*
     * 4. ORDER DETAILS
     *
     * Show up to 100 non-cancelled orders for the selected period.
     */
    $ordersSql = "
        SELECT
            order_number,
            order_status,
            payment_status,
            total,
            created_at
        FROM orders
        WHERE created_at >= :start_date
          AND created_at < :end_date
          AND LOWER(order_status) != 'cancelled'
        ORDER BY created_at DESC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($ordersSql);
    $stmt->execute([
        'start_date' => $startBoundary,
        'end_date' => $endBoundary
    ]);

    $orders = $stmt->fetchAll();

} catch (PDOException $exception) {
    error_log('Sales report error: ' . $exception->getMessage());

    http_response_code(500);

    exit(
        'Unable to load the sales report. ' .
        'Please check your database tables and configuration.'
    );
}

$rangeLabel = date('d M Y', strtotime($startDate))
    . ' - '
    . date('d M Y', strtotime($endDate));

$maxDailySales = $maxDailySales ?? 0;
$maxDailyOrders = $maxDailyOrders ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Sales Analytics | Sunrise Café</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f6f3ef;
            color: #33251e;
        }

        .header {
            background: #4b2e22;
            color: white;
            padding: 24px;
        }

        .header h1 {
            margin: 0 0 8px;
            font-size: 26px;
        }

        .header p {
            margin: 0;
            color: #f0ded1;
        }

        .container {
            max-width: 1300px;
            margin: 25px auto;
            padding: 0 20px;
        }

        .top-links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
        }

        .button {
            display: inline-block;
            border: none;
            border-radius: 7px;
            background: #70462f;
            color: white;
            padding: 11px 16px;
            font-size: 14px;
            text-decoration: none;
            cursor: pointer;
        }

        .button:hover {
            background: #533321;
        }

        .filter,
        .panel,
        .chart-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
        }

        h2 {
            font-size: 19px;
            margin-top: 0;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 15px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .field label {
            font-weight: bold;
            font-size: 13px;
        }

        .field input {
            padding: 11px;
            border: 1px solid #d9cec6;
            border-radius: 6px;
            font: inherit;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .stat {
            background: white;
            border-left: 4px solid #a16d45;
            padding: 21px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            min-width: 0;
        }

        .stat-label {
            color: #76665d;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 23px;
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .stat small {
            display: block;
            margin-top: 9px;
            color: #76665d;
            line-height: 1.5;
        }

        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 22px;
        }

        .chart-card {
            min-width: 0;
            margin-bottom: 0;
        }

        .chart-description {
            color: #76665d;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .chart-scroll {
            max-height: 420px;
            overflow-y: auto;
        }

        .chart-row {
            display: grid;
            grid-template-columns: 76px minmax(0, 1fr);
            gap: 10px;
            align-items: center;
            margin: 15px 0;
        }

        .chart-date {
            font-size: 12px;
            color: #634a3b;
        }

        .chart-track {
            height: 24px;
            background: #f0e8e1;
            border-radius: 5px;
            overflow: hidden;
        }

        .chart-bar {
            height: 100%;
            background: #a16d45;
            border-radius: 5px;
        }

        .orders-bar {
            background: #557d66;
        }

        .chart-value {
            font-size: 12px;
            color: #634a3b;
            margin-top: 5px;
            overflow-wrap: anywhere;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th,
        td {
            text-align: left;
            padding: 13px 12px;
            border-bottom: 1px solid #eee5df;
            white-space: nowrap;
        }

        th {
            background: #faf6f2;
            color: #634a3b;
        }

        tbody tr:hover {
            background: #fcfaf8;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #f0e8e1;
            font-size: 12px;
        }

        .empty {
            color: #76665d;
            padding: 12px 0;
            line-height: 1.5;
        }

        .error {
            background: #fff0ed;
            color: #8b271c;
            padding: 14px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .report-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .footer {
            color: #76665d;
            font-size: 12px;
            padding: 12px 0 25px;
        }

        @media (max-width: 850px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .analytics-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 550px) {
            .cards {
                grid-template-columns: 1fr;
            }

            .header {
                padding: 20px;
            }

            .container {
                padding: 0 12px;
            }

            .filter,
            .panel,
            .chart-card {
                padding: 16px;
            }

            .field,
            .field input {
                width: 100%;
            }

            .stat-value {
                font-size: 21px;
            }
        }

        @media print {
            body {
                background: white;
                color: black;
            }

            .header {
                background: white;
                color: black;
                border-bottom: 2px solid #333;
                padding: 12px 0;
            }

            .header p {
                color: #333;
            }

            .container {
                max-width: none;
                margin: 10px 0;
                padding: 0;
            }

            .no-print,
            .filter {
                display: none !important;
            }

            .cards {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .analytics-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .stat,
            .panel,
            .chart-card {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
            }

            .chart-scroll,
            .table-wrap {
                max-height: none;
                overflow: visible;
            }

            th,
            td {
                padding: 7px;
                font-size: 10px;
            }

            .footer {
                padding-bottom: 0;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <h1>Sunrise Café — Sales Analytics</h1>
    <p>Reporting period: <?= e($rangeLabel) ?></p>
</header>

<main class="container">

    <div class="top-links no-print">
        <a class="button" href="index.php">
            &larr; Admin Dashboard
        </a>

        <button
            class="button"
            type="button"
            onclick="window.print()"
        >
            Print Report
        </button>
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- DATE FILTER -->
    <section class="filter no-print">
        <h2>Filter Sales by Date</h2>

        <form method="GET" class="filter-form">
            <div class="field">
                <label for="start_date">Start Date</label>

                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?= e($startDate) ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="end_date">End Date</label>

                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?= e($endDate) ?>"
                    required
                >
            </div>

            <button class="button" type="submit">
                Generate Report
            </button>

            <a class="button" href="reports.php">
                Today
            </a>
        </form>
    </section>

    <!-- SUMMARY -->
    <div class="report-heading">
        <h2>Sales Summary</h2>
        <p><?= e($rangeLabel) ?></p>
    </div>

    <section class="cards">

        <div class="stat">
            <div class="stat-label">Total Orders</div>

            <div class="stat-value">
                <?= number_format((int)$summary['total_orders']) ?>
            </div>

            <small>Excludes cancelled orders.</small>
        </div>

        <div class="stat">
            <div class="stat-label">Completed Orders</div>

            <div class="stat-value">
                <?= number_format((int)$summary['completed_orders']) ?>
            </div>

            <small>Orders marked Completed.</small>
        </div>

        <div class="stat">
            <div class="stat-label">Pending Orders</div>

            <div class="stat-value">
                <?= number_format((int)$summary['pending_orders']) ?>
            </div>

            <small>Orders awaiting completion.</small>
        </div>

        <div class="stat">
            <div class="stat-label">Completed Order Value</div>

            <div class="stat-value">
                <?= e(money($summary['completed_order_value'])) ?>
            </div>

            <small>Value of orders marked Completed.</small>
        </div>

        <div class="stat">
            <div class="stat-label">Confirmed Paid Revenue</div>

            <div class="stat-value">
                <?= e(money($summary['paid_revenue'])) ?>
            </div>

            <small>
                Uses payment statuses Paid, Success, or Successful.
            </small>
        </div>

    </section>

    <!-- ANALYTICS CHARTS -->
    <section class="analytics-grid">

        <div class="chart-card">
            <h2>Daily Completed Sales</h2>

            <p class="chart-description">
                Completed order value for each day in the selected period.
                Longer bars represent higher sales.
            </p>

            <div class="chart-scroll">

                <?php if (!$dailySales): ?>

                    <p class="empty">
                        No completed sales were found for this period.
                    </p>

                <?php else: ?>

                    <?php foreach ($dailySales as $day): ?>

                        <?php
                        $salesValue = (float)$day['sales_value'];

                        $salesWidth = $maxDailySales > 0
                            ? ($salesValue / $maxDailySales) * 100
                            : 0;
                        ?>

                        <div class="chart-row">

                            <div class="chart-date">
                                <?= e(date(
                                    'd M',
                                    strtotime($day['sales_date'])
                                )) ?>
                            </div>

                            <div>
                                <div class="chart-track">
                                    <div
                                        class="chart-bar"
                                        style="width: <?= e(number_format(
                                            $salesWidth,
                                            2,
                                            '.',
                                            ''
                                        )) ?>%;"
                                    ></div>
                                </div>

                                <div class="chart-value">
                                    <?= e(money($salesValue)) ?>
                                </div>
                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>
        </div>

        <div class="chart-card">
            <h2>Daily Order Activity</h2>

            <p class="chart-description">
                Number of completed orders for each day in the selected period.
            </p>

            <div class="chart-scroll">

                <?php if (!$dailySales): ?>

                    <p class="empty">
                        No completed orders were found for this period.
                    </p>

                <?php else: ?>

                    <?php foreach ($dailySales as $day): ?>

                        <?php
                        $orderCount = (int)$day['order_count'];

                        $orderWidth = $maxDailyOrders > 0
                            ? ($orderCount / $maxDailyOrders) * 100
                            : 0;
                        ?>

                        <div class="chart-row">

                            <div class="chart-date">
                                <?= e(date(
                                    'd M',
                                    strtotime($day['sales_date'])
                                )) ?>
                            </div>

                            <div>
                                <div class="chart-track">
                                    <div
                                        class="chart-bar orders-bar"
                                        style="width: <?= e(number_format(
                                            $orderWidth,
                                            2,
                                            '.',
                                            ''
                                        )) ?>%;"
                                    ></div>
                                </div>

                                <div class="chart-value">
                                    <?= number_format($orderCount) ?>

                                    <?= $orderCount === 1
                                        ? 'order'
                                        : 'orders' ?>
                                </div>
                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>
        </div>

    </section>

    <!-- BEST SELLERS -->
    <section class="panel">
        <h2>Top 10 Best-Selling Menu Items</h2>

        <?php if (!$bestSellers): ?>

            <p class="empty">
                No completed product sales were found for this period.
            </p>

        <?php else: ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Product</th>
                            <th>Quantity Sold</th>
                            <th>Sales Value</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($bestSellers as $index => $item): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>

                                <td><?= e($item['product_name']) ?></td>

                                <td>
                                    <?= number_format(
                                        (int)$item['quantity_sold']
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(money($item['sales_value'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </section>

    <!-- ORDER DETAILS -->
    <section class="panel">
        <h2>Order Details</h2>

        <?php if (!$orders): ?>

            <p class="empty">
                No non-cancelled orders were found for this period.
            </p>

        <?php else: ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Order Number</th>
                            <th>Date &amp; Time</th>
                            <th>Order Status</th>
                            <th>Payment Status</th>
                            <th>Total</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?= e($order['order_number']) ?></td>

                                <td>
                                    <?= e(date(
                                        'd M Y, H:i',
                                        strtotime($order['created_at'])
                                    )) ?>
                                </td>

                                <td>
                                    <span class="status">
                                        <?= e($order['order_status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="status">
                                        <?= e(
                                            $order['payment_status']
                                            ?: 'Unspecified'
                                        ) ?>
                                    </span>
                                </td>

                                <td><?= e(money($order['total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="empty">
                Showing up to 100 orders, newest first.
            </p>

        <?php endif; ?>
    </section>

    <footer class="footer">
        Sunrise Café Ordering System |
        Report generated <?= e(date('d M Y, H:i')) ?>
    </footer>

</main>

</body>
</html>
