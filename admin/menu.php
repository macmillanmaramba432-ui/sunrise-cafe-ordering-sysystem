
<?php
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['menu_csrf'])) {
    $_SESSION['menu_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$categories = ['Coffee', 'Pastries', 'Cold Drinks', 'Juices', 'Other'];

// Process menu actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['menu_csrf'], $token)) {
        $error = 'Invalid request. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $id = filter_var(
                    $_POST['id'] ?? '0',
                    FILTER_VALIDATE_INT
                );

                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $price = filter_var(
                    $_POST['price'] ?? '',
                    FILTER_VALIDATE_FLOAT
                );
                $image = trim(
                    $_POST['image'] ?? 'assets/images/My Design.jpeg'
                );

                if ($id === false || $id < 0) {
                    throw new RuntimeException('Invalid menu item ID.');
                }

                if ($name === '' || strlen($name) > 150) {
                    throw new RuntimeException(
                        'Enter a product name of up to 150 characters.'
                    );
                }

                if ($price === false || $price < 0 ||
                    !is_finite((float)$price)) {
                    throw new RuntimeException(
                        'Enter a valid, non-negative price.'
                    );
                }

                if ($category === '' || strlen($category) > 100) {
                    throw new RuntimeException(
                        'Enter a valid category.'
                    );
                }

                if (strlen($description) > 10000) {
                    throw new RuntimeException(
                        'The description is too long.'
                    );
                }

                if ($image === '') {
                    $image = 'assets/images/My Design.jpeg';
                }

                if (strlen($image) > 255) {
                    throw new RuntimeException(
                        'The image path is too long.'
                    );
                }

                if ($id > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE menu_items
                         SET name = ?, description = ?, price = ?,
                             category = ?, image = ?
                         WHERE id = ?"
                    );

                    $stmt->execute([
                        $name, $description, $price,
                        $category, $image, $id
                    ]);

                    if ($stmt->rowCount() === 0) {
                        $check = $pdo->prepare(
                            "SELECT id FROM menu_items WHERE id = ?"
                        );
                        $check->execute([$id]);

                        if (!$check->fetch()) {
                            throw new RuntimeException(
                                'Menu item not found.'
                            );
                        }
                    }

                    $message = 'Menu item updated successfully.';
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO menu_items
                         (name, description, price, category, image, available)
                         VALUES (?, ?, ?, ?, ?, 1)"
                    );

                    $stmt->execute([
                        $name, $description, $price,
                        $category, $image
                    ]);

                    $message = 'Menu item added successfully.';
                }

                // Prevent accidental form resubmission.
                $_SESSION['menu_csrf'] = bin2hex(random_bytes(32));
                header('Location: menu.php?message=' . urlencode($message));
                exit;
            }

            if ($action === 'toggle') {
                $id = filter_var(
                    $_POST['id'] ?? '',
                    FILTER_VALIDATE_INT
                );

                if ($id === false || $id < 1) {
                    throw new RuntimeException('Invalid menu item ID.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE menu_items
                     SET available = CASE
                         WHEN available = 1 THEN 0 ELSE 1
                     END
                     WHERE id = ?"
                );
                $stmt->execute([$id]);

                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare(
                        "SELECT id FROM menu_items WHERE id = ?"
                    );
                    $check->execute([$id]);

                    if (!$check->fetch()) {
                        throw new RuntimeException(
                            'Menu item not found.'
                        );
                    }
                }

                $_SESSION['menu_csrf'] = bin2hex(random_bytes(32));
                header('Location: menu.php?message=' .
                    urlencode('Availability updated.'));
                exit;
            }

            throw new RuntimeException('Unknown action.');
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $error = 'The menu item could not be saved. Check your input and database.';
        }
    }
}

if (isset($_GET['message']) && is_string($_GET['message'])) {
    $message = $_GET['message'];
}

// Load item for editing
$editItem = null;

if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId !== false && $editId > 0) {
        $stmt = $pdo->prepare(
            "SELECT * FROM menu_items WHERE id = ?"
        );
        $stmt->execute([$editId]);
        $editItem = $stmt->fetch();

        if (!$editItem) {
            $error = 'Menu item not found.';
        }
    }
}

// Load all menu items
$stmt = $pdo->query(
    "SELECT id, name, description, price, category, image, available
     FROM menu_items
     ORDER BY id DESC"
);
$items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Menu | Sunrise Café</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .menu-form {
            max-width: 700px;
        }

        .menu-form label {
            display: block;
            font-weight: bold;
            color: #5b321d;
        }

        .menu-form textarea {
            min-height: 90px;
            resize: vertical;
        }

        .form-actions,
        .item-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .item-actions form {
            margin: 0;
        }

        .secondary-btn {
            background: #777;
        }

        .available-label {
            color: #176b2c;
            font-weight: bold;
        }

        .unavailable-label {
            color: #9b2020;
            font-weight: bold;
        }

        .product-image {
            width: 65px;
            height: 55px;
            object-fit: cover;
            border-radius: 6px;
        }

        .message {
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 6px;
            background: #d9f5df;
            color: #176b2c;
        }

        .error {
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 6px;
            background: #ffe0e0;
            color: #9b2020;
        }
    </style>
</head>

<body>

<header class="header">
    <h1>Sunrise Café</h1>
    <p>Menu Management</p>
</header>

<div class="layout">
    <nav class="sidebar">
        <a href="index.php">Dashboard</a>
        <a href="menu.php" class="active">Manage Menu</a>
        <a href="orders.php">Manage Orders</a>
        <a href="logout.php">Logout</a>
    </nav>

    <main class="main">
        <h2><?= $editItem ? 'Edit Menu Item' : 'Add Menu Item' ?></h2>

        <?php if ($message !== ''): ?>
            <div class="message"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="section">
            <form class="menu-form" method="POST" action="menu.php">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION['menu_csrf']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id"
                       value="<?= e($editItem['id'] ?? 0) ?>">

                <label for="name">Product Name</label>
                <input id="name" name="name" type="text"
                       maxlength="150" required
                       value="<?= e($editItem['name'] ?? '') ?>">

                <label for="description">Description</label>
                <textarea id="description" name="description"
                          maxlength="10000"><?= e($editItem['description'] ?? '') ?></textarea>

                <label for="price">Price (KSh)</label>
                <input id="price" name="price" type="number"
                       min="0" step="0.01" required
                       value="<?= e($editItem['price'] ?? '') ?>">

                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <?php
                    $currentCategory = $editItem['category'] ?? 'Coffee';
                    $knownCategory = in_array(
                        $currentCategory,
                        $categories,
                        true
                    );
                    ?>
                    <?php if (!$knownCategory): ?>
                        <option value="<?= e($currentCategory) ?>" selected>
                            <?= e($currentCategory) ?>
                        </option>
                    <?php endif; ?>

                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category) ?>"
                            <?= $currentCategory === $category ? 'selected' : '' ?>>
                            <?= e($category) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="image">Image Path</label>
                <input id="image" name="image" type="text"
                       maxlength="255"
                       placeholder="assets/images/My Design.jpeg"
                       value="<?= e($editItem['image'] ?? 'assets/images/My Design.jpeg') ?>">

                <p>
                    Enter a path to an image already stored in your project.
                    Example: <code>assets/images/My Design.jpeg</code>
                </p>

                <div class="form-actions">
                    <button type="submit">
                        <?= $editItem ? 'Save Changes' : 'Add Menu Item' ?>
                    </button>

                    <?php if ($editItem): ?>
                        <a class="btn secondary-btn" href="menu.php">
                            Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="section">
            <h2>All Menu Items (<?= count($items) ?>)</h2>

            <?php if (empty($items)): ?>
                <p class="empty">No menu items have been added yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Availability</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($item['image'])): ?>
                                        <span><?= e($item['image']) ?></span>
                                    <?php else: ?>
                                        <span>No image</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <strong><?= e($item['name']) ?></strong><br>
                                    <small><?= e($item['description'] ?? '') ?></small>
                                </td>

                                <td><?= e($item['category']) ?></td>

                                <td>
                                    KSh <?= number_format((float)$item['price'], 2) ?>
                                </td>

                                <td>
                                    <?php if ((int)$item['available'] === 1): ?>
                                        <span class="available-label">Available</span>
                                    <?php else: ?>
                                        <span class="unavailable-label">Unavailable</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="item-actions">
                                        <a class="btn"
                                           href="menu.php?edit=<?= (int)$item['id'] ?>">
                                            Edit
                                        </a>

                                        <form method="POST" action="menu.php">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($_SESSION['menu_csrf']) ?>">
                                            <input type="hidden" name="action"
                                                   value="toggle">
                                            <input type="hidden" name="id"
                                                   value="<?= (int)$item['id'] ?>">

                                            <button type="submit"
                                                class="secondary-btn">
                                                <?= (int)$item['available'] === 1
                                                    ? 'Disable'
                                                    : 'Enable' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </main>
</div>

</body>
</html>
