
<?php
session_start();

if (
    !isset($_SESSION['admin_logged_in']) ||
    $_SESSION['admin_logged_in'] !== true
) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* Handle recipe changes */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        $error = 'Invalid session token. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $menuItemId = filter_var(
                    $_POST['menu_item_id'] ?? '',
                    FILTER_VALIDATE_INT
                );

                $inventoryItemId = filter_var(
                    $_POST['inventory_item_id'] ?? '',
                    FILTER_VALIDATE_INT
                );

                $quantityInput = $_POST['quantity_required'] ?? '';

                if (
                    !$menuItemId || $menuItemId < 1 ||
                    !$inventoryItemId || $inventoryItemId < 1
                ) {
                    throw new RuntimeException(
                        'Select a valid menu item and ingredient.'
                    );
                }

                if (
                    !is_numeric($quantityInput) ||
                    !is_finite((float)$quantityInput) ||
                    (float)$quantityInput <= 0 ||
                    (float)$quantityInput > 999999999
                ) {
                    throw new RuntimeException(
                        'Ingredient quantity must be greater than zero.'
                    );
                }

                $quantity = round((float)$quantityInput, 3);

                if ($quantity <= 0) {
                    throw new RuntimeException(
                        'Quantity is too small. Enter at least 0.001.'
                    );
                }

                $stmt = $pdo->prepare(
                    "SELECT id FROM menu_items WHERE id = ?"
                );
                $stmt->execute([$menuItemId]);

                if (!$stmt->fetchColumn()) {
                    throw new RuntimeException(
                        'The selected menu item was not found.'
                    );
                }

                $stmt = $pdo->prepare(
                    "SELECT id FROM inventory_items WHERE id = ?"
                );
                $stmt->execute([$inventoryItemId]);

                if (!$stmt->fetchColumn()) {
                    throw new RuntimeException(
                        'The selected inventory ingredient was not found.'
                    );
                }

                /*
                 * If the ingredient is already in this recipe,
                 * update its required quantity.
                 */
                $stmt = $pdo->prepare("
                    INSERT INTO menu_item_ingredients
                        (menu_item_id, inventory_item_id, quantity_required)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        quantity_required = VALUES(quantity_required)
                ");

                $stmt->execute([
                    $menuItemId,
                    $inventoryItemId,
                    $quantity
                ]);

                header('Location: recipes.php?saved=1');
                exit;
            }

            if ($action === 'delete') {
                $recipeId = filter_var(
                    $_POST['recipe_id'] ?? '',
                    FILTER_VALIDATE_INT
                );

                if (!$recipeId || $recipeId < 1) {
                    throw new RuntimeException('Invalid recipe record.');
                }

                $stmt = $pdo->prepare("
                    DELETE FROM menu_item_ingredients
                    WHERE id = ?
                ");
                $stmt->execute([$recipeId]);

                header('Location: recipes.php?deleted=1');
                exit;
            }

            if ($action !== 'save' && $action !== 'delete') {
                throw new RuntimeException('Unknown action requested.');
            }
        } catch (Throwable $e) {
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to save this recipe. Please check your database tables.';
        }
    }
}

if (isset($_GET['saved'])) {
    $message = 'Recipe ingredient saved successfully.';
} elseif (isset($_GET['deleted'])) {
    $message = 'Ingredient removed from the recipe.';
}

/* Load available menu items and ingredients */
try {
    $menuStmt = $pdo->query("
        SELECT id, name, category, price
        FROM menu_items
        ORDER BY name ASC
    ");
    $menuItems = $menuStmt->fetchAll(PDO::FETCH_ASSOC);

    $inventoryStmt = $pdo->query("
        SELECT id, name, category, unit, quantity, min_quantity
        FROM inventory_items
        ORDER BY name ASC
    ");
    $inventoryItems = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);

    $recipeStmt = $pdo->query("
        SELECT
            mii.id,
            mii.menu_item_id,
            mii.inventory_item_id,
            mii.quantity_required,
            mi.name AS menu_name,
            mi.category AS menu_category,
            ii.name AS ingredient_name,
            ii.category AS ingredient_category,
            ii.unit AS ingredient_unit,
            ii.quantity AS stock_quantity,
            ii.min_quantity
        FROM menu_item_ingredients mii
        INNER JOIN menu_items mi
            ON mi.id = mii.menu_item_id
        INNER JOIN inventory_items ii
            ON ii.id = mii.inventory_item_id
        ORDER BY mi.name ASC, ii.name ASC
    ");

    $recipes = $recipeStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $menuItems = [];
    $inventoryItems = [];
    $recipes = [];
    $error = 'Could not load recipe information. Ensure the menu_item_ingredients table was created successfully.';
}

/* Group recipe ingredients by menu item */
$groupedRecipes = [];

foreach ($recipes as $recipe) {
    $menuId = (int)$recipe['menu_item_id'];

    if (!isset($groupedRecipes[$menuId])) {
        $groupedRecipes[$menuId] = [
            'name' => $recipe['menu_name'],
            'category' => $recipe['menu_category'],
            'ingredients' => []
        ];
    }

    $groupedRecipes[$menuId]['ingredients'][] = $recipe;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recipe Management | Sunrise Café</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f6f8;
            color: #26352b;
            font-family: Arial, sans-serif;
        }

        header {
            padding: 20px 24px;
            background: #24352c;
            color: white;
        }

        header h1 {
            margin: 0 0 7px;
            font-size: 25px;
        }

        header p {
            margin: 0;
            color: #dbe5de;
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 12px 22px;
            background: white;
            border-bottom: 1px solid #ddd;
        }

        nav a {
            padding: 9px 12px;
            color: #24352c;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }

        nav a:hover {
            background: #e7eee9;
        }

        main {
            max-width: 1250px;
            margin: 25px auto;
            padding: 0 20px 40px;
        }

        .panel {
            background: white;
            border-radius: 9px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }

        .panel h2 {
            margin: 0 0 17px;
            font-size: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 14px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd3cd;
            border-radius: 5px;
            background: white;
            font: inherit;
        }

        button, .button {
            display: inline-block;
            border: 0;
            border-radius: 5px;
            padding: 10px 14px;
            background: #286444;
            color: white;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }

        button:hover, .button:hover {
            background: #1c4d32;
        }

        .delete-button {
            background: #a52d2d;
        }

        .delete-button:hover {
            background: #812020;
        }

        .message, .error, .info {
            padding: 13px 15px;
            margin-bottom: 18px;
            border-radius: 5px;
        }

        .message {
            background: #e2f5e8;
            color: #175d31;
        }

        .error {
            background: #fde8e7;
            color: #8b2020;
        }

        .info {
            background: #edf4ff;
            color: #244b78;
        }

        .recipe-card {
            border: 1px solid #dfe6e1;
            border-radius: 7px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .recipe-heading {
            padding: 13px 15px;
            background: #edf3ee;
        }

        .recipe-heading strong {
            font-size: 16px;
        }

        .recipe-heading span {
            display: block;
            margin-top: 5px;
            font-size: 13px;
            color: #647168;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #e6ebe7;
            font-size: 14px;
            vertical-align: middle;
        }

        th {
            background: #f8faf8;
        }

        .stock-low {
            color: #a52d2d;
            font-weight: bold;
        }

        .stock-ok {
            color: #176233;
        }

        .muted {
            color: #68756c;
            font-size: 13px;
        }

        .delete-form {
            margin: 0;
        }

        @media (max-width: 700px) {
            main {
                padding: 0 12px 25px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }

            header {
                padding: 17px 14px;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>Recipe & Ingredient Management</h1>
    <p>Sunrise Café Management System</p>
</header>

<nav>
    <a href="index.php">Dashboard</a>
    <a href="menu.php">Menu Management</a>
    <a href="inventory.php">Inventory</a>
    <a href="suppliers.php">Suppliers</a>
    <a href="purchases.php">Purchases</a>
    <a href="purchase_payments.php">Supplier Payments</a>
    <a href="purchase_reports.php">Purchase Reports</a>
    <a href="logout.php">Logout</a>
</nav>

<main>

    <?php if ($message !== ''): ?>
        <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Add or Update Recipe Ingredient</h2>

        <p class="info">
            Enter the amount of an inventory ingredient needed to prepare
            one unit of a menu item. Use the same unit configured in
            Inventory. For example, if coffee beans are measured in kg,
            enter 0.020 for 20 grams.
        </p>

        <?php if (empty($menuItems) || empty($inventoryItems)): ?>
            <p class="error">
                Add at least one menu item and one inventory ingredient
                before creating a recipe.
            </p>
        <?php else: ?>
            <form method="POST" action="recipes.php">
                <input type="hidden" name="csrf_token"
                       value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="save">

                <div class="form-grid">
                    <div class="field">
                        <label for="menu_item_id">Menu Item *</label>
                        <select name="menu_item_id" id="menu_item_id" required>
                            <option value="">Choose a menu item</option>

                            <?php foreach ($menuItems as $item): ?>
                                <option value="<?= (int)$item['id'] ?>">
                                    <?= h($item['name']) ?>
                                    — <?= h($item['category'] ?? 'Uncategorized') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="inventory_item_id">Ingredient *</label>
                        <select
                            name="inventory_item_id"
                            id="inventory_item_id"
                            required
                        >
                            <option value="">Choose an ingredient</option>

                            <?php foreach ($inventoryItems as $item): ?>
                                <option value="<?= (int)$item['id'] ?>">
                                    <?= h($item['name']) ?>
                                    (<?= h($item['unit']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="quantity_required">
                            Quantity Required Per Serving *
                        </label>
                        <input
                            type="number"
                            name="quantity_required"
                            id="quantity_required"
                            min="0.001"
                            max="999999999"
                            step="0.001"
                            placeholder="e.g. 0.020"
                            required
                        >
                    </div>

                    <div class="field">
                        <label>Action</label>
                        <button type="submit">Save Recipe Ingredient</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Configured Recipes</h2>

        <p class="muted">
            Each recipe lists the ingredients required for one menu item.
            Adding an existing ingredient again updates its quantity.
        </p>

        <?php if (empty($groupedRecipes)): ?>
            <p>No recipes have been configured yet.</p>
        <?php else: ?>

            <?php foreach ($groupedRecipes as $menuId => $group): ?>
                <div class="recipe-card">
                    <div class="recipe-heading">
                        <strong><?= h($group['name']) ?></strong>
                        <span>
                            Category: <?= h($group['category'] ?? 'Uncategorized') ?>
                            · <?= count($group['ingredients']) ?> ingredient(s)
                        </span>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th>Required Per Serving</th>
                                <th>Current Stock</th>
                                <th>Stock Unit</th>
                                <th>Stock Status</th>
                                <th>Action</th>
                            </tr>
                            </thead>

                            <tbody>
                            <?php foreach ($group['ingredients'] as $ingredient): ?>
                                <?php
                                $stock = (float)$ingredient['stock_quantity'];
                                $minimum = (float)$ingredient['min_quantity'];
                                $low = $stock <= $minimum;
                                ?>
                                <tr>
                                    <td><?= h($ingredient['ingredient_name']) ?></td>
                                    <td>
                                        <?= number_format(
                                            (float)$ingredient['quantity_required'],
                                            3
                                        ) ?>
                                    </td>
                                    <td><?= number_format($stock, 3) ?></td>
                                    <td><?= h($ingredient['ingredient_unit']) ?></td>
                                    <td class="<?= $low ? 'stock-low' : 'stock-ok' ?>">
                                        <?= $low ? 'Low stock' : 'Available' ?>
                                    </td>
                                    <td>
                                        <form
                                            method="POST"
                                            action="recipes.php"
                                            class="delete-form"
                                            onsubmit="return confirm('Remove this ingredient from the recipe?');"
                                        >
                                            <input type="hidden"
                                                   name="csrf_token"
                                                   value="<?= h($csrfToken) ?>">
                                            <input type="hidden"
                                                   name="action"
                                                   value="delete">
                                            <input type="hidden"
                                                   name="recipe_id"
                                                   value="<?= (int)$ingredient['id'] ?>">
                                            <button
                                                type="submit"
                                                class="delete-button"
                                            >Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </section>

</main>
</body>
</html>
