<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

verify_login();

// Handle read-only filter with strict integer casting
$selected_product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : null;
if ($selected_product_id <= 0) {
    $selected_product_id = null;
}

// Check if filtering for a specific product
if ($selected_product_id !== null) {
    // Validate product exists to be safe
    $product_check = get_product_by_id($conn, $selected_product_id);
    if (!$product_check) {
        $selected_product_id = null; // invalid product, reset filter
    }
}

// Fetch stock movements
$movements = get_stock_movements($conn, $selected_product_id);
$products_list = get_products($conn); // for the dropdown filter

$page_title = 'Stock Ledger';
$active_page = 'stock_movements';

require_once '../includes/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once '../includes/layouts/sidebar.php'; ?>
    <?php require_once '../includes/layouts/navbar.php'; ?>

    <div class="container-fluid px-4 py-5">
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-0 fw-bold" style="color: var(--slate-700); font-family: var(--font-heading);">
                    Stock Ledger (سجل حركة المخزون)
                    <span class="badge bg-primary rounded-pill ms-2 align-middle" style="font-size: 0.75rem;"><?php echo count($movements); ?> Records</span>
                </h1>
                <p class="text-muted mb-0 mt-1">Detailed history of all inventory stock updates, additions, and manual corrections.</p>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-body py-4">
                <form method="GET" class="row align-items-end g-3">
                    <div class="col-md-6">
                        <label for="product_id" class="form-label fw-semibold text-secondary">Filter by Product</label>
                        <select name="product_id" id="product_id" class="form-select rounded-3">
                            <option value="">-- Show All Products --</option>
                            <?php foreach ($products_list as $prod): ?>
                                <option value="<?php echo $prod['id']; ?>" <?php echo $selected_product_id === intval($prod['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prod['name']); ?> (Current Stock: <?php echo $prod['stock']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary rounded-3 px-4 w-100">
                            <i class="fas fa-filter me-2"></i>Filter Ledger
                        </button>
                    </div>
                    <?php if ($selected_product_id !== null): ?>
                    <div class="col-md-2">
                        <a href="stock_movements.php" class="btn btn-outline-secondary rounded-3 w-100">
                            Clear
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Movements Table Card -->
        <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="bg-light text-secondary">
                            <tr>
                                <th scope="col" style="width: 80px;">ID</th>
                                <th scope="col">Product</th>
                                <th scope="col" class="text-center" style="width: 150px;">Quantity Change</th>
                                <th scope="col" class="text-center" style="width: 180px;">Movement Type</th>
                                <th scope="col">Reason</th>
                                <th scope="col">Recorded By</th>
                                <th scope="col" style="width: 200px;">Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($movements)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-history fa-3x mb-3 text-secondary" style="opacity: 0.5;"></i>
                                        <p class="mb-0">No stock movements found in the ledger.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($movements as $m): ?>
                                    <?php
                                    $qty = intval($m['quantity']);
                                    $qty_class = $qty > 0 ? 'text-success fw-bold' : ($qty < 0 ? 'text-danger fw-bold' : 'text-muted fw-bold');
                                    $qty_prefix = $qty > 0 ? '+' : '';
                                    
                                    // Movement Type Badge
                                    $type_badge = '';
                                    switch ($m['movement_type']) {
                                        case 'sale':
                                            $type_badge = '<span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-3 py-2">Sale</span>';
                                            break;
                                        case 'purchase':
                                            $type_badge = '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-3 py-2">Purchase</span>';
                                            break;
                                        case 'manual_adjustment':
                                        default:
                                            $type_badge = '<span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">Adjustment</span>';
                                            break;
                                    }
                                    ?>
                                    <tr>
                                        <td>#<?php echo $m['id']; ?></td>
                                        <td>
                                            <a href="products.php?highlight=<?php echo $m['product_id']; ?>" class="fw-semibold text-decoration-none text-dark">
                                                <?php echo htmlspecialchars($m['product_name']); ?>
                                            </a>
                                        </td>
                                        <td class="text-center <?php echo $qty_class; ?>">
                                            <?php echo $qty_prefix . $qty; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php echo $type_badge; ?>
                                        </td>
                                        <td class="text-muted">
                                            <?php echo htmlspecialchars($m['reason'] ?? 'N/A'); ?>
                                        </td>
                                        <td>
                                            <span class="fw-semibold text-secondary">
                                                <?php echo htmlspecialchars($m['staff_name'] ?? 'System'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-secondary small">
                                                <i class="far fa-clock me-1 text-muted"></i>
                                                <?php echo date('Y-m-d h:i A', strtotime($m['created_at'])); ?>
                                            </span>
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

<?php
$extra_js = ['assets/js/script.js'];
require_once '../includes/layouts/footer.php';
?>
