<?php
include 'homeheader.php';
require_once 'config/database.php';
require_once 'config/session_check.php';

// Fetch purchase history for the current buyer
$buyer_id = $_SESSION['user_id'];
$purchases = [];

try {
    // Get purchases from purchase_history table
    $query = "
        SELECT 
            ph.purchase_id,
            ph.order_id,
            ph.created_at,
            ph.status,
            ph.quantity,
            ph.price,
            p.name as product_name,
            p.image_url,
            s.fullname as seller_name,
            'purchase_history' as source
        FROM purchase_history ph
        JOIN products p ON ph.product_id = p.product_id
        JOIN sellers s ON ph.seller_id = s.seller_id
        WHERE ph.buyer_id = ?
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$buyer_id]);
    $history_purchases = $stmt->fetchAll();
    
    // Get purchases from Orders table (includes pickup orders)
    $query = "
        SELECT 
            o.order_id as purchase_id,
            o.order_id,
            o.created_at,
            o.status,
            oi.quantity,
            oi.price,
            p.name as product_name,
            p.image_url,
            s.fullname as seller_name,
            o.delivery_method,
            'orders' as source
        FROM orders o
        JOIN order_items oi ON o.order_id = oi.order_id
        JOIN products p ON oi.product_id = p.product_id
        JOIN sellers s ON p.seller_id = s.seller_id
        WHERE o.buyer_id = ?
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$buyer_id]);
    $order_purchases = $stmt->fetchAll();
    
    // Merge both arrays and remove duplicates
    $all_purchases = array_merge($history_purchases, $order_purchases);
    
    // Remove duplicates based on order_id and product_id
    $unique_purchases = [];
    $seen = [];
    foreach ($all_purchases as $purchase) {
        $key = $purchase['order_id'] . '_' . ($purchase['product_name'] ?? '');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique_purchases[] = $purchase;
        }
    }
    
    // Sort by date (most recent first)
    usort($unique_purchases, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    $purchases = $unique_purchases;
    
} catch (PDOException $e) {
    error_log("Error fetching purchase history: " . $e->getMessage());
    $purchases = [];
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <title>My Purchases - Ecocycle Nluc</title>
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
            <h3 class="fw-bold mb-4 text-start">My Purchases</h3>
            <div class="search-filter-row">
              <div class="input-group">
                <input type="text" id="purchaseSearch" class="form-control" placeholder="Search order#, product, seller...">
                <button id="purchaseSearchBtn" class="btn" type="button" style="background-color: #2c786c; border-color: #2c786c;">
                  <i class="fas fa-search" style="color: #fff;"></i>
                </button>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table table-bordered table-hover align-middle">
                <thead class="table-success">
                  <tr>
                    <th>Order #</th>
                    <th>Date</th>
                    <th>Product</th>
                    <th>Seller</th>
                    <th>Quantity</th>
                    <th>Price</th>
                    <th>Delivery</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($purchases)): ?>
                    <tr>
                      <td colspan="7" class="text-center">
                        <div class="p-3">
                          <i class="fas fa-shopping-bag fa-2x text-muted mb-2"></i>
                          <p class="mb-2">No purchases found.</p>
                          <small class="text-muted">You haven't made any purchases yet.</small>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($purchases as $purchase): ?>
                      <?php $rowDate = date('Y-m-d', strtotime($purchase['created_at'])); ?>
                      <tr data-date="<?php echo $rowDate; ?>">
                        <td><?php echo htmlspecialchars($purchase['order_id']); ?></td>
                        <td><?php echo date('M d, Y H:i', strtotime($purchase['created_at'])); ?></td>
                        <td>
                          <div class="d-flex align-items-center">
                            <?php if ($purchase['image_url']): ?>
                              <img src="<?php echo htmlspecialchars($purchase['image_url']); ?>" 
                                   alt="<?php echo htmlspecialchars($purchase['product_name']); ?>" 
                                   class="me-2" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;">
                            <?php else: ?>
                              <div class="me-2" style="width: 50px; height: 50px; background: #f0f0f0; border-radius: 5px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-image text-muted"></i>
                              </div>
                            <?php endif; ?>
                            <span><?php echo htmlspecialchars($purchase['product_name'] ?: 'Unknown Product'); ?></span>
                          </div>
                        </td>
                        <td><?php echo htmlspecialchars($purchase['seller_name'] ?: 'Unknown Seller'); ?></td>
                        <td><?php echo htmlspecialchars($purchase['quantity']); ?></td>
                        <td><strong>₱<?php echo number_format($purchase['price'] * $purchase['quantity'], 2); ?></strong></td>
                        <td>
                          <?php 
                          $delivery_method = $purchase['delivery_method'] ?? 'delivery';
                          if ($delivery_method === 'pickup') {
                              echo '<span class="badge bg-success"><i class="fas fa-store me-1"></i>Pickup</span>';
                          } else {
                              echo '<span class="badge bg-info"><i class="fas fa-truck me-1"></i>Delivery</span>';
                          }
                          ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
    <style>
      .search-filter-row {
        display: flex;
        gap: 1rem;
        margin-bottom: 1.5rem;
        flex-wrap: nowrap;
        align-items: center;
      }
      .search-filter-row .input-group {
        flex: 0 1 320px;
        min-width: 200px;
        max-width: 320px;
      }
    </style>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('purchaseSearch');
        const searchBtn = document.getElementById('purchaseSearchBtn');
        const table = document.querySelector('table');
        const rows = Array.from(table.querySelectorAll('tbody tr'));

        function filterRows() {
          const q = (searchInput.value || '').toLowerCase().trim();
          rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length === 1) return; // skip 'no purchases' row

            const orderId = (cells[0].textContent || '').toLowerCase();
            const date = row.dataset.date || '';
            const product = (cells[2].textContent || '').toLowerCase();
            const seller = (cells[3].textContent || '').toLowerCase();
            const delivery = (cells[6].textContent || '').toLowerCase();

            let matchesQuery = true;
            if (q) {
              matchesQuery = orderId.includes(q) || 
                            product.includes(q) || 
                            seller.includes(q) || 
                            delivery.includes(q);
            }

            if (matchesQuery) {
              row.style.display = '';
            } else {
              row.style.display = 'none';
            }
          });
        }

        searchBtn.addEventListener('click', filterRows);
        searchInput.addEventListener('keypress', function(e) {
          if (e.key === 'Enter') { e.preventDefault(); filterRows(); }
        });
        
        // Auto-filter on input
        searchInput.addEventListener('input', filterRows);
      });
    </script>
  </body>
</html>
