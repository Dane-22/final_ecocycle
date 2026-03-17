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
    $unread_count = 0;
    
    // Count unread order notifications
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE user_id = ? AND user_type = 'buyer' AND read_status = 0
    ");
    $stmt->execute([$buyer_id]);
    $unread_count += $stmt->fetch()['count'] ?? 0;
    
    // Count unread redemption notifications (if applicable)
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM bardproductsredeem 
            WHERE user_id = ? AND user_type = ? 
            AND (br.status = 'approved' OR br.status = 'rejected')
            AND viewed = 0
        ");
        $stmt->execute([$buyer_id, 'buyer']);
        $unread_count += $stmt->fetch()['count'] ?? 0;
    } catch (PDOException $e) {
        // Table might not exist, continue without redemption count
    }
    
    echo json_encode([
        'success' => true, 
        'unread_count' => (int)$unread_count
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
