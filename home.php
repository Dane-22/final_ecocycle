<?php 
// Include session check for buyers
require_once 'config/session_check.php';

// Check if user is a buyer
if (!isBuyer()) {
    header("Location: login.php");
    exit();
}

// Include database connection
require_once 'config/database.php';

// Fetch categories for filtering
try {
    $stmt = $pdo->prepare("SELECT * FROM categories ORDER BY name");
    $stmt->execute();
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
}

// Fetch sellers for filtering
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.seller_id, s.fullname as seller_name 
        FROM sellers s
        JOIN products p ON s.seller_id = p.seller_id
        WHERE p.status = 'active'
        ORDER BY s.fullname
    ");
    $stmt->execute();
    $sellers = $stmt->fetchAll();
} catch (PDOException $e) {
    $sellers = [];
}

// Handle filtering by category, seller, and best seller status
$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
$seller_filter = isset($_GET['seller']) ? intval($_GET['seller']) : 0;
$best_seller_filter = isset($_GET['best_seller']) ? intval($_GET['best_seller']) : 0;

// Fetch products from database with filters
try {
    $query = "
      SELECT p.*, c.name as category_name, s.fullname as seller_name, s.address as seller_location, s.seller_id, 'regular' as product_type,
             COALESCE(COUNT(CASE WHEN oi.status = 'delivered' THEN 1 END), 0) as sales_count
      FROM products p
      JOIN categories c ON p.category_id = c.category_id
      JOIN sellers s ON p.seller_id = s.seller_id
      LEFT JOIN order_items oi ON p.product_id = oi.product_id
      WHERE p.status = 'active'
    ";
    $params = [];
    
    if ($category_filter > 0) {
        $query .= " AND c.category_id = ?";
        $params[] = $category_filter;
    }
    
    if ($seller_filter > 0) {
        $query .= " AND s.seller_id = ?";
        $params[] = $seller_filter;
    }
    
    $query .= " GROUP BY p.product_id";
    
    if ($best_seller_filter == 1) {
        $query .= " HAVING COUNT(CASE WHEN oi.status = 'delivered' THEN 1 END) >= 5";
    }
    
    $query .= " ORDER BY p.created_at ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    $products = [];
}

// Handle add to cart functionality
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_to_cart'])) {
  $product_id = $_POST['product_id'];
  $quantity = $_POST['quantity'] ?? 1;
  $buyer_id = getCurrentUserId();
    
  try {
    // Check if product already exists in cart
    $stmt = $pdo->prepare("SELECT * FROM cart WHERE buyer_id = ? AND product_id = ?");
    $stmt->execute([$buyer_id, $product_id]);
    $existing_cart_item = $stmt->fetch();
        
    if ($existing_cart_item) {
      // Update quantity
      $new_quantity = $existing_cart_item['quantity'] + $quantity;
      $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE cart_id = ?");
      $stmt->execute([$new_quantity, $existing_cart_item['cart_id']]);
    } else {
      // Add new item to cart
      $stmt = $pdo->prepare("INSERT INTO cart (buyer_id, product_id, quantity) VALUES (?, ?, ?)");
      $stmt->execute([$buyer_id, $product_id, $quantity]);
    }
    $success_message = "Product added to cart successfully!";
  } catch (PDOException $e) {
    $error_message = "Failed to add product to cart.";
  }
}

// Show error if redirected from codpayment.php
if (isset($_GET['error']) && $_GET['error'] === 'cod_payment_failed') {
  $error_message = "Sorry, your order could not be processed.";
  if (isset($_GET['detail'])) {
    $error_message .= "<br><strong>Reason:</strong> " . htmlspecialchars($_GET['detail']);
  } else {
    $error_message .= " Please try again or contact support.";
  }
}

// Get cart count for the current user
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cart_count FROM cart WHERE buyer_id = ?");
    $stmt->execute([getCurrentUserId()]);
    $cart_count = $stmt->fetch()['cart_count'];
} catch (PDOException $e) {
    $cart_count = 0;
}

// Get buyer's order statistics
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_orders FROM orders WHERE buyer_id = ?");
    $stmt->execute([getCurrentUserId()]);
    $total_orders = $stmt->fetch()['total_orders'];
} catch (PDOException $e) {
    $total_orders = 0;
}

include 'homeheader.php';
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <title>Ecocycle NLUC</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="format-detection" content="telephone=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="author" content="">
    <meta name="keywords" content="">
    <meta name="description" content="">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-KK94CHFLLe+nY2dmCWGMq91rCGa5gtU4mk92HdvYe+M/SXH301p5ILy+dN9+nJOZ" crossorigin="anonymous">
    <link rel="stylesheet" type="text/css" href="css/vendor.css">
    <link rel="stylesheet" type="text/css" href="style.css">
    <!-- Font Awesome for sidebar icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;700&family=Open+Sans:ital,wght@0,400;0,700;1,400;1,700&display=swap" rel="stylesheet">

    <style>
      .payment-options {
        padding: 10px;
      }
      .form-check-label {
        padding: 10px;
        border-radius: 8px;
      }
      
      /* Force all mobile elements to be visible - Maximum specificity */
      @media (max-width: 576px) {
        /* Change product grid to 1 column on mobile - Override Bootstrap */
        .col-6 {
          flex: 0 0 100% !important;
          max-width: 100% !important;
        }
        
        /* More specific override for Bootstrap grid */
        div[class*="col-"] {
          flex: 0 0 100% !important;
          max-width: 100% !important;
        }
        
        /* Override row behavior */
        .row > .col-6 {
          flex: 0 0 100% !important;
          max-width: 100% !important;
        }
        
        .product-card {
          margin-bottom: 1rem;
          min-height: 280px;
          display: flex !important;
          flex-direction: column !important;
          width: 100% !important;
          max-width: 100% !important;
          border-radius: 12px !important;
          overflow: hidden !important;
          box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
        }
        
        .card-img-top {
          height: 140px !important;
          object-fit: cover !important;
          width: 100% !important;
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        .card-body {
          padding: 0.75rem !important;
          display: flex !important;
          flex-direction: column !important;
          flex: 1 !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        .card-title {
          font-size: 0.95rem !important;
          margin-bottom: 0.5rem !important;
          line-height: 1.2 !important;
          height: auto !important;
          max-height: 2.4rem !important;
          overflow: hidden !important;
          display: -webkit-box !important;
          -webkit-line-clamp: 2 !important;
          -webkit-box-orient: vertical !important;
          flex-shrink: 0 !important;
          color: #333 !important;
          font-weight: 600 !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        .card-text {
          font-size: 0.8rem !important;
          margin-bottom: 0.75rem !important;
          line-height: 1.3 !important;
          height: auto !important;
          max-height: 2.6rem !important;
          overflow: hidden !important;
          display: -webkit-box !important;
          -webkit-line-clamp: 2 !important;
          -webkit-box-orient: vertical !important;
          flex-shrink: 0 !important;
          color: #666 !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Force mt-auto section to be visible */
        .mt-auto {
          margin-top: auto !important;
          flex-shrink: 0 !important;
          position: relative !important;
          z-index: 10 !important;
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Force stock badges to be visible */
        .mb-1 {
          margin-bottom: 0.5rem !important;
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        .stock-label {
          font-size: 0.7rem !important;
          padding: 2px 6px !important;
          margin-bottom: 0.5rem !important;
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
          order: 0 !important;
          flex-shrink: 0 !important;
          position: relative !important;
          z-index: 8 !important;
          background: #6c757d !important;
          color: white !important;
          border-radius: 3px !important;
        }
        
        .stock-label.out {
          background-color: #dc3545 !important;
          color: white !important;
        }
        
        /* Force price to be visible - Multiple selectors for maximum specificity */
        .fw-bold.text-success.fs-5.mb-2,
        p.fw-bold.text-success.fs-5.mb-2,
        div.card-body > p.fw-bold.text-success.fs-5.mb-2,
        .product-card .fw-bold.text-success.fs-5.mb-2,
        .card-body .fw-bold.text-success.fs-5.mb-2 {
          font-size: 1.2rem !important;
          font-weight: 700 !important;
          color: #28bf4b !important;
          margin: 0.75rem 0 !important;
          margin-bottom: 0.75rem !important;
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
          line-height: 1.3 !important;
          order: 1 !important;
          flex-shrink: 0 !important;
          position: relative !important;
          z-index: 100 !important;
          background: rgba(40, 191, 75, 0.1) !important;
          padding: 8px !important;
          border-radius: 6px !important;
          border: 1px solid rgba(40, 191, 75, 0.3) !important;
          text-align: left !important;
          width: 100% !important;
          box-sizing: border-box !important;
        }
        
        /* Additional price visibility with inline styles override */
        .card-body > p[class*="fw-bold"][class*="text-success"] {
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
          color: #28bf4b !important;
          font-size: 1.2rem !important;
          font-weight: 700 !important;
          margin: 0.75rem 0 !important;
          padding: 8px !important;
          background: rgba(40, 191, 75, 0.1) !important;
          border: 1px solid rgba(40, 191, 75, 0.3) !important;
          border-radius: 6px !important;
        }
        
        /* Force EcoCoins badge to be visible */
        .d-flex.justify-content-between.align-items-center.mb-2 {
          display: flex !important;
          justify-content: space-between !important;
          align-items: center !important;
          margin-bottom: 0.75rem !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        .badge.bg-light.text-dark {
          font-size: 0.75rem !important;
          padding: 3px 8px !important;
          margin-bottom: 0.75rem !important;
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
          order: 2 !important;
          flex-shrink: 0 !important;
          position: relative !important;
          z-index: 9 !important;
          background: #f8f9fa !important;
          border: 1px solid #dee2e6 !important;
          border-radius: 4px !important;
        }
        
        /* Force button container to be visible */
        .d-flex {
          display: flex !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Force buy button to be visible */
        .buy-now-btn {
          font-size: 0.8rem !important;
          padding: 6px 12px !important;
          min-width: 60px !important;
          margin-right: 0.5rem !important;
          order: 3 !important;
          flex-shrink: 0 !important;
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Force cart button to be visible */
        .cart-icon-btn {
          font-size: 1rem !important;
          width: 32px !important;
          height: 32px !important;
          padding: 6px !important;
          order: 4 !important;
          flex-shrink: 0 !important;
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Force out of stock button to be visible */
        .btn.btn-secondary.btn-sm:disabled {
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Green Choice Badge */
        img[alt="Green Choice"] {
          height: 50px !important;
          top: 8px !important;
          right: 8px !important;
          z-index: 15 !important;
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Best Seller Badge */
        div[style*="BEST SELLER"] {
          font-size: 0.75rem !important;
          padding: 8px 12px !important;
          z-index: 15 !important;
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
      }
      
      @media (max-width: 480px) {
        .product-card {
          min-height: 260px;
        }
        
        .card-img-top {
          height: 120px !important;
        }
        
        .card-title {
          font-size: 0.9rem;
          max-height: 2.2rem;
        }
        
        .card-text {
          font-size: 0.75rem;
          max-height: 2.4rem;
        }
        
        .fw-bold.text-success.fs-5.mb-2 {
          font-size: 1rem !important;
        }
        
        .badge.bg-light.text-dark {
          font-size: 0.7rem !important;
          padding: 2px 6px !important;
        }
        
        .stock-label {
          font-size: 0.65rem !important;
          padding: 2px 5px !important;
        }
        
        .buy-now-btn {
          font-size: 0.75rem !important;
          padding: 5px 10px !important;
          min-width: 50px !important;
        }
        
        .cart-icon-btn {
          width: 28px !important;
          height: 28px !important;
          font-size: 0.9rem !important;
        }
      }
      
      @media (max-width: 400px) {
        .product-card {
          min-height: 240px;
        }
        
        .card-img-top {
          height: 110px !important;
        }
        
        .card-body {
          padding: 0.5rem !important;
        }
        
        .card-title {
          font-size: 0.85rem;
          max-height: 2rem;
        }
        
        .card-text {
          font-size: 0.7rem;
          max-height: 2.2rem;
        }
        
        .fw-bold.text-success.fs-5.mb-2 {
          font-size: 0.95rem !important;
          margin-bottom: 0.4rem !important;
        }
        
        .badge.bg-light.text-dark {
          font-size: 0.65rem !important;
          padding: 2px 5px !important;
          margin-bottom: 0.5rem !important;
        }
        
        .stock-label {
          font-size: 0.6rem !important;
          padding: 1px 4px !important;
          margin-bottom: 0.4rem !important;
        }
        
        .buy-now-btn {
          font-size: 0.7rem !important;
          padding: 4px 8px !important;
          min-width: 45px !important;
        }
        
        .cart-icon-btn {
          width: 26px !important;
          height: 26px !important;
          font-size: 0.85rem !important;
        }
      }
      
      @media (max-width: 360px) {
        .product-card {
          min-height: 220px;
        }
        
        .card-img-top {
          height: 100px !important;
        }
        
        .card-title {
          font-size: 0.8rem;
          max-height: 1.8rem;
        }
        
        .card-text {
          font-size: 0.65rem;
          max-height: 2rem;
        }
        
        .fw-bold.text-success.fs-5.mb-2 {
          font-size: 0.9rem !important;
          margin-bottom: 0.4rem !important;
        }
        
        .badge.bg-light.text-dark {
          font-size: 0.6rem !important;
          padding: 1px 4px !important;
        }
        
        .stock-label {
          font-size: 0.55rem !important;
          padding: 1px 3px !important;
        }
        
        .buy-now-btn {
          font-size: 0.65rem !important;
          padding: 3px 6px !important;
          min-width: 40px !important;
        }
        
        .cart-icon-btn {
          width: 24px !important;
          height: 24px !important;
          font-size: 0.8rem !important;
        }
      }  
      .form-check-input:checked + .form-check-label {
        background-color: #f0f8ff;
      }
      .form-check-input {
        margin-top: 0.8rem;
      }
      .product-card {
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        border-radius: 16px;
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
        border: none;
        position: relative;
        background: #fff;
      }
      .product-card:hover {
        transform: translateY(-4px) scale(1.02);
        /* Minimize buyer profile dropdown for mobile */
        @media (max-width: 600px) {
          .buyer-profile {
            flex-direction: row;
            align-items: center;
            padding: 4px 8px;
            min-width: 0;
          }
          .buyer-avatar {
            width: 28px;
            height: 28px;
            font-size: 1rem;
            margin-right: 6px;
          }
          .buyer-info {
            display: none;
          }
          #profileDropdownMenu {
            min-width: 140px;
            font-size: 0.95rem;
          }
        }

        /* Minimize buyer profile dropdown for iPad */
        @media (min-width: 601px) and (max-width: 1024px) {
          .buyer-profile {
            flex-direction: row;
            align-items: center;
            padding: 6px 12px;
            min-width: 0;
          }
          .buyer-avatar {
            width: 32px;
            height: 32px;
            font-size: 1.1rem;
            margin-right: 8px;
          }
          .buyer-info {
            display: none;
          }
          #profileDropdownMenu {
            min-width: 160px;
            font-size: 1rem;
          }
        }
        box-shadow: 0 6px 24px rgba(0,0,0,0.13);
      }
      .stock-label {
        color: #198754 !important; /* Bootstrap green */
        background: none !important;
        font-size: 0.85rem !important;
        padding: 4px 12px;
        border-radius: 12px;
        z-index: 2;
        font-weight: 700;
      }
        /* Mobile view (max-width: 600px) */
        @media (max-width: 600px) {
          .product-card {
            margin-bottom: 16px;
            border-radius: 10px;
          }
          .product-card .card-body {
            padding: 0.5rem;
          }
          .product-card .card-title {
            font-size: 1rem;
          }
          .product-card .product-price {
            font-size: 1rem;
          }
          .add-to-cart-btn {
            font-size: 0.95rem;
            padding: 0.4rem 0.8rem;
          }
          .container, .row {
            padding-left: 0;
            padding-right: 0;
          }
            .sidebar, .homesidebar {
              position: fixed !important;
              left: -280px;
              top: 60px;
              width: 280px !important;
              height: calc(100vh - 60px) !important;
              z-index: 1050;
              background: #fff !important;
              box-shadow: 2px 0 10px rgba(0,0,0,0.1);
              padding: 15px !important;
              overflow-y: auto !important;
              display: block !important;
              transition: left 0.3s ease;
            }
            .sidebar.show, .homesidebar.show {
              left: 0 !important;
            }
            .main-content {
              width: 100% !important;
              margin-left: 0 !important;
            }
            .main-content {
              width: 100% !important;
              margin-left: 0 !important;
            }
          .navbar, .homeheader {
            font-size: 1rem;
          }
        }

        /* iPad view (min-width: 601px and max-width: 1024px) */
        @media (min-width: 601px) and (max-width: 1024px) {
          .product-card {
            margin-bottom: 20px;
            border-radius: 14px;
          }
          .product-card .card-body {
            padding: 0.8rem;
          }
          .product-card .card-title {
            font-size: 1.15rem;
          }
          .product-card .product-price {
            font-size: 1.1rem;
          }
          .add-to-cart-btn {
            font-size: 1.05rem;
            padding: 0.5rem 1rem;
          }
          .container, .row {
            padding-left: 8px;
            padding-right: 8px;
          }
          .sidebar, .homesidebar {
            position: fixed !important;
            left: -220px;
            top: 60px;
            width: 220px !important;
            height: calc(100vh - 60px) !important;
            z-index: 1050;
            background: #fff !important;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            padding: 12px !important;
            overflow-y: auto !important;
            display: block !important;
            transition: left 0.3s ease;
          }
          .sidebar.show, .homesidebar.show {
            left: 0 !important;
          }
          .main-content {
            width: 100% !important;
            margin-left: 0 !important;
          }
          .navbar, .homeheader {
            font-size: 1.05rem;
          }
        }
      .stock-label.out {
        color: #dc3545 !important; /* Bootstrap red */
        background: none !important;
      }
      .best-seller-badge {
        position: absolute;
        left: 12px;
        top: 12px;
        background: #ffc107;
        color: #212529;
        font-size: 0.9rem;
        font-weight: bold;
        padding: 4px 12px;
        border-radius: 12px;
        z-index: 2;
        box-shadow: 0 1px 4px rgba(0,0,0,0.08);
      }
      .green-choice-badge {
        position: absolute;
        left: 12px;
        top: 12px;
        background: #28a745;
        color: #fff;
        font-size: 0.9rem;
        font-weight: bold;
        padding: 4px 12px;
        border-radius: 12px;
        z-index: 2;
        box-shadow: 0 1px 4px rgba(0,0,0,0.08);
      }
      .product-price {
        font-size: 1.2rem;
        font-weight: bold;
        color: #198754;
        margin-bottom: 0.5rem;
      }
      .add-to-cart-btn {
        font-weight: 600;
        letter-spacing: 0.5px;
        margin-bottom: 0.5rem;
      }
      /* Enhanced Product Details Modal */
      #productDetailsModal .modal-content {
        border-radius: 20px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.18);
        padding: 0;
        max-width: 440px;
        margin: auto;
      }
      #productDetailsModal .modal-header {
        border-bottom: none;
        padding-bottom: 0.3rem;
        padding-top: 0.7rem;
        background: #f8f9fa;
        border-radius: 20px 20px 0 0;
      }
      #productDetailsModal .modal-title {
        font-size: 1.2rem;
        font-weight: 700;
        color: #198754;
      }
      #productDetailsModal .modal-body {
        padding: 1rem 1rem 0.7rem 1rem;
        display: flex;
        flex-direction: column;
        align-items: center;
      }
      #modalProductImage {
        max-width: 140px;
        max-height: 140px;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.10);
        margin-bottom: 0.7rem;
        background: #fff;
        object-fit: cover;
      }
      #modalProductStock {
        font-size: 0.95rem;
        color: #6c757d;
        margin-bottom: 0.3rem;
      }
      #modalProductPrice {
        font-size: 1.2rem;
        font-weight: 700;
        color: #198754;
        margin-bottom: 0.4rem;
      }
      #modalProductEcocoinsPrice {
        font-size: 0.95rem;
        color: #6c757d;
        margin-bottom: 0.7rem;
      }
      #modalProductDescription {
        font-size: 0.95rem;
        color: #333;
        margin-bottom: 0.7rem;
        text-align: center;
      }
      #productDetailsModal .add-to-cart-btn {
        font-size: 1rem;
        font-weight: 700;
        border-radius: 10px;
        box-shadow: none;
        background: none;
        color: #198754;
        border: 1px solid #198754;
        transition: background 0.2s, color 0.2s;
      }
      #productDetailsModal .add-to-cart-btn:hover {
        background: #198754;
        color: #fff;
      }
      @media (max-width: 600px) {
        #productDetailsModal .modal-content {
          max-width: 98vw;
          padding: 0;
        }
        #productDetailsModal .modal-body {
          padding: 1rem 0.5rem 1rem 0.5rem;
        }
      }
      html {
        scroll-behavior: smooth;
      }
      .product-card.compact-card .card-body {
        padding: 0.15rem 0.25rem 0.15rem 0.25rem;
        font-size: 0.75rem;
      }
      .product-card.compact-card .card-title {
        font-size: 1rem;
        margin-bottom: 0.1rem;
        line-height: 1.1;
      }
      .product-card.compact-card .small {
        font-size: 0.85rem;
        margin-bottom: 0.1rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
        display: block;
      }
      .product-card.compact-card .product-price,
      .product-card.compact-card .ecocoins-price {
        font-size: 1.1rem;
        margin-bottom: 0.08rem;
      }
      .product-card.compact-card .add-to-cart-btn {
        font-size: 1rem;
        padding: 0.35rem 0.6rem;
        margin-bottom: 0.08rem;
        font-weight: 700;
      }
      .product-card.compact-card .stock-label {
        font-size: 0.8rem;
        padding: 2px 6px;
        top: 4px;
        right: 4px;
      }
      .product-card.compact-card .best-seller-badge,
      .product-card.compact-card .green-choice-badge {
        font-size: 0.8rem;
        padding: 2px 6px;
        top: 4px;
        left: 4px;
      }
      .product-card.compact-card .mt-auto {
        margin-top: 0.02rem !important;
      }
      .product-card {
        height: 160px; /* Minimized card height */
        display: flex;
        flex-direction: column;
      }
      .product-card .ratio {
        overflow: hidden;
      }
      .product-card .card-img-top {
        width: 100%;
        height: 100%;
        object-fit: cover;
      }
      .product-card .card-body {
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
        padding-bottom: 0;
        min-height: 0;
      }
      .product-card .card-title {
        font-size: 0.95rem;
        font-weight: 700;
        margin-bottom: 0.08rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .product-card .small {
        font-size: 0.8rem;
        margin-bottom: 0.08rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
        display: block;
      }
      .product-card .product-price,
      .product-card .ecocoins-price {
        font-size: 1rem;
        margin-bottom: 0.05rem;
      }
      .button-container { margin-top: auto; }
      /* Custom green Buy button styles */
      .buy-now-btn {
        background: #22bb33 !important;
        color: #fff !important;
        border: none !important;
        font-weight: bold !important;
        min-width: 80px !important;
        padding: 0.15rem 0.5rem !important;
        border-radius: 4px !important;
        font-size: 0.95rem !important;
        height: 32px !important;
        line-height: 1.1 !important;
        transition: background 0.2s;
      }
      .buy-now-btn:hover, .buy-now-btn:focus {
        background: #179a27 !important;
        color: #fff !important;
      }
      /* Cart icon button styles */
      .cart-icon-btn {
        transition: background 0.2s, box-shadow 0.2s;
        background: none !important;
        border: none !important;
        padding: 0 !important;
        width: 32px !important;
        height: 32px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        cursor: pointer;
      }
      .cart-icon-btn:focus {
        outline: none !important;
        box-shadow: none !important;
      }

      .cart-icon-btn:hover {
        background: #e6f9ec !important;
        box-shadow: 0 2px 8px rgba(34,187,51,0.12);
      }

      .cart-icon-btn:hover i {
        color: #179a27 !important;
        /* darker green for cart icon */
      }
    </style>
    
    <!-- FINAL MOBILE OVERRIDE - Must be last to override all conflicts -->
    <style>
      @media (max-width: 576px) {
        /* Force 1 column layout - Override ALL conflicts */
        .col-6,
        div[class*="col-6"],
        .row > .col-6 {
          flex: 0 0 100% !important;
          max-width: 100% !important;
          width: 100% !important;
        }
        
        /* Force proper card height - Override compact card styles */
        .product-card,
        .product-card.compact-card {
          height: auto !important;
          min-height: 320px !important;
          max-height: none !important;
          display: flex !important;
          flex-direction: column !important;
          width: 100% !important;
          margin-bottom: 1rem !important;
          border-radius: 12px !important;
          overflow: hidden !important;
          box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
        }
        
        /* Force proper image display */
        .card-img-top,
        .product-card .card-img-top {
          height: 140px !important;
          width: 100% !important;
          object-fit: cover !important;
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Force proper card body */
        .card-body,
        .product-card .card-body {
          display: flex !important;
          flex-direction: column !important;
          flex: 1 !important;
          padding: 0.75rem !important;
          visibility: visible !important;
          opacity: 1 !important;
          background: white !important;
        }
        
        /* Force title visibility */
        .card-title,
        .product-card .card-title,
        h5.card-title {
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
          font-size: 0.95rem !important;
          color: #333 !important;
          font-weight: 600 !important;
          margin-bottom: 0.5rem !important;
          line-height: 1.2 !important;
          max-height: 2.4rem !important;
          overflow: hidden !important;
        }
        
        /* Force description visibility */
        .card-text,
        .product-card .card-text,
        p.card-text {
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
          font-size: 0.8rem !important;
          color: #666 !important;
          margin-bottom: 0.75rem !important;
          line-height: 1.3 !important;
          max-height: 2.6rem !important;
          overflow: hidden !important;
        }
        
        /* Force mt-auto section */
        .mt-auto,
        .product-card .mt-auto {
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
          margin-top: auto !important;
          flex-shrink: 0 !important;
        }
        
        /* Force stock badge visibility */
        .stock-label,
        .product-card .stock-label,
        span.stock-label {
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
          font-size: 0.7rem !important;
          padding: 2px 6px !important;
          margin-bottom: 0.5rem !important;
          background: #6c757d !important;
          color: white !important;
          border-radius: 3px !important;
        }
        
        .stock-label.out {
          background-color: #dc3545 !important;
        }
        
        /* Force price visibility - Multiple approaches */
        .fw-bold.text-success.fs-5.mb-2,
        p.fw-bold.text-success.fs-5.mb-2,
        .product-card .fw-bold.text-success.fs-5.mb-2,
        .card-body .fw-bold.text-success.fs-5.mb-2 {
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
          font-size: 1.2rem !important;
          color: #28bf4b !important;
          font-weight: 700 !important;
          margin: 0.75rem 0 !important;
          background: rgba(40, 191, 75, 0.1) !important;
          padding: 8px !important;
          border-radius: 6px !important;
          border: 1px solid rgba(40, 191, 75, 0.3) !important;
          z-index: 1000 !important;
          position: relative !important;
          text-align: left !important;
        }
        
        /* Force EcoCoins badge visibility */
        .badge.bg-light.text-dark,
        .product-card .badge.bg-light.text-dark,
        span.badge.bg-light.text-dark {
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
          font-size: 0.75rem !important;
          padding: 3px 8px !important;
          margin-bottom: 0.75rem !important;
          background: #f8f9fa !important;
          border: 1px solid #dee2e6 !important;
          border-radius: 4px !important;
        }
        
        /* Force button containers */
        .d-flex,
        .product-card .d-flex,
        .d-flex.justify-content-between,
        .d-flex.justify-content-between.align-items-center {
          display: flex !important;
          visibility: visible !important;
          opacity: 1 !important;
          justify-content: space-between !important;
          align-items: center !important;
          margin-bottom: 0.75rem !important;
        }
        
        /* Force buy button visibility */
        .buy-now-btn,
        .product-card .buy-now-btn,
        button.buy-now-btn {
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
          font-size: 0.8rem !important;
          padding: 6px 12px !important;
          min-width: 60px !important;
          margin-right: 0.5rem !important;
          background: #22bb33 !important;
          color: white !important;
          border: none !important;
          border-radius: 4px !important;
          cursor: pointer !important;
        }
        
        /* Force cart button visibility */
        .cart-icon-btn,
        .product-card .cart-icon-btn,
        button.cart-icon-btn {
          display: inline-flex !important;
          visibility: visible !important;
          opacity: 1 !important;
          width: 32px !important;
          height: 32px !important;
          padding: 6px !important;
          background: none !important;
          border: none !important;
          cursor: pointer !important;
          align-items: center !important;
          justify-content: center !important;
        }
        
        .cart-icon-btn i,
        .product-card .cart-icon-btn i {
          color: #22bb33 !important;
          font-size: 1rem !important;
        }
        
        /* Force form containers */
        form,
        .product-card form {
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Force out of stock button */
        .btn.btn-secondary.btn-sm:disabled,
        .product-card .btn.btn-secondary.btn-sm:disabled {
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
          background: #6c757d !important;
          color: white !important;
          padding: 6px 12px !important;
          border: none !important;
          border-radius: 4px !important;
        }
        
        /* Force all divs in card body to be visible */
        .card-body > div,
        .product-card .card-body > div {
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Force all paragraphs in card body to be visible */
        .card-body > p,
        .product-card .card-body > p {
          display: block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
        
        /* Force all spans in card body to be visible */
        .card-body > span,
        .product-card .card-body > span {
          display: inline-block !important;
          visibility: visible !important;
          opacity: 1 !important;
        }
      }
    </style>
  </head>
  <body>
    <div class="container-fluid">
      <div class="row">
        <!-- Main Content Column -->
        <div class="main-content">
          
          <!-- Display Messages -->
          <?php 
          $show_success_alert = false;
          $success_message_text = '';
          
          if (isset($_SESSION['success_message'])): 
            $show_success_alert = true;
            $success_message_text = $_SESSION['success_message'];
            unset($_SESSION['success_message']);
          endif;
          
          if (isset($success_message)): 
            $show_success_alert = true;
            $success_message_text = $success_message;
          endif;
          
          if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <?php echo htmlspecialchars($error_message); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          <?php endif; ?>
          
          <!-- Active Filters Indicator -->
          <?php if ($category_filter > 0 || $seller_filter > 0 || $best_seller_filter == 1): ?>
            <div class="container-lg mt-3 mb-3">
              <div class="alert alert-info d-flex align-items-center justify-content-between" role="alert">
                <div>
                  <strong><i class="fas fa-filter"></i> Active Filters:</strong>
                  <?php
                  $active_filters = [];
                  if ($category_filter > 0) {
                      $cat = array_filter($categories, function($c) use ($category_filter) {
                          return $c['category_id'] == $category_filter;
                      });
                      if (!empty($cat)) {
                          $active_filters[] = 'Category: ' . htmlspecialchars(reset($cat)['name']);
                      }
                  }
                  if ($seller_filter > 0) {
                      $sel = array_filter($sellers, function($s) use ($seller_filter) {
                          return $s['seller_id'] == $seller_filter;
                      });
                      if (!empty($sel)) {
                          $active_filters[] = 'Seller: ' . htmlspecialchars(reset($sel)['seller_name']);
                      }
                  }
                  if ($best_seller_filter == 1) {
                      $active_filters[] = '⭐ Best Seller';
                  }
                  echo implode(' | ', $active_filters);
                  ?>
                </div>
                <a href="home.php" class="btn btn-sm btn-outline-secondary">
                  <i class="fas fa-times"></i> Clear All
                </a>
              </div>
            </div>
          <?php endif; ?>
          
          <!-- Recycling Products Section -->
          <div class="container-lg mt-3" id="recyclingProducts">
            <div class="row">
              <?php if (empty($products)): ?>
                <div class="col-12 text-center">
                  <img src="images/logo.png.png" alt="No Products" style="width: 100px; opacity: 0.5; margin-bottom: 20px;">
                  <?php if ($category_filter > 0 || $seller_filter > 0 || $best_seller_filter == 1): ?>
                    <p class="lead">No products found matching your filters.</p>
                    <p class="text-muted">Try adjusting your filters or <a href="home.php">clear all filters</a> to see all products.</p>
                  <?php else: ?>
                    <p class="lead">No products available yet.</p>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <?php foreach ($products as $product): ?>
                <div class="col-6 col-md-6 col-lg-3 mb-4">
                  <div class="card h-100 border shadow-sm product-card position-relative"
                       data-name="<?php echo htmlspecialchars($product['name']); ?>"
                       data-image="<?php echo htmlspecialchars($product['image_url'] ?: 'images/logo.png.png'); ?>"
                       data-stock="<?php echo $product['stock_quantity']; ?>"
                       data-price="₱<?php echo number_format($product['price'], 2); ?>"
                       data-description="<?php echo htmlspecialchars($product['description']); ?>"
                       data-product-id="<?php echo $product['product_id']; ?>">
                    <?php if ($product['sales_count'] >= 5): ?>
                      <div style="position: absolute; left: 0; top: 0; background: linear-gradient(135deg, #28bf4b 0%, #20a745 100%); color: white; padding: 8px 12px; font-weight: bold; font-size: 0.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.2); z-index: 10; border-radius: 0 0 4px 0; letter-spacing: 0.5px;">
                        ★ BEST SELLER
                      </div>
                    <?php endif; ?>
                    <div class="position-relative">
                      <img src="<?php echo htmlspecialchars($product['image_url'] ?: 'images/logo.png.png'); ?>" class="card-img-top" alt="<?php echo htmlspecialchars($product['name']); ?>" style="max-height: 200px; object-fit: contain;">
                      <?php 
                      $category_name = strtolower($product['category_name']);
                      // Green choice badge
                      if ($category_name === 'greenchoice'): ?>
                        <?php
                        $greenChoicePath = 'images/green choice.png';
                        $greenChoiceSrc = $greenChoicePath;
                        if (file_exists($greenChoicePath)) {
                            $greenChoiceSrc .= '?v=' . filemtime($greenChoicePath);
                        }
                        ?>
                        <img src="<?php echo $greenChoiceSrc; ?>" alt="Green Choice" style="position:absolute; top:8px; right:8px; height: 56px; width: auto; box-shadow: 0 2px 8px rgba(0,0,0,0.12); pointer-events:none;">
                      <?php endif; ?>
                    </div>
                      <div class="card-body d-flex flex-column">
                      <h5 class="card-title"><?php echo htmlspecialchars($product['name']); ?></h5>
                      <p class="card-text small"><?php echo htmlspecialchars(substr($product['description'], 0, 80)) . (strlen($product['description']) > 80 ? '...' : ''); ?></p>
                      <div class="mt-auto">
                        <!-- Stock badge above EcoCoins -->
                        <div class="mb-1">
                          <?php if ($product['stock_quantity'] > 0): ?>
                            <span class="badge stock-label">Stocks: <?php echo $product['stock_quantity']; ?></span>
                          <?php else: ?>
                            <span class="badge stock-label out">Out of Stock</span>
                          <?php endif; ?>
                        </div>
                        <p class="fw-bold text-success fs-5 mb-2">₱<?php echo number_format($product['price'], 2); ?></p>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                          <span class="badge bg-light text-dark">EcoCoins: <?php echo number_format($product['price'] + 20, 2); ?></span>
                          <?php if ($product['stock_quantity'] > 0): ?>
                            <div class="d-flex">
                              <form method="POST" action="buycheckout.php" style="display: inline;">
                                <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                <input type="hidden" name="quantity" value="1">
                                <button type="submit" class="buy-now-btn me-2" style="min-width: 80px; font-weight: bold;">Buy</button>
                              </form>
                              <form method="POST" style="display: inline;">
                                <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                <input type="hidden" name="quantity" value="1">
                                <input type="hidden" name="add_to_cart" value="1">
                                <button type="submit" class="cart-icon-btn" title="Add to Cart">
                                  <i class="fas fa-shopping-cart" style="color: #22bb33; font-size: 1.3rem;"></i>
                                </button>
                              </form>
                            </div>
                          <?php else: ?>
                            <button class="btn btn-secondary btn-sm" disabled>Out of Stock</button>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
          
          <!-- Copyright -->
          <div class="row justify-content-center mt-4">
            <div class="col-md-6 text-center">
             <p>© 2024 DMMMSU Environmental Concerns, Sustainability and Development Unit. All rights reserved.</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Payment Method Modal -->
    <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="paymentModalLabel">Select Payment Method</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="payment-options">
              <div class="form-check mb-3">
                <input class="form-check-input" type="radio" name="paymentMethod" id="gcashPayment" value="gcash" checked>
                <label class="form-check-label d-flex align-items-center" for="gcashPayment">
                  <img src="images/gcash-logo.png" alt="GCash" style="width: 40px; height: 40px; margin-right: 10px;">
                  <div>
                    <h6 class="mb-0">GCash</h6>
                    <small class="text-muted">Pay using your GCash wallet</small>
                  </div>
                </label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="paymentMethod" id="ecocoinsPayment" value="ecocoins">
                <label class="form-check-label d-flex align-items-center" for="ecocoinsPayment">
                  <img src="images/ecocoins-logo.png" alt="EcoCoins" style="width: 40px; height: 40px; margin-right: 10px;">
                  <div>
                    <h6 class="mb-0">EcoCoins</h6>
                    <small class="text-muted">Pay using your EcoCoins balance</small>
                  </div>
                </label>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-success" id="proceedToPayment">Proceed to Payment</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Product Details Modal -->
    <div class="modal fade" id="productDetailsModal" tabindex="-1" aria-labelledby="productDetailsModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="productDetailsModalLabel"></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body d-flex flex-column align-items-center">
            <img id="modalProductImage" src="" alt="" class="img-fluid rounded">
            <div id="modalProductStockLabel" class="fw-bold mb-1"></div>
            <div id="modalProductStock"></div>
            <div id="modalProductPrice"></div>
            <div id="modalProductEcocoinsPrice"></div>
            <div id="modalProductDescription"></div>
            <div id="modalProductSeller" class="mt-2"></div>
            <div id="modalProductLocation" class="mb-2"></div>
            <button class="btn btn-outline-success add-to-cart-btn w-100 mb-2">Add to Cart</button>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ENjdO4Dr2bkBIFxQpeoTz1HIcje39Wm4jDKdf19U8gI4ddQ3GYNS7NTKfAdVQSZe" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .swal2-popup,
        .swal2-title,
        .swal2-html-container,
        .swal2-confirm,
        .swal2-cancel {
            font-family: 'Poppins', sans-serif !important;
        }
    </style>
    <script>
  // Show SweetAlert for success messages
  <?php if ($show_success_alert): ?>
  document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
      title: 'Success!',
      text: '<?php echo htmlspecialchars($success_message_text); ?>',
      icon: 'success',
      confirmButtonText: 'OK',
      confirmButtonColor: '#198754',
      timer: 3000,
      timerProgressBar: true,
      showConfirmButton: false
    });
  });
  <?php endif; ?>

  // Simple script to handle category selection
  document.querySelectorAll('.category-item').forEach(item => {
    item.addEventListener('click', function() {
      document.querySelectorAll('.category-item').forEach(i => i.classList.remove('active'));
      this.classList.add('active');
      // Here you would typically filter products based on category
      console.log('Selected category:', this.textContent);
    });
  });

  // Payment modal functionality
  document.addEventListener('DOMContentLoaded', function() {
    // Initialize Bootstrap modal
    const paymentModal = new bootstrap.Modal(document.getElementById('paymentModal'));
    
    // Removed buy-now-btn JS event so form submit works for direct buy
    
    // Handle proceed to payment button
    document.getElementById('proceedToPayment').addEventListener('click', function() {
      const selectedPayment = document.querySelector('input[name="paymentMethod"]:checked').value;
      const productData = JSON.parse(sessionStorage.getItem('currentProduct'));
      
      // Close the modal
      paymentModal.hide();
      
      // Redirect based on payment method with total amount as URL parameter
      if (selectedPayment === 'gcash') {
        window.location.href = `gcash-payment.php?total=${productData.totalAmount}`;
      } else if (selectedPayment === 'ecocoins') {
        window.location.href = `ecocoins-payment.php?amount=${productData.totalAmount}&order_id=ECO-${Date.now()}`;
      }
    });
  });

  // Product details modal functionality
  document.querySelectorAll('.product-card').forEach(card => {
    card.addEventListener('click', function(e) {
      // Prevent click on Add to Cart button from triggering modal
      if (e.target.classList.contains('add-to-cart-btn') || e.target.closest('form')) return;

      // Get product details from data attributes
      const name = this.getAttribute('data-name');
      const image = this.getAttribute('data-image');
      const stock = this.getAttribute('data-stock');
      const price = this.getAttribute('data-price');
      const description = this.getAttribute('data-description');
      const productId = this.getAttribute('data-product-id');
      const sellerName = <?php echo json_encode(array_column($products, 'seller_name', 'product_id')); ?>[productId];
      const sellerLocation = <?php echo json_encode(array_column($products, 'seller_location', 'product_id')); ?>[productId];

      // Calculate EcoCoins price (Peso price + 20 ecocoins)
      let ecocoinsPrice = '';
      const pesoValue = parseFloat(price.replace(/[^0-9.]/g, ''));
      const ecocoinsValue = pesoValue + 20; // Add 20 ecocoins to peso price
      ecocoinsPrice = ecocoinsValue > 0 ? `${ecocoinsValue.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} EcoCoins` : '0.00 EcoCoins';

      // Set modal content
      document.getElementById('productDetailsModalLabel').textContent = name;
      document.getElementById('modalProductImage').src = image;
      document.getElementById('modalProductImage').alt = name;
      // Add label for stock
      const stockLabel = parseInt(stock) > 0 ? 'In Stock:' : 'Out of Stock:';
      document.getElementById('modalProductStockLabel').textContent = stockLabel;
      document.getElementById('modalProductStock').textContent = stock;
      document.getElementById('modalProductPrice').textContent = price;
      document.getElementById('modalProductEcocoinsPrice').textContent = ecocoinsPrice;
      document.getElementById('modalProductDescription').textContent = description;
      document.getElementById('modalProductSeller').innerHTML = `<span class='fw-bold'>Seller:</span> ${sellerName}`;
      document.getElementById('modalProductLocation').innerHTML = `<span class='fw-bold'>Location:</span> ${sellerLocation}`;

      // Update the Add to Cart button in modal to include product ID
      const modalAddToCartBtn = document.querySelector('#productDetailsModal .add-to-cart-btn');
      if (parseInt(stock) > 0) {
        modalAddToCartBtn.disabled = false;
        modalAddToCartBtn.textContent = 'Add to Cart';
        modalAddToCartBtn.classList.remove('btn-secondary');
        modalAddToCartBtn.classList.add('btn-outline-success');
        modalAddToCartBtn.onclick = function() {
          // Create a form and submit it
          const form = document.createElement('form');
          form.method = 'POST';
          form.innerHTML = `
            <input type="hidden" name="product_id" value="${productId}">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="add_to_cart" value="1">
          `;
          document.body.appendChild(form);
          form.submit();
        };
      } else {
        modalAddToCartBtn.disabled = true;
        modalAddToCartBtn.textContent = 'Out of Stock';
        modalAddToCartBtn.classList.remove('btn-outline-success');
        modalAddToCartBtn.classList.add('btn-secondary');
        modalAddToCartBtn.onclick = null;
      }

      // Show modal
      const productDetailsModal = new bootstrap.Modal(document.getElementById('productDetailsModal'));
      productDetailsModal.show();
    });
  });

  function scrollToSection(sectionId) {
    const section = document.getElementById(sectionId);
    if (section) {
      section.scrollIntoView({ behavior: 'smooth' });
    }
  }
</script>
  </body>
</html>
