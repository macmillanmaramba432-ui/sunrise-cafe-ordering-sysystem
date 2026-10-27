
<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

$message = '';
$messageType = 'info';

if (empty($_SESSION['review_csrf'])) {
    $_SESSION['review_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['review_csrf'], $token)) {
        $message = 'Your session has expired. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        $orderNumber = strtoupper(trim($_POST['order_number'] ?? ''));
        $email = strtolower(trim($_POST['email'] ?? ''));
        $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
        $comment = trim($_POST['comment'] ?? '');

        if (
            $orderNumber === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            $rating === false ||
            $rating === null ||
            $rating < 1 ||
            $rating > 5 ||
            strlen($comment) < 3 ||
            strlen($comment) > 2000
        ) {
            $message = 'Enter a valid order number, email, rating, and comment (3–2,000 characters).';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare(
                "SELECT o.id, o.customer_id, o.order_status
                 FROM orders o
                 INNER JOIN customers c ON c.id = o.customer_id
                 WHERE o.order_number = ?
                   AND LOWER(c.email) = ?
                 LIMIT 1"
            );
            $stmt->execute([$orderNumber, $email]);
            $order = $stmt->fetch();

            if (!$order) {
                $message = 'We could not verify that order number and email combination.';
                $messageType = 'error';
            } elseif (strtolower($order['order_status']) !== 'completed') {
                $message = 'You can review your order after its status is Completed.';
                $messageType = 'error';
            } else {
                $check = $pdo->prepare("SELECT id FROM reviews WHERE order_id = ?");
                $check->execute([$order['id']]);

                if ($check->fetch()) {
                    $message = 'A review has already been submitted for this order.';
                    $messageType = 'error';
                } else {
                    try {
                        $insert = $pdo->prepare(
                            "INSERT INTO reviews
                                (order_id, customer_id, rating, comment, status)
                             VALUES (?, ?, ?, ?, 'Pending')"
                        );
                        $insert->execute([
                            $order['id'],
                            $order['customer_id'],
                            $rating,
                            $comment
                        ]);

                        $message = 'Thank you! Your review has been submitted and is awaiting approval.';
                        $messageType = 'success';
                        $_SESSION['review_csrf'] = bin2hex(random_bytes(32));
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') {
                            $message = 'A review has already been submitted for this order.';
                        } else {
                            error_log($e->getMessage());
                            $message = 'We could not save your review. Please try again.';
                        }
                        $messageType = 'error';
                    }
                }
            }
        }
    }
}

$reviewsStmt = $pdo->query(
    "SELECT r.rating, r.comment, r.created_at, c.name
     FROM reviews r
     INNER JOIN customers c ON c.id = r.customer_id
     WHERE r.status = 'Approved'
     ORDER BY r.created_at DESC
     LIMIT 12"
);
$reviews = $reviewsStmt->fetchAll();

$averageStmt = $pdo->query(
    "SELECT ROUND(AVG(rating), 1) AS average_rating, COUNT(*) AS total_reviews
     FROM reviews WHERE status = 'Approved'"
);
$ratingSummary = $averageStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Reviews | Sunrise Café</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #fffaf4;
            color: #34251e;
        }
        header {
            background: #432719;
            color: white;
            padding: 20px;
            text-align: center;
        }
        header a { color: #ffe1a8; text-decoration: none; }
        .container { width: min(1050px, 92%); margin: 30px auto; }
        .intro { text-align: center; margin-bottom: 25px; }
        .intro h1 { color: #75411f; margin-bottom: 8px; }
        .summary { color: #a55d22; font-weight: bold; }
        .layout {
            display: grid;
            grid-template-columns: minmax(280px, 0.9fr) minmax(280px, 1.1fr);
            gap: 24px;
            align-items: start;
        }
        .panel {
            background: white;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 5px 22px #43271912;
        }
        label { display: block; margin: 15px 0 7px; font-weight: bold; }
        input, textarea, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dfd2c8;
            border-radius: 8px;
            font: inherit;
        }
        textarea { min-height: 120px; resize: vertical; }
        button {
            margin-top: 18px;
            padding: 13px 18px;
            border: 0;
            border-radius: 8px;
            background: #9b5528;
            color: white;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
        }
        button:hover { background: #75411f; }
        .notice { padding: 13px; border-radius: 8px; margin-bottom: 18px; }
        .success { background: #e6f6e9; color: #205b2b; }
        .error { background: #ffebeb; color: #8c2020; }
        .review {
            border-bottom: 1px solid #eee2d8;
            padding: 16px 0;
        }
        .review:last-child { border-bottom: 0; }
        .stars { color: #d28a14; letter-spacing: 2px; }
        .review p { line-height: 1.6; overflow-wrap: anywhere; }
        .review small { color: #76675f; }
        .hint { font-size: 13px; color: #76675f; line-height: 1.5; }
        @media (max-width: 700px) {
            .layout { grid-template-columns: 1fr; }
            .panel { padding: 18px; }
        }
    </style>
</head>
<body>
<header>
    <h2>☕ Sunrise Café</h2>
    <nav>
        <a href="index.php">Home</a> |
        <a href="menu.php">Menu</a> |
        <a href="cart.php">My Cart</a> |
        <a href="track_order.php">Track Order</a>
    </nav>
</header>

<main class="container">
    <section class="intro">
        <h1>Share Your Sunrise Café Experience</h1>
        <p>Your feedback helps us serve you better.</p>
        <div class="summary">
            <?php if ((int)$ratingSummary['total_reviews'] > 0): ?>
                ★ <?= htmlspecialchars((string)$ratingSummary['average_rating']) ?>/5
                from <?= (int)$ratingSummary['total_reviews'] ?> approved reviews
            <?php else: ?>
                Be the first to leave an approved review!
            <?php endif; ?>
        </div>
    </section>

    <?php if ($message !== ''): ?>
        <div class="notice <?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : '') ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="layout">
        <section class="panel">
            <h2>Write a Review</h2>
            <p class="hint">Only completed orders can be reviewed. Use the order number and email address you entered at checkout. Your review will be visible after approval.</p>

            <form method="POST" action="reviews.php">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($_SESSION['review_csrf']) ?>">

                <label for="order_number">Order Number</label>
                <input id="order_number" name="order_number" required
                       maxlength="60" placeholder="e.g. SC202610241234AB12">

                <label for="email">Checkout Email Address</label>
                <input id="email" type="email" name="email" required maxlength="254"
                       placeholder="you@example.com">

                <label for="rating">Your Rating</label>
                <select id="rating" name="rating" required>
                    <option value="">Choose a rating</option>
                    <option value="5">★★★★★ — Excellent</option>
                    <option value="4">★★★★☆ — Very good</option>
                    <option value="3">★★★☆☆ — Good</option>
                    <option value="2">★★☆☆☆ — Fair</option>
                    <option value="1">★☆☆☆☆ — Poor</option>
                </select>

                <label for="comment">Your Comments</label>
                <textarea id="comment" name="comment" required minlength="3"
                          maxlength="2000" placeholder="Tell us what you enjoyed or how we can improve..."></textarea>

                <button type="submit">Submit Review</button>
            </form>
        </section>

        <section class="panel">
            <h2>What Our Customers Say</h2>

            <?php if (!$reviews): ?>
                <p>No approved reviews yet. Check back soon!</p>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                    <article class="review">
                        <div class="stars" aria-label="<?= (int)$review['rating'] ?> out of 5 stars">
                            <?= str_repeat('★', (int)$review['rating']) ?>
                            <?= str_repeat('☆', 5 - (int)$review['rating']) ?>
                        </div>
                        <p><?= nl2br(htmlspecialchars($review['comment'])) ?></p>
                        <small>
                            <?= htmlspecialchars($review['name']) ?> ·
                            <?= htmlspecialchars(date('d M Y', strtotime($review['created_at']))) ?>
                        </small>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</main>
</body>
</html>
