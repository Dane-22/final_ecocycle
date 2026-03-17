<?php
session_start();
// REDIRECT LOGIC MUST BE FIRST!
if (
    !(
        isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'buyer' &&
        isset($_POST['product_id']) && isset($_POST['quantity'])
    )
) {
    header('Location: home.php');
    exit();
}

include 'homeheader.php';
// Fetch user info for autofill
$firstName = '';
$lastName = '';
$email = '';
$phone = '';
$address = '';
$city = '';
$postalCode = '';

if (isset($_SESSION['fullname'])) {
    $fullname = trim($_SESSION['fullname']);
    $nameParts = explode(' ', $fullname, 2);
    $firstName = $nameParts[0];
    $lastName = isset($nameParts[1]) ? $nameParts[1] : '';
}
if (isset($_SESSION['email'])) {
    $email = $_SESSION['email'];
}
// Fetch phone/address from DB if not in session
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
    require_once 'config/database.php';
    if ($_SESSION['user_type'] === 'buyer') {
        $stmt = $pdo->prepare('SELECT phone_number, address FROM buyers WHERE buyer_id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $phone = $row['phone_number'];
            $address = $row['address'];
        }
    } elseif ($_SESSION['user_type'] === 'seller') {
        $stmt = $pdo->prepare('SELECT phone_number, address FROM sellers WHERE seller_id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $phone = $row['phone_number'];
            $address = $row['address'];
        }
    }
    
    // Fetch ECSD Head information from admins table
    $ecsd_head = [
        'fullname' => 'ECSD Head',
        'email' => 'ecsdhead@ecocycle.com',
        'phone_number' => '09123456789'
    ];
    
    try {
        $stmt = $pdo->prepare('SELECT fullname, email, phone_number FROM admins WHERE username = ? AND status = ? LIMIT 1');
        $stmt->execute(['ecsdhead', 'active']);
        $admin = $stmt->fetch();
        if ($admin) {
            $ecsd_head = [
                'fullname' => $admin['fullname'],
                'email' => $admin['email'],
                'phone_number' => $admin['phone_number'] ?? '09123456789'
            ];
        }
    } catch (PDOException $e) {
        // Keep default values if query fails
        error_log("Error fetching ECSD Head info: " . $e->getMessage());
    }
}
// Optionally, split address for city/postal code if you want

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

// Direct buy: fetch product from POST, not cart
$order_item = null;
$subtotal = 0;
$shipping_fee = 100;
$handling_fee = 0;
$total = 0;
$subtotal_ecocoins = 0;
$shipping_fee_ecocoins = 0;
$handling_fee_ecocoins = 0;
$total_ecocoins = 0;
$user_ecocoins_balance = 0;
require_once 'config/database.php';
$buyer_id = $_SESSION['user_id'];

// Fetch user's EcoCoins balance
$stmt = $pdo->prepare('SELECT ecocoins_balance FROM buyers WHERE buyer_id = ?');
$stmt->execute([$buyer_id]);
$buyer = $stmt->fetch();
$user_ecocoins_balance = $buyer ? (float)$buyer['ecocoins_balance'] : 0;

$product_id = intval($_POST['product_id']);
$quantity = max(1, intval($_POST['quantity']));

// Fetch product details with weight, size, and shipping_type
$stmt = $pdo->prepare('SELECT p.*, cat.name as category_name FROM products p LEFT JOIN categories cat ON p.category_id = cat.category_id WHERE p.product_id = ?');
$stmt->execute([$product_id]);
$product = $stmt->fetch();
if ($product && $product['stock_quantity'] >= $quantity) {
    $order_item = [
        'product_id' => $product['product_id'],
        'name' => $product['name'],
        'price' => $product['price'],
        'image_url' => $product['image_url'],
        'quantity' => $quantity,
        'seller_id' => $product['seller_id'],
        'category_name' => $product['category_name']
    ];
    $subtotal = $product['price'] * $quantity;
    
    // Calculate handling fee based on weight and size
    if ($product['shipping_type'] === 'size_based' && $product['size'] === 'small') {
        // Small Box handling fee
        $handling_fee = 10 * $quantity;
    } else {
        // Weight-based handling fee
        $weight = $product['weight'];
        if ($weight <= 3) {
            $handling_fee = 20 * $quantity; // 0-3 kg
        } elseif ($weight <= 5) {
            $handling_fee = 35 * $quantity; // 3-5 kg
        } else {
            $handling_fee = 50 * $quantity; // >5 kg
        }
    }
    
    // Calculate shipping fee based on product weight and size
    $region = getRegionFromAddress($address);
    
    if ($product) {
        if ($product['shipping_type'] === 'size_based' && $product['size'] === 'small') {
            // Small Box flat rate
            $shipping_fee = 45;
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
                $shipping_fee = ($weight_rates[$region][0] + $weight_rates[$region][1]) / 2;
            } else {
                $shipping_fee = isset($shipping_fee_map[$region]) ? $shipping_fee_map[$region] : 100;
            }
        }
    } else {
        // Fallback to old system
        $shipping_fee = isset($shipping_fee_map[$region]) ? $shipping_fee_map[$region] : 100;
    }
    
    // Ecocoins: product price + 20 per unit
    $subtotal_ecocoins = $subtotal + (20 * $quantity);
    $shipping_fee_ecocoins = $shipping_fee;
    $handling_fee_ecocoins = $handling_fee;
    $total = $subtotal + $shipping_fee + $handling_fee;
    $total_ecocoins = $subtotal_ecocoins + $shipping_fee_ecocoins + $handling_fee_ecocoins;

    // Calculate EcoCoins that will be awarded for this purchase
    // For pickup: 10 ecocoins per 100 pesos; For delivery: 1 ecocoin per 100 pesos
    $ecocoins_awarded_delivery = round($subtotal / 100, 2); // 1 ecocoin per 100 pesos for delivery
    $ecocoins_awarded_pickup = round($subtotal / 10, 2); // 10 ecocoins per 100 pesos for pickup
    $ecocoins_awarded = $ecocoins_awarded_delivery; // Default to delivery rate
}

$sellerQrs = [];
if ($order_item && !empty($order_item['seller_id'])) {
    $stmt = $pdo->prepare('SELECT seller_id, fullname, username, gcash_qr, phone_number FROM sellers WHERE seller_id = ?');
    $stmt->execute([$order_item['seller_id']]);
    $sr = $stmt->fetch();
    if ($sr) {
        $displayName = !empty($sr['fullname']) ? $sr['fullname'] : (!empty($sr['username']) ? $sr['username'] : 'Seller');
        $phoneNumber = !empty($sr['phone_number']) ? $sr['phone_number'] : '';
        $sellerQrs[$sr['seller_id']] = ['name' => $displayName, 'gcash_qr' => $sr['gcash_qr'], 'phone' => $phoneNumber];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Checkout - Ecocycle</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="format-detection" content="telephone=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="author" content="">
    <meta name="keywords" content="">
    <meta name="description" content="">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="css/vendor.css">
    <link rel="stylesheet" type="text/css" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700&family=Open+Sans:ital,wght@0,400;0,700;1,400;1,700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="main-content">
                <div class="container-lg mt-5">
                    <!-- Breadcrumb -->
                    <nav aria-label="breadcrumb" class="mb-4">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="home.php" class="text-decoration-none">Home</a></li>
                            <li class="breadcrumb-item"><a href="mycart.php" class="text-decoration-none">Cart</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Checkout</li>
                        </ol>
                    </nav>

                    <h2 class="fw-bold mb-4 text-start">Checkout</h2>
                    
                    <?php if (isset($_GET['error']) && $_GET['error'] === 'insufficient_ecocoins'): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Insufficient EcoCoins Balance!</strong>
                        <?php 
                        $required = isset($_GET['required']) ? floatval($_GET['required']) : 0;
                        $balance = isset($_GET['balance']) ? floatval($_GET['balance']) : 0;
                        $shortfall = $required - $balance;
                        ?>
                        <p class="mb-0">You need <strong><?php echo number_format($shortfall); ?></strong> more EcoCoins to complete this purchase. Your current balance is <strong><?php echo number_format($balance); ?> coins</strong>.</p>
                        <p class="mb-0 mt-2"><a href="ecocoins.php" class="alert-link">Add EcoCoins to your account</a></p>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row justify-content-center">
                        <!-- Checkout Form and Payment Method centered -->
                        <div class="col-lg-8 col-md-10 mx-auto">
                            <div class="card shadow-sm border-0 mb-4">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0"><i class="fas fa-shipping-fast me-2"></i>Shipping Information</h5>
                                    <div class="d-flex gap-2 mt-2">
                                        <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#weightRatesModal">
                                            <i class="fas fa-weight me-1"></i>Weight-based Rates
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#sizeRatesModal">
                                            <i class="fas fa-box me-1"></i>Size-based Rates
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <form id="checkoutForm" method="POST" action="process-buycheckout.php">
                                        <input type="hidden" name="product_id" value="<?php echo $order_item ? htmlspecialchars($order_item['product_id']) : ''; ?>">
                                        <input type="hidden" name="quantity" value="<?php echo $order_item ? htmlspecialchars($order_item['quantity']) : 1; ?>">
                                        <div class="row">
                                            <div class="col-md-12 mb-3">
                                                <label for="fullName" class="form-label">Full Name *</label>
                                                <input type="text" class="form-control" id="fullName" value="<?php echo htmlspecialchars($firstName . ($lastName ? ' ' . $lastName : '')); ?>" required readonly>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="email" class="form-label">Email Address *</label>
                                            <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($email); ?>" required readonly>
                                        </div>
                                        <div class="mb-3">
                                            <label for="phone" class="form-label">Phone Number *</label>
                                            <input type="tel" class="form-control" id="phone" value="<?php echo htmlspecialchars($phone); ?>" required readonly>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Delivery Method *</label>
                                            <div class="d-flex gap-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="delivery_method" id="delivery" value="delivery" checked>
                                                    <label class="form-check-label" for="delivery">
                                                        <i class="fas fa-truck me-1"></i>Delivery
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="delivery_method" id="pickup" value="pickup">
                                                    <label class="form-check-label" for="pickup">
                                                        <i class="fas fa-store me-1"></i>Pick Up
                                                        <span 
                                                            id="pickupInfoBtn"
                                                            class="badge bg-success rounded-circle d-inline-flex align-items-center justify-content-center ms-2" 
                                                            style="width: 20px; height: 20px; cursor: pointer; font-size: 12px; vertical-align: top;"
                                                            data-bs-toggle="popover" 
                                                            data-bs-trigger="hover focus" 
                                                            data-bs-placement="right"
                                                            title="Benefits"
                                                            data-bs-html="true"
                                                        >
                                                            <i class="fas fa-info text-white"></i>
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-3" id="addressSection">
                                            <label for="deliveryAddress" class="form-label">Delivery Address *
                                                <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="changeAddressBtn">Change</button>
                                            </label>
                                            <textarea class="form-control" id="deliveryAddress" name="shipping_address" rows="3" required><?php echo htmlspecialchars($address); ?></textarea>
                                        </div>
                                        <div class="mb-3" id="pickupSection" style="display: none;">
                                            <label class="form-label">Pickup Location</label>
                                            <p class="text-muted">
                                                <i class="fas fa-map-marker-alt text-success me-2"></i>
                                                <strong>ECSD DMMMSU Sapilang, Bacnotan La Union</strong>
                                            </p>
                                            
                                            <!-- ECSD Head Contact Information -->
                                            <div class="mt-3" id="ecsdContactSection">
                                                <label class="form-label"><i class="fas fa-user-tie me-1"></i>Contact Person</label>
                                                <div class="bg-light p-3 rounded border" style="background-color: #fff8e1 !important; border-color: #ffe082 !important;">
                                                    <div class="row g-2">
                                                        <div class="col-12">
                                                            <small class="text-muted d-block">Full Name:</small>
                                                            <span class="fw-medium"><?php echo htmlspecialchars($ecsd_head['fullname']); ?></span>
                                                        </div>
                                                        <div class="col-12">
                                                            <small class="text-muted d-block">Email Address:</small>
                                                            <span class="text-primary"><?php echo htmlspecialchars($ecsd_head['email']); ?></span>
                                                        </div>
                                                        <div class="col-12">
                                                            <small class="text-muted d-block">Phone Number:</small>
                                                            <span><?php echo htmlspecialchars($ecsd_head['phone_number']); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <input type="hidden" name="amount" id="hiddenTotal" value="<?php echo htmlspecialchars($total); ?>">
                                        <input type="hidden" name="payment_method" id="paymentMethodInput" value="cod">
                                        <!-- Hidden field to hold base64-encoded uploaded receipt; moved into the form so it is submitted -->
                                        <input type="hidden" id="receiptData" name="receipt_data" value="">
                                    </form>
                                </div>
                            </div>

                            <!-- Payment Method -->
                            <div class="card shadow-sm border-0 mb-4">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Payment Method</h5>
                                </div>
                                <div class="card-body">
                                    <div class="payment-methods">
                                        <!-- Cash on Delivery Payment -->
                                        <div class="payment-option mb-3">
                                            <input type="radio" class="btn-check" name="paymentMethod" id="cod" value="cod" checked>
                                            <label class="btn btn-outline-success w-100 text-start p-3" for="cod">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-money-bill-wave fa-2x text-success me-3"></i>
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-1">Cash on Delivery</h6>
                                                        <small class="text-muted">Pay with cash upon delivery</small>
                                                    </div>
                                                    <i class="fas fa-check-circle text-success" style="display: none;"></i>
                                                </div>
                                            </label>
                                        </div>
                                        <!-- GCash Payment -->
                                        <div class="payment-option mb-3">
                                            <input type="radio" class="btn-check" name="paymentMethod" id="gcash" value="gcash">
                                            <label class="btn btn-outline-success w-100 text-start p-3" for="gcash">
                                                <div class="d-flex align-items-center">
                                                    <img src="images/gcash-logo.png" alt="GCash" class="payment-logo me-3" style="width: 40px; height: 40px;">
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-1">GCash</h6>
                                                        <small class="text-muted">Pay by scanning QR Code Below</small>
                                                    </div>
                                                    <i class="fas fa-check-circle text-success" style="display: none;"></i>
                                                </div>
                                            </label>
                                        </div>
                                        <!-- Ecocoins Payment -->
                                        <div class="payment-option mb-3">
                                            <input type="radio" class="btn-check" name="paymentMethod" id="ecocoins" value="ecocoins">
                                            <label class="btn btn-outline-success w-100 text-start p-3" for="ecocoins">
                                                <div class="d-flex align-items-center">
                                                    <img src="images/ecocoins-logo.png" alt="Ecocoins" class="payment-logo me-3" style="width: 40px; height: 40px;">
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-1">Ecocoins</h6>
                                                        <small class="text-muted">Pay using your Ecocoins balance</small>
                                                    </div>
                                                    <i class="fas fa-check-circle text-success" style="display: none;"></i>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                    <!-- GCash Payment Details -->
                                    <div id="gcashDetails" class="payment-details mt-3" style="display: none;">
                                        <div class="alert alert-info">
                                            <p class="mb-2"><i class="fas fa-info-circle me-1"></i>Please scan the QR code and upload your payment receipt.</p>
                                            <div class="d-grid gap-2">
                                                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#gcashQrModal">
                                                    <i class="fas fa-qrcode me-2"></i>View GCash QR Code
                                                </button>
                                                <button type="button" class="btn btn-outline-success" id="uploadReceiptBtn">
                                                    <i class="fas fa-upload me-2"></i>Upload Payment Receipt
                                                </button>
                                            </div>
                                            <div id="receiptPreview" class="mt-3" style="display: none;">
                                                <p class="text-success mb-2"><i class="fas fa-check-circle me-1"></i>Receipt uploaded successfully!</p>
                                                <img id="receiptImage" src="" alt="Receipt Preview" class="img-fluid" style="max-width: 200px; border: 1px solid #ddd; border-radius: 5px;">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- EcoCoins Payment Details -->
                                    <div id="ecocoinsDetails" class="payment-details mt-3" style="display: none;">
                                        <div class="card border-success">
                                            <div class="card-body">
                                                <div class="row mb-3">
                                                    <div class="col-6">
                                                        <span class="text-muted"><i class="fas fa-coins me-1 text-warning"></i>Your EcoCoins Balance:</span>
                                                    </div>
                                                    <div class="col-6 text-end">
                                                        <strong class="fs-5 text-warning"><?php echo number_format($user_ecocoins_balance, 2); ?> coins</strong>
                                                    </div>
                                                </div>
                                                <div class="row mb-3">
                                                    <div class="col-6">
                                                        <span class="text-muted"><i class="fas fa-shopping-cart me-1"></i>Amount Required:</span>
                                                    </div>
                                                    <div class="col-6 text-end">
                                                        <strong class="fs-5"><?php echo number_format($total_ecocoins, 2); ?> coins</strong>
                                                    </div>
                                                </div>
                                                <?php 
                                                $can_pay_ecocoins = $user_ecocoins_balance >= $total_ecocoins;
                                                $remaining_ecocoins = $user_ecocoins_balance - $total_ecocoins;
                                                ?>
                                                <div class="row mb-3">
                                                    <div class="col-6">
                                                        <span class="text-muted"><i class="fas fa-balance-scale me-1"></i>Remaining Balance:</span>
                                                    </div>
                                                    <div class="col-6 text-end">
                                                        <span class="badge <?php echo $remaining_ecocoins >= 0 ? 'bg-success' : 'bg-danger'; ?>">
                                                            <?php echo number_format($remaining_ecocoins, 2); ?> coins
                                                        </span>
                                                    </div>
                                                </div>
                                                <?php if (!$can_pay_ecocoins): ?>
                                                <div class="alert alert-danger mb-0">
                                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                                    <strong>Insufficient EcoCoins!</strong> You need <?php echo number_format($total_ecocoins - $user_ecocoins_balance, 2); ?> more coins to complete this purchase.
                                                </div>
                                                <?php else: ?>
                                                <div class="alert alert-success mb-0">
                                                    <i class="fas fa-check-circle me-2"></i>
                                                    You have sufficient EcoCoins to proceed with this purchase.
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Order Summary at the bottom, outside shipping info and payment method -->
                            <div class="card shadow-sm border-0 mb-4">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0"><i class="fas fa-receipt me-2"></i>Order Summary</h5>
                                </div>
                                <div class="card-body">
                                    <?php if ($order_item): ?>
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center mb-2">
                                            <img src="<?php echo htmlspecialchars($order_item['image_url']); ?>" alt="<?php echo htmlspecialchars($order_item['name']); ?>" style="width:60px;height:60px;object-fit:cover;margin-right:10px;">
                                            <div>
                                                <strong><?php echo htmlspecialchars($order_item['name']); ?></strong><br>
                                                <span id="priceDisplay">Qty: <?php echo $order_item['quantity']; ?> x ₱<?php echo number_format($order_item['price'], 2); ?></span>
                                                <span id="priceDisplayEco" style="display:none;">Qty: <?php echo $order_item['quantity']; ?> x <?php echo number_format($order_item['price'] + 20, 2); ?> EcoCoins</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-3 pb-3 border-bottom">
                                        <div class="d-flex justify-content-between">
                                            <span>Delivery Method:</span>
                                            <span id="summaryDeliveryMethod" class="fw-bold">Delivery</span>
                                        </div>
                                    </div>
                                    <div class="price-breakdown mb-3">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Subtotal:</span>
                                            <span id="summarySubtotal">₱<?php echo number_format($subtotal, 2); ?></span>
                                            <span id="summarySubtotalEco" style="display:none;"><?php echo number_format($subtotal_ecocoins, 2); ?> EcoCoins</span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Shipping Fee:</span>
                                            <span id="summaryShipping">₱<?php echo number_format($shipping_fee, 2); ?></span>
                                            <span id="summaryShippingEco" style="display:none;"><?php echo number_format($shipping_fee_ecocoins, 2); ?> EcoCoins</span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Handling Fee:</span>
                                            <span id="summaryHandling">₱<?php echo number_format($handling_fee, 2); ?></span>
                                            <span id="summaryHandlingEco" style="display:none;"><?php echo number_format($handling_fee_ecocoins, 2); ?> EcoCoins</span>
                                        </div>
                                        <hr>
                                        <div class="d-flex justify-content-between fw-bold fs-5">
                                            <span>Total:</span>
                                            <span id="summaryTotal">₱<?php echo number_format($total, 2); ?></span>
                                            <span id="summaryTotalEco" style="display:none;"><?php echo number_format($total_ecocoins, 2); ?> EcoCoins</span>
                                        </div>
                                        <!-- EcoCoins Rewards Section -->
                                        <div class="mt-3 p-3 bg-light rounded">
                                            <div class="d-flex align-items-center mb-2">
                                                <i class="fas fa-coins text-warning me-2"></i>
                                                <strong class="text-success">EcoCoins You'll Earn:</strong>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="text-muted">Delivery Reward:</span>
                                                <span class="badge bg-success" id="deliveryPoints">+<?php echo number_format($ecocoins_awarded_delivery, 2); ?> coins</span>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center mt-1">
                                                <span class="text-muted">Pickup Reward (10x more):</span>
                                                <span class="badge bg-warning text-dark" id="pickupPoints">+<?php echo number_format($ecocoins_awarded_pickup, 2); ?> coins</span>
                                            </div>
                                            <div class="alert alert-success mt-2 mb-0 py-2">
                                                <small class="d-flex align-items-center">
                                                    <i class="fas fa-leaf me-2"></i>
                                                    <span id="rewardMessage">Choose pickup to earn <strong><?php echo number_format($ecocoins_awarded_pickup - $ecocoins_awarded_delivery, 2); ?></strong> more EcoCoins and help the environment!</span>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-danger">No product selected for direct buy.</div>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-primary btn-lg w-100 mt-2" id="placeOrderBtn">
                                        <i class="fas fa-lock me-2"></i>Place Order
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- GCash QR Code Modal -->
    <div class="modal fade" id="gcashQrModal" tabindex="-1" aria-labelledby="gcashQrModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="gcashQrModalLabel">
                        <img src="images/gcash-logo.png" alt="GCash" style="height: 30px; margin-right: 10px;">
                        Scan to Pay
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <p class="mb-2">Total Amount to Pay:</p>
                        <h4 class="text-success fw-bold">₱<?php echo number_format($total, 2); ?></h4>
                    </div>
                    <div class="mb-3">
                        <p class="mb-2">Scan the QR code using GCash app</p>
                        <?php if (!empty($sellerQrs)): ?>
                            <div class="row justify-content-center">
                                <?php foreach ($sellerQrs as $sid => $sinfo): ?>
                                    <?php
                                        $qrPath = !empty($sinfo['gcash_qr']) ? $sinfo['gcash_qr'] : 'images/gcash-qr.png';
                                        // Ensure path is safe for output
                                        $qrPathEsc = htmlspecialchars($qrPath);
                                        $sellerNameEsc = htmlspecialchars($sinfo['name']);
                                    ?>
                                    <div class="col-12 col-sm-6 mb-3 d-flex justify-content-center">
                                        <div class="d-flex align-items-center justify-content-center w-100">
                                            <div class="card-body text-center p-2" style="padding:0;">
                                                <?php if (!empty($sinfo['phone'])): ?>
                                                    <p class="mb-2 fw-bold text-success">GCash Number: <?php echo htmlspecialchars($sinfo['phone']); ?></p>
                                                <?php endif; ?>
                                                <div class="d-flex justify-content-center">
                                                    <img src="<?php echo $qrPathEsc; ?>" alt="GCash QR for <?php echo $sellerNameEsc; ?>" class="img-fluid" style="max-width: 350px; min-width: 250px; display:block; margin:auto;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <img src="images/gcash-qr.png" alt="GCash QR Code" class="img-fluid" style="max-width: 350px; min-width: 250px;">
                        <?php endif; ?>
                    </div>
                    <div class="alert alert-info">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            Please complete the payment within 15 minutes. Your order will be processed once payment is confirmed.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" id="confirmGcashPayment">
                        <i class="fas fa-check-circle me-1"></i> I've Made the Payment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="successModalLabel">
                        <i class="fas fa-check-circle me-2"></i>Order Placed Successfully!
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-3">
                        <i class="fas fa-shopping-bag text-success" style="font-size: 3rem;"></i>
                    </div>
                    <h6>Thank you for your order!</h6>
                    <p class="text-muted">Your order has been placed successfully. You will receive a confirmation email shortly.</p>
                    <div class="alert alert-info">
                        <strong>Order ID:</strong> <span id="orderId">ECO-2024-001</span><br>
                        <strong>Payment Method:</strong> <span id="paymentMethodUsed">GCash</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Continue Shopping</button>
                    <a href="myorders.php" class="btn btn-success">View My Orders</a>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* GCash QR Code Modal Styling */
        #gcashQrModal .modal-content {
            border: none;
            border-radius: 10px;
            overflow: hidden;
        }
        #gcashQrModal .modal-header {
            border-bottom: none;
            padding: 1.5rem;
        }
        #gcashQrModal .modal-body {
            padding: 2rem;
        }
        #gcashQrModal .modal-footer {
            border-top: none;
            padding: 1rem 1.5rem 1.5rem;
            justify-content: space-between;
        }
        #gcashQrModal img {
            border: 1px solid #eee;
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 350px;
            min-width: 250px;
        }
        #gcashQrModal .btn-success {
            background-color: #00A67E;
            border-color: #00A67E;
        }
        #gcashQrModal .btn-success:hover {
            background-color: #008f6b;
            border-color: #008f6b;
        }
        
        .payment-logo {
            object-fit: contain;
        }
        
        .payment-option .btn-check:checked + .btn {
            border-color: #28a745;
            background-color: #f8fff9;
        }
        
        .payment-option .btn-check:checked + .btn .fa-check-circle {
            display: block !important;
        }
        
        .payment-details {
            border-top: 1px solid #dee2e6;
            padding-top: 1rem;
        }
        
        .order-items {
            max-height: 200px;
            overflow-y: auto;
        }
        
        .sticky-top {
            z-index: 1020;
        }
        
        .btn-check:checked + .btn-outline-success {
            background-color: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        
        /* Responsive Styles */
        @media (max-width: 991.98px) {
            .sticky-top {
                position: relative !important;
                top: 0 !important;
            }
            
            .main-content {
                padding: 1rem;
            }
            
            .container-lg {
                padding: 0;
            }
            
            .card {
                margin-bottom: 1.5rem;
            }
            
            .breadcrumb {
                font-size: 0.9rem;
            }
            
            h2 {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 767.98px) {
            .main-content {
                padding: 0.5rem;
            }
            
            .card-body {
                padding: 1rem;
            }
            
            .card-header {
                padding: 0.75rem 1rem;
            }
            
            .payment-option .btn {
                padding: 0.75rem !important;
            }
            
            .payment-logo {
                width: 30px !important;
                height: 30px !important;
            }
            
            .order-items {
                max-height: 150px;
            }
            
            .btn-lg {
                padding: 0.75rem 1.5rem;
                font-size: 1rem;
            }
            
            .modal-dialog {
                margin: 0.5rem;
            }
            
            .breadcrumb {
                font-size: 0.8rem;
                margin-bottom: 1rem;
            }
            
            h2 {
                font-size: 1.25rem;
                margin-bottom: 1rem;
            }
            
            .form-label {
                font-size: 0.9rem;
            }
            
            .form-control {
                font-size: 0.9rem;
                padding: 0.5rem 0.75rem;
            }
            
            .alert {
                font-size: 0.85rem;
                padding: 0.75rem;
            }
            
            .price-breakdown {
                font-size: 0.9rem;
            }
            
            .price-breakdown .fw-bold.fs-5 {
                font-size: 1.1rem !important;
            }
        }
        
        @media (max-width: 575.98px) {
            .main-content {
                padding: 0.25rem;
            }
            
            .card-body {
                padding: 0.75rem;
            }
            
            .card-header {
                padding: 0.5rem 0.75rem;
            }
            
            .card-header h5 {
                font-size: 1rem;
            }
            
            .payment-option .btn {
                padding: 0.5rem !important;
            }
            
            .payment-logo {
                width: 25px !important;
                height: 25px !important;
            }
            
            .btn-lg {
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
            }
            
            .modal-dialog {
                margin: 0.25rem;
            }
            
            .breadcrumb {
                font-size: 0.75rem;
            }
            
            h2 {
                font-size: 1.1rem;
            }
            
            .form-label {
                font-size: 0.85rem;
            }
            
            .form-control {
                font-size: 0.85rem;
                padding: 0.4rem 0.6rem;
            }
            
            .alert {
                font-size: 0.8rem;
                padding: 0.6rem;
            }
            
            .price-breakdown {
                font-size: 0.85rem;
            }
            
            .price-breakdown .fw-bold.fs-5 {
                font-size: 1rem !important;
            }
            
            .order-items {
                max-height: 120px;
            }
        }
        
        /* Improve form layout on mobile */
        @media (max-width: 767.98px) {
            .row .col-md-6 {
                margin-bottom: 0.5rem;
            }
            
            .mb-3 {
                margin-bottom: 0.75rem !important;
            }
            
            .mb-4 {
                margin-bottom: 1rem !important;
            }
        }
        
        /* Improve payment method layout */
        @media (max-width: 767.98px) {
            .payment-option .btn .d-flex {
                flex-direction: column;
                text-align: center;
            }
            
            .payment-option .btn .d-flex .flex-grow-1 {
                margin: 0.5rem 0;
            }
            
            .payment-option .btn .d-flex .fa-check-circle {
                position: absolute;
                top: 0.5rem;
                right: 0.5rem;
            }
        }
        
        /* Improve order summary on mobile */
        @media (max-width: 767.98px) {
            .order-items .d-flex {
                flex-wrap: wrap;
            }
            
            .order-items .d-flex span:first-child {
                flex: 1;
                min-width: 0;
                margin-right: 0.5rem;
            }
            
            .order-items .d-flex span:last-child {
                flex-shrink: 0;
            }
        }
        
        /* Improve modal responsiveness */
        @media (max-width: 575.98px) {
            .modal-body {
                padding: 1rem 0.75rem;
            }
            
            .modal-footer {
                padding: 0.75rem;
            }
            
            .modal-title {
                font-size: 1.1rem;
            }
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Pickup Info Popover
        const pickupInfoBtn = document.getElementById('pickupInfoBtn');
        if (pickupInfoBtn) {
            const popoverContent = `
                <div class="d-flex align-items-start">
                    <i class="fas fa-leaf text-success me-2" style="margin-top: 3px;"></i>
                    <div>
                        <strong class="text-success">Eco-Friendly Benefits:</strong>
                            <ul class="mb-0 mt-2" style="font-size: 0.85rem; padding-left: 1.2rem;">
                            <li><i class="fas fa-bicycle text-success me-1"></i> Additional 10 Ecocoins</li>
                            <li><i class="fas fa-gas-pump text-success me-1"></i> Zero Delivery Gasoline Consumption</li>
                            <li><i class="fas fa-wind text-success me-1"></i> Zero Delivery Emissions</li>
                            <li><i class="fas fa-globe text-success me-1"></i> Support Environmental Sustainability</li>
                            <li><i class="fas fa-seedling text-success me-1"></i> Reduce Carbon Footprint</li>
                        </ul>
                    </div>
                </div>
            `;
            pickupInfoBtn.setAttribute('data-bs-content', popoverContent);
            new bootstrap.Popover(pickupInfoBtn);
        }
        
        // Payment method toggle functionality
        const gcashRadio = document.getElementById('gcash');
        const codRadio = document.getElementById('cod');
        const ecocoinsRadio = document.getElementById('ecocoins');
        const gcashDetails = document.getElementById('gcashDetails');
        const ecocoinsDetails = document.getElementById('ecocoinsDetails');
        const paymentRadios = document.querySelectorAll('input[name="paymentMethod"]');
        
        // Function to toggle GCash and EcoCoins details visibility
        function togglePaymentDetails() {
            if (gcashRadio && gcashRadio.checked) {
                gcashDetails.style.display = 'block';
                if (ecocoinsDetails) ecocoinsDetails.style.display = 'none';
                // Show peso prices
                if (document.getElementById('summarySubtotal')) document.getElementById('summarySubtotal').style.display = 'inline';
                if (document.getElementById('summarySubtotalEco')) document.getElementById('summarySubtotalEco').style.display = 'none';
                if (document.getElementById('summaryShipping')) document.getElementById('summaryShipping').style.display = 'inline';
                if (document.getElementById('summaryShippingEco')) document.getElementById('summaryShippingEco').style.display = 'none';
                if (document.getElementById('summaryHandling')) document.getElementById('summaryHandling').style.display = 'inline';
                if (document.getElementById('summaryHandlingEco')) document.getElementById('summaryHandlingEco').style.display = 'none';
                if (document.getElementById('summaryTotal')) document.getElementById('summaryTotal').style.display = 'inline';
                if (document.getElementById('summaryTotalEco')) document.getElementById('summaryTotalEco').style.display = 'none';
                if (document.getElementById('priceDisplay')) document.getElementById('priceDisplay').style.display = 'block';
                if (document.getElementById('priceDisplayEco')) document.getElementById('priceDisplayEco').style.display = 'none';
            } else if (ecocoinsRadio && ecocoinsRadio.checked) {
                gcashDetails.style.display = 'none';
                if (ecocoinsDetails) ecocoinsDetails.style.display = 'block';
                // Show ecocoins prices
                if (document.getElementById('summarySubtotal')) document.getElementById('summarySubtotal').style.display = 'none';
                if (document.getElementById('summarySubtotalEco')) document.getElementById('summarySubtotalEco').style.display = 'inline';
                if (document.getElementById('summaryShipping')) document.getElementById('summaryShipping').style.display = 'none';
                if (document.getElementById('summaryShippingEco')) document.getElementById('summaryShippingEco').style.display = 'inline';
                if (document.getElementById('summaryHandling')) document.getElementById('summaryHandling').style.display = 'none';
                if (document.getElementById('summaryHandlingEco')) document.getElementById('summaryHandlingEco').style.display = 'inline';
                if (document.getElementById('summaryTotal')) document.getElementById('summaryTotal').style.display = 'none';
                if (document.getElementById('summaryTotalEco')) document.getElementById('summaryTotalEco').style.display = 'inline';
                if (document.getElementById('priceDisplay')) document.getElementById('priceDisplay').style.display = 'none';
                if (document.getElementById('priceDisplayEco')) document.getElementById('priceDisplayEco').style.display = 'block';
            } else {
                gcashDetails.style.display = 'none';
                if (ecocoinsDetails) ecocoinsDetails.style.display = 'none';
                // Show peso prices
                if (document.getElementById('summarySubtotal')) document.getElementById('summarySubtotal').style.display = 'inline';
                if (document.getElementById('summarySubtotalEco')) document.getElementById('summarySubtotalEco').style.display = 'none';
                if (document.getElementById('summaryShipping')) document.getElementById('summaryShipping').style.display = 'inline';
                if (document.getElementById('summaryShippingEco')) document.getElementById('summaryShippingEco').style.display = 'none';
                if (document.getElementById('summaryHandling')) document.getElementById('summaryHandling').style.display = 'inline';
                if (document.getElementById('summaryHandlingEco')) document.getElementById('summaryHandlingEco').style.display = 'none';
                if (document.getElementById('summaryTotal')) document.getElementById('summaryTotal').style.display = 'inline';
                if (document.getElementById('summaryTotalEco')) document.getElementById('summaryTotalEco').style.display = 'none';
                if (document.getElementById('priceDisplay')) document.getElementById('priceDisplay').style.display = 'block';
                if (document.getElementById('priceDisplayEco')) document.getElementById('priceDisplayEco').style.display = 'none';
            }
        }
        
        // Initial check on page load
        togglePaymentDetails();
        
        // Add event listeners to payment radio buttons
        paymentRadios.forEach(radio => {
            radio.addEventListener('change', togglePaymentDetails);
        });
        
        // Upload receipt functionality
        const uploadReceiptBtn = document.getElementById('uploadReceiptBtn');
        if (uploadReceiptBtn) {
            uploadReceiptBtn.addEventListener('click', function() {
                // Create file input
                const fileInput = document.createElement('input');
                fileInput.type = 'file';
                fileInput.accept = 'image/*';
                fileInput.style.display = 'none';
                
                fileInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        // Validate file type
                        if (!file.type.startsWith('image/')) {
                            Swal.fire({
                                title: 'Invalid File',
                                text: 'Please upload an image file.',
                                icon: 'error',
                                confirmButtonColor: '#198754'
                            });
                            return;
                        }
                        
                        // Validate file size (max 5MB)
                        if (file.size > 5 * 1024 * 1024) {
                            Swal.fire({
                                title: 'File Too Large',
                                text: 'Please upload an image smaller than 5MB.',
                                icon: 'error',
                                confirmButtonColor: '#198754'
                            });
                            return;
                        }
                        
                        // Read and display the image
                        const reader = new FileReader();
                        reader.onload = function(event) {
                            const receiptPreview = document.getElementById('receiptPreview');
                            const receiptImage = document.getElementById('receiptImage');
                            const receiptData = document.getElementById('receiptData');
                            
                            receiptImage.src = event.target.result;
                            receiptData.value = event.target.result;
                            receiptPreview.style.display = 'block';
                            
                            Swal.fire({
                                title: 'Receipt Uploaded!',
                                text: 'Your payment receipt has been uploaded successfully.',
                                icon: 'success',
                                confirmButtonColor: '#198754',
                                timer: 2000
                            });
                        };
                        reader.readAsDataURL(file);
                    }
                });
                
                document.body.appendChild(fileInput);
                fileInput.click();
                document.body.removeChild(fileInput);
            });
        }

        // When user confirms payment in the GCash modal, automatically open upload receipt
        const confirmGcashPaymentBtn = document.getElementById('confirmGcashPayment');
        if (confirmGcashPaymentBtn) {
            confirmGcashPaymentBtn.addEventListener('click', function() {
                // Hide the GCash modal first
                var gcashModalEl = document.getElementById('gcashQrModal');
                try {
                    var gcashModal = bootstrap.Modal.getInstance(gcashModalEl) || new bootstrap.Modal(gcashModalEl);
                    gcashModal.hide();
                } catch (err) {
                    // ignore if bootstrap modal instance not found
                }

                // Slight delay to allow modal to hide cleanly, then trigger upload
                setTimeout(function() {
                    if (uploadReceiptBtn) {
                        uploadReceiptBtn.click();
                    } else {
                        // Fallback: show an informational alert if upload button isn't present
                        Swal.fire({
                            title: 'Upload Receipt',
                            text: 'Please upload your payment receipt on the next screen.',
                            icon: 'info',
                            confirmButtonColor: '#198754'
                        });
                    }
                }, 300);
            });
        }
        
        var placeOrderBtn = document.getElementById('placeOrderBtn');
        var checkoutForm = document.getElementById('checkoutForm');
        if (placeOrderBtn && checkoutForm) {
            placeOrderBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var paymentMethod = document.querySelector('input[name="paymentMethod"]:checked').value;
                document.getElementById('paymentMethodInput').value = paymentMethod;
                
                // Check if EcoCoins payment is selected and user has insufficient balance
                if (paymentMethod === 'ecocoins') {
                    const userBalance = <?php echo (int)$user_ecocoins_balance; ?>;
                    const requiredAmount = <?php echo (int)$total_ecocoins; ?>;
                    if (userBalance < requiredAmount) {
                        Swal.fire({
                            title: 'Insufficient EcoCoins',
                            text: 'You need ' + (requiredAmount - userBalance).toLocaleString() + ' more EcoCoins to complete this purchase. Current balance: ' + userBalance.toLocaleString() + ' coins.',
                            icon: 'error',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#28a745'
                        });
                        return;
                    }
                }
                
                // If GCash is selected and no receipt uploaded, show warning
                if (paymentMethod === 'gcash') {
                    const receiptData = document.getElementById('receiptData').value;
                    if (!receiptData) {
                        Swal.fire({
                            title: 'Receipt Required',
                            text: 'Please upload your GCash payment receipt before placing the order.',
                            icon: 'warning',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#28a745'
                        });
                        return;
                    }
                }
                
                Swal.fire({
                    title: 'Confirm Order',
                    text: 'Are you sure you want to place this order?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, place order',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // For EcoCoins, redirect to ecocoins-payment.php for confirmation
                        if (paymentMethod === 'ecocoins') {
                            let total = document.getElementById('hiddenTotal').value;
                            let shippingAddress = document.getElementById('deliveryAddress').value;
                            let productId = document.querySelector('input[name="product_id"]').value;
                            let quantity = document.querySelector('input[name="quantity"]').value;
                            // Redirect to ecocoins-payment.php with parameters
                            window.location.href = `ecocoins-payment.php?amount=${encodeURIComponent(total)}&order_id=ECO-${Date.now()}&shipping_address=${encodeURIComponent(shippingAddress)}&product_id=${encodeURIComponent(productId)}&quantity=${encodeURIComponent(quantity)}&payment_method=ecocoins`;
                        } else {
                            // For COD and GCash, submit the form directly
                            document.getElementById('checkoutForm').submit();
                        }
                    }
                });
            });
        }
            // PHP values for both PHP and Ecocoins
            const summaryValues = {
                php: {
                    subtotal: <?php echo json_encode(number_format($subtotal, 2)); ?>,
                    shipping: <?php echo json_encode(number_format($shipping_fee, 2)); ?>,
                    handling: <?php echo json_encode(number_format($handling_fee, 2)); ?>,
                    total: <?php echo json_encode(number_format($total, 2)); ?>
                },
                ecocoins: {
                    subtotal: <?php echo json_encode(number_format($subtotal_ecocoins, 2)); ?>,
                    shipping: <?php echo json_encode(number_format($shipping_fee_ecocoins, 2)); ?>,
                    handling: <?php echo json_encode(number_format($handling_fee_ecocoins, 2)); ?>,
                    total: <?php echo json_encode(number_format($total_ecocoins, 2)); ?>
                }
            };
            
            // Function to update summary display based on payment method
            function updateSummaryDisplay(paymentMethod) {
                // Toggle payment details visibility based on method
                togglePaymentDetails();
            }
            
            // Add change listeners to payment methods to update summary
            paymentRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    updateSummaryDisplay(this.value);
                });
            });
            
            // Initial display
            updateSummaryDisplay(document.querySelector('input[name="paymentMethod"]:checked').value);
            
            // Keep SweetAlert confirm flow above; do not attach a second click handler that bypasses it.
            
            // Enable editing delivery address on Change button click
            const changeAddressBtn = document.getElementById('changeAddressBtn');
            const deliveryAddress2 = document.getElementById('deliveryAddress');
            if (changeAddressBtn && deliveryAddress2) {
                changeAddressBtn.addEventListener('click', function() {
                    deliveryAddress2.removeAttribute('readonly');
                    deliveryAddress2.focus();
                });
            }

            // Delivery method toggle
            const deliveryRadios = document.querySelectorAll('input[name="delivery_method"]');
            const addressSection = document.getElementById('addressSection');
            const pickupSection = document.getElementById('pickupSection');
            const summaryDeliveryMethod = document.getElementById('summaryDeliveryMethod');
            const deliveryAddressField = document.getElementById('deliveryAddress');
            
            // EcoCoins points display elements
            const deliveryPoints = document.getElementById('deliveryPoints');
            const pickupPoints = document.getElementById('pickupPoints');
            const rewardMessage = document.getElementById('rewardMessage');
            
            deliveryRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    const isDelivery = this.value === 'delivery';
                    
                    // Show/hide address field based on delivery method
                    if (isDelivery) {
                        addressSection.style.display = 'block';
                        pickupSection.style.display = 'none';
                        deliveryAddressField.required = true;
                        summaryDeliveryMethod.textContent = 'Delivery';
                        
                        // Restore original shipping and handling fees for delivery
                        const summaryShipping = document.getElementById('summaryShipping');
                        const summaryHandling = document.getElementById('summaryHandling');
                        const summaryShippingEco = document.getElementById('summaryShippingEco');
                        const summaryHandlingEco = document.getElementById('summaryHandlingEco');
                        const summaryTotal = document.getElementById('summaryTotal');
                        const summaryTotalEco = document.getElementById('summaryTotalEco');
                        const hiddenTotal = document.getElementById('hiddenTotal');
                        const hiddenTotalEco = document.getElementById('hiddenTotalEco');
                        
                        if (summaryShipping) summaryShipping.textContent = `₱${summaryValues.php.shipping}`;
                        if (summaryHandling) summaryHandling.textContent = `₱${summaryValues.php.handling}`;
                        if (summaryShippingEco) summaryShippingEco.textContent = `${summaryValues.ecocoins.shipping} EcoCoins`;
                        if (summaryHandlingEco) summaryHandlingEco.textContent = `${summaryValues.ecocoins.handling} EcoCoins`;
                        if (summaryTotal) summaryTotal.textContent = `₱${summaryValues.php.total}`;
                        if (summaryTotalEco) summaryTotalEco.textContent = `${summaryValues.ecocoins.total} EcoCoins`;
                        if (hiddenTotal) hiddenTotal.value = '<?php echo $total; ?>';
                        if (hiddenTotalEco) hiddenTotalEco.value = '<?php echo $total_ecocoins; ?>';
                        
                        // Update EcoCoins rewards display for delivery
                        if (deliveryPoints) {
                            deliveryPoints.className = 'badge bg-success';
                            deliveryPoints.style.textDecoration = 'none';
                        }
                        if (pickupPoints) {
                            pickupPoints.className = 'badge bg-secondary';
                            pickupPoints.style.textDecoration = 'line-through';
                        }
                        if (rewardMessage) {
                            rewardMessage.innerHTML = 'Choose pickup to earn <strong><?php echo number_format($ecocoins_awarded_pickup - $ecocoins_awarded_delivery, 2); ?></strong> more EcoCoins and help the environment!';
                        }
                    } else {
                        addressSection.style.display = 'none';
                        pickupSection.style.display = 'block';
                        deliveryAddressField.required = false;
                        summaryDeliveryMethod.textContent = 'Pick Up - ECSD DMMMSU Sapilang, Bacnotan La Union';
                        
                        // Set shipping and handling fees to zero for pickup
                        const summaryShipping = document.getElementById('summaryShipping');
                        const summaryHandling = document.getElementById('summaryHandling');
                        const summaryShippingEco = document.getElementById('summaryShippingEco');
                        const summaryHandlingEco = document.getElementById('summaryHandlingEco');
                        const summaryTotal = document.getElementById('summaryTotal');
                        const summaryTotalEco = document.getElementById('summaryTotalEco');
                        const hiddenTotal = document.getElementById('hiddenTotal');
                        const hiddenTotalEco = document.getElementById('hiddenTotalEco');
                        const summarySubtotal = document.getElementById('summarySubtotal');
                        const summarySubtotalEco = document.getElementById('summarySubtotalEco');
                        
                        // Calculate new totals without shipping and handling fees
                        const subtotalValue = <?php echo $subtotal; ?>;
                        const subtotalEcoValue = <?php echo $subtotal_ecocoins; ?>;
                        
                        if (summaryShipping) summaryShipping.textContent = '₱0.00';
                        if (summaryHandling) summaryHandling.textContent = '₱0.00';
                        if (summaryShippingEco) summaryShippingEco.textContent = '0.00 EcoCoins';
                        if (summaryHandlingEco) summaryHandlingEco.textContent = '0.00 EcoCoins';
                        if (summaryTotal) summaryTotal.textContent = `₱${subtotalValue.toFixed(2)}`;
                        if (summaryTotalEco) summaryTotalEco.textContent = `${subtotalEcoValue.toFixed(2)} EcoCoins`;
                        if (hiddenTotal) hiddenTotal.value = subtotalValue.toFixed(2);
                        if (hiddenTotalEco) hiddenTotalEco.value = subtotalEcoValue.toFixed(2);
                        
                        // Update EcoCoins rewards display for pickup
                        if (deliveryPoints) {
                            deliveryPoints.className = 'badge bg-secondary';
                            deliveryPoints.style.textDecoration = 'line-through';
                        }
                        if (pickupPoints) {
                            pickupPoints.className = 'badge bg-warning text-dark';
                            pickupPoints.style.textDecoration = 'none';
                        }
                        if (rewardMessage) {
                            rewardMessage.innerHTML = '<i class="fas fa-star me-1"></i>Great choice! You\'ll earn <strong><?php echo number_format($ecocoins_awarded_pickup, 2); ?></strong> EcoCoins for helping the environment!';
                        }
                    }
                });
            });
        });
    </script>

<!-- Weight-based Rates Modal -->
<div class="modal fade" id="weightRatesModal" tabindex="-1" aria-labelledby="weightRatesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="weightRatesModalLabel">
                    <i class="fas fa-weight me-2"></i>Weight-based Shipping Rates
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle me-2"></i>Weight-based Rates</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <strong>NCR:</strong><br>
                            • 0-3 kg: ₱60-₱100<br>
                            • 3-5 kg: ₱100-₱150
                        </div>
                        <div class="col-md-4">
                            <strong>Luzon:</strong><br>
                            • 0-3 kg: ₱75-₱120<br>
                            • 3-5 kg: ₱120-₱180
                        </div>
                        <div class="col-md-4">
                            <strong>Visayas/Mindanao:</strong><br>
                            • 0-3 kg: ₱90-₱150<br>
                            • 3-5 kg: ₱150-₱220
                        </div>
                    </div>
                </div>
                <div class="alert alert-warning mt-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Note:</strong> Rates may vary based on exact location and courier availability.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Size-based Rates Modal -->
<div class="modal fade" id="sizeRatesModal" tabindex="-1" aria-labelledby="sizeRatesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sizeRatesModalLabel">
                    <i class="fas fa-box me-2"></i>Size-based Shipping Rates
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle me-2"></i>Size-based Rates</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Small Box (Flat Rate):</strong><br>
                            • Price: ₱45<br>
                            • Maximum weight: up to 3 kg<br>
                            • Best for: Small items, documents
                        </div>
                        <div class="col-md-6">
                            <strong>Large Box:</strong><br>
                            • Price: ₱70-₱105<br>
                            • Varies by location<br>
                            • Best for: Bulky items, multiple products
                        </div>
                    </div>
                </div>
                <div class="alert alert-warning mt-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Note:</strong> Large box rates vary depending on destination and courier.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
<?php
