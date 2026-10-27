
<?php
session_start();

if (
    !isset($_SESSION['admin_logged_in']) ||
    $_SESSION['admin_logged_in'] !== true
) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function kes($amount): string
{
    return 'KSh ' . number_format((float)$amount, 2);
}

function validDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

/* Report filters */
$today = date('Y-m-d');
$defaultStart = date('Y-m-01');

$startDate = $_GET['start_date'] ?? $defaultStart;
$endDate = $_GET['end_date'] ?? $today;
$supplierFilter = $_GET['supplier_id'] ?? '';

$error = '';

if (!validDate($startDate) || !validDate($endDate)) {
    $startDate = $defaultStart;
    $endDate = $today;
    $error = 'Invalid dates supplied. The current month has been selected.';
} elseif ($startDate > $endDate) {
    $error = 'The start date cannot be later than the end date.';
}

if (
    $supplierFilter !== '' &&
    (!ctype_digit((string)$supplierFilter) || (int)$supplierFilter < 1)
) {
    $supplierFilter = '';
}

$supplierFilter = $supplierFilter === ''
    ? ''
    : (string)(int)$supplierFilter;

try {
    /* Load suppliers for the filter */
    $supplierStmt = $pdo->query("
        SELECT id, name
        FROM suppliers
        ORDER BY name ASC
    ");
    $suppliers = $supplierStmt->fetchAll(PDO::FETCH_ASSOC);

    $hasValidRange = $error === '';

    /*
     * Purchase invoices are filtered by purchase date.
     * Paid amount means payments recorded against each invoice
     * across its entire payment history.
     */
    $purchaseParams = [$startDate, $endDate];

    $purchaseSql = "
        SELECT
            po.id,
            po.invoice_number,
            po.purchase_date,
            po.total_amount,
            po.purchase_status,
            s.name AS supplier_name,
            COALESCE(pay.paid_amount, 0) AS paid_amount
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        LEFT JOIN (
            SELECT purchase_id, SUM(amount) AS paid_amount
            FROM purchase_payments
            GROUP BY purchase_id
        ) pay ON pay.purchase_id = po.id
        WHERE po.purchase_date BETWEEN ? AND ?
          AND po.purchase_status <> 'Cancelled'
    ";

    if ($supplierFilter !== '') {
        $purchaseSql .= " AND po.supplier_id = ?";
        $purchaseParams[] = (int)$supplierFilter;
    }

    $purchaseSql .= "
        ORDER BY po.purchase_date DESC, po.id DESC
    ";

    $purchaseStmt = $pdo->prepare($purchaseSql);
    $purchaseStmt->execute($purchaseParams);
    $purchases = $purchaseStmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Payment records are filtered by the actual payment date.
     * This is a separate date range from the invoice purchase date.
     */
    $paymentParams = [$startDate, $endDate];

    $paymentSql = "
        SELECT
            pp.id,
            pp.payment_date,
            pp.amount,
            pp.payment_method,
            pp.reference_number,
            pp.notes,
            pp.admin_username,
            po.id AS purchase_id,
            po.invoice_number,
            s.name AS supplier_name
        FROM purchase_payments pp
        INNER JOIN purchase_orders po ON po.id = pp.purchase_id
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        WHERE pp.payment_date BETWEEN ? AND ?
          AND po.purchase_status <> 'Cancelled'
    ";

    if ($supplierFilter !== '') {
        $paymentSql .= " AND po.supplier_id = ?";
        $paymentParams[] = (int)$supplierFilter;
    }

    $paymentSql .= "
        ORDER BY pp.payment_date DESC, pp.id DESC
    ";

    $paymentStmt = $pdo->prepare($paymentSql);
    $paymentStmt->execute($paymentParams);
    $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

    /* Calculate invoice totals */
    $totalPurchases = 0;
    $totalPaidOnInvoices = 0;
    $totalOutstanding = 0;

    foreach ($purchases as $purchase) {
        $invoiceTotal = (float)$purchase['total_amount'];
        $paidAmount = (float)$purchase['paid_amount'];
        $balance = max(0, $invoiceTotal - $paidAmount);

        $totalPurchases += $invoiceTotal;
        $totalPaidOnInvoices += min($invoiceTotal, $paidAmount);
        $totalOutstanding += $balance;
    }

    /* Calculate payments actually made during the selected dates */
    $paymentsInPeriod = 0;

    foreach ($payments as $payment) {
        $paymentsInPeriod += (float)$payment['amount'];
    }

    /*
     * Supplier summary for the selected purchase-date range.
     */
    $supplierSummaryParams = [$startDate, $endDate];

    $supplierSummarySql = "
        SELECT
            s.id,
            s.name,
            COUNT(po.id) AS invoice_count,
            COALESCE(SUM(po.total_amount), 0) AS purchase_total,
            COALESCE(SUM(pay.paid_amount), 0) AS paid_total
        FROM suppliers s
        INNER JOIN purchase_orders po ON po.supplier_id = s.id
        LEFT JOIN (
            SELECT purchase_id, SUM(amount) AS paid_amount
            FROM purchase_payments
            GROUP BY purchase_id
        ) pay ON pay.purchase_id = po.id
        WHERE po.purchase_date BETWEEN ? AND ?
          AND po.purchase_status <> 'Cancelled'
    ";

    if ($supplierFilter !== '') {
        $supplierSummarySql .= " AND s.id = ?";
        $supplierSummaryParams[] = (int)$supplierFilter;
    }

    $supplierSummarySql .= "
        GROUP BY s.id, s.name
        ORDER BY purchase_total DESC, s.name ASC
    ";

    $supplierSummaryStmt = $pdo->prepare($supplierSummarySql);
    $supplierSummaryStmt->execute($supplierSummaryParams);
    $supplierSummaries = $supplierSummaryStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = 'Unable to load purchase reports. Check that your purchase, supplier, and payment tables exist.';
    $suppliers = $suppliers ?? [];
    $purchases = [];
    $payments = [];
    $supplierSummaries = [];
    $totalPurchases = 0;
    $totalPaidOnInvoices = 0;
    $totalOutstanding = 0;
    $paymentsInPeriod = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Purchase Reports | Sunrise Café</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6f8;
            color: #26352b;
            font-family: Arial, sans-serif;
        }

        .topbar {
            padding: 20px 25px;
            background: #24352c;
            color: white;
        }

        .topbar h1 {
            margin: 0 0 7px;
            font-size: 25px;
        }

        .topbar p {
            margin: 0;
            color: #dbe5de;
        }

        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 12px 22px;
            background: white;
            border-bottom: 1px solid #ddd;
        }

        .nav a {
            padding: 9px 12px;
            color: #24352c;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }

        .nav a:hover {
            background: #e7eee9;
        }

        .container {
            max-width: 1450px;
            margin: 25px auto;
            padding: 0 20px 40px;
        }

        .panel, .summary-card {
            background: white;
            border-radius: 9px;
            padding: 20px;
            margin-bottom: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        .panel h2 {
            margin: 0 0 18px;
            font-size: 20px;
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            align-items: end;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        label {
            font-size: 14px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd3cd;
            border-radius: 5px;
            background: white;
            font: inherit;
        }

        button, .button {
            display: inline-block;
            padding: 11px 15px;
            border: none;
            border-radius: 5px;
            background: #286444;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        button:hover, .button:hover {
            background: #1c4d32;
        }

        .secondary {
            background: #5d6c62;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .summary-card {
            margin: 0;
            border-top: 4px solid #286444;
        }

        .summary-card span {
            display: block;
            margin-bottom: 10px;
            color: #647168;
            font-size: 14px;
        }

        .summary-card strong {
            font-size: 22px;
            overflow-wrap: anywhere;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 780px;
        }

        th, td {
            padding: 12px 10px;
            border-bottom: 1px solid #e6ebe7;
            text-align: left;
            font-size: 14px;
            vertical-align: top;
        }

        th {
            background: #f0f4f1;
            color: #34473b;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            white-space: nowrap;
        }

        .paid {
            background: #dff5e5;
            color: #176233;
        }

        .partial {
            background: #fff0cb;
            color: #795500;
        }

        .unpaid {
            background: #fde4e4;
            color: #8b2525;
        }

        .notice {
            padding: 13px 15px;
            margin-bottom: 18px;
            border-radius: 5px;
            background: #fff0cb;
            color: #795500;
        }

        .muted {
            color: #68756c;
        }

        .section-note {
            margin: -8px 0 16px;
            color: #68756c;
            font-size: 13px;
        }

        .report-footer {
            margin-top: 15px;
            color: #69766e;
            font-size: 12px;
        }

        @media (max-width: 950px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filters {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .summary-grid, .filters {
                grid-template-columns: 1fr;
            }

            .container {
                padding: 0 12px 25px;
            }

            .topbar {
                padding: 17px 14px;
            }

            .nav {
                padding: 10px;
            }
        }

        @media print {
            body {
                background: white;
                color: black;
            }

            .nav, .filters-panel, .no-print {
                display: none !important;
            }

            .container {
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .panel, .summary-card {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
            }

            table {
                min-width: 0;
            }

            th, td {
                font-size: 10px;
                padding: 6px;
            }

            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <h1>Purchase Reports</h1>
    <p>Sunrise Café Management System</p>
</header>

<nav class="nav">
    <a href="index.php">Dashboard</a>
    <a href="inventory.php">Inventory</a>
    <a href="suppliers.php">Suppliers</a>
    <a href="purchases.php">Purchases</a>
    <a href="purchase_payments.php">Supplier Payments</a>
    <a href="purchase_reports.php">Purchase Reports</a>
    <a href="logout.php">Logout</a>
</nav>

<main class="container">

    <?php if ($error !== ''): ?>
        <div class="notice"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="panel filters-panel">
        <h2>Filter Reports</h2>

        <form method="GET" action="purchase_reports.php" class="filters">
            <div class="field">
                <label for="start_date">Start Date</label>
                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?= h($startDate) ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="end_date">End Date</label>
                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?= h($endDate) ?>"
                    required
                >
            </div>

            <div class="field">
                <label for="supplier_id">Supplier</label>
                <select id="supplier_id" name="supplier_id">
                    <option value="">All suppliers</option>

                    <?php foreach ($suppliers as $supplier): ?>
                        <option
                            value="<?= (int)$supplier['id'] ?>"
                            <?= $supplierFilter == $supplier['id'] ? 'selected' : '' ?>
                        >
                            <?= h($supplier['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <button type="submit">Generate Report</button>
                <a
                    class="button secondary"
                    href="purchase_reports.php"
                    style="text-align:center; margin-top:8px;"
                >Reset Filters</a>
            </div>
        </form>
    </section>

    <section class="summary-grid">
        <div class="summary-card">
            <span>Purchase Invoice Total</span>
            <strong><?= kes($totalPurchases) ?></strong>
        </div>

        <div class="summary-card">
            <span>Paid Against These Invoices</span>
            <strong><?= kes($totalPaidOnInvoices) ?></strong>
        </div>

        <div class="summary-card">
            <span>Outstanding Invoice Balance</span>
            <strong><?= kes($totalOutstanding) ?></strong>
        </div>

        <div class="summary-card">
            <span>Payments Made During Period</span>
            <strong><?= kes($paymentsInPeriod) ?></strong>
        </div>
    </section>

    <section class="panel">
        <h2>Supplier Purchase Summary</h2>

        <p class="section-note">
            Invoices are grouped by supplier using their purchase dates.
            Paid amounts include all payments recorded against those invoices,
            including payments made outside the selected date range.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Number of Invoices</th>
                    <th>Purchase Total</th>
                    <th>Paid</th>
                    <th>Outstanding</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($supplierSummaries)): ?>
                    <tr>
                        <td colspan="5">No supplier purchases found for this period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($supplierSummaries as $row): ?>
                        <?php
                        $supplierTotal = (float)$row['purchase_total'];
                        $supplierPaid = (float)$row['paid_total'];
                        $supplierBalance = max(0, $supplierTotal - $supplierPaid);
                        ?>
                        <tr>
                            <td><?= h($row['name']) ?></td>
                            <td><?= (int)$row['invoice_count'] ?></td>
                            <td><?= kes($supplierTotal) ?></td>
                            <td><?= kes(min($supplierTotal, $supplierPaid)) ?></td>
                            <td><strong><?= kes($supplierBalance) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Purchase Invoice Details</h2>

        <p class="section-note">
            These invoices fall within the selected purchase-date range.
            Cancelled invoices are excluded.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Invoice Number</th>
                    <th>Purchase Date</th>
                    <th>Supplier</th>
                    <th>Invoice Total</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Purchase Status</th>
                    <th>Payment Status</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($purchases)): ?>
                    <tr>
                        <td colspan="8">No purchase invoices found for the selected filters.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($purchases as $purchase): ?>
                        <?php
                        $invoiceTotal = (float)$purchase['total_amount'];
                        $paidAmount = (float)$purchase['paid_amount'];
                        $balance = max(0, $invoiceTotal - $paidAmount);

                        if ($balance <= 0.001) {
                            $statusText = 'Paid';
                            $statusClass = 'paid';
                        } elseif ($paidAmount > 0) {
                            $statusText = 'Partially Paid';
                            $statusClass = 'partial';
                        } else {
                            $statusText = 'Unpaid';
                            $statusClass = 'unpaid';
                        }
                        ?>
                        <tr>
                            <td>
                                <?= h($purchase['invoice_number'] ?: 'Purchase #' . $purchase['id']) ?>
                            </td>
                            <td><?= h($purchase['purchase_date']) ?></td>
                            <td><?= h($purchase['supplier_name'] ?? 'Unknown supplier') ?></td>
                            <td><?= kes($invoiceTotal) ?></td>
                            <td><?= kes(min($invoiceTotal, $paidAmount)) ?></td>
                            <td><?= kes($balance) ?></td>
                            <td><?= h($purchase['purchase_status']) ?></td>
                            <td>
                                <span class="status <?= $statusClass ?>">
                                    <?= h($statusText) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Supplier Payments During Selected Period</h2>

        <p class="section-note">
            Payments below are filtered by the date each payment was recorded.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Payment Date</th>
                    <th>Invoice</th>
                    <th>Supplier</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Recorded By</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="7">No payments found for the selected period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?= h($payment['payment_date']) ?></td>
                            <td>
                                <?= h($payment['invoice_number'] ?: 'Purchase #' . $payment['purchase_id']) ?>
                            </td>
                            <td><?= h($payment['supplier_name'] ?? 'Unknown supplier') ?></td>
                            <td><strong><?= kes($payment['amount']) ?></strong></td>
                            <td><?= h($payment['payment_method']) ?></td>
                            <td><?= h($payment['reference_number'] ?? '—') ?></td>
                            <td><?= h($payment['admin_username'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>

                <?php if (!empty($payments)): ?>
                    <tfoot>
                    <tr>
                        <th colspan="3">Total Payments in Period</th>
                        <th><?= kes($paymentsInPeriod) ?></th>
                        <th colspan="3"></th>
                    </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </section>

    <div class="no-print" style="margin-bottom:25px;">
        <button type="button" onclick="window.print()">Print Purchase Reports</button>
    </div>

    <p class="report-footer">
        Report period: <?= h($startDate) ?> to <?= h($endDate) ?>.
        Generated on <?= h(date('Y-m-d H:i:s')) ?>.
    </p>

</main>
</body>
</html>
