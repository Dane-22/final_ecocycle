<?php
require_once 'config/database.php';

try {
    // Add delivery_method column to Orders table
    $pdo->exec("ALTER TABLE Orders ADD COLUMN delivery_method varchar(20) DEFAULT 'delivery' AFTER payment_method");
    echo "Delivery method column added successfully.\n";
    
    echo "Migration completed successfully!";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
