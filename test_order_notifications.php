<?php
// Test script to verify order notification system
require_once 'config/database.php';
require_once 'config/session_check.php';

session_start();
// Simulate seller login for testing
$_SESSION['user_id'] = 1;
$_SESSION['user_type'] = 'seller';

echo "<h2>Order Notification System Test</h2>";

// Test 1: Check if order notifications exist
echo "<h3>1. Checking order notifications...</h3>";
try {
    $stmt = $pdo->prepare("
        SELECT 
            n.order_id,
            n.product_name,
            n.status,
            n.created_at,
            o.total_amount,
            b.fullname as buyer_name
        FROM notifications n
        LEFT JOIN Orders o ON n.order_id = o.order_id
        LEFT JOIN Buyers b ON o.buyer_id = b.buyer_id
        WHERE n.user_type = 'seller' AND n.type = 'order'
        ORDER BY n.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $order_notifications = $stmt->fetchAll();
    
    echo "✅ Found " . count($order_notifications) . " order notifications<br>";
    echo "<br>Recent order notifications:<br>";
    foreach ($order_notifications as $notif) {
        echo "- Order #{$notif['order_id']}: {$notif['product_name']} - {$notif['buyer_name']} - ₱{$notif['total_amount']}<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// Test 2: Test order notifications API
echo "<h3>2. Testing order notifications API...</h3>";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/Ecocycle/api/notifications-seller-manageorders.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
$response = curl_exec($ch);
curl_close($ch);

echo "API Response: $response<br>";
$data = json_decode($response, true);
if ($data && $data['success']) {
    echo "✅ Order notifications found: " . count($data['orders']) . "<br>";
} else {
    echo "❌ API failed: " . ($data['error'] ?? 'Unknown error') . "<br>";
}

// Test 3: Test notification count API (should include orders)
echo "<h3>3. Testing notification count API...</h3>";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/Ecocycle/api/notifications-count.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
$response = curl_exec($ch);
curl_close($ch);

echo "API Response: $response<br>";
$data = json_decode($response, true);
if ($data && $data['success']) {
    echo "✅ Total unread notifications: {$data['unread_count']}<br>";
} else {
    echo "❌ API failed: " . ($data['error'] ?? 'Unknown error') . "<br>";
}

echo "<h3>✅ Test Complete!</h3>";
echo "<p>To test the complete order notification flow:</p>";
echo "<ol>";
echo "<li>Buyer places an order at <a href='http://localhost/Ecocycle/buycheckout.php'>buycheckout.php</a></li>";
echo "<li>Order is processed and notification is created</li>";
echo "<li>Seller should see notification badge on bell icon</li>";
echo "<li>Seller clicks bell to see 'New Orders Received' section</li>";
echo "<li>Click order to view details and manage</li>";
echo "</ol>";

// Show sample notification creation SQL
echo "<h3>4. Sample Order Notification Creation:</h3>";
echo "<pre style='background:#f5f5f5;padding:10px;border-radius:5px;'>";
echo "INSERT INTO notifications (user_id, user_type, order_id, product_id, product_name, status, type, read_status) 
VALUES (?, 'seller', ?, ?, ?, 'pending', 'order', 0)";
echo "</pre>";
?>
