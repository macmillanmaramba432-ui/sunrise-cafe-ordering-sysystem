
<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['admin_reviews_csrf'])) {
    $_SESSION['admin_reviews_csrf'] = bin2hex(random_bytes(32));
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $reviewId = filter_var($_POST['review_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['admin_reviews_csrf'], $token)
    ) {
        $message = 'Invalid session token. Refresh the page and try again.';
    } elseif (!$reviewId || !in_array($status, ['Pending', 'Approved', 'Hidden'], true)) {
        $message = 'Invalid review or status.';
    } else {
        $stmt = $pdo->prepare("UPDATE reviews SET status = ? WHERE id = ?");
        $stmt->execute([$status, $reviewId]);
        $message = $stmt->rowCount() > 0
            ? 'Review status updated.'
            : 'No changes were made. The review may already have that status.';
        $_SESSION['admin_reviews_csrf'] = bin2hex(random_bytes(32));
    }
}

$filter = $_GET['status'] ?? 'All';
$allowedFilters = ['All', 'Pending', 'Approved', 'Hidden'];

if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'All';
}

$sql = "SELECT
            r.id, r.rating, r.comment, r.status, r.created_at,
            o.order_number,
            c.name AS customer_name,
            c.email AS customer_email
        FROM reviews r
        INNER JOIN orders o ON o.id = r.order_id
        INNER JOIN customers c ON c.id = r.customer_id";

$params = [];

if ($filter !== 'All') {
    $sql .= " WHERE r.status = ?";
    $params[] = $filter;
}

$sql .= " ORDER BY
            CASE r.status
                WHEN 'Pending' THEN 1
                WHEN 'Approved' THEN 2
                ELSE 3
            END,
            r.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

$countStmt = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'Pending') AS pending,
        SUM(status = 'Approved') AS approved,
        SUM(status = 'Hidden') AS hidden
     FROM reviews"
);
$counts = $countStmt->fetch();

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reviews | Sunrise Café Admin</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f5f3f0;
            color: #30251f;
            font-family: Arial, sans-serif;
        }
        header {
            background: #432719;
            color: white;
            padding: 20px 4%;
        }
        header h1 { margin: 0 0 12px; font-size: 24px; }
        header a { color: #ffe1a8; text-decoration: none; margin-right: 18px; }
        main { width: min(1200px, 94%); margin: 28px auto; }
        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }
        .stat, .review-card {
            background: white;
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 3px 15px #00000008;
        }
        .stat span { display: block; color: #74665e; margin-bottom: 8px; }
        .stat strong { font-size: 26px; color: #87491f; }
        .toolbar { margin: 18px 0; display: flex; gap: 10px; flex-wrap: wrap; }
        .toolbar a {
            background: white;
            border: 1px solid #e3d8ce;
            padding: 9px 13px;
            border-radius: 7px;
            color: #432719;
            text-decoration: none;
        }
        .toolbar a.active { background: #87491f; color: white; }
        .review-card { margin-bottom: 16px; }
        .review-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .stars { color: #c9820c; letter-spacing: 2px; }
        .status {
            display: inline-block;
            border-radius: 20px;
            padding: 5px 10px;
            background: #f1e8df;
            font-size: 12px;
            font-weight: bold;
        }
        .review-comment { line-height: 1.6; overflow-wrap: anywhere; }
        .meta { color: #75675f; font-size: 13px; line-height: 1.7; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 15px; }
        button {
            border: 0;
            border-radius: 7px;
            padding: 10px 13px;
            cursor: pointer;
            font-weight: bold;
        }
        .approve { background: #217a43; color: white; }
        .hide { background: #a33b35; color: white; }
        .pending { background: #e9e0d6; color: #432719; }
        .notice { background: #e7f4e9; padding: 12px; border-radius: 8px; }
        .empty { background: white; padding: 25px; border-radius: 10px; }
        @media (max-width: 650px) {
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body>
<header>
    <h1>Sunrise Café — Review Management</h1>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="menu.php">Manage Menu</a>
        <a href="orders.php">Manage Orders</a>
    </nav>
</header>

<main>
    <h2>Customer Reviews</h2>

    <?php if ($message !== ''): ?>
        <p class="notice"><?= h($message) ?></p>
    <?php endif; ?>

    <section class="stats">
        <div class="stat">
            <span>Total Reviews</span>
            <strong><?= (int)($counts['total'] ?? 0) ?></strong>
        </div>
        <div class="stat">
            <span>Pending</span>
            <strong><?= (int)($counts['pending'] ?? 0) ?></strong>
        </div>
        <div class="stat">
            <span>Approved</span>
            <strong><?= (int)($counts['approved'] ?? 0) ?></strong>
        </div>
        <div class="stat">
            <span>Hidden</span>
            <strong><?= (int)($counts['hidden'] ?? 0) ?></strong>
        </div>
    </section>

    <div class="toolbar">
        <?php foreach (['All', 'Pending', 'Approved', 'Hidden'] as $option): ?>
            <a class="<?= $filter === $option ? 'active' : '' ?>"
               href="?status=<?= urlencode($option) ?>">
                <?= h($option) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$reviews): ?>
        <div class="empty">No reviews found for this filter.</div>
    <?php else: ?>
        <?php foreach ($reviews as $review): ?>
            <article class="review-card">
                <div class="review-top">
                    <div>
                        <strong><?= h($review['customer_name']) ?></strong>
                        <div class="meta">
                            <?= h($review['customer_email']) ?><br>
                            Order: <?= h($review['order_number']) ?><br>
                            Submitted: <?= h(date('d M Y, H:i', strtotime($review['created_at']))) ?>
                        </div>
                    </div>
                    <div>
                        <div class="stars">
                            <?= str_repeat('★', (int)$review['rating']) ?>
                            <?= str_repeat('☆', 5 - (int)$review['rating']) ?>
                        </div>
                        <p><span class="status"><?= h($review['status']) ?></span></p>
                    </div>
                </div>

                <p class="review-comment"><?= nl2br(h($review['comment'])) ?></p>

                <form method="POST" class="actions">
                    <input type="hidden" name="csrf_token"
                           value="<?= h($_SESSION['admin_reviews_csrf']) ?>">
                    <input type="hidden" name="review_id"
                           value="<?= (int)$review['id'] ?>">

                    <button class="approve" name="status" value="Approved">
                        Approve & Publish
                    </button>
                    <button class="hide" name="status" value="Hidden">
                        Hide Review
                    </button>
                    <button class="pending" name="status" value="Pending">
                        Mark Pending
                    </button>
                </form>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
