<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    header('Location: login.php');
    exit();
}

// Get order details
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$payment_method = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';

if ($order_id <= 0) {
    header('Location: home.php');
    exit();
}

// Fetch order details
try {
    // Fetch order info and buyer info
    $stmt = $pdo->prepare('
        SELECT o.*, b.fullname as buyer_name, b.email as buyer_email, b.phone_number, b.address as shipping_address
        FROM Orders o
        JOIN Buyers b ON o.buyer_id = b.buyer_id
        WHERE o.order_id = ?
    ');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        header('Location: home.php');
        exit();
    }

    // Fetch order items
    $stmt = $pdo->prepare('
        SELECT oi.*, p.name as product_name, p.image_url, s.fullname as seller_name, cat.name as category_name
        FROM Order_Items oi
        JOIN Products p ON oi.product_id = p.product_id
        JOIN Sellers s ON p.seller_id = s.seller_id
        LEFT JOIN Categories cat ON p.category_id = cat.category_id
        WHERE oi.order_id = ?
    ');
    $stmt->execute([$order_id]);

    $order_items = $stmt->fetchAll();

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

    // Calculate totals
    $subtotal = 0;
    $total_quantity = 0;
    $total_handling_fee = 0;
    foreach ($order_items as $item) {
        $subtotal += $item['price'] * $item['quantity'];
        $total_quantity += $item['quantity'];
        
        // Add handling fee based on weight and size
        $stmt = $pdo->prepare("SELECT weight, size, shipping_type FROM products WHERE product_id = ?");
        $stmt->execute([$item['product_id']]);
        $product = $stmt->fetch();
        
        if ($product) {
            if ($product['shipping_type'] === 'size_based' && $product['size'] === 'small') {
                // Small Box handling fee
                $item_handling = 10 * $item['quantity'];
            } else {
                // Weight-based handling fee
                $weight = $product['weight'];
                if ($weight <= 3) {
                    $item_handling = 20 * $item['quantity']; // 0-3 kg
                } elseif ($weight <= 5) {
                    $item_handling = 35 * $item['quantity']; // 3-5 kg
                } else {
                    $item_handling = 50 * $item['quantity']; // >5 kg
                }
            }
            $total_handling_fee += $item_handling;
        } else {
            // Fallback to old system
            $category = isset($item['category_name']) ? $item['category_name'] : 'Eco Friendly';
            $category_fee = isset($handling_fee_map[$category]) ? $handling_fee_map[$category] : 20;
            $total_handling_fee += $category_fee * $item['quantity'];
        }
    }
    
    $handling_fee = $total_handling_fee;
    $region = getRegionFromAddress($order['shipping_address']);
    $shipping_fee = 0;
    
    // Get delivery method from order
    $delivery_method = isset($order['delivery_method']) ? $order['delivery_method'] : 'delivery';
    
    // Calculate shipping for each order item (only for delivery orders)
    if ($delivery_method !== 'pickup') {
        foreach ($order_items as $item) {
            // Fetch product details to get weight, size, and shipping_type
            $stmt = $pdo->prepare("SELECT weight, size, shipping_type FROM products WHERE product_id = ?");
            $stmt->execute([$item['product_id']]);
            $product = $stmt->fetch();
            
            if ($product) {
                if ($product['shipping_type'] === 'size_based' && $product['size'] === 'small') {
                    // Small Box flat rate
                    $item_shipping = 45;
                } else {
                    // Weight-based rates
                    $weight = $product['weight'];
                    if ($weight <= 3) {
                        // 0-3 kg rates
                        $weight_rates = [
                            'Luzon' => [75, 120], // min, max
                            'Visayas' => [90, 150],
                            'Mindanao' => [90, 150],
                            'NCR' => [60, 100]
                        ];
                    } else {
                        // 3-5 kg rates  
                        $weight_rates = [
                            'Luzon' => [120, 180],
                            'Visayas' => [150, 220],
                            'Mindanao' => [150, 220],
                            'NCR' => [100, 150]
                        ];
                    }
                    
                    // Use average of range for simplicity
                    if (isset($weight_rates[$region])) {
                        $item_shipping = ($weight_rates[$region][0] + $weight_rates[$region][1]) / 2;
                    } else {
                        $item_shipping = isset($shipping_fee_map[$region]) ? $shipping_fee_map[$region] : 100;
                    }
                }
                
                // Add shipping for this item (per quantity)
                $shipping_fee += $item_shipping * $item['quantity'];
            } else {
                // Fallback to old system
                $shipping_fee += (isset($shipping_fee_map[$region]) ? $shipping_fee_map[$region] : 100) * $item['quantity'];
            }
        }
    }
    
    // Set handling fee to zero for pickup orders
    if ($delivery_method === 'pickup') {
        $handling_fee = 0;
    }
    $total_amount = $subtotal + $shipping_fee + $handling_fee;
    
    // Calculate ecocoins totals (items with +20 per unit, plus fees)
    $subtotal_ecocoins = $subtotal + (20 * $total_quantity);
    $shipping_fee_ecocoins = $shipping_fee;
    $handling_fee_ecocoins = $handling_fee;
    $total_amount_ecocoins = $subtotal_ecocoins + $shipping_fee_ecocoins + $handling_fee_ecocoins;

    // Fetch buyer's current EcoCoins balance for EcoCoins payment display
    $buyer_ecocoins_balance = 0;
    $stmt = $pdo->prepare('SELECT ecocoins_balance FROM Buyers WHERE buyer_id = ?');
    $stmt->execute([$order['buyer_id']]);
    $buyer = $stmt->fetch();
    if ($buyer) {
        $buyer_ecocoins_balance = (float)$buyer['ecocoins_balance'];
    }

    // Normalize the current payment method
    $current_payment_method = $payment_method;
    if (empty($current_payment_method) && isset($order['payment_method'])) {
        $current_payment_method = $order['payment_method'];
    }

    // Get delivery method from order
    $delivery_method = isset($order['delivery_method']) ? $order['delivery_method'] : 'delivery';

    // Get ecocoins earned from GET parameter or calculate based on delivery method
    $ecocoins_awarded = isset($_GET['ecocoins_awarded']) ? floatval($_GET['ecocoins_awarded']) : 0;
    
    // If not provided in GET, calculate based on payment method and delivery method
    if ($ecocoins_awarded <= 0 && $current_payment_method !== 'ecocoins') {
        if ($delivery_method === 'pickup') {
            $ecocoins_awarded = round($subtotal / 20, 2); // 5 ecocoins per 100 pesos
        } else {
            $ecocoins_awarded = round($subtotal / 100, 2); // 1 ecocoin per 100 pesos
        }
    }

} catch (Exception $e) {
    header('Location: home.php');
    exit();
}

// Build receipt HTML for SweetAlert
$receipt_html = '';
$receipt_html .= '<div style="text-align: left; padding: 15px; max-width: 500px; margin: 0 auto;">';
$receipt_html .= '<div style="border-bottom: 2px solid #ddd; padding-bottom: 10px; margin-bottom: 10px;">';
$receipt_html .= '<h6 style="margin: 0; font-weight: bold;">Order #' . htmlspecialchars($order_id) . '</h6>';
$receipt_html .= '<small style="color: #666;">' . date('M j, Y g:i A', strtotime($order['created_at'])) . '</small>';
$receipt_html .= '</div>';

// Order Items
$receipt_html .= '<div style="margin-bottom: 15px;">';
$receipt_html .= '<h6 style="font-weight: bold; margin-bottom: 8px;">Items:</h6>';
foreach ($order_items as $item) {
    $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 14px;">';
    $receipt_html .= '<span>' . htmlspecialchars($item['product_name']) . ' (x' . $item['quantity'] . ')</span>';
    if ($current_payment_method === 'ecocoins') {
        // Show ecocoins price with +20 per unit
        $item_ecocoins = ($item['price'] * $item['quantity']) + (20 * $item['quantity']);
        $receipt_html .= '<span>' . number_format($item_ecocoins, 2) . ' EcoCoins</span>';
    } else {
        $receipt_html .= '<span>₱' . number_format($item['price'] * $item['quantity'], 2) . '</span>';
    }
    $receipt_html .= '</div>';
}
$receipt_html .= '</div>';

// Summary - Different layouts for pickup vs delivery
if ($delivery_method === 'pickup') {
    // Pickup receipt - cleaner without shipping/handling fees
    $receipt_html .= '<div style="border-top: 1px solid #ddd; border-bottom: 1px solid #ddd; padding: 15px 0; margin-bottom: 15px; background: #f8fff8;">';
    $receipt_html .= '<div style="text-align: center; margin-bottom: 10px;">';
    $receipt_html .= '<span style="background: #28a745; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: bold;">PICKUP ORDER</span>';
    $receipt_html .= '</div>';
    
    if ($current_payment_method === 'ecocoins') {
        // EcoCoins summary for pickup
        $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px;">';
        $receipt_html .= '<span>Subtotal:</span>';
        $receipt_html .= '<span>' . number_format($subtotal_ecocoins, 2) . ' EcoCoins</span>';
        $receipt_html .= '</div>';
        $receipt_html .= '<div style="display: flex; justify-content: space-between; font-size: 14px; color: #28a745; font-weight: 500;">';
        $receipt_html .= '<span>No Shipping/Handling Fees:</span>';
        $receipt_html .= '<span>FREE</span>';
        $receipt_html .= '</div>';
    } else {
        // Peso summary for pickup
        $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px;">';
        $receipt_html .= '<span>Subtotal:</span>';
        $receipt_html .= '<span>₱' . number_format($subtotal, 2) . '</span>';
        $receipt_html .= '</div>';
        $receipt_html .= '<div style="display: flex; justify-content: space-between; font-size: 14px; color: #28a745; font-weight: 500;">';
        $receipt_html .= '<span>No Shipping/Handling Fees:</span>';
        $receipt_html .= '<span>FREE</span>';
        $receipt_html .= '</div>';
    }
    $receipt_html .= '</div>';
} else {
    // Delivery receipt - shows all fees
    $receipt_html .= '<div style="border-top: 1px solid #ddd; border-bottom: 1px solid #ddd; padding: 10px 0; margin-bottom: 15px;">';
    if ($current_payment_method === 'ecocoins') {
        // Show ecocoins summary
        $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 14px;">';
        $receipt_html .= '<span>Subtotal:</span>';
        $receipt_html .= '<span>' . number_format($subtotal_ecocoins, 2) . ' EcoCoins</span>';
        $receipt_html .= '</div>';
        $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 14px;">';
        $receipt_html .= '<span>Shipping:</span>';
        $receipt_html .= '<span>' . number_format($shipping_fee_ecocoins, 2) . ' EcoCoins</span>';
        $receipt_html .= '</div>';
        $receipt_html .= '<div style="display: flex; justify-content: space-between; font-size: 14px;">';
        $receipt_html .= '<span>Handling Fee:</span>';
        $receipt_html .= '<span>' . number_format($handling_fee_ecocoins, 2) . ' EcoCoins</span>';
        $receipt_html .= '</div>';
    } else {
        // Show peso summary
        $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 14px;">';
        $receipt_html .= '<span>Subtotal:</span>';
        $receipt_html .= '<span>₱' . number_format($subtotal, 2) . '</span>';
        $receipt_html .= '</div>';
        $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 14px;">';
        $receipt_html .= '<span>Shipping:</span>';
        $receipt_html .= '<span>₱' . number_format($shipping_fee, 2) . '</span>';
        $receipt_html .= '</div>';
        $receipt_html .= '<div style="display: flex; justify-content: space-between; font-size: 14px;">';
        $receipt_html .= '<span>Handling Fee:</span>';
        $receipt_html .= '<span>₱' . number_format($handling_fee, 2) . '</span>';
        $receipt_html .= '</div>';
    }
    $receipt_html .= '</div>';
}

// Total
$receipt_html .= '<div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 16px; margin-bottom: 15px;">';
$receipt_html .= '<span>Total:</span>';
if ($current_payment_method === 'ecocoins') {
    $receipt_html .= '<span style="color: #28a745;">' . number_format($total_amount_ecocoins, 2) . ' EcoCoins</span>';
} else {
    $receipt_html .= '<span style="color: #28a745;">₱' . number_format($total_amount, 2) . '</span>';
}
$receipt_html .= '</div>';

// Payment Method
if ($current_payment_method === 'ecocoins') {
    $ecocoins_used = round($total_amount_ecocoins, 2);
    $receipt_html .= '<div style="background: #fff9e6; padding: 10px; border-radius: 5px; border-left: 4px solid #FFD700;">';
    $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">';
    $receipt_html .= '<span style="font-weight: 500;"><i class="fas fa-coins" style="color: #FFD700;"></i> Payment Method:</span>';
    $receipt_html .= '<span style="font-weight: bold;">EcoCoins</span>';
    $receipt_html .= '</div>';
    $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">';
    $receipt_html .= '<span>EcoCoins Deducted:</span>';
    $receipt_html .= '<span style="color: #FFD700; font-weight: bold;">' . number_format($ecocoins_used, 2) . '</span>';
    $receipt_html .= '</div>';
    $receipt_html .= '<div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #ffd700;">';
    $receipt_html .= '<span style="font-weight: 500;">Current Balance:</span>';
    $receipt_html .= '<span style="color: #28a745; font-weight: bold;">' . number_format($buyer_ecocoins_balance, 2) . ' coins</span>';
    $receipt_html .= '</div>';
    $receipt_html .= '</div>';
} elseif ($current_payment_method === 'gcash') {
    $receipt_html .= '<div style="background: #e3f2fd; padding: 10px; border-radius: 5px; border-left: 4px solid #1976d2;">';
    $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">';
    $receipt_html .= '<span style="font-weight: 500;">Payment Method:</span>';
    $receipt_html .= '<span style="font-weight: bold;">GCash</span>';
    $receipt_html .= '</div>';
    $receipt_html .= '<div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #1976d2;">';
    $receipt_html .= '<span style="font-weight: 500;"><i class="fas fa-coins me-1" style="color: #FFD700;"></i>EcoCoins Earned:</span>';
    $receipt_html .= '<span style="color: #FFD700; font-weight: bold;">' . number_format($ecocoins_awarded, 2) . '</span>';
    if ($delivery_method === 'pickup') {
        $receipt_html .= ' <small style="color: #FF9800;">(Pickup Bonus: 5x)</small>';
    }
    $receipt_html .= '</div>';
    $receipt_html .= '</div>';
} else {
    $receipt_html .= '<div style="background: #fce4ec; padding: 10px; border-radius: 5px; border-left: 4px solid #c2185b;">';
    $receipt_html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">';
    $receipt_html .= '<span style="font-weight: 500;">Payment Method:</span>';
    $receipt_html .= '<span style="font-weight: bold;">Cash on Delivery</span>';
    $receipt_html .= '</div>';
    $receipt_html .= '<div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #c2185b;">';
    $receipt_html .= '<span style="font-weight: 500;"><i class="fas fa-coins me-1" style="color: #FFD700;"></i>EcoCoins Earned:</span>';
    $receipt_html .= '<span style="color: #FFD700; font-weight: bold;">' . number_format($ecocoins_awarded, 2) . '</span>';
    if ($delivery_method === 'pickup') {
        $receipt_html .= ' <small style="color: #FF9800;">(Pickup Bonus: 5x)</small>';
    }
    $receipt_html .= '</div>';
    $receipt_html .= '</div>';
}

$receipt_html .= '</div>';

// Shipping Address or Pickup Location
if ($delivery_method === 'pickup') {
    $receipt_html .= '<div style="margin-top: 15px; padding: 10px; background: #FFF3E0; border-radius: 5px; font-size: 13px; border-left: 4px solid #FF9800;">';
    $receipt_html .= '<h6 style="font-weight: bold; margin-bottom: 5px;"><i class="fas fa-store me-1"></i>Pickup Location:</h6>';
    $receipt_html .= '<p style="margin: 0;">ECSD DMMMSU Sapilang, Bacnotan La Union</p>';
    $receipt_html .= '</div>';
} else {
    $receipt_html .= '<div style="margin-top: 15px; padding: 10px; background: #f5f5f5; border-radius: 5px; font-size: 13px;">';
    $receipt_html .= '<h6 style="font-weight: bold; margin-bottom: 5px;"><i class="fas fa-truck me-1"></i>Delivery Address:</h6>';
    $receipt_html .= '<p style="margin: 0;">' . htmlspecialchars($order['shipping_address'] ?: $order['buyer_name']) . '</p>';
    $receipt_html .= '</div>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Order Receipt - Ecocycle</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .swal2-popup,
        .swal2-title,
        .swal2-html-container,
        .swal2-confirm,
        .swal2-cancel {
            font-family: 'Poppins', sans-serif !important;
        }
    </style>
</head>
<body>
<script>
    // Determine success message based on payment method
    let successTitle = 'Payment Successful!';
    let successIcon = '<i class="fas fa-check-circle" style="color: #28a745;"></i>';
    
    <?php if ($current_payment_method === 'cod'): ?>
        successTitle = 'Your order has been successfully placed';
        successIcon = '<i class="fas fa-shopping-cart" style="color: #28a745;"></i>';
    <?php elseif ($current_payment_method === 'ecocoins'): ?>
        successTitle = 'EcoCoins Payment Successful!';
        successIcon = '<i class="fas fa-leaf" style="color: #28a745;"></i>';
    <?php else: ?>
        successTitle = 'Payment Successful!';
        successIcon = '<i class="fas fa-mobile-alt" style="color: #28a745;"></i>';
    <?php endif; ?>
    
    Swal.fire({
        title: successIcon + ' ' + successTitle,
        html: `<?php echo $receipt_html; ?>`,
        icon: 'success',
        confirmButtonText: 'View My Orders',
        cancelButtonText: 'Continue Shopping',
        showCancelButton: true,
        background: '#f8f9fa',
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: function() {
            const popup = document.querySelector('.swal2-popup');
            popup.style.borderRadius = '20px';
            popup.style.boxShadow = '0 8px 30px rgba(0, 0, 0, 0.15)';
            popup.style.maxWidth = '550px';
        }
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'myorders.php';
        } else {
            window.location.href = 'home.php';
        }
    });
</script>
</body>
</html>