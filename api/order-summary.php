<?php
session_start();
header('Content-Type: application/json');

require_once '../config/database.php';

// Dynamic fee mapping
$handling_fee_map = [
    'Food' => 30,
    'Eco Friendly' => 20,
    'Organic' => 35,
    'GreenChoice' => 25
];

$shipping_fee_map = [
    'Luzon' => 100,
    'Visayas' => 150,
    'Mindanao' => 200
];

// Function to determine region from address
function getRegionFromAddress($address) {
    $address_upper = strtoupper($address);
    if (strpos($address_upper, 'LUZON') !== false || 
        preg_match('/(NCR|METRO MANILA|MANILA|NUEVA ECIJA|LAGUNA|CAVITE|BATANGAS|BULACAN|BATAAN|ZAMBALES|PANGASINAN|LA UNION|ILOCOS NORTE|ILOCOS SUR|ABRA|IFUGAO|BENGUET|QUIRINO|MOUNTAIN PROVINCE|TARLAC|Nueva VIZCAYA|APAYAO|CAGAYAN|ISABELA|AURORA)/i', $address)) {
        return 'Luzon';
    } elseif (strpos($address_upper, 'VISAYAS') !== false || 
              preg_match('/(CEBU|BOHOL|NEGROS|PANAY|ILOILO|CAPIZ|ANTIQUE|GUIMARAS|SIQUIJOR|LEYTE|SOUTHERN LEYTE|SAMAR|EASTERN SAMAR|NORTHERN SAMAR)/i', $address)) {
        return 'Visayas';
    } elseif (strpos($address_upper, 'MINDANAO') !== false || 
              preg_match('/(DAVAO|COTABATO|LANAO|MISAMIS|BUKIDNON|SURIGAO|AGUSAN|COMPOSTELA|ZAMBOANGA|BASILAN|MAGUINDANAO|SULU)/i', $address)) {
        return 'Mindanao';
    }
    return 'Luzon'; // Default
}

$response = [
    'success' => false,
    'items' => [],
    'subtotal' => 0,
    'shipping_fee' => 100,
    'handling_fee' => 0,
    'total' => 0,
    'subtotal_ecocoins' => 0,
    'shipping_fee_ecocoins' => 100,
    'handling_fee_ecocoins' => 0,
    'total_ecocoins' => 0,
    'total_quantity' => 0
];

if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'buyer') {
    $buyer_id = $_SESSION['user_id'];
    
    // Fetch buyer address for shipping fee calculation
    $stmt = $pdo->prepare('SELECT address FROM Buyers WHERE buyer_id = ?');
    $stmt->execute([$buyer_id]);
    $buyer_row = $stmt->fetch();
    $buyer_address = $buyer_row ? $buyer_row['address'] : '';
    
    $stmt = $pdo->prepare('
        SELECT c.cart_id, c.quantity, p.product_id, p.name, p.price, cat.name as category_name
        FROM Cart c
        JOIN Products p ON c.product_id = p.product_id
        LEFT JOIN Categories cat ON p.category_id = cat.category_id
        WHERE c.buyer_id = ?
    ');
    $stmt->execute([$buyer_id]);
    $cart_items = $stmt->fetchAll();
    $subtotal = 0;
    $total_quantity = 0;
    $total_handling_fee = 0;
    
    foreach ($cart_items as $item) {
        $item_total = $item['price'] * $item['quantity'];
        $response['items'][] = [
            'name' => $item['name'],
            'quantity' => (int)$item['quantity'],
            'price' => (float)$item['price'],
            'total' => (float)$item_total
        ];
        $subtotal += $item_total;
        $total_quantity += $item['quantity'];
        
        // Add handling fee based on category
        $category = isset($item['category_name']) ? $item['category_name'] : 'Eco Friendly';
        $category_fee = isset($handling_fee_map[$category]) ? $handling_fee_map[$category] : 20;
        $total_handling_fee += $category_fee * $item['quantity'];
    }
    
    // Calculate shipping fee based on region
    $region = getRegionFromAddress($buyer_address);
    $shipping_fee = isset($shipping_fee_map[$region]) ? $shipping_fee_map[$region] : 100;
    $handling_fee = $total_handling_fee;
    $total = $subtotal + $shipping_fee + $handling_fee;
    
    // Calculate ecocoins totals (products with +20 per unit, plus fees)
    $subtotal_ecocoins = $subtotal + (20 * $total_quantity);
    $handling_fee_ecocoins = $handling_fee;
    $shipping_fee_ecocoins = $shipping_fee;
    $total_ecocoins = $subtotal_ecocoins + $shipping_fee_ecocoins + $handling_fee_ecocoins;
    $shipping_fee_ecocoins = $shipping_fee;
    $total_ecocoins = $subtotal_ecocoins + $shipping_fee_ecocoins + $handling_fee_ecocoins;
    
    $response['success'] = true;
    $response['subtotal'] = $subtotal;
    $response['shipping_fee'] = $shipping_fee;
    $response['handling_fee'] = $handling_fee;
    $response['total'] = $total;
    $response['subtotal_ecocoins'] = $subtotal_ecocoins;
    $response['shipping_fee_ecocoins'] = $shipping_fee_ecocoins;
    $response['handling_fee_ecocoins'] = $handling_fee_ecocoins;
    $response['total_ecocoins'] = $total_ecocoins;
    $response['total_quantity'] = $total_quantity;
}

echo json_encode($response);