
<?php
session_start();

require_once dirname(__DIR__) . '/config/database.php';

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if (empty($_SESSION['menu_csrf'])) {
    $_SESSION['menu_csrf'] = bin2hex(random_bytes(32));
}

/* AJAX: Add an item to the cart without reloading */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'add_to_cart') {

    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals(
        $_SESSION['menu_csrf'],
        $_POST['csrf_token'] ?? ''
    )) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Session expired. Please refresh the page.'
        ]);
        exit;
    }

    $itemId = filter_var(
        $_POST['item_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$itemId || $itemId < 1) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid menu item.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT id, name FROM menu_items
         WHERE id = ? AND available = 1"
    );
    $stmt->execute([$itemId]);
    $product = $stmt->fetch();

    if (!$product) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'This item is currently unavailable.'
        ]);
        exit;
    }

    // Store quantities as integers, keyed by menu item ID.
    $currentQuantity = $_SESSION['cart'][$itemId] ?? 0;

    // Support an older cart format containing quantity arrays.
    if (is_array($currentQuantity)) {
        $currentQuantity = $currentQuantity['quantity'] ?? 0;
    }

    $currentQuantity = max(0, (int) $currentQuantity);

    if ($currentQuantity >= 99) {
        echo json_encode([
            'success' => false,
            'message' => 'Maximum quantity is 99 per item.'
        ]);
        exit;
    }

    $_SESSION['cart'][$itemId] = $currentQuantity + 1;

    $cartCount = 0;
    foreach ($_SESSION['cart'] as $cartItem) {
        $cartCount += is_array($cartItem)
            ? (int) ($cartItem['quantity'] ?? 0)
            : (int) $cartItem;
    }

    echo json_encode([
        'success' => true,
        'message' => $product['name'] . ' added to cart!',
        'cartCount' => $cartCount
    ]);
    exit;
}

/* Load available menu items */
$stmt = $pdo->query(
    "SELECT id, name, description, price, category, image
     FROM menu_items
     WHERE available = 1
     ORDER BY name ASC"
);
$menuItems = $stmt->fetchAll();

$categories = [];
foreach ($menuItems as $item) {
    $category = trim($item['category'] ?? '');
    if ($category !== '') {
        $categories[$category] = true;
    }
}
$categories = array_keys($categories);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

$cartCount = 0;
foreach ($_SESSION['cart'] as $cartItem) {
    $cartCount += is_array($cartItem)
        ? (int) ($cartItem['quantity'] ?? 0)
        : (int) $cartItem;
}

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function menuImage($filename) {
    $filename = trim((string) $filename);

    if ($filename === '') {
        return '../assets/images/cafe-placeholder.jpg';
    }

    // Only allow filenames from the project's image folder.
    return '../assets/images/' . rawurlencode(basename($filename));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Menu | Sunrise Café</title>

    <style>
        * { box-sizing: border-box; }

        :root {
            --coffee: #633b26;
            --coffee-dark: #402719;
            --cream: #fff8ed;
            --orange: #e9a23b;
            --text: #30251f;
            --muted: #766d66;
            --white: #ffffff;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--cream);
            color: var(--text);
        }

        a { color: inherit; text-decoration: none; }
        button, input, select { font: inherit; }

        .navbar {
            background: var(--white);
            padding: 17px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            box-shadow: 0 2px 12px #0000000b;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .brand {
            font-size: 22px;
            font-weight: 800;
            color: var(--coffee);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 23px;
        }

        .nav-links a:hover { color: #bd7622; }

        .cart-link {
            background: var(--coffee);
            color: white !important;
            padding: 11px 17px;
            border-radius: 25px;
        }

        .hero {
            padding: 55px 20px 40px;
            text-align: center;
            background: linear-gradient(135deg, #f9e5c5, #fff8ed);
        }

        .hero h1 {
            font-size: clamp(32px, 5vw, 48px);
            margin: 0 0 12px;
            color: var(--coffee-dark);
        }

        .hero p {
            color: var(--muted);
            line-height: 1.7;
            margin: 0;
        }

        .container {
            width: min(1180px, 92%);
            margin: 35px auto 60px;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 22px;
        }

        .search {
            flex: 1 1 260px;
            min-width: 0;
            padding: 14px 16px;
            border: 1px solid #ded4c8;
            border-radius: 12px;
            background: white;
            outline: none;
        }

        .search:focus {
            border-color: var(--orange);
            box-shadow: 0 0 0 3px #e9a23b25;
        }

        .sort {
            padding: 13px;
            border: 1px solid #ded4c8;
            border-radius: 12px;
            background: white;
        }

        .filters {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }

        .filter-btn {
            border: 1px solid #e0d1c0;
            background: white;
            color: var(--coffee);
            padding: 10px 17px;
            border-radius: 25px;
            cursor: pointer;
            transition: .2s;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: var(--coffee);
            color: white;
            border-color: var(--coffee);
        }

        .results-info {
            color: var(--muted);
            margin-bottom: 18px;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px;
        }

        .product {
            background: white;
            border-radius: 17px;
            overflow: hidden;
            box-shadow: 0 5px 20px #49301b0b;
            transition: transform .25s, box-shadow .25s;
        }

        .product:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 28px #49301b18;
        }

        .product img {
            display: block;
            width: 100%;
            height: 215px;
            object-fit: cover;
            background: #f1e5d7;
        }

        .product-body { padding: 19px; }

        .category-label {
            color: #a56b2b;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .product h3 {
            margin: 9px 0;
            font-size: 20px;
        }

        .description {
            min-height: 42px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.5;
        }

        .product-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-top: 18px;
        }

        .price {
            font-size: 20px;
            font-weight: 800;
            color: var(--coffee);
            white-space: nowrap;
        }

        .add-btn {
            border: none;
            background: var(--orange);
            color: #352214;
            padding: 11px 13px;
            border-radius: 9px;
            font-weight: bold;
            cursor: pointer;
            transition: .2s;
        }

        .add-btn:hover { background: #f2b754; }
        .add-btn:disabled { opacity: .6; cursor: wait; }

        .empty {
            grid-column: 1 / -1;
            text-align: center;
            padding: 45px 15px;
            background: white;
            border-radius: 15px;
            color: var(--muted);
        }

        .toast {
            position: fixed;
            right: 20px;
            bottom: 22px;
            background: #263c2d;
            color: white;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 8px 25px #0002;
            opacity: 0;
            transform: translateY(15px);
            pointer-events: none;
            transition: .25s;
            z-index: 20;
            max-width: calc(100% - 40px);
        }

        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        footer {
            background: var(--coffee-dark);
            color: white;
            text-align: center;
            padding: 25px 15px;
        }

        @media (max-width: 850px) {
            .menu-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 560px) {
            .navbar { padding: 15px 4%; }
            .brand { font-size: 18px; }
            .nav-links { gap: 12px; font-size: 13px; }
            .menu-grid { grid-template-columns: 1fr; }
            .product img { height: 230px; }
            .hero { padding: 40px 16px 30px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                transition-duration: .01ms !important;
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a class="brand" href="index.php">☀ Sunrise Café</a>

    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="menu.php">Menu</a>
        <a class="cart-link" href="cart.php">
            🛒 Cart (<span id="cartCount"><?= $cartCount ?></span>)
        </a>
        <a href="reviews.php">Reviews</a>
    </div>
</nav>

<header class="hero">
    <h1>Explore Our Menu</h1>
    <p>Freshly prepared favourites, delicious coffee and treats made for you.</p>
</header>

<main class="container">
    <div class="toolbar">
        <input
            class="search"
            type="search"
            id="searchInput"
            placeholder="🔍 Search coffee, burgers, pastries..."
            aria-label="Search menu"
        >

        <select class="sort" id="sortSelect" aria-label="Sort menu">
            <option value="default">Sort: Recommended</option>
            <option value="low">Price: Low to High</option>
            <option value="high">Price: High to Low</option>
            <option value="name">Name: A to Z</option>
        </select>
    </div>

    <div class="filters" id="filters">
        <button class="filter-btn active" data-category="all">
            All Items
        </button>

        <?php foreach ($categories as $category): ?>
            <button
                class="filter-btn"
                data-category="<?= e($category) ?>"
            ><?= e($category) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="results-info" id="resultsInfo" aria-live="polite">
        Showing <?= count($menuItems) ?> menu items
    </div>

    <section class="menu-grid" id="menuGrid">
        <?php foreach ($menuItems as $item): ?>
            <article
                class="product"
                data-name="<?= e(strtolower($item['name'])) ?>"
                data-category="<?= e($item['category'] ?? '') ?>"
                data-price="<?= e($item['price']) ?>"
            >
                <img
                    src="<?= e(menuImage($item['image'] ?? '')) ?>"
                    alt="<?= e($item['name']) ?>"
                    loading="lazy"
                    onerror="this.onerror=null;this.src='../assets/images/cafe-placeholder.jpg';"
                >

                <div class="product-body">
                    <div class="category-label">
                        <?= e($item['category'] ?: 'Café Favourite') ?>
                    </div>

                    <h3><?= e($item['name']) ?></h3>

                    <p class="description">
                        <?= e($item['description'] ?: 'Freshly prepared at Sunrise Café.') ?>
                    </p>

                    <div class="product-bottom">
                        <span class="price">
                            KSh <?= number_format((float)$item['price'], 2) ?>
                        </span>

                        <button
                            class="add-btn"
                            data-id="<?= (int)$item['id'] ?>"
                        >
                            + Add to Cart
                        </button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if (!$menuItems): ?>
            <div class="empty">
                <h3>Our menu is being updated.</h3>
                <p>Please check back soon.</p>
            </div>
        <?php endif; ?>
    </section>
</main>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<footer>
    <p>© <?= date('Y') ?> Sunrise Café. Freshness in every bite.</p>
    <p><a href="index.php">Home</a> · <a href="cart.php">Your Cart</a></p>
</footer>

<script>
const csrfToken = <?= json_encode($_SESSION['menu_csrf']) ?>;

const searchInput = document.getElementById('searchInput');
const sortSelect = document.getElementById('sortSelect');
const menuGrid = document.getElementById('menuGrid');
const resultsInfo = document.getElementById('resultsInfo');
const toast = document.getElementById('toast');

let selectedCategory = 'all';
let toastTimer;

function showToast(message) {
    toast.textContent = message;
    toast.classList.add('show');

    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.classList.remove('show');
    }, 2800);
}

function filterMenu() {
    const query = searchInput.value.trim().toLowerCase();
    const products = [...menuGrid.querySelectorAll('.product')];

    products.sort((a, b) => {
        const priceA = Number(a.dataset.price);
        const priceB = Number(b.dataset.price);

        switch (sortSelect.value) {
            case 'low': return priceA - priceB;
            case 'high': return priceB - priceA;
            case 'name':
                return a.dataset.name.localeCompare(b.dataset.name);
            default: return 0;
        }
    });

    let visible = 0;

    products.forEach(product => {
        const matchesName = product.dataset.name.includes(query);
        const matchesCategory =
            selectedCategory === 'all' ||
            product.dataset.category.toLowerCase() ===
                selectedCategory.toLowerCase();

        const show = matchesName && matchesCategory;
        product.hidden = !show;

        if (show) visible++;
        menuGrid.appendChild(product);
    });

    let empty = document.getElementById('noResults');

    if (visible === 0) {
        if (!empty) {
            empty = document.createElement('div');
            empty.id = 'noResults';
            empty.className = 'empty';
            empty.innerHTML =
                '<h3>No matching items found</h3><p>Try another search or category.</p>';
            menuGrid.appendChild(empty);
        }
        empty.hidden = false;
    } else if (empty) {
        empty.hidden = true;
    }

    resultsInfo.textContent =
        `Showing ${visible} of ${products.length} menu items`;
}

searchInput.addEventListener('input', filterMenu);
sortSelect.addEventListener('change', filterMenu);

document.querySelectorAll('.filter-btn').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelector('.filter-btn.active')
            ?.classList.remove('active');

        button.classList.add('active');
        selectedCategory = button.dataset.category;
        filterMenu();
    });
});

menuGrid.addEventListener('click', async event => {
    const button = event.target.closest('.add-btn');
    if (!button) return;

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Adding...';

    try {
        const formData = new FormData();
        formData.append('action', 'add_to_cart');
        formData.append('item_id', button.dataset.id);
        formData.append('csrf_token', csrfToken);

        const response = await fetch('menu.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Could not add item.');
        }

        document.getElementById('cartCount').textContent =
            result.cartCount;

        showToast(result.message);
        button.textContent = '✓ Added';
        setTimeout(() => {
            button.textContent = originalText;
        }, 900);

    } catch (error) {
        showToast(error.message || 'Something went wrong. Please try again.');
        button.textContent = originalText;
    } finally {
        button.disabled = false;
    }
});
</script>

</body>
</html>
