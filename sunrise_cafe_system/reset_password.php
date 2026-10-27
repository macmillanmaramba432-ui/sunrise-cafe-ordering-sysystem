
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

$message = '';
$messageType = 'error';
$resetValid = false;
$token = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Accept the reset token from the URL.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = $_GET['token'] ?? '';
} else {
    $token = $_POST['token'] ?? '';
}

if (
    !is_string($token) ||
    !preg_match('/^[a-f0-9]{64}$/', $token)
) {
    $message = 'This password reset link is invalid.';
} else {
    try {
        $tokenHash = hash('sha256', $token);

        $stmt = $pdo->prepare(
            'SELECT id, customer_id
             FROM password_resets
             WHERE token_hash = ?
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reset) {
            $resetValid = true;
        } else {
            $message =
                'This reset link is invalid or has expired. Please request a new one.';
        }
    } catch (PDOException $e) {
        error_log('Reset token verification error: ' . $e->getMessage());
        $message = 'Unable to verify this link right now. Please try again later.';
    }
}

// Process the new password.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetValid) {
    $csrf = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrf) ||
        !hash_equals($_SESSION['csrf_token'], $csrf)
    ) {
        $message = 'Your session token is invalid. Refresh the page and try again.';
        $resetValid = true;
    } else {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (
            !is_string($newPassword) ||
            !is_string($confirmPassword)
        ) {
            $message = 'Please enter valid password values.';
        } elseif (
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            $message = 'Please fill in both password fields.';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Your password must contain at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'The passwords do not match.';
        } else {
            try {
                $pdo->beginTransaction();

                // Lock and recheck the reset token to prevent reuse.
                $stmt = $pdo->prepare(
                    'SELECT id, customer_id
                     FROM password_resets
                     WHERE token_hash = ?
                       AND expires_at > NOW()
                     LIMIT 1
                     FOR UPDATE'
                );
                $stmt->execute([hash('sha256', $token)]);
                $reset = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$reset) {
                    $pdo->rollBack();
                    $resetValid = false;
                    $message =
                        'This reset link has expired or has already been used. Request a new one.';
                } else {
                    $passwordHash = password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                    $update = $pdo->prepare(
                        'UPDATE customers
                         SET password_hash = ?
                         WHERE id = ?'
                    );
                    $update->execute([
                        $passwordHash,
                        (int) $reset['customer_id']
                    ]);

                    // Remove the used token and any other outstanding reset requests.
                    $delete = $pdo->prepare(
                        'DELETE FROM password_resets WHERE customer_id = ?'
                    );
                    $delete->execute([(int) $reset['customer_id']]);

                    $pdo->commit();

                    // Do not automatically sign the customer in.
                    header('Location: login.php?password_reset=1');
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Password reset error: ' . $e->getMessage());
                $message = 'Unable to reset your password right now. Please try again.';
                $resetValid = true;
            }
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
    <title>Reset Password | Sunrise Café</title>
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
            background: #f8d7da;
            color: #842029;
            margin: 15px 0;
        }

        .reset-card a {
            color: #75451f;
        }
    </style>
</head>
<body>
<div class="reset-container">
    <div class="reset-card">
        <h1>Reset Your Password</h1>

        <?php if ($message !== ''): ?>
            <div class="reset-message">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($resetValid): ?>
            <p>Choose a new password for your account.</p>

            <form method="POST" action="reset_password.php">
                <input
                    type="hidden"
                    name="token"
                    value="<?= htmlspecialchars($token) ?>"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
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

                <label for="confirm_password">Confirm New Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                >

                <button type="submit">Reset Password</button>
            </form>
        <?php endif; ?>

        <p><a href="login.php">Return to Login</a></p>
    </div>
</div>
</body>
</html>
