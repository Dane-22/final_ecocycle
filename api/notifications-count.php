<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../config/session_check.php';

if (!isSeller()) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit();
}

$seller_id = getCurrentUserId();

try {
    // Ensure the notifications table has a 'read' column
    $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS read_status TINYINT(1) DEFAULT 0");
    
    // Count unread notifications for this seller
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as unread_count 
        FROM notifications 
        WHERE user_id = ? AND user_type = 'seller' AND read_status = 0
    ");
    $stmt->execute([$seller_id]);
    $unread_count = $stmt->fetch()['unread_count'] ?? 0;
    
    echo json_encode([
        'success' => true, 
        'unread_count' => (int)$unread_count
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
