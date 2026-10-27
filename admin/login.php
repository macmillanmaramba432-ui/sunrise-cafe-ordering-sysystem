<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Default values prevent undefined-variable warnings when the page first opens.
$error = '';
$username = '';

// Create a security token for the login form.
if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

// Process the login only after the form is submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) ||
        !hash_equals($_SESSION['login_csrf_token'], $submittedToken)) {
        $error = 'Your login session expired. Refresh the page and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, username, password FROM admins WHERE username = :username LIMIT 1"
            );
            $stmt->execute(['username' => $username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));

                header('Location: index.php');
                exit;
            }

            $error = 'Invalid username or password.';
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'Unable to sign in right now. Check the database setup and try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Login | Sunrise Café</title>

    <style>
        :root {
            --brown: #8b4513;
            --dark-brown: #5c2e0b;
            --cream: #f8f3ec;
            --gold: #f3b562;
            --text: #292929;
            --muted: #777;
            --white: #fff;
            --border: #e7ddd1;
            --red: #a52828;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            font-family: Arial, Helvetica, sans-serif;
            background:
                radial-gradient(circle at top left, #f5dfc5, transparent 40%),
                linear-gradient(135deg, #fbf7f1, #efe3d5);
            color: var(--text);
            display: flex;
            flex-direction: column;
        }

        .topbar {
            width: 100%;
            padding: 22px 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .brand {
            color: var(--dark-brown);
            font-size: 23px;
            font-weight: bold;
        }

        .brand span {
            color: var(--brown);
        }

        .website-link {
            color: var(--brown);
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
        }

        .website-link:hover {
            text-decoration: underline;
        }

        .page {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 35px 18px 55px;
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--white);
            border: 1px solid rgba(139, 69, 19, .12);
            border-radius: 18px;
            padding: 38px;
            box-shadow: 0 18px 55px rgba(67, 39, 16, .12);
            animation: appear .45s ease;
        }

        @keyframes appear {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-circle {
            width: 76px;
            height: 76px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--cream);
            font-size: 37px;
        }

        .heading {
            text-align: center;
            margin-bottom: 28px;
        }

        .heading h1 {
            color: var(--dark-brown);
            font-size: 26px;
            margin-bottom: 10px;
        }

        .heading p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .error-message {
            padding: 12px 14px;
            margin-bottom: 20px;
            border-radius: 8px;
            background: #fff0ef;
            border: 1px solid #f1c7c4;
            color: var(--red);
            font-size: 13px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 19px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: bold;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap input {
            width: 100%;
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 14px;
            outline: none;
            background: #fffdfa;
            transition: border-color .2s, box-shadow .2s;
        }

        .input-wrap input:focus {
            border-color: var(--brown);
            box-shadow: 0 0 0 3px rgba(139, 69, 19, .1);
        }

        .password-input {
            padding-right: 70px !important;
        }

        .show-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--brown);
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            padding: 5px;
        }

        .login-button {
            width: 100%;
            padding: 15px;
            margin-top: 6px;
            border: none;
            border-radius: 9px;
            background: var(--brown);
            color: var(--white);
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            transition: background .2s, transform .2s;
        }

        .login-button:hover {
            background: var(--dark-brown);
        }

        .login-button:active {
            transform: scale(.99);
        }

        .login-button:disabled {
            opacity: .7;
            cursor: wait;
        }

        .security-note {
            margin-top: 22px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .demo-note {
            margin-top: 20px;
            padding: 13px;
            border-radius: 8px;
            background: #fff7e8;
            border: 1px solid #f2dfbd;
            font-size: 12px;
            line-height: 1.7;
            color: #68471f;
        }

        .demo-note strong {
            display: block;
            margin-bottom: 4px;
        }

        footer {
            padding: 20px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }

        @media (max-width: 480px) {
            .topbar {
                padding: 20px;
            }

            .brand {
                font-size: 20px;
            }

            .login-card {
                padding: 27px 22px;
            }

            .heading h1 {
                font-size: 23px;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <a href="../index.php" class="brand">
        ☕ Sunrise <span>Café</span>
    </a>

    <a href="../index.php" class="website-link">
        ← Customer Website
    </a>
</header>

<main class="page">
    <section class="login-card">

        <div class="logo-circle">☕</div>

        <div class="heading">
            <h1>Administrator Login</h1>
            <p>Sign in to manage Sunrise Café orders and menu items.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="error-message" role="alert">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" id="loginForm">
            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars(
                    $_SESSION['login_csrf_token'],
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>"
            >

            <div class="form-group">
                <label for="username">Username</label>

                <div class="input-wrap">
                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Enter administrator username"
                        value="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>

                <div class="input-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="password-input"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="show-password"
                        id="togglePassword"
                        aria-label="Show password"
                    >
                        Show
                    </button>
                </div>
            </div>

            <button type="submit" class="login-button" id="loginButton">
                Sign In to Dashboard →
            </button>
        </form>

        <div class="demo-note">
            <strong>Demo login credentials</strong>
            Username: admin<br>
            Password: Sunrise@123
        </div>

        <div class="security-note">
            🔒 Administrator access only.<br>
            Sunrise Café Ordering System
        </div>

    </section>
</main>

<footer>
    &copy; <?php echo date('Y'); ?> Sunrise Café. All rights reserved.
</footer>

<script>
    // Toggle password visibility.
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');

    togglePassword.addEventListener('click', function () {
        const showingPassword = passwordInput.type === 'password';

        passwordInput.type = showingPassword ? 'text' : 'password';
        togglePassword.textContent = showingPassword ? 'Hide' : 'Show';
        togglePassword.setAttribute(
            'aria-label',
            showingPassword ? 'Hide password' : 'Show password'
        );
    });

    // Prevent accidental repeated clicks while submitting.
    document.getElementById('loginForm').addEventListener('submit', function () {
        const button = document.getElementById('loginButton');

        if (this.checkValidity()) {
            button.disabled = true;
            button.textContent = 'Signing in...';
        }
    });
</script>

</body>
</html>
