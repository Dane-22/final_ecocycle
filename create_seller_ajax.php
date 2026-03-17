<?php
// Include session check for buyers
require_once 'config/session_check.php';
require_once 'config/database.php';

// Check if user is a buyer
if (!isBuyer()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'You must be logged in as a buyer to become a seller.']);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'create_seller') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

try {
    $buyer_id = getCurrentUserId();
    $fullname = getCurrentFullname();
    $username = $_SESSION['username'];
    $email = getCurrentEmail();

    // Get buyer info (including phone, address, password)
    $stmt = $pdo->prepare('SELECT * FROM Buyers WHERE buyer_id = ?');
    $stmt->execute([$buyer_id]);
    $buyer = $stmt->fetch();
    
    if (!$buyer) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Buyer account not found.']);
        exit();
    }
    
    $phone = $buyer['phone_number'];
    $address = $buyer['address'];
    $password = $buyer['password']; // Already hashed

    // Check if already a seller (by email or username)
    $stmt = $pdo->prepare('SELECT * FROM Sellers WHERE email = ? OR username = ?');
    $stmt->execute([$email, $username]);
    $seller = $stmt->fetch();

    if ($seller) {
        // Already a seller, set session and return success
        $_SESSION['user_type'] = 'seller';
        $_SESSION['user_id'] = $seller['seller_id'];
        $_SESSION['fullname'] = $seller['fullname'];
        $_SESSION['username'] = $seller['username'];
        $_SESSION['email'] = $seller['email'];
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Seller account already exists.']);
        exit();
    }

    // Create new seller account
    $stmt = $pdo->prepare('INSERT INTO Sellers (fullname, username, phone_number, email, password, address, status) VALUES (?, ?, ?, ?, ?, ?, "active")');
    $stmt->execute([
        $fullname,
        $username,
        $phone,
        $email,
        $password,
        $address
    ]);
    
    $seller_id = $pdo->lastInsertId();

    // Set session as seller
    $_SESSION['user_type'] = 'seller';
    $_SESSION['user_id'] = $seller_id;
    $_SESSION['fullname'] = $fullname;
    $_SESSION['username'] = $username;
    $_SESSION['email'] = $email;

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Seller account created successfully!']);
    
} catch (PDOException $e) {
    error_log("Error creating seller account: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error occurred. Please try again.']);
}
?>
