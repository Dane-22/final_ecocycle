<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../config/session_check.php';

if (!isBuyer()) {
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit();
}

$buyer_id = getCurrentUserId();

try {
    $marked_count = 0;
    
    // Mark order notifications as read
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET read_status = 1 
        WHERE user_id = ? AND user_type = 'buyer' AND read_status = 0
    ");
    $stmt->execute([$buyer_id]);
    $marked_count += $stmt->rowCount();
    
    // Mark redemption notifications as read (if applicable)
    try {
        $stmt = $pdo->prepare("
            UPDATE bardproductsredeem 
            SET viewed = 1 
            WHERE user_id = ? AND user_type = ? AND viewed = 0
        ");
        $stmt->execute([$buyer_id, 'buyer']);
        $marked_count += $stmt->rowCount();
    } catch (PDOException $e) {
        // Table might not exist, continue without marking redemption notifications
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'All notifications marked as read',
        'marked_count' => $marked_count
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
