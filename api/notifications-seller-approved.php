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
    // Fetch approval notifications for this seller by joining with products table
    $stmt = $pdo->prepare("
        SELECT p.product_id, p.name as product_name, p.status, p.updated_at as created_at 
        FROM products p
        WHERE p.seller_id = ? AND p.status = 'active' AND p.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY p.updated_at DESC LIMIT 20
    ");
    $stmt->execute([$seller_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'products' => $products]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
