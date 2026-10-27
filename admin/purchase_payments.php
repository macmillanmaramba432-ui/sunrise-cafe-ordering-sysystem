
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

$message = '';
$error = '';

/* CSRF protection */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Format Kenyan shillings */
function moneyKES($amount)
{
    return 'KSh ' . number_format((float)$amount, 2);
}

/* Calculate invoice payment details */
function getPaymentSummary(PDO $pdo, int $purchaseId): array
{
    $stmt = $pdo->prepare("
        SELECT
            po.id,
            po.invoice_number,
            po.supplier_id,
            po.purchase_date,
            po.total_amount,
            po.payment_status,
            po.purchase_status,
            s.name AS supplier_name,
            COALESCE(
                (
                    SELECT SUM(pp.amount)
                    FROM purchase_payments pp
                    WHERE pp.purchase_id = po.id
                ),
                0
            ) AS paid_amount
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.id = ?
    ");

    $stmt->execute([$purchaseId]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$purchase) {
        return [];
    }

    $purchase['paid_amount'] = (float)$purchase['paid_amount'];
    $purchase['total_amount'] = (float)$purchase['total_amount'];
    $purchase['balance'] = max(
        0,
        $purchase['total_amount'] - $purchase['paid_amount']
    );

    return $purchase;
}

/* Record a supplier payment */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)) {
        $error = 'Your session token is invalid. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'record_payment') {
            $purchaseId = filter_var(
                $_POST['purchase_id'] ?? '',
                FILTER_VALIDATE_INT
            );

            $amountInput = trim($_POST['amount'] ?? '');
            $paymentDate = $_POST['payment_date'] ?? '';
            $paymentMethod = $_POST['payment_method'] ?? 'Cash';
            $referenceNumber = trim($_POST['reference_number'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $allowedMethods = [
                'Cash',
                'M-Pesa',
                'Bank Transfer',
                'Other'
            ];

            $dateObject = DateTime::createFromFormat(
                '!Y-m-d',
                $paymentDate
            );

            $validDate = $dateObject &&
                $dateObject->format('Y-m-d') === $paymentDate;

            if (!$purchaseId || $purchaseId < 1) {
                $error = 'Please select a valid purchase invoice.';
            } elseif (
                !is_numeric($amountInput) ||
                !is_finite((float)$amountInput) ||
                (float)$amountInput <= 0
            ) {
                $error = 'Enter a valid payment amount greater than zero.';
            } elseif (!$validDate) {
                $error = 'Please select a valid payment date.';
            } elseif (!in_array($paymentMethod, $allowedMethods, true)) {
                $error = 'Please select a valid payment method.';
            } elseif (
                strlen($referenceNumber) > 100 ||
                strlen($notes) > 255
            ) {
                $error = 'Reference must be at most 100 characters and notes at most 255 characters.';
            } else {
                $amount = round((float)$amountInput, 2);

                try {
                    $pdo->beginTransaction();

                    /* Lock the invoice while calculating its balance */
                    $stmt = $pdo->prepare("
                        SELECT id, total_amount, purchase_status
                        FROM purchase_orders
                        WHERE id = ?
                        FOR UPDATE
                    ");
                    $stmt->execute([$purchaseId]);
                    $lockedPurchase = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$lockedPurchase) {
                        throw new RuntimeException(
                            'The selected purchase invoice was not found.'
                        );
                    }

                    if ($lockedPurchase['purchase_status'] === 'Cancelled') {
                        throw new RuntimeException(
                            'Payments cannot be recorded against a cancelled purchase.'
                        );
                    }

                    $stmt = $pdo->prepare("
                        SELECT COALESCE(SUM(amount), 0)
                        FROM purchase_payments
                        WHERE purchase_id = ?
                    ");
                    $stmt->execute([$purchaseId]);
                    $alreadyPaid = (float)$stmt->fetchColumn();

                    $invoiceTotal = (float)$lockedPurchase['total_amount'];
                    $balance = round($invoiceTotal - $alreadyPaid, 2);

                    if ($balance <= 0) {
                        throw new RuntimeException(
                            'This invoice is already fully paid.'
                        );
                    }

                    if ($amount > $balance + 0.001) {
                        throw new RuntimeException(
                            'Payment exceeds the outstanding balance of ' .
                            moneyKES($balance) . '.'
                        );
                    }

                    $adminUsername = $_SESSION['admin_username'] ?? 'admin';

                    $stmt = $pdo->prepare("
                        INSERT INTO purchase_payments (
                            purchase_id,
                            payment_date,
                            amount,
                            payment_method,
                            reference_number,
                            notes,
                            admin_username
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $purchaseId,
                        $paymentDate,
                        $amount,
                        $paymentMethod,
                        $referenceNumber !== '' ? $referenceNumber : null,
                        $notes !== '' ? $notes : null,
                        $adminUsername
                    ]);

                    $newPaid = round($alreadyPaid + $amount, 2);

                    if ($newPaid >= $invoiceTotal - 0.001) {
                        $newStatus = 'Paid';
                    } elseif ($newPaid > 0) {
                        $newStatus = 'Partially Paid';
                    } else {
                        $newStatus = 'Unpaid';
                    }

                    $stmt = $pdo->prepare("
                        UPDATE purchase_orders
                        SET payment_status = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$newStatus, $purchaseId]);

                    $pdo->commit();

                    header(
                        'Location: purchase_payments.php?success=1'
                    );
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : 'Unable to record payment. Check your database structure and try again.';
                }
            }
        }
    }
}

if (isset($_GET['success'])) {
    $message = 'Supplier payment recorded successfully.';
}

/* Search and invoice filter */
$search = trim($_GET['search'] ?? '');
$selectedPurchaseId = filter_var(
    $_GET['purchase_id'] ?? '',
    FILTER_VALIDATE_INT
);

try {
    $sql = "
        SELECT
            po.id,
            po.invoice_number,
            po.purchase_date,
            po.total_amount,
            po.payment_status,
            po.purchase_status,
            s.name AS supplier_name,
            COALESCE(SUM(pp.amount), 0) AS paid_amount
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        LEFT JOIN purchase_payments pp ON pp.purchase_id = po.id
        WHERE po.purchase_status <> 'Cancelled'
    ";

    $params = [];

    if ($search !== '') {
        $sql .= "
            AND (
                po.invoice_number LIKE ?
                OR s.name LIKE ?
            )
        ";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    if ($selectedPurchaseId && $selectedPurchaseId > 0) {
        $sql .= " AND po.id = ?";
        $params[] = $selectedPurchaseId;
    }

    $sql .= "
        GROUP BY
            po.id,
            po.invoice_number,
            po.purchase_date,
            po.total_amount,
            po.payment_status,
            po.purchase_status,
            s.name
        ORDER BY po.purchase_date DESC, po.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $paymentStmt = $pdo->query("
        SELECT
            pp.id,
            pp.purchase_id,
            pp.payment_date,
            pp.amount,
            pp.payment_method,
            pp.reference_number,
            pp.notes,
            pp.admin_username,
            po.invoice_number,
            s.name AS supplier_name
        FROM purchase_payments pp
        INNER JOIN purchase_orders po ON po.id = pp.purchase_id
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        ORDER BY pp.payment_date DESC, pp.id DESC
        LIMIT 200
    ");

    $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

    $summaryStmt = $pdo->query("
        SELECT
            COUNT(*) AS invoice_count,
            COALESCE(SUM(po.total_amount), 0) AS total_invoices,
            COALESCE(SUM(COALESCE(p.paid_amount, 0)), 0) AS total_paid
        FROM purchase_orders po
        LEFT JOIN (
            SELECT purchase_id, SUM(amount) AS paid_amount
            FROM purchase_payments
            GROUP BY purchase_id
        ) p ON p.purchase_id = po.id
        WHERE po.purchase_status <> 'Cancelled'
    ");

    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    $totalInvoices = (float)$summary['total_invoices'];
    $totalPaid = (float)$summary['total_paid'];
    $totalOutstanding = max(0, $totalInvoices - $totalPaid);

} catch (PDOException $e) {
    $purchases = [];
    $payments = [];
    $totalInvoices = 0;
    $totalPaid = 0;
    $totalOutstanding = 0;

    $error = 'Unable to load payment records. Confirm that the purchase_payments table exists and that its columns match the setup instructions.';
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Payments | Sunrise Café</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #263238;
        }

        .topbar {
            background: #24352c;
            color: white;
            padding: 18px 24px;
        }

        .topbar h1 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        .topbar p {
            margin: 0;
            color: #d9e5dc;
        }

        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 12px 24px;
            background: #fff;
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
            max-width: 1400px;
            margin: 25px auto;
            padding: 0 20px 35px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .card, .panel {
            background: white;
            border-radius: 9px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        .card span {
            display: block;
            color: #68756d;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .card strong {
            font-size: 23px;
            overflow-wrap: anywhere;
        }

        .panel {
            margin-bottom: 24px;
        }

        .panel h2 {
            margin: 0 0 18px;
            font-size: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 14px;
            font-weight: bold;
        }

        input, select, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd2ce;
            border-radius: 5px;
            font: inherit;
            background: #fff;
        }

        textarea {
            min-height: 75px;
            resize: vertical;
        }

        button, .button {
            display: inline-block;
            border: 0;
            border-radius: 5px;
            padding: 11px 16px;
            cursor: pointer;
            background: #286444;
            color: white;
            font-size: 14px;
            text-decoration: none;
        }

        button:hover, .button:hover {
            background: #1d4c32;
        }

        .message, .error {
            padding: 13px 15px;
            margin-bottom: 18px;
            border-radius: 5px;
        }

        .message {
            background: #e2f5e8;
            color: #175d31;
        }

        .error {
            background: #fde8e7;
            color: #8b2020;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid #e8ece9;
            font-size: 14px;
            vertical-align: top;
        }

        th {
            background: #f3f6f4;
            color: #34473b;
        }

        .status {
            display: inline-block;
            border-radius: 20px;
            padding: 5px 9px;
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

        .muted {
            color: #69766e;
        }

        .filter {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
        }

        .filter input {
            max-width: 400px;
        }

        .filter button {
            flex-shrink: 0;
        }

        .small {
            font-size: 12px;
            color: #69766e;
        }

        @media (max-width: 800px) {
            .cards {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .field.full {
                grid-column: auto;
            }

            .container {
                padding: 0 12px 25px;
            }

            .topbar {
                padding: 17px 15px;
            }

            .nav {
                padding: 10px;
            }
        }

        @media print {
            .nav, .payment-form, .filter, .no-print {
                display: none !important;
            }

            body {
                background: white;
            }

            .container {
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .panel, .card {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
<header class="topbar">
    <h1>Supplier Payments</h1>
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

    <?php if ($message !== ''): ?>
        <div class="message">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="cards">
        <div class="card">
            <span>Total Purchase Invoices</span>
            <strong><?= moneyKES($totalInvoices) ?></strong>
        </div>

        <div class="card">
            <span>Total Payments Recorded</span>
            <strong><?= moneyKES($totalPaid) ?></strong>
        </div>

        <div class="card">
            <span>Outstanding Balance</span>
            <strong><?= moneyKES($totalOutstanding) ?></strong>
        </div>
    </section>

    <section class="panel payment-form">
        <h2>Record Supplier Payment</h2>

        <form method="POST" action="purchase_payments.php">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="record_payment">

            <div class="form-grid">
                <div class="field full">
                    <label for="purchase_id">Purchase Invoice *</label>
                    <select name="purchase_id" id="purchase_id" required>
                        <option value="">Select an invoice</option>

                        <?php foreach ($purchases as $purchase): ?>
                            <?php
                            $paid = (float)$purchase['paid_amount'];
                            $total = (float)$purchase['total_amount'];
                            $balance = max(0, $total - $paid);
                            ?>
                            <?php if ($balance > 0.001): ?>
                                <option
                                    value="<?= (int)$purchase['id'] ?>"
                                    <?= $selectedPurchaseId == $purchase['id'] ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars(
                                        $purchase['invoice_number'] ?: 'Purchase #' . $purchase['id']
                                    ) ?>
                                    — <?= htmlspecialchars($purchase['supplier_name'] ?? 'Unknown supplier') ?>
                                    — Balance: <?= moneyKES($balance) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>

                    <span class="small">
                        Only invoices with an outstanding balance are listed.
                    </span>
                </div>

                <div class="field">
                    <label for="amount">Payment Amount (KSh) *</label>
                    <input
                        type="number"
                        id="amount"
                        name="amount"
                        min="0.01"
                        step="0.01"
                        placeholder="e.g. 2500.00"
                        required
                    >
                </div>

                <div class="field">
                    <label for="payment_date">Payment Date *</label>
                    <input
                        type="date"
                        id="payment_date"
                        name="payment_date"
                        value="<?= htmlspecialchars($today) ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="payment_method">Payment Method *</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value="Cash">Cash</option>
                        <option value="M-Pesa">M-Pesa</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="field">
                    <label for="reference_number">Transaction Reference</label>
                    <input
                        type="text"
                        id="reference_number"
                        name="reference_number"
                        maxlength="100"
                        placeholder="M-Pesa code or bank reference"
                    >
                </div>

                <div class="field full">
                    <label for="notes">Notes</label>
                    <textarea
                        id="notes"
                        name="notes"
                        maxlength="255"
                        placeholder="Optional payment details"
                    ></textarea>
                </div>

                <div class="field full">
                    <button type="submit">Save Supplier Payment</button>
                </div>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Purchase Invoice Balances</h2>

        <form method="GET" class="filter">
            <input
                type="text"
                name="search"
                value="<?= htmlspecialchars($search) ?>"
                placeholder="Search invoice number or supplier"
            >
            <button type="submit">Search</button>
            <a class="button" href="purchase_payments.php">Reset</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Supplier</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th class="no-print">Action</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($purchases)): ?>
                    <tr>
                        <td colspan="8">No purchase invoices found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($purchases as $purchase): ?>
                        <?php
                        $total = (float)$purchase['total_amount'];
                        $paid = (float)$purchase['paid_amount'];
                        $balance = max(0, $total - $paid);

                        if ($balance <= 0.001) {
                            $displayStatus = 'Paid';
                            $statusClass = 'paid';
                        } elseif ($paid > 0) {
                            $displayStatus = 'Partially Paid';
                            $statusClass = 'partial';
                        } else {
                            $displayStatus = 'Unpaid';
                            $statusClass = 'unpaid';
                        }
                        ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars(
                                    $purchase['invoice_number'] ?: 'Purchase #' . $purchase['id']
                                ) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($purchase['supplier_name'] ?? 'Unknown supplier') ?>
                            </td>
                            <td><?= htmlspecialchars($purchase['purchase_date']) ?></td>
                            <td><?= moneyKES($total) ?></td>
                            <td><?= moneyKES($paid) ?></td>
                            <td><strong><?= moneyKES($balance) ?></strong></td>
                            <td>
                                <span class="status <?= $statusClass ?>">
                                    <?= htmlspecialchars($displayStatus) ?>
                                </span>
                            </td>
                            <td class="no-print">
                                <?php if ($balance > 0.001): ?>
                                    <a
                                        class="button"
                                        href="purchase_payments.php?purchase_id=<?= (int)$purchase['id'] ?>"
                                    >Pay</a>
                                <?php else: ?>
                                    <span class="muted">Settled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <h2>Recent Supplier Payments</h2>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Date</th>
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
                        <td colspan="7">No supplier payments have been recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?= htmlspecialchars($payment['payment_date']) ?></td>
                            <td>
                                <?= htmlspecialchars(
                                    $payment['invoice_number'] ?: 'Purchase #' . $payment['purchase_id']
                                ) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($payment['supplier_name'] ?? 'Unknown supplier') ?>
                            </td>
                            <td><strong><?= moneyKES($payment['amount']) ?></strong></td>
                            <td><?= htmlspecialchars($payment['payment_method']) ?></td>
                            <td>
                                <?= htmlspecialchars($payment['reference_number'] ?? '—') ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($payment['admin_username'] ?? '—') ?>
                            </td>
                        </tr>
                        <?php if (!empty($payment['notes'])): ?>
                            <tr>
                                <td colspan="7" class="muted">
                                    Note: <?= htmlspecialchars($payment['notes']) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="no-print">
            <button type="button" onclick="window.print()">Print Payment Records</button>
        </p>
    </section>

</main>
</body>
</html>
