
<?php
session_start();

require_once dirname(__DIR__) . '/config/database.php';

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function checkoutEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Initialize cart and CSRF token
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if (empty($_SESSION['checkout_csrf_token'])) {
    $_SESSION['checkout_csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| Default form values
|--------------------------------------------------------------------------
*/

$name = '';
$email = '';
$phone = '';
$orderType = 'pickup';
$address = '';
$paymentMethod = 'cash';
$notes = '';
$errors = [];
$success = isset($_GET['success']) && $_GET['success'] === '1';

/*
|--------------------------------------------------------------------------
| Load order confirmation
|--------------------------------------------------------------------------
*/

$orderConfirmation = $_SESSION['last_order'] ?? null;

/*
|--------------------------------------------------------------------------
| Prefill logged-in customer details
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['customer_id'])) {
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, phone
             FROM customers
             WHERE id = ?
             LIMIT 1'
        );

        $stmt->execute([(int) $_SESSION['customer_id']]);
        $loggedInCustomer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($loggedInCustomer) {
            $name = $loggedInCustomer['name'] ?? '';
            $email = $loggedInCustomer['email'] ?? '';
            $phone = $loggedInCustomer['phone'] ?? '';
        } else {
            unset(
                $_SESSION['customer_id'],
                $_SESSION['customer_name'],
                $_SESSION['customer_email']
            );
        }
    } catch (PDOException $e) {
        error_log('Checkout customer lookup failed: ' . $e->getMessage());
        $errors[] = 'We could not load your account details. Please try again.';
    }
}

/*
|--------------------------------------------------------------------------
| Load cart products
|--------------------------------------------------------------------------
*/

$cartItems = [];
$subtotal = 0;
$cartError = '';

if (!empty($_SESSION['cart'])) {
    try {
        $cartIds = [];

        foreach ($_SESSION['cart'] as $itemId => $quantity) {
            if (
                filter_var($itemId, FILTER_VALIDATE_INT) !== false
                && (int) $itemId > 0
                && filter_var($quantity, FILTER_VALIDATE_INT) !== false
                && (int) $quantity > 0
            ) {
                $cartIds[(int) $itemId] = (int) $quantity;
            }
        }

        if (!empty($cartIds)) {
            $placeholders = implode(',', array_fill(0, count($cartIds), '?'));

            $stmt = $pdo->prepare(
                "SELECT id, name, price, image, available
                 FROM menu_items
                 WHERE id IN ($placeholders)"
            );

            $stmt->execute(array_keys($cartIds));
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($products as $product) {
                $productId = (int) $product['id'];
                $quantity = $cartIds[$productId];

                if ((int) $product['available'] !== 1) {
                    $cartError = $product['name'] . ' is currently unavailable.';
                    continue;
                }

                $price = (float) $product['price'];
                $lineTotal = $price * $quantity;
                $subtotal += $lineTotal;

                $cartItems[] = [
                    'id' => $productId,
                    'name' => $product['name'],
                    'price' => $price,
                    'image' => $product['image'] ?? '',
                    'quantity' => $quantity,
                    'line_total' => $lineTotal
                ];
            }

            if (count($cartItems) !== count($cartIds) && $cartError === '') {
                $cartError = 'One or more items in your cart could not be found.';
            }
        } else {
            $cartError = 'Your cart contains invalid items. Please update your cart.';
        }
    } catch (PDOException $e) {
        error_log('Checkout cart lookup failed: ' . $e->getMessage());
        $cartError = 'We could not load your cart. Please try again.';
    }
} else {
    $cartError = 'Your cart is empty. Please add items before checking out.';
}

$deliveryFee = $orderType === 'delivery' ? 200 : 0;
$total = $subtotal + $deliveryFee;

/*
|--------------------------------------------------------------------------
| Process checkout
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $orderType = $_POST['order_type'] ?? 'pickup';
    $address = trim($_POST['delivery_address'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $notes = trim($_POST['notes'] ?? '');

    $deliveryFee = $orderType === 'delivery' ? 200 : 0;
    $total = $subtotal + $deliveryFee;

    /*
    | Validate CSRF token
    */

    $submittedToken = $_POST['checkout_csrf_token'] ?? '';

    if (
        !is_string($submittedToken)
        || !hash_equals($_SESSION['checkout_csrf_token'], $submittedToken)
    ) {
        $errors[] = 'Your session has expired. Please refresh the page and try again.';
    }

    /*
    | Validate customer details
    */

    if ($name === '') {
        $errors[] = 'Please enter your full name.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($phone === '') {
        $errors[] = 'Please enter your phone number.';
    } elseif (!preg_match('/^[0-9+\s()-]{7,20}$/', $phone)) {
        $errors[] = 'Please enter a valid phone number.';
    }

    if (!in_array($orderType, ['pickup', 'delivery'], true)) {
        $errors[] = 'Please select a valid order type.';
    }

    if ($orderType === 'delivery' && $address === '') {
        $errors[] = 'Please enter your delivery address.';
    }

    if (!in_array($paymentMethod, ['cash', 'mpesa'], true)) {
        $errors[] = 'Please select a valid payment method.';
    }

    if (
        $paymentMethod === 'mpesa'
        && empty($_SESSION['customer_id'])
    ) {
        $errors[] = 'Please log in before choosing M-Pesa. Your cart is still saved.';
    }

    if ($cartError !== '') {
        $errors[] = $cartError;
    }

    if (empty($cartItems)) {
        $errors[] = 'Your cart has no available items to order.';
    }

    if ($subtotal <= 0) {
        $errors[] = 'Your order total must be greater than zero.';
    }

    /*
    | Create order
    */

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            /*
            | Recheck products and prices
            */

            $verifiedItems = [];
            $verifiedSubtotal = 0;

            foreach ($_SESSION['cart'] as $itemId => $quantity) {
                if (
                    filter_var($itemId, FILTER_VALIDATE_INT) === false
                    || filter_var($quantity, FILTER_VALIDATE_INT) === false
                    || (int) $itemId <= 0
                    || (int) $quantity <= 0
                ) {
                    throw new RuntimeException(
                        'Your cart contains an invalid item. Please update your cart.'
                    );
                }

                $stmt = $pdo->prepare(
                    'SELECT id, name, price, available
                     FROM menu_items
                     WHERE id = ?
                     FOR UPDATE'
                );

                $stmt->execute([(int) $itemId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    throw new RuntimeException(
                        'A product in your cart could not be found. Please update your cart.'
                    );
                }

                if ((int) $product['available'] !== 1) {
                    throw new RuntimeException(
                        $product['name'] . ' is currently unavailable.'
                    );
                }

                $quantity = (int) $quantity;
                $price = (float) $product['price'];
                $lineTotal = $price * $quantity;
                $verifiedSubtotal += $lineTotal;

                $verifiedItems[] = [
                    'id' => (int) $product['id'],
                    'name' => $product['name'],
                    'price' => $price,
                    'quantity' => $quantity,
                    'subtotal' => $lineTotal
                ];
            }

            if (empty($verifiedItems) || $verifiedSubtotal <= 0) {
                throw new RuntimeException(
                    'Your cart is empty. Please add items before checking out.'
                );
            }

            $subtotal = $verifiedSubtotal;
            $deliveryFee = $orderType === 'delivery' ? 200 : 0;
            $total = $subtotal + $deliveryFee;

            /*
            |--------------------------------------------------------------------------
            | Resolve customer account
            |--------------------------------------------------------------------------
            */

            if (isset($_SESSION['customer_id'])) {
                $customerId = (int) $_SESSION['customer_id'];

                $stmt = $pdo->prepare(
                    'SELECT id
                     FROM customers
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE'
                );

                $stmt->execute([$customerId]);

                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException(
                        'Your customer account could not be found. Please log in again.'
                    );
                }

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
            } else {
                $stmt = $pdo->prepare(
                    "SELECT id
                     FROM customers
                     WHERE phone = ?
                       AND (password_hash IS NULL OR password_hash = '')
                     ORDER BY id DESC
                     LIMIT 1
                     FOR UPDATE"
                );

                $stmt->execute([$phone]);
                $guestCustomer = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($guestCustomer) {
                    $customerId = (int) $guestCustomer['id'];

                    $stmt = $pdo->prepare(
                        'UPDATE customers
                         SET name = ?, email = ?
                         WHERE id = ?'
                    );

                    $stmt->execute([
                        $name,
                        $email !== '' ? $email : null,
                        $customerId
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO customers (name, email, phone)
                         VALUES (?, ?, ?)'
                    );

                    $stmt->execute([
                        $name,
                        $email !== '' ? $email : null,
                        $phone
                    ]);

                    $customerId = (int) $pdo->lastInsertId();
                }
            }

            /*
            | Generate order number
            */

            $orderNumber = 'SC' . date('YmdHis') . random_int(100, 999);

            /*
            | Insert order
            */

            $paymentMethodDatabase = $paymentMethod === 'mpesa'
                ? 'M-Pesa'
                : 'Cash';

            $stmt = $pdo->prepare(
                'INSERT INTO orders (
                    order_number,
                    customer_id,
                    order_type,
                    delivery_address,
                    payment_method,
                    payment_status,
                    order_status,
                    subtotal,
                    delivery_fee,
                    total,
                    notes,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );

            $stmt->execute([
                $orderNumber,
                $customerId,
                $orderType,
                $orderType === 'delivery' ? $address : null,
                $paymentMethodDatabase,
                'Pending',
                'Pending',
                $subtotal,
                $deliveryFee,
                $total,
                $notes !== '' ? $notes : null
            ]);

            $orderId = (int) $pdo->lastInsertId();

            /*
            | Insert order items
            */

            $stmt = $pdo->prepare(
                'INSERT INTO order_items (
                    order_id,
                    menu_item_id,
                    product_name,
                    price,
                    quantity,
                    subtotal
                ) VALUES (?, ?, ?, ?, ?, ?)'
            );

            foreach ($verifiedItems as $item) {
                $stmt->execute([
                    $orderId,
                    $item['id'],
                    $item['name'],
                    $item['price'],
                    $item['quantity'],
                    $item['subtotal']
                ]);
            }

            $pdo->commit();

            /*
            | Save order details for the success page
            */

            $_SESSION['last_order'] = [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'total' => $total,
                'payment_method' => $paymentMethodDatabase,
                'phone' => $phone
            ];

            $_SESSION['cart'] = [];
            $_SESSION['checkout_csrf_token'] = bin2hex(random_bytes(32));

            header('Location: checkout.php?success=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Checkout failed: ' . $e->getMessage());

            if ($e instanceof RuntimeException) {
                $errors[] = $e->getMessage();
            } else {
                $errors[] = 'We could not place your order. Please try again.';
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Refresh totals for display
|--------------------------------------------------------------------------
*/

$deliveryFee = $orderType === 'delivery' ? 200 : 0;
$total = $subtotal + $deliveryFee;

$orderConfirmation = $_SESSION['last_order'] ?? null;

$isMpesaOrder = (
    $success
    && is_array($orderConfirmation)
    && ($orderConfirmation['payment_method'] ?? '') === 'M-Pesa'
    && !empty($_SESSION['customer_id'])
    && (int) ($_SESSION['customer_id']) > 0
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | Sunrise Café</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f7f4ef;
            color: #30271f;
        }

        header {
            background: #4b2e1e;
            color: #fff;
            padding: 18px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        header h1 {
            margin: 0;
            font-size: 25px;
        }

        nav {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }

        nav a {
            color: #fff;
            text-decoration: none;
            font-weight: bold;
        }

        nav a:hover {
            color: #f3c982;
        }

        .container {
            max-width: 1100px;
            width: 92%;
            margin: 35px auto;
        }

        h2 {
            color: #4b2e1e;
        }

        .layout {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 25px;
            align-items: start;
        }

        .panel {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 14px rgba(0, 0, 0, 0.07);
            margin-bottom: 20px;
        }

        .field {
            margin-bottom: 17px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d5cec5;
            border-radius: 7px;
            font-size: 15px;
            background: #fff;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 12px 0;
        }

        .radio-option input {
            width: auto;
        }

        .radio-option label {
            margin: 0;
            font-weight: normal;
        }

        .btn {
            display: inline-block;
            border: none;
            border-radius: 7px;
            padding: 13px 18px;
            background: #8a4b2a;
            color: #fff;
            text-decoration: none;
            text-align: center;
            font-weight: bold;
            cursor: pointer;
            font-size: 15px;
        }

        .btn:hover {
            background: #65351e;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-block {
            width: 100%;
        }

        .secondary {
            background: #6d6258;
        }

        .error {
            background: #fde8e7;
            color: #8b1e18;
            border: 1px solid #efb5b1;
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .success {
            background: #e5f5e9;
            color: #205c30;
            border: 1px solid #a6d8b1;
            padding: 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .pending {
            background: #fff5dc;
            color: #72500b;
            border: 1px solid #ecd08a;
            padding: 18px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .mpesa-payment-box {
            background: #f4eee7;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }

        .mpesa-payment-box h3 {
            margin-top: 0;
            color: #4b2e1e;
        }

        #mpesaMessage {
            margin-bottom: 0;
            line-height: 1.6;
        }

        .order-item {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 13px 0;
            border-bottom: 1px solid #eee5dc;
        }

        .order-item:last-child {
            border-bottom: none;
        }

        .muted {
            color: #756c63;
            font-size: 14px;
            line-height: 1.6;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 10px 0;
        }

        .grand-total {
            border-top: 2px solid #e5ddd4;
            margin-top: 10px;
            padding-top: 17px;
            font-size: 20px;
            font-weight: bold;
        }

        .account-note {
            background: #f4eee7;
            border-radius: 8px;
            padding: 13px;
            margin-bottom: 18px;
            line-height: 1.6;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #766b60;
        }

        @media (max-width: 760px) {
            .layout {
                grid-template-columns: 1fr;
            }

            header {
                padding: 18px 4%;
            }

            .container {
                width: 94%;
                margin-top: 20px;
            }

            .panel {
                padding: 19px;
            }
        }
    </style>
</head>
<body>

<header>
    <h1>Sunrise Café</h1>

    <nav>
        <a href="index.php">Home</a>
        <a href="menu.php">Menu</a>
        <a href="cart.php">Cart</a>

        <?php if (isset($_SESSION['customer_id'])): ?>
            <a href="my_account.php">My Account</a>
            <a href="my_orders.php">My Orders</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </nav>
</header>

<main class="container">
    <h2>Checkout</h2>
    <p>Complete your details below to place your order.</p>

    <?php if ($success): ?>

        <section class="panel">

            <?php if ($isMpesaOrder): ?>
                <div class="pending">
                    <h2>Order Created — Payment Pending</h2>

                    <p>
                        Your order has been created successfully.
                        It is <strong>not yet paid</strong>.
                    </p>

                    <?php if ($orderConfirmation): ?>
                        <p>
                            Order number:
                            <strong>
                                <?= checkoutEscape($orderConfirmation['order_number'] ?? '') ?>
                            </strong>
                        </p>

                        <p>
                            Total:
                            <strong>
                                KSh <?= number_format((float) ($orderConfirmation['total'] ?? 0), 2) ?>
                            </strong>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="mpesa-payment-box">
                    <h3>Pay with M-Pesa</h3>

                    <p>
                        Click the button below to request an M-Pesa payment
                        prompt on the phone number you entered during checkout.
                        Follow the instructions on your phone if a prompt arrives.
                    </p>

                    <button
                        type="button"
                        id="mpesaPayButton"
                        class="btn"
                    >
                        Send M-Pesa Prompt
                    </button>

                    <p id="mpesaMessage" role="status" aria-live="polite"></p>

                    <p class="muted">
                        A successful request only means the payment prompt was
                        accepted for processing. Your order remains unpaid until
                        the payment callback confirms the transaction.
                    </p>
                </div>

            <?php else: ?>
                <div class="success">
                    <h2>Order placed successfully!</h2>

                    <?php if ($orderConfirmation): ?>
                        <p>
                            Your order number is:
                            <strong>
                                <?= checkoutEscape($orderConfirmation['order_number'] ?? '') ?>
                            </strong>
                        </p>

                        <p>
                            Total:
                            <strong>
                                KSh <?= number_format((float) ($orderConfirmation['total'] ?? 0), 2) ?>
                            </strong>
                        </p>

                        <p>
                            Payment method:
                            <strong>
                                <?= checkoutEscape($orderConfirmation['payment_method'] ?? 'Not specified') ?>
                            </strong>
                        </p>
                    <?php else: ?>
                        <p>Your order has been submitted.</p>
                    <?php endif; ?>

                    <p>Keep your order number to track your order.</p>
                </div>
            <?php endif; ?>

            <a
                class="btn"
                href="track_order.php<?= $orderConfirmation ? '?order_number=' . urlencode((string) ($orderConfirmation['order_number'] ?? '')) : '' ?>"
            >
                Track Order
            </a>

            <?php if (isset($_SESSION['customer_id'])): ?>
                <a class="btn secondary" href="my_orders.php">
                    View My Orders
                </a>
            <?php endif; ?>

            <a class="btn secondary" href="menu.php">
                Continue Shopping
            </a>
        </section>

    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="error">
                <strong>Please check the following:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= checkoutEscape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="layout">
            <section class="panel">
                <h2>Your Details</h2>

                <?php if (isset($_SESSION['customer_id'])): ?>
                    <div class="account-note">
                        You are checking out while logged in. This order will be
                        linked to your customer account.
                    </div>
                <?php else: ?>
                    <div class="account-note">
                        You can check out as a guest or
                        <a href="login.php">log in</a> to connect this order
                        to your customer account.
                    </div>
                <?php endif; ?>

                <form method="POST" action="checkout.php">
                    <input
                        type="hidden"
                        name="checkout_csrf_token"
                        value="<?= checkoutEscape($_SESSION['checkout_csrf_token']) ?>"
                    >

                    <div class="field">
                        <label for="name">Full Name *</label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            maxlength="150"
                            required
                            value="<?= checkoutEscape($name) ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="email">Email Address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            maxlength="190"
                            value="<?= checkoutEscape($email) ?>"
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
                            value="<?= checkoutEscape($phone) ?>"
                        >
                    </div>

                    <h2>Order Type</h2>

                    <div class="radio-option">
                        <input
                            type="radio"
                            id="pickup"
                            name="order_type"
                            value="pickup"
                            <?= $orderType === 'pickup' ? 'checked' : '' ?>
                        >
                        <label for="pickup">Pickup — no delivery fee</label>
                    </div>

                    <div class="radio-option">
                        <input
                            type="radio"
                            id="delivery"
                            name="order_type"
                            value="delivery"
                            <?= $orderType === 'delivery' ? 'checked' : '' ?>
                        >
                        <label for="delivery">Delivery — KSh 200</label>
                    </div>

                    <div class="field" id="addressField">
                        <label for="delivery_address">Delivery Address *</label>
                        <textarea
                            id="delivery_address"
                            name="delivery_address"
                            maxlength="1000"
                            placeholder="Enter your estate, building, street and other directions"
                        ><?= checkoutEscape($address) ?></textarea>
                    </div>

                    <h2>Payment Method</h2>

                    <div class="radio-option">
                        <input
                            type="radio"
                            id="cash"
                            name="payment_method"
                            value="cash"
                            <?= $paymentMethod === 'cash' ? 'checked' : '' ?>
                        >
                        <label for="cash">Cash</label>
                    </div>

                    <div class="radio-option">
                        <input
                            type="radio"
                            id="mpesa"
                            name="payment_method"
                            value="mpesa"
                            <?= $paymentMethod === 'mpesa' ? 'checked' : '' ?>
                        >
                        <label for="mpesa">M-Pesa</label>
                    </div>

                    <p class="muted">
                        If you select M-Pesa, you must be logged in.
                        After the order is created, use the payment button
                        to request an M-Pesa prompt. Your order will remain
                        unpaid until payment is confirmed.
                    </p>

                    <div class="field">
                        <label for="notes">Order Notes (Optional)</label>
                        <textarea
                            id="notes"
                            name="notes"
                            maxlength="2000"
                            placeholder="Any special instructions?"
                        ><?= checkoutEscape($notes) ?></textarea>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-block"
                        <?= empty($cartItems) || $cartError !== '' ? 'disabled' : '' ?>
                    >
                        Place Order
                    </button>

                    <p class="muted">
                        By placing your order, you confirm that your details
                        and order information are correct.
                    </p>
                </form>
            </section>

            <aside class="panel">
                <h2>Order Summary</h2>

                <?php if (!empty($cartItems)): ?>
                    <?php foreach ($cartItems as $item): ?>
                        <div class="order-item">
                            <div>
                                <strong><?= checkoutEscape($item['name']) ?></strong>
                                <div class="muted">
                                    KSh <?= number_format($item['price'], 2) ?>
                                    × <?= (int) $item['quantity'] ?>
                                </div>
                            </div>

                            <strong>
                                KSh <?= number_format($item['line_total'], 2) ?>
                            </strong>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Your cart is empty.</p>
                <?php endif; ?>

                <div class="total-row">
                    <span>Subtotal</span>
                    <strong>KSh <?= number_format($subtotal, 2) ?></strong>
                </div>

                <div class="total-row">
                    <span>Delivery Fee</span>
                    <strong>KSh <?= number_format($deliveryFee, 2) ?></strong>
                </div>

                <div class="total-row grand-total">
                    <span>Total</span>
                    <span>KSh <?= number_format($total, 2) ?></span>
                </div>

                <a class="btn secondary btn-block" href="cart.php">
                    Return to Cart
                </a>
            </aside>
        </div>

    <?php endif; ?>
</main>

<footer>
    <p>&copy; <?= date('Y') ?> Sunrise Café. All rights reserved.</p>
</footer>

<script>
    /*
    |--------------------------------------------------------------------------
    | Show or hide delivery address
    |--------------------------------------------------------------------------
    */

    const pickupRadio = document.getElementById('pickup');
    const deliveryRadio = document.getElementById('delivery');
    const addressField = document.getElementById('addressField');
    const addressInput = document.getElementById('delivery_address');

    function updateDeliveryFields() {
        if (!pickupRadio || !deliveryRadio || !addressField || !addressInput) {
            return;
        }

        const isDelivery = deliveryRadio.checked;

        addressField.style.display = isDelivery ? 'block' : 'none';
        addressInput.required = isDelivery;

        if (!isDelivery) {
            addressInput.setCustomValidity('');
        }
    }

    if (pickupRadio && deliveryRadio) {
        pickupRadio.addEventListener('change', updateDeliveryFields);
        deliveryRadio.addEventListener('change', updateDeliveryFields);
        updateDeliveryFields();
    }

    /*
    |--------------------------------------------------------------------------
    | Request M-Pesa STK Push
    |--------------------------------------------------------------------------
    */

    const mpesaButton = document.getElementById('mpesaPayButton');
    const mpesaMessage = document.getElementById('mpesaMessage');

    if (mpesaButton && mpesaMessage) {
        mpesaButton.addEventListener('click', async function () {
            mpesaButton.disabled = true;
            mpesaMessage.textContent = 'Sending payment request...';

            try {
                const formData = new FormData();

                formData.append(
                    'order_id',
                    <?= json_encode(
                        (int) ($orderConfirmation['order_id'] ?? 0)
                    ) ?>
                );

                formData.append(
                    'phone',
                    <?= json_encode(
                        (string) ($orderConfirmation['phone'] ?? '')
                    ) ?>
                );

                const response = await fetch('mpesa_stk_push.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    mpesaMessage.textContent =
                        'The payment request was accepted. Check your phone ' +
                        'for the M-Pesa prompt. Your order is still unpaid ' +
                        'until the payment is confirmed.';

                    /*
                    | Prevent another request for this order after acceptance.
                    */

                    mpesaButton.disabled = true;
                } else {
                    mpesaMessage.textContent =
                        result.message ||
                        'The payment request could not be sent. Please check the configuration and try again.';

                    mpesaButton.disabled = false;
                }
            } catch (error) {
                mpesaMessage.textContent =
                    'We could not contact the payment service. Check that ' +
                    'your server is running and try again.';

                mpesaButton.disabled = false;
            }
        });
    }
</script>

</body>
</html>
