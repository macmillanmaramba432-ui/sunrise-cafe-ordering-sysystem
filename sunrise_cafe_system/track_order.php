
<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getOrder(PDO $pdo, string $orderNumber): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, order_number, order_status, order_type,
                payment_status, subtotal, delivery_fee, total, created_at
         FROM orders
         WHERE order_number = ?
         LIMIT 1"
    );
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();

    if (!$order) {
        return null;
    }

    $itemsStmt = $pdo->prepare(
        "SELECT product_name, quantity, price, subtotal
         FROM order_items
         WHERE order_id = ?
         ORDER BY id ASC"
    );
    $itemsStmt->execute([$order['id']]);

    $order['items'] = $itemsStmt->fetchAll();

    return $order;
}

function publicOrderData(array $order): array {
    return [
        'order_number' => $order['order_number'],
        'order_status' => $order['order_status'],
        'order_type' => $order['order_type'],
        'payment_status' => $order['payment_status'],
        'subtotal' => (float)$order['subtotal'],
        'delivery_fee' => (float)$order['delivery_fee'],
        'total' => (float)$order['total'],
        'created_at' => $order['created_at'],
        'items' => array_map(function ($item) {
            return [
                'name' => $item['product_name'],
                'quantity' => (int)$item['quantity'],
                'price' => (float)$item['price'],
                'subtotal' => (float)$item['subtotal']
            ];
        }, $order['items'])
    ];
}

/* AJAX order lookup and status refresh */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'track_order') {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $orderNumber = strtoupper(trim($_POST['order_number'] ?? ''));

    if (
        $orderNumber === '' ||
        strlen($orderNumber) > 60 ||
        !preg_match('/^[A-Z0-9-]+$/', $orderNumber)
    ) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Please enter a valid order number.'
        ]);
        exit;
    }

    try {
        $order = getOrder($pdo, $orderNumber);

        if (!$order) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'We could not find that order. Check the order number and try again.'
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'order' => publicOrderData($order)
        ]);
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to check your order right now. Please try again.'
        ]);
    }

    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Track Your Order | Sunrise Café</title>

<style>
* { box-sizing: border-box; }

:root {
    --coffee: #633b26;
    --dark: #402719;
    --cream: #fff8ed;
    --orange: #e9a23b;
    --muted: #766d66;
    --border: #eadfd3;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: var(--cream);
    color: #30251f;
}

a { text-decoration: none; color: inherit; }

.navbar {
    background: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 17px 6%;
    box-shadow: 0 2px 12px #0000000b;
}

.brand { font-weight: 800; font-size: 22px; color: var(--coffee); }
.nav-links { display: flex; align-items: center; gap: 20px; }

.hero {
    text-align: center;
    padding: 45px 18px 25px;
}

.hero h1 {
    color: var(--dark);
    font-size: clamp(30px, 5vw, 42px);
    margin-bottom: 10px;
}

.hero p { color: var(--muted); line-height: 1.6; }

.container {
    width: min(850px, 92%);
    margin: 15px auto 60px;
}

.panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 25px;
    margin-bottom: 22px;
    box-shadow: 0 5px 22px #49301b08;
}

.panel h2 { margin-top: 0; color: var(--dark); }

.track-form {
    display: flex;
    gap: 10px;
}

.track-form input {
    flex: 1;
    min-width: 0;
    padding: 14px;
    border: 1px solid #d9cbbd;
    border-radius: 10px;
    outline: none;
}

.track-form input:focus {
    border-color: var(--orange);
    box-shadow: 0 0 0 3px #e9a23b22;
}

button {
    border: none;
    cursor: pointer;
    border-radius: 10px;
    font-weight: bold;
}

.track-btn {
    background: var(--coffee);
    color: white;
    padding: 14px 20px;
}

.track-btn:disabled { opacity: .6; cursor: wait; }

.message {
    display: none;
    margin-top: 15px;
    padding: 12px 14px;
    border-radius: 9px;
    line-height: 1.5;
}

.message.error {
    display: block;
    background: #fff0ed;
    color: #a72e24;
}

.message.info {
    display: block;
    background: #fff5df;
    color: #72501c;
}

.order-result { display: none; }

.order-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
}

.order-number { color: var(--muted); font-size: 14px; }
.order-number strong { display: block; color: var(--coffee); font-size: 20px; margin-top: 5px; }

.status-pill {
    display: inline-block;
    padding: 9px 13px;
    border-radius: 20px;
    background: #fff0cf;
    color: #805311;
    font-size: 13px;
    font-weight: bold;
}

.status-pill.completed { background: #e2f5e6; color: #226537; }
.status-pill.cancelled { background: #ffebeb; color: #a72e24; }

.progress {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    margin: 30px 0;
    position: relative;
}

.progress::before {
    content: "";
    position: absolute;
    top: 17px;
    left: 12%;
    right: 12%;
    height: 3px;
    background: #e7ddd1;
}

.progress-step {
    position: relative;
    text-align: center;
    z-index: 1;
    color: #978b80;
    font-size: 12px;
}

.progress-step .circle {
    width: 36px;
    height: 36px;
    margin: 0 auto 9px;
    display: grid;
    place-items: center;
    background: #eee7df;
    border: 3px solid white;
    border-radius: 50%;
    font-size: 15px;
    font-weight: bold;
}

.progress-step.active { color: var(--coffee); font-weight: bold; }
.progress-step.active .circle { background: var(--orange); color: #382416; }
.progress-step.done .circle { background: #427c4d; color: white; }

.cancelled-note {
    display: none;
    background: #fff0ed;
    color: #a72e24;
    padding: 14px;
    border-radius: 10px;
    margin: 15px 0;
}

.item-row, .total-row {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding: 12px 0;
    border-bottom: 1px solid #f0e8df;
}

.item-row span:first-child, .total-row span:first-child { color: var(--muted); }

.total-row.final {
    border-bottom: none;
    padding-top: 18px;
    font-size: 19px;
    font-weight: bold;
    color: var(--dark);
}

.details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 20px;
}

.detail-box {
    padding: 14px;
    border-radius: 10px;
    background: #fff9f0;
}

.detail-box span {
    display: block;
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 7px;
}

.refresh-note { color: var(--muted); font-size: 12px; margin-top: 20px; }

.links {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.link-btn {
    display: inline-block;
    background: var(--orange);
    color: #382416;
    padding: 13px 18px;
    border-radius: 10px;
    font-weight: bold;
}

.link-secondary {
    display: inline-block;
    padding: 13px 18px;
    border: 1px solid var(--coffee);
    color: var(--coffee);
    border-radius: 10px;
    font-weight: bold;
}

footer {
    background: var(--dark);
    color: white;
    text-align: center;
    padding: 25px 12px;
}

@media (max-width: 560px) {
    .navbar { padding: 15px 4%; }
    .brand { font-size: 18px; }
    .nav-links { gap: 12px; font-size: 13px; }
    .panel { padding: 18px; }
    .track-form { flex-direction: column; }
    .progress-step { font-size: 10px; }
    .progress-step .circle { width: 31px; height: 31px; }
    .progress::before { top: 15px; }
    .details-grid { grid-template-columns: 1fr; }
}
</style>
</head>

<body>
<nav class="navbar">
    <a class="brand" href="index.php">☀ Sunrise Café</a>
    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="menu.php">Menu</a>
        <a href="cart.php">Cart</a>
        <a href="reviews.php">Reviews</a>
    </div>
</nav>

<header class="hero">
    <h1>Track Your Order 📦</h1>
    <p>Find out what's happening with your Sunrise Café order.</p>
</header>

<main class="container">
    <section class="panel">
        <h2>Enter Your Order Number</h2>
        <p>Use the order number shown after checkout.</p>

        <form class="track-form" id="trackForm">
            <input
                type="text"
                id="orderNumber"
                name="order_number"
                placeholder="Example: SC202610241234ABCD"
                maxlength="60"
                required
                autocomplete="off"
                aria-label="Order number"
            >
            <button class="track-btn" id="trackBtn" type="submit">
                Track Order
            </button>
        </form>

        <div class="message" id="message" role="status" aria-live="polite"></div>
    </section>

    <section class="panel order-result" id="orderResult" aria-live="polite">
        <div class="order-top">
            <div class="order-number">
                Order number
                <strong id="displayOrderNumber"></strong>
            </div>
            <span class="status-pill" id="statusPill">Pending</span>
        </div>

        <div class="details-grid">
            <div class="detail-box">
                <span>Order type</span>
                <strong id="orderType">Pickup</strong>
            </div>
            <div class="detail-box">
                <span>Order placed</span>
                <strong id="orderDate">—</strong>
            </div>
            <div class="detail-box">
                <span>Payment status</span>
                <strong id="paymentStatus">Pending</strong>
            </div>
            <div class="detail-box">
                <span>Current status</span>
                <strong id="currentStatus">Pending</strong>
            </div>
        </div>

        <h3>Order progress</h3>
        <div class="progress" id="progress">
            <div class="progress-step" data-step="Pending">
                <div class="circle">1</div>
                <div>Received</div>
            </div>
            <div class="progress-step" data-step="Preparing">
                <div class="circle">2</div>
                <div>Preparing</div>
            </div>
            <div class="progress-step" data-step="Ready">
                <div class="circle">3</div>
                <div>Ready</div>
            </div>
            <div class="progress-step" data-step="Completed">
                <div class="circle">4</div>
                <div>Completed</div>
            </div>
        </div>

        <div class="cancelled-note" id="cancelledNote">
            This order has been cancelled. Please contact the café if you need assistance.
        </div>

        <h3>Items ordered</h3>
        <div id="orderItems"></div>

        <div class="total-row">
            <span>Subtotal</span>
            <strong id="subtotal"></strong>
        </div>
        <div class="total-row">
            <span>Delivery fee</span>
            <strong id="deliveryFee"></strong>
        </div>
        <div class="total-row final">
            <span>Total</span>
            <strong id="total"></strong>
        </div>

        <p class="refresh-note" id="refreshNote">
            Order status checks automatically every 10 seconds while this page is open.
        </p>
    </section>

    <section class="panel">
        <h2>Need something else?</h2>
        <div class="links">
            <a class="link-btn" href="menu.php">Order More Food</a>
            <a class="link-secondary" href="index.php">Back to Home</a>
        </div>
    </section>
</main>

<footer>
    <p>© <?= date('Y') ?> Sunrise Café. Thank you for ordering with us!</p>
</footer>

<script>
const form = document.getElementById('trackForm');
const input = document.getElementById('orderNumber');
const trackBtn = document.getElementById('trackBtn');
const message = document.getElementById('message');
const resultPanel = document.getElementById('orderResult');

let currentOrderNumber = '';
let refreshTimer = null;
let requestInProgress = false;

function money(value) {
    return 'KSh ' + Number(value).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function showMessage(text, type = 'error') {
    message.textContent = text;
    message.className = 'message ' + type;
}

function clearMessage() {
    message.textContent = '';
    message.className = 'message';
}

function setText(id, value) {
    document.getElementById(id).textContent = value ?? '';
}

function normalizeStatus(status) {
    const value = String(status || '').trim().toLowerCase();

    const known = {
        pending: 'Pending',
        preparing: 'Preparing',
        ready: 'Ready',
        completed: 'Completed',
        cancelled: 'Cancelled'
    };

    return known[value] || 'Pending';
}

function renderOrder(order) {
    resultPanel.style.display = 'block';

    setText('displayOrderNumber', order.order_number);
    setText('orderType', order.order_type || '—');
    setText('orderDate', order.created_at || '—');
    setText('paymentStatus', order.payment_status || '—');

    const status = normalizeStatus(order.order_status);
    setText('currentStatus', status);

    const pill = document.getElementById('statusPill');
    pill.textContent = status;
    pill.className = 'status-pill';

    if (status === 'Completed') pill.classList.add('completed');
    if (status === 'Cancelled') pill.classList.add('cancelled');

    const steps = ['Pending', 'Preparing', 'Ready', 'Completed'];
    const currentIndex = steps.indexOf(status);

    document.querySelectorAll('.progress-step').forEach((step, index) => {
        step.classList.remove('active', 'done');

        if (status !== 'Cancelled' && currentIndex >= 0) {
            if (index < currentIndex) {
                step.classList.add('done');
                step.querySelector('.circle').textContent = '✓';
            } else if (index === currentIndex) {
                step.classList.add('active');
                step.querySelector('.circle').textContent = index + 1;
            } else {
                step.querySelector('.circle').textContent = index + 1;
            }
        } else {
            step.querySelector('.circle').textContent = index + 1;
        }
    });

    document.getElementById('cancelledNote').style.display =
        status === 'Cancelled' ? 'block' : 'none';

    const itemsContainer = document.getElementById('orderItems');
    itemsContainer.replaceChildren();

    (order.items || []).forEach(item => {
        const row = document.createElement('div');
        row.className = 'item-row';

        const name = document.createElement('span');
        name.textContent = `${item.name} × ${item.quantity}`;

        const amount = document.createElement('strong');
        amount.textContent = money(item.subtotal);

        row.append(name, amount);
        itemsContainer.append(row);
    });

    setText('subtotal', money(order.subtotal));
    setText('deliveryFee', money(order.delivery_fee));
    setText('total', money(order.total));

    const refreshNote = document.getElementById('refreshNote');

    if (status === 'Completed' || status === 'Cancelled') {
        refreshNote.textContent = 'This order has reached its final status. Automatic status checks have stopped.';
        stopPolling();
    } else {
        refreshNote.textContent = 'Order status checks automatically every 10 seconds while this page is open.';
    }
}

async function lookupOrder(orderNumber, automatic = false) {
    if (requestInProgress || !orderNumber) return;

    requestInProgress = true;

    if (!automatic) {
        trackBtn.disabled = true;
        trackBtn.textContent = 'Checking...';
        clearMessage();
    }

    try {
        const body = new FormData();
        body.append('action', 'track_order');
        body.append('order_number', orderNumber);

        const response = await fetch('track_order.php', {
            method: 'POST',
            body,
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            if (!automatic) {
                resultPanel.style.display = 'none';
                showMessage(data.message || 'Could not find that order.');
            }
            return;
        }

        renderOrder(data.order);
        currentOrderNumber = orderNumber;

        if (!automatic) {
            clearMessage();
            if (!refreshTimer) {
                refreshTimer = setInterval(() => {
                    if (!document.hidden && currentOrderNumber) {
                        lookupOrder(currentOrderNumber, true);
                    }
                }, 10000);
            }
        }
    } catch (error) {
        if (!automatic) {
            showMessage('Unable to connect. Check your connection and try again.');
        }
    } finally {
        requestInProgress = false;

        if (!automatic) {
            trackBtn.disabled = false;
            trackBtn.textContent = 'Track Order';
        }
    }
}

function stopPolling() {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
}

form.addEventListener('submit', event => {
    event.preventDefault();

    const orderNumber = input.value.trim().toUpperCase();

    if (!/^[A-Z0-9-]{1,60}$/.test(orderNumber)) {
        showMessage('Enter the order number shown after checkout.');
        return;
    }

    stopPolling();
    currentOrderNumber = '';
    resultPanel.style.display = 'none';

    lookupOrder(orderNumber);
});

document.addEventListener('visibilitychange', () => {
    if (!document.hidden && currentOrderNumber) {
        lookupOrder(currentOrderNumber, true);
    }
});

window.addEventListener('beforeunload', stopPolling);
</script>
</body>
</html>
