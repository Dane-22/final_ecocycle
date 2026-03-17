<?php
require_once 'config/database.php';

echo "<h3>Checking delivery_method column in Orders table...</h3>";

try {
    // Check if delivery_method column exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM Orders LIKE 'delivery_method'");
    $stmt->execute();
    $column = $stmt->fetch();
    
    if ($column) {
        echo "<p style='color: green;'>✅ delivery_method column exists!</p>";
        echo "<pre>" . print_r($column, true) . "</pre>";
    } else {
        echo "<p style='color: orange;'>⚠️ delivery_method column does not exist. Adding it now...</p>";
        
        // Add the column
        $sql = "ALTER TABLE `orders` ADD COLUMN `delivery_method` VARCHAR(50) DEFAULT 'delivery' AFTER `payment_method`";
        $pdo->exec($sql);
        
        echo "<p style='color: green;'>✅ delivery_method column added successfully!</p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<p><a href='home.php'>← Back to Home</a></p>";
?>
