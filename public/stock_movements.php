<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

verify_login();

// Handle Manual Stock Adjustment
$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_stock') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Invalid request token.';
    } elseif (!is_admin()) {
        $error = 'Access denied. You do not have permission to adjust stock directly.';
    } else {
        $adj_product_id = intval($_POST['product_id']);
        $adj_quantity = intval($_POST['quantity']); // Can be negative or positive
        $adj_reason = sanitize_input($_POST['reason']);

        if ($adj_product_id <= 0 || $adj_quantity === 0) {
            $error = 'Please select a product and enter a non-zero quantity.';
        } elseif (empty($adj_reason)) {
            $error = 'Please provide a reason for the adjustment.';
        } else {
            // Get current stock
            $prod = get_product_by_id($conn, $adj_product_id);
            if (!$prod) {
                $error = 'Product not found.';
            } else {
                $new_stock = $prod['stock'] + $adj_quantity;
                if ($new_stock < 0) {
                    $error = 'Adjustment failed: Cannot reduce stock below zero.';
                } else {
                    $conn->begin_transaction();
                    try {
                        // Update stock
                        $stmt = $conn->prepare("UPDATE Product SET stock = ? WHERE id = ?");
                        $stmt->bind_param("ii", $new_stock, $adj_product_id);
                        if (!$stmt->execute()) {
                            throw new Exception("Failed to update stock.");
                        }
                        $stmt->close();

                        // Log movement
                        if (!log_stock_movement($conn, $adj_product_id, $_SESSION['staff_id'], $adj_quantity, 'manual_adjustment', $adj_reason)) {
                            throw new Exception("Failed to log stock movement.");
                        }

                        $conn->commit();
                        $success = 'Stock adjusted successfully.';
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = 'Database error: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

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
                    Stock Ledger
                    <span class="badge bg-primary rounded-pill ms-2 align-middle" style="font-size: 0.75rem;"><?php echo count($movements); ?> Records</span>
                </h1>
                <p class="text-muted mb-0 mt-1">Detailed history of all inventory stock updates, additions, and manual corrections.</p>
            </div>
            <div class="d-flex gap-2">
                <?php if (is_admin()): ?>
                <a href="export_report.php?entity=stock" class="btn btn-success rounded-3 shadow-sm px-4 fw-medium" target="_blank">
                    <i class="fas fa-file-excel me-2"></i>Export CSV
                </a>
                <?php endif; ?>
                <button class="btn btn-primary rounded-3 shadow-sm px-4 fw-medium pulse-btn" data-bs-toggle="modal" data-bs-target="#addMovementModal">
                    <i class="fas fa-plus-circle me-2"></i>New Adjustment
                </button>
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

<!-- Add Movement Modal -->
<div class="modal fade" id="addMovementModal" tabindex="-1" aria-labelledby="addMovementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header py-3">
                <h5 class="modal-title fw-bold" id="addMovementModalLabel" style="font-family: var(--font-heading); color: var(--slate-900);"><i class="fas fa-plus-circle me-2 text-primary"></i>New Stock Adjustment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="stock_movements.php" method="POST">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label for="adj_product_id" class="form-label fw-bold">Select Product <span class="text-danger">*</span></label>
                        <select class="form-select rounded-3" id="adj_product_id" name="product_id" required>
                            <option value="">-- Choose Product --</option>
                            <?php foreach ($products_list as $prod): ?>
                                <option value="<?php echo $prod['id']; ?>">
                                    <?php echo htmlspecialchars($prod['name']); ?> (Stock: <?php echo $prod['stock']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="adj_quantity" class="form-label fw-bold">Quantity Change <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" class="form-control rounded-start-3" id="adj_quantity" name="quantity" required placeholder="-5 or 10">
                        </div>
                        <div class="form-text">Use negative numbers to deduct stock, positive to add.</div>
                    </div>
                    <div class="mb-3">
                        <label for="adj_reason" class="form-label fw-bold">Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control rounded-3" id="adj_reason" name="reason" rows="2" required placeholder="e.g. Damaged goods, found extra inventory"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = [
    'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js',
    'assets/js/script.js'
];
require_once '../includes/layouts/footer.php';
?>

<?php if (!empty($success)): ?>
<script>
    window.addEventListener('load', function() {
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: <?php echo json_encode($success); ?>,
            confirmButtonColor: '#10b981',
            timer: 3000,
            timerProgressBar: true
        });
    });
</script>
<?php endif; ?>

<?php if (!empty($error)): ?>
<script>
    window.addEventListener('load', function() {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: <?php echo json_encode($error); ?>,
            confirmButtonColor: '#ef4444'
        });
    });
</script>
<?php endif; ?>
