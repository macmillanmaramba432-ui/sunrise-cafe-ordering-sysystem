
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

$message = '';
$messageType = 'success';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $email = trim($_POST['email'] ?? '');

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $message = 'Your session has expired. Refresh the page and try again.';
        $messageType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageType = 'error';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, name, email
                 FROM customers
                 WHERE email = ?
                 LIMIT 1'
            );
            $stmt->execute([$email]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            // Use the same public message whether the account exists or not.
            if ($customer) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);

                $pdo->beginTransaction();

                // Invalidate previous reset links for this customer.
                $delete = $pdo->prepare(
                    'DELETE FROM password_resets WHERE customer_id = ?'
                );
                $delete->execute([(int) $customer['id']]);

                $insert = $pdo->prepare(
                    'INSERT INTO password_resets
                        (customer_id, token_hash, expires_at)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
                );
                $insert->execute([
                    (int) $customer['id'],
                    $tokenHash
                ]);

                $pdo->commit();

                $resetUrl =
                    'http://localhost/sunrise_cafe_sysytem/sunrise_cafe_system/reset_password.php?token='
                    . urlencode($rawToken);

                $subject = 'Sunrise Cafe Password Reset';

                $body =
                    "Hello " . $customer['name'] . ",\n\n"
                    . "We received a request to reset your Sunrise Cafe password.\n\n"
                    . "Open the link below within 30 minutes:\n"
                    . $resetUrl . "\n\n"
                    . "If you did not request this, you can ignore this email.\n";

                $headers = [
                    'From' => 'no-reply@sunrisecafe.example',
                    'Content-Type' => 'text/plain; charset=UTF-8'
                ];

                // Requires PHP email delivery to be configured.
                $sent = mail(
                    $customer['email'],
                    $subject,
                    $body,
                    $headers
                );

                if (!$sent) {
                    // Invalidate a link that was not successfully sent.
                    $delete = $pdo->prepare(
                        'DELETE FROM password_resets WHERE customer_id = ?'
                    );
                    $delete->execute([(int) $customer['id']]);

                    error_log(
                        'Password reset email could not be sent for customer ID '
                        . (int) $customer['id']
                    );
                }
            }

            $message =
                'If an account exists for that email address and email delivery is configured, a password reset link will be sent.';
            $messageType = 'success';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Forgot password error: ' . $e->getMessage());

            $message =
                'We could not process your request right now. Please try again later.';
            $messageType = 'error';
        }
    }

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | Sunrise Café</title>
    <link rel="stylesheet" href="css/style.css">

    <style>
        .reset-container {
            max-width: 500px;
            margin: 50px auto;
            padding: 25px;
        }

        .reset-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,.08);
        }

        .reset-card h1 {
            color: #75451f;
            margin-top: 0;
        }

        .reset-card label {
            display: block;
            margin: 18px 0 7px;
            font-weight: bold;
        }

        .reset-card input {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        .reset-card button {
            margin-top: 20px;
            padding: 12px 20px;
            background: #75451f;
            color: white;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
        }

        .reset-message {
            padding: 12px;
            border-radius: 6px;
            background: #e8f5e9;
            margin: 15px 0;
        }

        .reset-message.error {
            background: #f8d7da;
            color: #842029;
        }

        .reset-card a {
            color: #75451f;
        }
    </style>
</head>
<body>
<div class="reset-container">
    <div class="reset-card">
        <h1>Forgot Password?</h1>

        <p>
            Enter the email address connected to your account.
            If email delivery is configured, we will send you a reset link.
        </p>

        <?php if ($message !== ''): ?>
            <div class="reset-message <?= htmlspecialchars($messageType) ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="forgot_password.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
            >

            <label for="email">Email Address</label>
            <input
                type="email"
                id="email"
                name="email"
                maxlength="190"
                autocomplete="email"
                required
            >

            <button type="submit">Send Reset Link</button>
        </form>

        <p><a href="login.php">Return to Login</a></p>
    </div>
</div>
</body>
</html>
