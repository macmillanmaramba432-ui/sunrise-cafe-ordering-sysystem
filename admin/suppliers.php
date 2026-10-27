
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

/* Add or update a supplier */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrfToken, $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        $name = trim($_POST['name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $productsSupplied = trim($_POST['products_supplied'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);

            if ($name === '') {
                $error = 'Supplier name is required.';
            } elseif (
                $email !== '' &&
                !filter_var($email, FILTER_VALIDATE_EMAIL)
            ) {
                $error = 'Please enter a valid email address.';
            } else {
                try {
                    if ($id > 0) {
                        $stmt = $pdo->prepare(
                            "UPDATE suppliers
                             SET name = ?, contact_person = ?, phone = ?,
                                 email = ?, address = ?,
                                 products_supplied = ?, notes = ?
                             WHERE id = ?"
                        );

                        $stmt->execute([
                            $name,
                            $contactPerson ?: null,
                            $phone ?: null,
                            $email ?: null,
                            $address ?: null,
                            $productsSupplied ?: null,
                            $notes ?: null,
                            $id
                        ]);

                        $message = 'Supplier updated successfully.';
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO suppliers
                             (name, contact_person, phone, email,
                              address, products_supplied, notes)
                             VALUES (?, ?, ?, ?, ?, ?, ?)"
                        );

                        $stmt->execute([
                            $name,
                            $contactPerson ?: null,
                            $phone ?: null,
                            $email ?: null,
                            $address ?: null,
                            $productsSupplied ?: null,
                            $notes ?: null
                        ]);

                        $message = 'Supplier added successfully.';
                    }
                } catch (PDOException $e) {
                    $error = 'Unable to save the supplier. Please try again.';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                $error = 'Invalid supplier selected.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        "DELETE FROM suppliers WHERE id = ?"
                    );
                    $stmt->execute([$id]);

                    $message = $stmt->rowCount() > 0
                        ? 'Supplier deleted successfully.'
                        : 'Supplier not found.';
                } catch (PDOException $e) {
                    $error = 'This supplier cannot be deleted because purchase records may be linked to them.';
                }
            }
        }
    }
}

/* Load supplier selected for editing */
$editSupplier = null;
$editId = (int)($_GET['edit'] ?? 0);

if ($editId > 0) {
    $stmt = $pdo->prepare(
        "SELECT * FROM suppliers WHERE id = ?"
    );
    $stmt->execute([$editId]);
    $editSupplier = $stmt->fetch(PDO::FETCH_ASSOC);
}

/* Load supplier list */
$stmt = $pdo->query(
    "SELECT s.*,
            (SELECT COUNT(*)
             FROM purchase_orders p
             WHERE p.supplier_id = s.id) AS purchase_count
     FROM suppliers s
     ORDER BY s.id DESC"
);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalSuppliers = count($suppliers);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Management | Sunrise Café</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f8;
            color: #25313b;
        }

        .header {
            background: #49301f;
            color: white;
            padding: 22px 30px;
        }

        .header h1 {
            margin: 0 0 7px;
        }

        .header p {
            margin: 0;
            color: #eadfd4;
        }

        .container {
            max-width: 1250px;
            margin: 25px auto;
            padding: 0 20px;
        }

        .navigation {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .navigation a {
            background: white;
            color: #49301f;
            padding: 11px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            border: 1px solid #e1e5e9;
        }

        .navigation a:hover {
            background: #f0e6dd;
        }

        .summary {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 22px;
            border: 1px solid #e1e5e9;
        }

        .summary strong {
            display: block;
            font-size: 28px;
            color: #70482d;
            margin-top: 8px;
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(280px, 350px) minmax(0, 1fr);
            gap: 22px;
            align-items: start;
        }

        .panel {
            background: white;
            border-radius: 10px;
            padding: 22px;
            border: 1px solid #e1e5e9;
            margin-bottom: 22px;
        }

        .panel h2 {
            margin-top: 0;
            font-size: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            margin: 14px 0 6px;
            font-size: 14px;
        }

        input, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccd3da;
            border-radius: 6px;
            font: inherit;
        }

        textarea {
            min-height: 75px;
            resize: vertical;
        }

        .btn {
            display: inline-block;
            padding: 10px 14px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
        }

        .btn-primary {
            background: #70482d;
            color: white;
        }

        .btn-secondary {
            background: #e7ebef;
            color: #25313b;
        }

        .btn-danger {
            background: #b42318;
            color: white;
        }

        .form-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .alert {
            padding: 13px 15px;
            margin-bottom: 18px;
            border-radius: 6px;
        }

        .success {
            background: #e3f7e9;
            color: #176534;
        }

        .failure {
            background: #fde8e7;
            color: #a11b1b;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th, td {
            text-align: left;
            padding: 13px 12px;
            border-bottom: 1px solid #e8ebee;
            vertical-align: top;
        }

        th {
            background: #f8f5f2;
            font-size: 13px;
        }

        td {
            font-size: 14px;
        }

        .actions {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }

        .inline-form {
            display: inline;
        }

        .muted {
            color: #697681;
            font-size: 13px;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #697681;
        }

        @media (max-width: 900px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {
            .header {
                padding: 20px;
            }

            .container {
                padding: 0 12px;
            }

            .panel {
                padding: 16px;
            }
        }
    </style>
</head>

<body>
<header class="header">
    <h1>Sunrise Café</h1>
    <p>Supplier Management</p>
</header>

<main class="container">

    <nav class="navigation">
        <a href="index.php">Dashboard</a>
        <a href="inventory.php">Inventory</a>
        <a href="reports.php">Reports</a>
        <a href="suppliers.php">Suppliers</a>
        <a href="logout.php">Logout</a>
    </nav>

    <?php if ($message !== ''): ?>
        <div class="alert success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert failure"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="summary">
        Total Registered Suppliers
        <strong><?= $totalSuppliers ?></strong>
    </section>

    <div class="layout">

        <section class="panel">
            <h2>
                <?= $editSupplier ? 'Edit Supplier' : 'Add New Supplier' ?>
            </h2>

            <form method="POST" action="suppliers.php">
                <input type="hidden" name="csrf_token"
                       value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id"
                       value="<?= h($editSupplier['id'] ?? 0) ?>">

                <label for="name">Supplier Name *</label>
                <input id="name" name="name" required maxlength="150"
                       value="<?= h($editSupplier['name'] ?? '') ?>"
                       placeholder="e.g. Nairobi Coffee Supplies">

                <label for="contact_person">Contact Person</label>
                <input id="contact_person" name="contact_person"
                       maxlength="150"
                       value="<?= h($editSupplier['contact_person'] ?? '') ?>"
                       placeholder="Contact person's name">

                <label for="phone">Phone Number</label>
                <input id="phone" name="phone" maxlength="30"
                       value="<?= h($editSupplier['phone'] ?? '') ?>"
                       placeholder="e.g. 07XXXXXXXX">

                <label for="email">Email Address</label>
                <input id="email" name="email" type="email" maxlength="150"
                       value="<?= h($editSupplier['email'] ?? '') ?>"
                       placeholder="supplier@example.com">

                <label for="address">Address / Location</label>
                <input id="address" name="address" maxlength="255"
                       value="<?= h($editSupplier['address'] ?? '') ?>"
                       placeholder="Supplier location">

                <label for="products_supplied">Products Supplied</label>
                <textarea id="products_supplied" name="products_supplied"
                          placeholder="Coffee beans, milk, sugar..."><?= h($editSupplier['products_supplied'] ?? '') ?></textarea>

                <label for="notes">Additional Notes</label>
                <textarea id="notes" name="notes"
                          placeholder="Any other supplier information"><?= h($editSupplier['notes'] ?? '') ?></textarea>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">
                        <?= $editSupplier ? 'Update Supplier' : 'Save Supplier' ?>
                    </button>

                    <?php if ($editSupplier): ?>
                        <a class="btn btn-secondary"
                           href="suppliers.php">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="panel">
            <h2>Registered Suppliers</h2>

            <?php if (empty($suppliers)): ?>
                <div class="empty">
                    <p>No suppliers have been registered yet.</p>
                    <p>Add your first supplier using the form.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Contact Details</th>
                            <th>Products Supplied</th>
                            <th>Purchases</th>
                            <th>Actions</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td>
                                    <strong><?= h($supplier['name']) ?></strong>
                                    <div class="muted">
                                        <?= h($supplier['address'] ?? '') ?>
                                    </div>
                                </td>

                                <td>
                                    <?= h($supplier['contact_person'] ?? '') ?>
                                    <br>
                                    <?= h($supplier['phone'] ?? '') ?>
                                    <br>
                                    <?= h($supplier['email'] ?? '') ?>
                                </td>

                                <td>
                                    <?= nl2br(h($supplier['products_supplied'] ?? '')) ?>
                                </td>

                                <td><?= (int)$supplier['purchase_count'] ?></td>

                                <td>
                                    <div class="actions">
                                        <a class="btn btn-secondary"
                                           href="suppliers.php?edit=<?= (int)$supplier['id'] ?>">
                                            Edit
                                        </a>

                                        <form class="inline-form"
                                              method="POST"
                                              action="suppliers.php"
                                              onsubmit="return confirm('Are you sure you want to delete this supplier?');">
                                            <input type="hidden"
                                                   name="csrf_token"
                                                   value="<?= h($csrfToken) ?>">
                                            <input type="hidden"
                                                   name="action"
                                                   value="delete">
                                            <input type="hidden"
                                                   name="id"
                                                   value="<?= (int)$supplier['id'] ?>">

                                            <button class="btn btn-danger"
                                                    type="submit">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

    </div>
</main>
</body>
</html>
