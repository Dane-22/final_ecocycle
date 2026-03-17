<?php
// Test script to verify notification system
require_once 'config/database.php';
require_once 'config/session_check.php';

session_start();
// Simulate seller login for testing
$_SESSION['user_id'] = 1;
$_SESSION['user_type'] = 'seller';

echo "<h2>Notification System Test</h2>";

// Test 1: Check if notifications table exists and has data
echo "<h3>1. Checking notifications table...</h3>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM notifications");
    $count = $stmt->fetch()['total'];
    echo "✅ Notifications table exists with $count records<br>";
    
    // Show sample data
    $stmt = $pdo->query("SELECT * FROM notifications WHERE user_type = 'seller' LIMIT 5");
    $seller_notifications = $stmt->fetchAll();
    echo "<br>Seller notifications:<br>";
    foreach ($seller_notifications as $notif) {
        echo "- ID: {$notif['id']}, Product: {$notif['product_name']}, Type: {$notif['type']}, Read: " . ($notif['read_status'] ? 'Yes' : 'No') . "<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// Test 2: Test notification count API
echo "<h3>2. Testing notification count API...</h3>";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/Ecocycle/api/notifications-count.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
$response = curl_exec($ch);
curl_close($ch);

echo "API Response: $response<br>";
$data = json_decode($response, true);
if ($data && $data['success']) {
    echo "✅ Unread notifications: {$data['unread_count']}<br>";
} else {
    echo "❌ API failed: " . ($data['error'] ?? 'Unknown error') . "<br>";
}

// Test 3: Test approval notifications API
echo "<h3>3. Testing approval notifications API...</h3>";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/Ecocycle/api/notifications-seller-approved.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
$response = curl_exec($ch);
curl_close($ch);

echo "API Response: $response<br>";
$data = json_decode($response, true);
if ($data && $data['success']) {
    echo "✅ Approval notifications found: " . count($data['products']) . "<br>";
} else {
    echo "❌ API failed: " . ($data['error'] ?? 'Unknown error') . "<br>";
}

echo "<h3>✅ Test Complete!</h3>";
echo "<p>If all tests pass, the notification system should work correctly.</p>";
echo "<p>To test with a real product approval:</p>";
echo "<ol>";
echo "<li>Have a seller submit a new product</li>";
echo "<li>Have admin approve the product</li>";
echo "<li>Check seller dashboard for notification badge</li>";
echo "</ol>";
?>
