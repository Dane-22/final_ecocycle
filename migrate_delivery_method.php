<?php
require_once 'config/database.php';

echo '<h2>Database Migration: Add delivery_method to Orders</h2>';

try {
    // Check if delivery_method column exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM Orders LIKE 'delivery_method'");
    $stmt->execute();
    $exists = $stmt->fetch();
    
    if (!$exists) {
        $sql = "ALTER TABLE `orders` ADD COLUMN `delivery_method` VARCHAR(50) DEFAULT 'delivery' AFTER `payment_method`";
        $pdo->exec($sql);
        echo '<p style="color: green;">✓ Successfully added delivery_method column to Orders table</p>';
    } else {
        echo '<p style="color: blue;">→ delivery_method column already exists in Orders table</p>';
    }
} catch (PDOException $e) {
    echo '<p style="color: red;">✗ Error adding delivery_method column: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<p><a href="home.php">Return to Home</a></p>';
?>
