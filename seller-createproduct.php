<?php
include 'sellerheader.php';
require_once 'config/database.php';
require_once 'config/session_check.php';

// Fetch categories for dropdown
$all_categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
// Filter out "best seller" category
$categories = array_filter($all_categories, function($cat) {
    return strtolower($cat['name']) !== 'best seller';
});

// Get seller_id from session if user is a seller
$seller_id = isSeller() ? getCurrentUserId() : null;
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$seller_id) {
        $error_message = 'You must be logged in as a seller to create a product.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $stock = intval($_POST['stock'] ?? 0);
        $weight = floatval($_POST['weight'] ?? 0);
        $category_id = intval($_POST['category_id'] ?? 0);
        
        // Auto-determine shipping type and size based on weight
        if ($weight <= 3) {
            $size = 'small';
            $shipping_type = 'size_based';
        } elseif ($weight <= 5) {
            $size = 'large';
            $shipping_type = 'weight_based';
        } else {
            $size = 'large';
            $shipping_type = 'weight_based';
        }
    $image_url = null;
    $producers = trim($_POST['producers'] ?? ($seller['fullname'] ?? ''));

        // Validate required fields
    if ($name === '' || $description === '' || $price <= 0 || $stock <= 0 || $weight <= 0 || $category_id <= 0 || $producers === '') {
            $error_message = 'All fields are required and must be valid.';
        } else {
            // Handle image upload
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
                if (!in_array($ext, $allowed_ext)) {
                    $error_message = 'Invalid image format. Only JPG, JPEG, PNG, and GIF are allowed.';
                } elseif ($_FILES['image']['size'] > 2 * 1024 * 1024) {
                    $error_message = 'Image size must be less than 2MB.';
                } else {
                    $filename = uniqid('product_', true) . '.' . $ext;
                    $target = 'uploads/' . $filename;
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                        $image_url = $target;
                    } else {
                        $error_message = 'Failed to upload image.';
                    }
                }
            } else {
                $error_message = 'Product image is required.';
            }
        }

        // Insert product if no errors
        if ($error_message === '') {
            try {
                $stmt = $pdo->prepare('INSERT INTO products (seller_id, category_id, producers, name, description, price, stock_quantity, weight, size, shipping_type, image_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "inactive")');
                $result = $stmt->execute([$seller_id, $category_id, $producers, $name, $description, $price, $stock, $weight, $size, $shipping_type, $image_url]);
                if ($result) {
                    $success_message = 'Product submitted for admin approval.';
                } else {
                    $error_message = 'Error submitting product.';
                }
            } catch (PDOException $e) {
                $error_message = 'Database error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Ecocycle NLUC</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="css/vendor.css">
    <link rel="stylesheet" type="text/css" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Open+Sans:ital,wght@0,400;0,600;0,700;1,400;1,600;1,700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        html, body {
            overflow-x: hidden;
            overflow-y: auto; /* Enable vertical scroll bar */
        }
        body {
            background-color: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }
        .main-content {
            margin-left: 80px; /* Reduced by 100px from 180px */
            padding: 20px;
            min-height: 100vh;
            background-color: #f8f9fa;
            width: 100%;
            margin-top: 40px; /* Reduce further to move more to top */
        }
        .container-fluid {
            max-width: 100%; /* Use full width */
            margin-left: 0; /* Move to left */
            margin-right: 0; /* Remove right margin */
            padding: 0; /* Remove padding to start from very left */
            margin-top: -20px; /* More negative margin to move more to top */
            box-sizing: border-box;
        }
        @media (max-width: 992px) {
            .main-content { margin-left: 280px; }
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .container-fluid { 
                padding: 0; 
                max-width: 100%;
            }
        }

        .products-card {
            background: transparent; /* Transparent background */
            border-radius: 0; /* Remove border radius */
            box-shadow: none; /* Remove shadow */
            border: none; /* Remove border */
            overflow: visible; /* Remove overflow hidden */
            margin: 20px 0 20px 20px; /* Left-aligned with margin */
            max-width: 85%; /* Reduce width */
            width: 85%; /* Reduce width */
            position: relative; /* Make it relative positioned */
            z-index: 1; /* Normal stacking */
            padding: 20px; /* Add padding */
        }

        .card-header {
            background: transparent; /* Remove header background */
            border-bottom: none; /* Remove border */
            padding: 0; /* Remove padding */
            border-radius: 0; /* Remove border radius */
        }

        .card-title {
            font-size: 2rem; /* Reduced to make it smaller but not too small */
            font-weight: 700; /* Made it bolder */
            color: #2c3e50;
            margin: 0;
        }

        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: #28bf4b;
            box-shadow: 0 0 0 0.2rem rgba(40, 191, 75, 0.25);
        }

        .form-select {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-select:focus {
            border-color: #28bf4b;
            box-shadow: 0 0 0 0.2rem rgba(40, 191, 75, 0.25);
        }

        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
        }

        .btn-success {
            background: linear-gradient(135deg, #28bf4b 0%, #20a745 100%);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-weight: 600;
            color: #fff;
            transition: all 0.3s ease;
        }

        .btn-success:hover, .btn-success:focus {
            background: linear-gradient(135deg, #20a745 0%, #1e7e34 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(40,191,75,0.3);
            transform: translateY(-2px);
        }

        .product-img-preview {
            height: 200px;
            object-fit: cover;
            width: 100%;
            border-radius: 10px;
            border: 2px solid #e9ecef;
        }

        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }

        .card {
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: none;
            overflow: hidden;
        }

        .card-body {
            padding: 0; /* Remove padding */
            width: 100%; /* Ensure full width */
            background: transparent; /* Remove background */
        }

        .form-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -15px;
        }

        .form-col {
            flex: 1;
            padding: 0 15px;
            min-width: 300px; /* Minimum width for form columns */
        }

        @media (max-width: 768px) {
            .form-col {
                min-width: 100%;
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="container-fluid">
            <?php if ($success_message): ?>
                <script>
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: '<?= htmlspecialchars($success_message) ?>',
                        confirmButtonColor: '#28bf4b',
                        confirmButtonText: 'OK'
                    });
                </script>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <script>
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: '<?= htmlspecialchars($error_message) ?>',
                        confirmButtonColor: '#dc3545',
                        confirmButtonText: 'OK'
                    });
                </script>
            <?php endif; ?>
            
            <!-- Create Product Form -->
            <div class="products-card mb-4">
                <div class="card-header">
                    <h5 class="card-title">Create New Product</h5>
                </div>
                <br>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" autocomplete="off">
                        <div class="form-row">
                            <div class="form-col">
                        <div class="mb-3">
                           <label for="producers" class="form-label">Producers</label>
                           <input type="text" name="producers" class="form-control" id="producers" value="<?php echo htmlspecialchars($seller['fullname'] ?? ''); ?>">
                           <div class="form-text">This product will be listed under your name as the producer.</div>
                                    <label for="name" class="form-label">Product Name</label>
                                    <input type="text" name="name" class="form-control" id="name" required>
                        </div>
                        <div class="mb-3">
                                    <label for="price" class="form-label">Price</label>
                                    <input type="number" name="price" step="0.01" class="form-control" id="price" min="0" required>
                            <div class="form-text">Enter the price in PHP (₱).</div>
                        </div>
                        <div class="mb-3">
                                    <label for="category_id" class="form-label">Category</label>
                                    <select name="category_id" class="form-select" id="category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['category_id'] ?>">
                                        <?= htmlspecialchars($cat['name']) ?>
                                        <?php if (strtolower($cat['name']) === 'greenchoice'): ?>
                                            (Green Choice Label)
                                        <?php elseif (strtolower($cat['name']) === 'no label'): ?>
                                            (No Special Label)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <strong>Special Labels:</strong> Products in "Greenchoice" category will show a "Green Choice" badge, and "No Label" products will appear without special badges on the marketplace.
                            </div>
                        </div>
                    </div>
                            <div class="form-col">
                        <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea name="description" class="form-control" id="description" rows="4" required></textarea>
                        </div>
                        <div class="mb-3">
                                    <label for="stock" class="form-label">Stock</label>
                                    <input type="number" name="stock" class="form-control" id="stock" min="1" required>
                            <div class="form-text">How many items are available?</div>
                        </div>
                        <div class="mb-3">
                                    <label for="weight" class="form-label">Weight (kg)</label>
                                    <div class="input-group">
                                        <input type="number" name="weight" step="0.1" class="form-control" id="weight" min="0.1" required>
                                        <span class="input-group-text">kg</span>
                                    </div>
                            <div class="form-text">Product weight in kilograms (affects shipping rates).</div>
                        </div>
                                                <div class="mb-3">
                                    <label for="image" class="form-label">Product Image</label>
                                    <input type="file" name="image" accept="image/*" class="form-control" id="image" required>
                                    <div class="form-text">Upload a clear product image (JPG, PNG, max 2MB).</div>
                                </div>
                            </div>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i>Submit Product
                            </button>
                    </div>
                    </form>
                </div>
        </div>
    </div>
</div>

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/js/bootstrap.bundle.min.js"></script>
<script>
        // Image preview functionality removed - only filename will be displayed
    </script>
</body>
</html> 