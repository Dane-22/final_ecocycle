<?php
// Test script to verify buyer notification system
require_once 'config/database.php';
require_once 'config/session_check.php';

session_start();
// Simulate buyer login for testing
$_SESSION['user_id'] = 2;
$_SESSION['user_type'] = 'buyer';

echo "<h2>Buyer Notification System Test</h2>";

// Test 1: Check if buyer notifications exist
echo "<h3>1. Checking buyer notifications...</h3>";
try {
    $notifications = [];
    
    // Order notifications
    $stmt = $pdo->prepare("
        SELECT order_id, product_name as name, status, product_id, created_at as date, type 
        FROM notifications 
        WHERE user_id = ? AND user_type = 'buyer' AND type = 'order' 
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $notifications[] = $order;
    }
    
    echo "✅ Found " . count($notifications) . " order notifications<br>";
    echo "<br>Recent buyer notifications:<br>";
    foreach ($notifications as $notif) {
        echo "- Order #{$notif['order_id']}: {$notif['name']} - Status: {$notif['status']}<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// Test 2: Test buyer notifications API
echo "<h3>2. Testing buyer notifications API...</h3>";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/Ecocycle/api/notifications-buyer.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
$response = curl_exec($ch);
curl_close($ch);

echo "API Response: $response<br>";
$data = json_decode($response, true);
if ($data && $data['success']) {
    echo "✅ Buyer notifications found: " . count($data['notifications']) . "<br>";
} else {
    echo "❌ API failed: " . ($data['error'] ?? 'Unknown error') . "<br>";
}

// Test 3: Test buyer notification count API
echo "<h3>3. Testing buyer notification count API...</h3>";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/Ecocycle/api/notifications-buyer-count.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
$response = curl_exec($ch);
curl_close($ch);

echo "API Response: $response<br>";
$data = json_decode($response, true);
if ($data && $data['success']) {
    echo "✅ Total unread buyer notifications: {$data['unread_count']}<br>";
} else {
    echo "❌ API failed: " . ($data['error'] ?? 'Unknown error') . "<br>";
}

echo "<h3>✅ Test Complete!</h3>";
echo "<p>To test the complete buyer notification flow:</p>";
echo "<ol>";
echo "<li>Buyer places an order and order status changes</li>";
echo "<li>Seller updates order status (shipped, delivered, etc.)</li>";
echo "<li>Buyer should see notification badge on bell icon</li>";
echo "<li>Buyer clicks bell to see order status notifications</li>";
echo "<li>Use 'Mark all as read' to clear notifications</li>";
echo "</ol>";

// Show sample notification creation SQL for buyers
echo "<h3>4. Sample Buyer Notification Creation:</h3>";
echo "<pre style='background:#f5f5f5;padding:10px;border-radius:5px;'>";
echo "-- When seller updates order status
INSERT INTO notifications (user_id, user_type, order_id, product_id, product_name, status, type, read_status) 
VALUES (?, 'buyer', ?, ?, ?, 'shipped', 'order', 0)";
echo "</pre>";
?>
