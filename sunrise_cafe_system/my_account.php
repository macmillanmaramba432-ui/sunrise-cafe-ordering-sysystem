
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

$customer = null;
$statistics = ['total_orders' => 0, 'total_spent' => 0];
$activeOrders = 0;
$recentOrders = [];
$errors = [];
$successMessage = '';
$pageError = '';

/*
|--------------------------------------------------------------------------
| Generate CSRF token
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['account_csrf_token'])) {
    $_SESSION['account_csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function accountEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function accountStatusClass($status): string
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
| Load current customer
|--------------------------------------------------------------------------
*/

try {
    $stmt = $pdo->prepare(
        'SELECT id, name, email, phone, created_at
         FROM customers
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        unset(
            $_SESSION['customer_id'],
            $_SESSION['customer_name'],
            $_SESSION['customer_email']
        );

        header('Location: login.php');
        exit;
    }

} catch (PDOException $e) {
    error_log('Account profile lookup failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load your account. Please try again later.');
}

/*
|--------------------------------------------------------------------------
| Handle profile update
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['account_csrf_token'] ?? '';

    if (
        !is_string($submittedToken)
        || !hash_equals($_SESSION['account_csrf_token'], $submittedToken)
    ) {
        $errors[] = 'Your session has expired. Refresh the page and try again.';
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($name === '') {
        $errors[] = 'Full name is required.';
    } elseif (strlen($name) > 150) {
        $errors[] = 'Full name must not exceed 150 characters.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (strlen($email) > 190) {
        $errors[] = 'Email address must not exceed 190 characters.';
    }

    if ($phone === '') {
        $errors[] = 'Phone number is required.';
    } elseif (!preg_match('/^[0-9+\s()-]{7,20}$/', $phone)) {
        $errors[] = 'Please enter a valid phone number.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                'UPDATE customers
                 SET name = ?, email = ?, phone = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $name,
                $email !== '' ? $email : null,
                $phone,
                $customerId
            ]);

            $_SESSION['customer_name'] = $name;
            $_SESSION['customer_email'] = $email;

            $_SESSION['account_success'] =
                'Your profile has been updated successfully.';

            $_SESSION['account_csrf_token'] = bin2hex(random_bytes(32));

            header('Location: my_account.php');
            exit;

        } catch (PDOException $e) {
            error_log('Account profile update failed: ' . $e->getMessage());
            $errors[] = 'Your profile could not be updated. Please try again.';
        }
    }

    // Keep submitted values in the form if validation fails.
    $customer['name'] = $name;
    $customer['email'] = $email;
    $customer['phone'] = $phone;
}

/*
|--------------------------------------------------------------------------
| Show one-time success message
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['account_success'])) {
    $successMessage = $_SESSION['account_success'];
    unset($_SESSION['account_success']);
}

/*
|--------------------------------------------------------------------------
| Load order statistics and recent orders
|--------------------------------------------------------------------------
*/

try {
    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(total), 0) AS total_spent
         FROM orders
         WHERE customer_id = ?'
    );

    $stmt->execute([$customerId]);
    $statistics = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM orders
         WHERE customer_id = ?
           AND order_status NOT IN ('Completed', 'Cancelled')"
    );

    $stmt->execute([$customerId]);
    $activeOrders = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT
            id,
            order_number,
            order_status,
            total,
            created_at
         FROM orders
         WHERE customer_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 5'
    );

    $stmt->execute([$customerId]);
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Account order statistics failed: ' . $e->getMessage());
    $pageError = 'Some order information could not be loaded.';
}

$customerName = $customer['name'] ?? 'Customer';
$nameParts = preg_split('/\s+/', trim($customerName));
$firstName = $nameParts[0] ?? 'Customer';
$initial = strtoupper(substr($firstName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Account | Sunrise Café</title>

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
            font-size: 24px;
            font-weight: bold;
            color: white;
        }

        .navigation {
            display: flex;
            align-items: center;
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
            max-width: 1200px;
            margin: 35px auto;
        }

        .welcome {
            background: linear-gradient(135deg, #4b2e1e, #875333);
            color: white;
            padding: 28px;
            border-radius: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .welcome-left {
            display: flex;
            align-items: center;
            gap: 17px;
        }

        .welcome h1 {
            margin: 0 0 5px;
            font-size: 28px;
        }

        .welcome p {
            margin: 0;
            color: #f5e7d9;
        }

        .avatar {
            width: 62px;
            height: 62px;
            border-radius: 50%;
            background: #f2c783;
            color: #4b2e1e;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 27px;
            font-weight: bold;
            flex-shrink: 0;
        }

        .button {
            display: inline-block;
            border: none;
            border-radius: 7px;
            padding: 11px 16px;
            background: #8a4b2a;
            color: white;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
            text-align: center;
        }

        .button:hover {
            background: #65351e;
        }

        .button-light {
            background: white;
            color: #4b2e1e;
        }

        .button-light:hover {
            background: #f3e4d4;
        }

        .button-outline {
            background: transparent;
            border: 1px solid #8a4b2a;
            color: #754126;
        }

        .button-outline:hover {
            background: #f7eee7;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 17px;
            margin-bottom: 25px;
        }

        .stat-card,
        .card {
            background: white;
            padding: 23px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .stat-label {
            color: #786b60;
            font-size: 14px;
        }

        .stat-value {
            display: block;
            margin-top: 5px;
            font-size: 25px;
            font-weight: bold;
            color: #4b2e1e;
            overflow-wrap: anywhere;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 22px;
            align-items: start;
        }

        .card {
            margin-bottom: 22px;
        }

        .card h2 {
            color: #4b2e1e;
            margin: 0 0 18px;
            font-size: 21px;
        }

        .profile-row {
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eee6df;
        }

        .profile-label {
            display: block;
            color: #85786d;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-value {
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .field {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d5cec5;
            border-radius: 7px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #c79b70;
            border-color: #8a4b2a;
        }

        .quick-links {
            display: grid;
            gap: 10px;
        }

        .quick-link {
            display: flex;
            justify-content: space-between;
            padding: 12px;
            border: 1px solid #eee2d7;
            border-radius: 8px;
            color: #4b2e1e;
            font-weight: bold;
        }

        .quick-link:hover {
            background: #faf4ed;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 480px;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #eee6df;
            font-size: 13px;
        }

        th {
            background: #faf5ef;
            color: #665344;
        }

        .status {
            display: inline-block;
            padding: 4px 9px;
            border-radius: 20px;
            font-size: 11px;
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

        .message {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .message-success {
            background: #e5f5e9;
            color: #205c30;
            border: 1px solid #a6d8b1;
        }

        .message-error {
            background: #fde8e7;
            color: #8b1e18;
            border: 1px solid #efb5b1;
        }

        .muted {
            color: #786b60;
            font-size: 13px;
        }

        .footer {
            text-align: center;
            padding: 25px;
            color: #7d7167;
            font-size: 13px;
        }

        @media (max-width: 850px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
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

            .welcome h1 {
                font-size: 23px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .card,
            .stat-card {
                padding: 18px;
            }
        }
    </style>
</head>
<body>

<header class="topbar">
    <a class="brand" href="index.php">Sunrise Café</a>

    <nav class="navigation">
        <a href="index.php">Home</a>
        <a href="menu.php">Menu</a>
        <a href="cart.php">Cart</a>
        <a href="my_orders.php">My Orders</a>
        <a href="change_password.php">Change Password</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main class="container">

    <section class="welcome">
        <div class="welcome-left">
            <div class="avatar"><?= accountEscape($initial) ?></div>

            <div>
                <h1>Hello, <?= accountEscape($firstName) ?>!</h1>
                <p>Manage your Sunrise Café account.</p>
            </div>
        </div>

        <a class="button button-light" href="menu.php">
            Browse Our Menu
        </a>
    </section>

    <?php if ($successMessage !== ''): ?>
        <div class="message message-success">
            <?= accountEscape($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="message message-error">
            <strong>Please correct the following:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= accountEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($pageError !== ''): ?>
        <div class="message message-error">
            <?= accountEscape($pageError) ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid">
        <div class="stat-card">
            <span class="stat-label">Total Orders</span>
            <span class="stat-value">
                <?= (int) ($statistics['total_orders'] ?? 0) ?>
            </span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Active Orders</span>
            <span class="stat-value"><?= $activeOrders ?></span>
        </div>

        <div class="stat-card">
            <span class="stat-label">Total Order Value</span>
            <span class="stat-value">
                KSh <?= number_format((float) ($statistics['total_spent'] ?? 0), 2) ?>
            </span>
        </div>
    </section>

    <div class="content-grid">

        <div>
            <section class="card">
                <h2>Edit My Profile</h2>

                <p class="muted">
                    Update your contact information below. Your existing
                    orders will remain linked to your account.
                </p>

                <form method="POST" action="my_account.php">
                    <input
                        type="hidden"
                        name="account_csrf_token"
                        value="<?= accountEscape($_SESSION['account_csrf_token']) ?>"
                    >

                    <div class="field">
                        <label for="name">Full Name *</label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            maxlength="150"
                            required
                            value="<?= accountEscape($customer['name']) ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="email">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            maxlength="190"
                            value="<?= accountEscape($customer['email'] ?? '') ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="phone">Phone Number *</label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            maxlength="20"
                            required
                            value="<?= accountEscape($customer['phone'] ?? '') ?>"
                        >
                    </div>

                    <button type="submit" class="button">
                        Save Changes
                    </button>
                </form>
            </section>

            <section class="card">
                <h2>Quick Links</h2>

                <div class="quick-links">
                    <a class="quick-link" href="my_orders.php">
                        <span>My Order History</span>
                        <span>&rarr;</span>
                    </a>

                    <a class="quick-link" href="menu.php">
                        <span>Browse Menu</span>
                        <span>&rarr;</span>
                    </a>

                    <a class="quick-link" href="cart.php">
                        <span>Shopping Cart</span>
                        <span>&rarr;</span>
                    </a>

                    <a class="quick-link" href="reviews.php">
                        <span>Customer Reviews</span>
                        <span>&rarr;</span>
                    </a>

                    <a class="quick-link" href="logout.php">
                        <span>Log Out</span>
                        <span>&rarr;</span>
                    </a>
                </div>
            </section>
        </div>

        <section class="card">
            <h2>Recent Orders</h2>

            <?php if (!empty($recentOrders)): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Order Number</th>
                                <th>Date</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td>
                                        <?= accountEscape($order['order_number']) ?>
                                    </td>

                                    <td>
                                        <?= accountEscape(date(
                                            'd M Y',
                                            strtotime($order['created_at'])
                                        )) ?>
                                    </td>

                                    <td>
                                        KSh <?= number_format(
                                            (float) $order['total'],
                                            2
                                        ) ?>
                                    </td>

                                    <td>
                                        <span class="status <?= accountEscape(
                                            accountStatusClass($order['order_status'])
                                        ) ?>">
                                            <?= accountEscape($order['order_status']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <a
                                            class="button button-outline"
                                            href="track_order.php?order_number=<?= urlencode($order['order_number']) ?>"
                                        >
                                            Track
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <p style="margin-top: 18px;">
                    <a class="button" href="my_orders.php">
                        View All Orders
                    </a>
                </p>
            <?php else: ?>
                <p>You have not placed any orders yet.</p>

                <a class="button" href="menu.php">
                    Explore the Menu
                </a>
            <?php endif; ?>
        </section>

    </div>
</main>

<footer class="footer">
    &copy; <?= date('Y') ?> Sunrise Café. All rights reserved.
</footer>

</body>
</html>
