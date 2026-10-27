
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Redirect customers who are already logged in.
if (isset($_SESSION['customer_id'])) {
    header('Location: my_account.php');
    exit;
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['login_csrf'], $csrfToken)
    ) {
        $error = 'Your session expired. Please refresh and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, name, email, password_hash
                 FROM customers
                 WHERE email = ?
                 LIMIT 1'
            );

            $stmt->execute([$email]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $customer &&
                !empty($customer['password_hash']) &&
                password_verify($password, $customer['password_hash'])
            ) {
                session_regenerate_id(true);

                $_SESSION['customer_id'] = (int) $customer['id'];
                $_SESSION['customer_name'] = $customer['name'];
                $_SESSION['customer_email'] = $customer['email'];

                unset($_SESSION['login_csrf']);

                header('Location: my_account.php');
                exit;
            }

            $error = 'Incorrect email or password. Please try again.';

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Unable to log in right now. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Customer Login | Sunrise Café</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 15px;
            font-family: Arial, sans-serif;
            background: #f7f1e8;
            color: #33251d;
        }

        .login-container {
            max-width: 450px;
            margin: 45px auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }

        h1 {
            text-align: center;
            color: #704323;
            margin-top: 0;
        }

        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin: 16px 0 6px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 16px;
        }

        input:focus {
            outline: 2px solid #b98b5f;
            border-color: #704323;
        }

        button {
            width: 100%;
            margin-top: 22px;
            padding: 13px;
            border: none;
            border-radius: 6px;
            background: #704323;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #4d2c17;
        }

        .error {
            padding: 12px;
            margin: 15px 0;
            border-radius: 6px;
            background: #f8d7da;
            color: #842029;
        }

        .links {
            margin-top: 22px;
            text-align: center;
            line-height: 1.9;
        }

        a {
            color: #704323;
        }
    </style>
</head>

<body>

<div class="login-container">

    <h1>Welcome Back!</h1>

    <p class="subtitle">
        Log in to your Sunrise Café account
    </p>

    <?php if ($error !== ''): ?>
        <div class="error" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(
                $_SESSION['login_csrf'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

        <label for="email">Email Address</label>

        <input
            type="email"
            id="email"
            name="email"
            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
            autocomplete="email"
            maxlength="150"
            required
        >

        <label for="password">Password</label>

        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <p>
            <a href="forgot_password.php">Forgot Password?</a>
        </p>
        <button type="submit">Log In</button>

    </form>

    <div class="links">
        Don't have an account?
        <a href="register.php">Register here</a>

        <br>

        <a href="index.php">Back to Home</a>
    </div>

</div>

</body>
</html>
