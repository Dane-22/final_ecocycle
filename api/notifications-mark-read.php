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
    // First, ensure the notifications table has a 'read' column
    $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS read_status TINYINT(1) DEFAULT 0");
    
    // Mark all notifications for this seller as read
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET read_status = 1 
        WHERE user_id = ? AND user_type = 'seller' AND read_status = 0
    ");
    $result = $stmt->execute([$seller_id]);
    
    if ($result) {
        $affected_rows = $stmt->rowCount();
        echo json_encode([
            'success' => true, 
            'message' => 'All notifications marked as read',
            'marked_count' => $affected_rows
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update notifications']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
