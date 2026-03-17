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
    // Fetch order notifications for this seller
    $stmt = $pdo->prepare("
        SELECT 
            n.order_id,
            n.product_id,
            n.product_name,
            n.status,
            n.created_at,
            o.total_amount,
            o.buyer_id,
            b.fullname as buyer_name
        FROM notifications n
        LEFT JOIN Orders o ON n.order_id = o.order_id
        LEFT JOIN Buyers b ON o.buyer_id = b.buyer_id
        WHERE n.user_id = ? 
        AND n.user_type = 'seller' 
        AND n.type = 'order' 
        ORDER BY n.created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([$seller_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'orders' => $orders]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
