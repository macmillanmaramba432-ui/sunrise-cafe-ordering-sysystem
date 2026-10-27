
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Ensure the customer is logged in
if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];
$message = '';
$messageType = '';

// Create a CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Process the password change form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $message = 'Your session token is invalid. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (
            !is_string($currentPassword) ||
            !is_string($newPassword) ||
            !is_string($confirmPassword)
        ) {
            $message = 'Please enter valid password values.';
            $messageType = 'error';
        } elseif (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            $message = 'Please fill in all password fields.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Your new password must contain at least 8 characters.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'The new password and confirmation do not match.';
            $messageType = 'error';
        } elseif ($currentPassword === $newPassword) {
            $message = 'Your new password must differ from your current password.';
            $messageType = 'error';
        } else {
            try {
                // Get the logged-in customer's password hash
                $stmt = $pdo->prepare(
                    'SELECT password_hash
                     FROM customers
                     WHERE id = ?
                     LIMIT 1'
                );
                $stmt->execute([$customerId]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$customer) {
                    $message = 'Customer account not found. Please log in again.';
                    $messageType = 'error';
                } elseif (
                    empty($customer['password_hash']) ||
                    !password_verify(
                        $currentPassword,
                        $customer['password_hash']
                    )
                ) {
                    $message = 'Your current password is incorrect.';
                    $messageType = 'error';
                } else {
                    // Hash the new password before saving it
                    $newPasswordHash = password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                    $update = $pdo->prepare(
                        'UPDATE customers
                         SET password_hash = ?
                         WHERE id = ?'
                    );

                    $update->execute([
                        $newPasswordHash,
                        $customerId
                    ]);

                    // Rotate the CSRF token after a successful change
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header(
                        'Location: my_account.php?password_changed=1'
                    );
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Password change error: ' . $e->getMessage());

                $message = 'Unable to change your password right now. Please try again.';
                $messageType = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Change Password | Sunrise Café</title>

    <link rel="stylesheet" href="css/style.css">

    <style>
        .password-page {
            max-width: 650px;
            margin: 40px auto;
            padding: 25px;
        }

        .password-card {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .password-card h1 {
            margin-top: 0;
            color: #75451f;
        }

        .password-card label {
            display: block;
            margin: 18px 0 7px;
            font-weight: 600;
        }

        .password-card input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .password-card button {
            margin-top: 22px;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            background: #75451f;
            color: white;
            cursor: pointer;
        }

        .password-card button:hover {
            background: #593317;
        }

        .password-message {
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 6px;
        }

        .password-message.error {
            color: #842029;
            background: #f8d7da;
        }

        .password-hint {
            color: #666;
            font-size: 14px;
            margin-top: 6px;
        }

        .password-links {
            margin-top: 20px;
        }

        .password-links a {
            color: #75451f;
            margin-right: 15px;
        }
    </style>
</head>

<body>

<div class="password-page">
    <div class="password-card">
        <h1>Change Password</h1>

        <p>
            Update your Sunrise Café account password.
            Enter your current password first.
        </p>

        <?php if ($message !== ''): ?>
            <div class="password-message <?= htmlspecialchars($messageType) ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="change_password.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
            >

            <label for="current_password">Current Password</label>
            <input
                type="password"
                id="current_password"
                name="current_password"
                autocomplete="current-password"
                required
            >

            <label for="new_password">New Password</label>
            <input
                type="password"
                id="new_password"
                name="new_password"
                minlength="8"
                autocomplete="new-password"
                required
            >
            <p class="password-hint">
                Use at least 8 characters.
            </p>

            <label for="confirm_password">Confirm New Password</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                minlength="8"
                autocomplete="new-password"
                required
            >

            <button type="submit">Update Password</button>
        </form>

        <div class="password-links">
            <a href="my_account.php">Back to My Account</a>
            <a href="my_orders.php">My Orders</a>
        </div>
    </div>
</div>

</body>
</html>
