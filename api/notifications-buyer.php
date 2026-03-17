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
    // Fetch all notifications for this buyer
    $notifications = [];
    
    // Order status notifications
    $stmt = $pdo->prepare("
        SELECT order_id, product_name as name, status, product_id, created_at as date, type 
        FROM notifications 
        WHERE user_id = ? AND user_type = 'buyer' AND type = 'order' 
        ORDER BY created_at DESC LIMIT 20
    ");
    $stmt->execute([$buyer_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $notifications[] = $order;
    }
    
    // Redemption notifications (if applicable)
    try {
        $stmt = $pdo->prepare("
            SELECT br.order_id, bp.name, br.status, br.redeemed_at as date, 'redeem' as type 
            FROM bardproductsredeem br 
            JOIN bardproducts bp ON br.product_id = bp.id 
            WHERE br.user_id = ? AND br.user_type = ? 
            AND (br.status = 'approved' OR br.status = 'rejected') 
            ORDER BY br.redeemed_at DESC LIMIT 10
        ");
        $stmt->execute([$buyer_id, 'buyer']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $redeem) {
            $notifications[] = $redeem;
        }
    } catch (PDOException $e) {
        // Table might not exist, continue without redemption notifications
    }
    
    // Sort by date
    usort($notifications, function($a, $b) { 
        return strtotime($b['date']) - strtotime($a['date']); 
    });
    
    echo json_encode(['success' => true, 'notifications' => array_slice($notifications, 0, 20)]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
