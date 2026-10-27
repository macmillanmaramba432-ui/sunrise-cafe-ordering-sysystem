
<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if (empty($_SESSION['cart_csrf'])) {
    $_SESSION['cart_csrf'] = bin2hex(random_bytes(32));
}

function cartCount(): int {
    $count = 0;

    foreach ($_SESSION['cart'] as $quantity) {
        if (is_array($quantity)) {
            $quantity = $quantity['quantity'] ?? 0;
        }
        $count += max(0, (int) $quantity);
    }

    return $count;
}

function getCartData(PDO $pdo): array {
    $items = [];
    $subtotal = 0.0;

    foreach ($_SESSION['cart'] as $id => $quantity) {
        $id = filter_var($id, FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            continue;
        }

        if (is_array($quantity)) {
            $quantity = $quantity['quantity'] ?? 0;
        }

        $quantity = (int) $quantity;

        if ($quantity < 1) {
            continue;
        }

        $stmt = $pdo->prepare(
            "SELECT id, name, description, price, image, available
             FROM menu_items WHERE id = ?"
        );
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product) {
            continue;
        }

        $price = (float) $product['price'];
        $lineTotal = $price * $quantity;
        $available = (int) $product['available'] === 1;

        $items[] = [
            'id' => (int) $product['id'],
            'name' => $product['name'],
            'description' => $product['description'] ?? '',
            'price' => $price,
            'quantity' => $quantity,
            'lineTotal' => $lineTotal,
            'available' => $available,
            'image' => trim((string) ($product['image'] ?? '')) !== ''
                ? '../assets/images/' . rawurlencode(basename($product['image']))
                : ''
        ];

        $subtotal += $lineTotal;
    }

    return [
        'items' => $items,
        'subtotal' => $subtotal,
        'count' => cartCount(),
        'canCheckout' => count($items) > 0
            && count(array_filter($items, fn($item) => !$item['available'])) === 0
    ];
}

/* Handle cart actions without reloading the page. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals(
        $_SESSION['cart_csrf'],
        $_POST['csrf_token'] ?? ''
    )) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your session expired. Refresh the page and try again.'
        ]);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $message = '';

    if ($action === 'update') {
        $id = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var(
            $_POST['quantity'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$id || $id < 1 || $quantity === false || $quantity < 0 || $quantity > 99) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Please choose a valid quantity between 0 and 99.'
            ]);
            exit;
        }

        if (!isset($_SESSION['cart'][$id])) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'This item is not in your cart.'
            ]);
            exit;
        }

        $stmt = $pdo->prepare(
            "SELECT id, available FROM menu_items WHERE id = ?"
        );
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product || !(int) $product['available']) {
            unset($_SESSION['cart'][$id]);
            $message = 'An unavailable item was removed from your cart.';
        } elseif ($quantity === 0) {
            unset($_SESSION['cart'][$id]);
            $message = 'Item removed from your cart.';
        } else {
            $_SESSION['cart'][$id] = $quantity;
            $message = 'Cart updated.';
        }

    } elseif ($action === 'remove') {
        $id = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid item.'
            ]);
            exit;
        }

        unset($_SESSION['cart'][$id]);
        $message = 'Item removed from your cart.';

    } elseif ($action === 'clear') {
        $_SESSION['cart'] = [];
        $message = 'Your cart has been cleared.';

    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Unknown cart action.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'cart' => getCartData($pdo)
    ]);
    exit;
}

$cart = getCartData($pdo);

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your Shopping Cart | Sunrise Café</title>

<style>
* { box-sizing: border-box; }

:root {
    --coffee: #633b26;
    --dark: #402719;
    --cream: #fff8ed;
    --orange: #e9a23b;
    --muted: #756b63;
    --border: #eadfd3;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    color: #30251f;
    background: var(--cream);
}

a { color: inherit; text-decoration: none; }

.navbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 18px 6%;
    background: white;
    box-shadow: 0 2px 12px #0000000b;
}

.brand { font-size: 22px; font-weight: 800; color: var(--coffee); }

.nav-links { display: flex; gap: 20px; align-items: center; }

.cart-badge {
    padding: 10px 15px;
    border-radius: 24px;
    background: var(--coffee);
    color: white;
}

.hero { text-align: center; padding: 36px 15px 18px; }

.hero h1 { color: var(--dark); margin-bottom: 8px; }
.hero p { color: var(--muted); }

.container { width: min(1100px, 92%); margin: 22px auto 60px; }

.cart-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(270px, 1fr);
    align-items: start;
    gap: 24px;
}

.panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 22px;
}

.panel h2 { margin-top: 0; font-size: 21px; }

.cart-item {
    display: grid;
    grid-template-columns: 94px minmax(0, 1fr);
    gap: 16px;
    padding: 19px 0;
    border-bottom: 1px solid #eee5dc;
}

.cart-item:last-child { border-bottom: none; }

.cart-item img {
    width: 94px;
    height: 94px;
    object-fit: cover;
    border-radius: 12px;
    background: #f3e7d8;
}

.item-name { margin: 0 0 7px; font-size: 17px; }
.item-description { color: var(--muted); font-size: 13px; margin: 0 0 9px; }

.item-price { font-weight: bold; color: var(--coffee); }

.item-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 12px;
}

.quantity {
    display: inline-flex;
    align-items: center;
    border: 1px solid var(--border);
    border-radius: 9px;
    overflow: hidden;
}

.quantity button {
    width: 35px;
    height: 34px;
    border: 0;
    background: #fff5e7;
    cursor: pointer;
    font-size: 19px;
}

.quantity button:disabled { opacity: .4; cursor: not-allowed; }

.quantity span {
    min-width: 36px;
    text-align: center;
    font-weight: bold;
}

.remove-btn {
    border: none;
    background: transparent;
    color: #b33c32;
    cursor: pointer;
    padding: 6px 0;
}

.line-total { font-weight: 800; white-space: nowrap; }

.summary-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin: 17px 0;
    color: var(--muted);
}

.summary-row strong { color: #30251f; }

.summary-total {
    border-top: 1px solid var(--border);
    padding-top: 18px;
    margin-top: 20px;
    font-size: 19px;
    font-weight: 800;
    color: var(--dark);
}

.checkout-btn, .continue-btn, .clear-btn {
    display: block;
    width: 100%;
    text-align: center;
    padding: 14px;
    border-radius: 10px;
    font-weight: bold;
    cursor: pointer;
    border: none;
}

.checkout-btn {
    background: var(--orange);
    color: #352214;
    margin-top: 22px;
}

.checkout-btn:hover { background: #f3b955; }

.checkout-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.continue-btn {
    border: 1px solid var(--coffee);
    color: var(--coffee);
    background: white;
    margin-top: 10px;
}

.clear-btn {
    color: #b33c32;
    background: transparent;
    margin-top: 10px;
}

.empty-cart { text-align: center; padding: 40px 12px; }
.empty-cart .symbol { font-size: 48px; }
.empty-cart p { color: var(--muted); line-height: 1.6; }

.unavailable {
    font-size: 13px;
    color: #b33c32;
    margin-top: 8px;
}

.notice {
    margin-top: 15px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.5;
}

.toast {
    position: fixed;
    right: 20px;
    bottom: 20px;
    max-width: calc(100% - 40px);
    padding: 14px 18px;
    background: #263c2d;
    color: white;
    border-radius: 10px;
    opacity: 0;
    transform: translateY(10px);
    pointer-events: none;
    transition: .2s;
    z-index: 10;
}

.toast.show { opacity: 1; transform: translateY(0); }

footer {
    text-align: center;
    padding: 25px 12px;
    background: var(--dark);
    color: white;
}

@media (max-width: 760px) {
    .cart-layout { grid-template-columns: 1fr; }
    .summary-panel { order: -1; }
    .navbar { padding: 15px 4%; }
    .brand { font-size: 18px; }
    .nav-links { gap: 12px; font-size: 13px; }
    .panel { padding: 17px; }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { transition: none !important; }
}
</style>
</head>

<body>
<nav class="navbar">
    <a class="brand" href="index.php">☀ Sunrise Café</a>
    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="menu.php">Menu</a>
        <a class="cart-badge" href="cart.php">
            🛒 Cart (<span id="navCount"><?= $cart['count'] ?></span>)
        </a>
    </div>
</nav>

<header class="hero">
    <h1>Your Shopping Cart 🛒</h1>
    <p>Review your favourites before checking out.</p>
</header>

<main class="container">
    <div class="cart-layout">
        <section class="panel">
            <h2>Cart Items (<span id="itemCount"><?= $cart['count'] ?></span>)</h2>
            <div id="cartItems"></div>
        </section>

        <aside class="panel summary-panel">
            <h2>Order Summary</h2>

            <div class="summary-row">
                <span>Items subtotal</span>
                <strong id="subtotal">KSh 0.00</strong>
            </div>

            <div class="summary-row">
                <span>Delivery fee</span>
                <strong>Calculated at checkout</strong>
            </div>

            <div class="summary-row summary-total">
                <span>Subtotal</span>
                <strong id="total">KSh 0.00</strong>
            </div>

            <p class="notice">
                Delivery charges, if applicable, will be included at checkout.
                Product prices are rechecked by the server.
            </p>

            <a class="checkout-btn" id="checkoutBtn" href="checkout.php">
                Proceed to Checkout →
            </a>

            <a class="continue-btn" href="menu.php">← Continue Shopping</a>

            <button type="button" class="clear-btn" id="clearBtn">
                Clear Entire Cart
            </button>
        </aside>
    </div>
</main>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<footer>
    <p>© <?= date('Y') ?> Sunrise Café</p>
    <a href="menu.php">Browse Menu</a>
</footer>

<script>
const csrfToken = <?= json_encode($_SESSION['cart_csrf']) ?>;
let cartState = <?= json_encode(
    $cart,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const cartItems = document.getElementById('cartItems');
const checkoutBtn = document.getElementById('checkoutBtn');
const clearBtn = document.getElementById('clearBtn');
const toast = document.getElementById('toast');
let toastTimer;

function money(value) {
    return 'KSh ' + Number(value).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function showToast(message) {
    toast.textContent = message;
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 2600);
}

function makeElement(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
}

function renderCart() {
    cartItems.replaceChildren();

    document.getElementById('navCount').textContent = cartState.count;
    document.getElementById('itemCount').textContent = cartState.count;
    document.getElementById('subtotal').textContent = money(cartState.subtotal);
    document.getElementById('total').textContent = money(cartState.subtotal);

    checkoutBtn.setAttribute('aria-disabled', String(!cartState.canCheckout));
    checkoutBtn.style.opacity = cartState.canCheckout ? '1' : '.5';
    checkoutBtn.style.pointerEvents = cartState.canCheckout ? 'auto' : 'none';

    clearBtn.disabled = cartState.items.length === 0;

    if (cartState.items.length === 0) {
        const empty = makeElement('div', 'empty-cart');
        empty.append(
            makeElement('div', 'symbol', '🛍️'),
            makeElement('h3', '', 'Your cart is empty'),
            makeElement('p', '', 'Discover coffee, pastries and more from our menu.')
        );

        const link = makeElement('a', 'checkout-btn', 'Explore the Menu');
        link.href = 'menu.php';
        empty.append(link);
        cartItems.append(empty);
        return;
    }

    cartState.items.forEach(item => {
        const card = makeElement('article', 'cart-item');

        if (item.image) {
            const img = makeElement('img');
            img.src = item.image;
            img.alt = item.name;
            img.onerror = () => { img.style.visibility = 'hidden'; };
            card.append(img);
        } else {
            card.append(makeElement('div', 'symbol', '☕'));
        }

        const details = makeElement('div');
        details.append(
            makeElement('h3', 'item-name', item.name),
            makeElement('p', 'item-description', item.description || 'Freshly prepared at Sunrise Café.'),
            makeElement('div', 'item-price', money(item.price))
        );

        if (!item.available) {
            details.append(makeElement('p', 'unavailable', 'Currently unavailable. Remove this item to continue.'));
        }

        const controls = makeElement('div', 'item-controls');
        const quantity = makeElement('div', 'quantity');

        const minus = makeElement('button', '', '−');
        minus.type = 'button';
        minus.setAttribute('aria-label', 'Decrease quantity of ' + item.name);
        minus.disabled = item.quantity <= 1 || !item.available;
        minus.dataset.action = 'update';
        minus.dataset.id = item.id;
        minus.dataset.quantity = item.quantity - 1;

        const number = makeElement('span', '', item.quantity);

        const plus = makeElement('button', '', '+');
        plus.type = 'button';
        plus.setAttribute('aria-label', 'Increase quantity of ' + item.name);
        plus.disabled = item.quantity >= 99 || !item.available;
        plus.dataset.action = 'update';
        plus.dataset.id = item.id;
        plus.dataset.quantity = item.quantity + 1;

        quantity.append(minus, number, plus);

        const remove = makeElement('button', 'remove-btn', 'Remove');
        remove.type = 'button';
        remove.dataset.action = 'remove';
        remove.dataset.id = item.id;

        controls.append(quantity, remove);

        const line = makeElement('div', 'line-total', money(item.lineTotal));
        details.append(controls, line);
        card.append(details);
        cartItems.append(card);
    });
}

async function sendAction(action, extra = {}) {
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('action', action);

    Object.entries(extra).forEach(([key, value]) => {
        formData.append(key, value);
    });

    try {
        const response = await fetch('cart.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Unable to update your cart.');
        }

        cartState = result.cart;
        renderCart();
        showToast(result.message);
    } catch (error) {
        showToast(error.message || 'Please try again.');
    }
}

cartItems.addEventListener('click', event => {
    const button = event.target.closest('button[data-action]');
    if (!button) return;

    const action = button.dataset.action;
    const id = button.dataset.id;

    if (action === 'update') {
        sendAction('update', {
            item_id: id,
            quantity: button.dataset.quantity
        });
    } else if (action === 'remove') {
        sendAction('remove', { item_id: id });
    }
});

clearBtn.addEventListener('click', () => {
    if (cartState.items.length === 0) return;

    if (confirm('Are you sure you want to remove every item from your cart?')) {
        sendAction('clear');
    }
});

renderCart();
</script>
</body>
</html>
