
<?php
require_once __DIR__ . '/../config/database.php';

echo "Database connection successful!<br>";

$stmt = $pdo->query("SELECT DATABASE()");
echo "Connected database: " . htmlspecialchars($stmt->fetchColumn()) . "<br>";

$stmt = $pdo->query("SHOW TABLES LIKE 'menu_items'");

if ($stmt->fetch()) {
    echo "The menu_items table exists and PHP can access it.";
} else {
    echo "The menu_items table was not found in this database.";
}
?>
