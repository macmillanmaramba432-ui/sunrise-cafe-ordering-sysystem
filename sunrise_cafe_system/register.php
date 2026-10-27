
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

$error = '';
$success = '';

$name = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $phone === '' ||
        $password === '' || $confirm_password === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Your password must contain at least 8 characters.';
    } elseif ($password !== $confirm_password) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $check = $pdo->prepare(
                'SELECT id FROM customers WHERE email = ? LIMIT 1'
            );
            $check->execute([$email]);

            if ($check->fetch()) {
                $error = 'An account with this email already exists.';
            } else {
                $password_hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $pdo->prepare(
                    'INSERT INTO customers
                    (name, email, phone, password_hash)
                    VALUES (?, ?, ?, ?)'
                );

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $password_hash
                ]);

                $success = 'Registration successful! You can now log in.';
                $name = '';
                $email = '';
                $phone = '';
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Registration could not be completed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Registration | Sunrise Café</title>
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

        .container {
            max-width: 480px;
            margin: 30px auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
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
            margin: 15px 0 6px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 16px;
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

        .message {
            padding: 12px;
            border-radius: 6px;
            margin: 15px 0;
        }

        .error {
            color: #842029;
            background: #f8d7da;
        }

        .success {
            color: #0f5132;
            background: #d1e7dd;
        }

        .links {
            text-align: center;
            margin-top: 20px;
        }

        a {
            color: #704323;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>Join Sunrise Café</h1>
    <p class="subtitle">Create your customer account</p>

    <?php if ($error !== ''): ?>
        <div class="message error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="message success">
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            <a href="login.php">Log in here</a>.
        </div>
    <?php endif; ?>

    <form method="POST" action="register.php">
        <label for="name">Full Name</label>
        <input
            type="text"
            id="name"
            name="name"
            value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
            maxlength="150"
            autocomplete="name"
            required
        >

        <label for="email">Email Address</label>
        <input
            type="email"
            id="email"
            name="email"
            value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
            maxlength="150"
            autocomplete="email"
            required
        >

        <label for="phone">Phone Number</label>
        <input
            type="tel"
            id="phone"
            name="phone"
            value="<?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?>"
            maxlength="30"
            autocomplete="tel"
            required
        >

        <label for="password">Password</label>
        <input
            type="password"
            id="password"
            name="password"
            minlength="8"
            autocomplete="new-password"
            required
        >

        <label for="confirm_password">Confirm Password</label>
        <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            minlength="8"
            autocomplete="new-password"
            required
        >

        <button type="submit">Create Account</button>
    </form>

    <div class="links">
        Already have an account?
        <a href="login.php">Log in</a>
        <br><br>
        <a href="index.php">Back to Home</a>
    </div>
</div>

</body>
</html>
