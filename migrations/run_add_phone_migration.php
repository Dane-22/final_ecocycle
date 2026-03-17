<?php
require_once 'config/database.php';

try {
    // Add phone_number column to admins table
    $pdo->exec("ALTER TABLE admins ADD COLUMN phone_number varchar(20) DEFAULT NULL AFTER email");
    echo "Phone number column added successfully.\n";
    
    // Update the ECSD head record with a phone number
    $stmt = $pdo->prepare("UPDATE admins SET phone_number = ? WHERE username = ?");
    $stmt->execute(['09123456789', 'ecsdhead']);
    echo "ECSD head phone number updated successfully.\n";
    
    echo "Migration completed successfully!";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
