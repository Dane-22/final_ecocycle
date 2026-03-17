<?php
require_once 'config/database.php';

try {
    // Add weight column if it doesn't exist
    $pdo->exec("ALTER TABLE products ADD COLUMN weight DECIMAL(10,2) DEFAULT 0.00 AFTER stock_quantity");
    echo "Weight column added successfully.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Weight column already exists.\n";
    } else {
        echo "Error adding weight column: " . $e->getMessage() . "\n";
    }
}

try {
    // Add size column if it doesn't exist
    $pdo->exec("ALTER TABLE products ADD COLUMN size ENUM('small', 'medium', 'large') DEFAULT 'small' AFTER weight");
    echo "Size column added successfully.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Size column already exists.\n";
    } else {
        echo "Error adding size column: " . $e->getMessage() . "\n";
    }
}

try {
    // Add shipping_type column if it doesn't exist
    $pdo->exec("ALTER TABLE products ADD COLUMN shipping_type ENUM('weight_based', 'size_based') DEFAULT 'weight_based' AFTER size");
    echo "Shipping type column added successfully.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Shipping type column already exists.\n";
    } else {
        echo "Error adding shipping type column: " . $e->getMessage() . "\n";
    }
}

echo "Database schema update completed!\n";
?>
