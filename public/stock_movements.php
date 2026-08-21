<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

auth_verify_login($conn);

// Handle Manual Stock Adjustment
$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_stock') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        http_response_code(403);
        audit_log_current_actor($conn, 'stock_adjustment', 'Product', null, false, ['reason' => 'csrf_validation_failed']);
        $error = 'Security check failed. Invalid request token.';
    } elseif (!auth_is_admin($conn)) {
        http_response_code(403);
        audit_log_denied($conn, 'stock_adjustment', 'Product', null);
        $error = 'Access denied. You do not have permission to adjust stock directly.';
    } else {
        $adj_product_id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647]
        ]);
        $adj_quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => -2147483647, 'max_range' => 2147483647]
        ]);
        $adj_reason = sanitize_input($_POST['reason'] ?? '');

        if ($adj_product_id === false || $adj_quantity === false || $adj_quantity === 0) {
            $error = 'Please select a product and enter a non-zero quantity.';
        } elseif (empty($adj_reason)) {
            $error = 'Please provide a reason for the adjustment.';
        } else {
            if (!inventory_adjust_stock($conn, (int)$adj_product_id, (int)($_SESSION['staff_id'] ?? 0), (int)$adj_quantity, $adj_reason)) {
                $error = 'Unable to complete the stock adjustment right now.';
            } else {
                $success = 'Stock adjusted successfully.';
            }
        }
    }
}

// Handle read-only filter with strict integer casting
$selected_product_id = isset($_GET['product_id'])
    ? filter_var($_GET['product_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;
if ($selected_product_id === false || $selected_product_id === null) {
    $selected_product_id = null;
}

// Check if filtering for a specific product
if ($selected_product_id !== null) {
    // Validate product exists to be safe
    $product_check = catalog_get_product_by_id($conn, $selected_product_id);
    if (!$product_check) {
        $selected_product_id = null; // invalid product, reset filter
    }
}

$page_size_options = [10, 25, 50, 100];
$page_size = normalize_page_size($_GET['page_size'] ?? 25, 25, $page_size_options);
$page = normalize_page_number($_GET['page'] ?? 1);
$total_movements = inventory_count_stock_movements($conn, $selected_product_id);
$total_pages = max(1, (int)ceil($total_movements / $page_size));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $page_size;
$movements = inventory_get_stock_movements_page($conn, $selected_product_id, $page_size, $offset);
$products_list = catalog_get_pos_products($conn, '', 100); // bounded selector data
$range_start = $total_movements > 0 ? $offset + 1 : 0;
$range_end = $total_movements > 0 ? min($offset + count($movements), $total_movements) : 0;
$stock_ledger_url = static function ($target_page) use ($selected_product_id, $page_size) {
    $query = [
        'page' => max(1, (int)$target_page),
        'page_size' => $page_size,
    ];
    if ($selected_product_id !== null) {
        $query['product_id'] = $selected_product_id;
    }
    return 'stock_movements.php?' . http_build_query($query);
};
$pagination_pages = [];
if ($total_pages <= 7) {
    $pagination_pages = range(1, $total_pages);
} else {
    $pagination_pages = [1];
    $window_start = max(2, $page - 2);
    $window_end = min($total_pages - 1, $page + 2);
    if ($window_start > 2) {
        $pagination_pages[] = '...';
    }
    for ($pagination_page = $window_start; $pagination_page <= $window_end; $pagination_page++) {
        $pagination_pages[] = $pagination_page;
    }
    if ($window_end < $total_pages - 1) {
        $pagination_pages[] = '...';
    }
    $pagination_pages[] = $total_pages;
}

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
                <h2 class="h3 mb-0 fw-bold ui-page-heading">
                    Stock Ledger
                    <span class="badge bg-primary rounded-pill ms-2 align-middle ui-count-text-lg"><?php echo number_format($total_movements); ?> Records</span>
                </h2>
                <p class="text-muted mb-0 mt-1">Detailed history of all inventory stock updates, additions, and manual corrections.</p>
            </div>
            <div class="d-flex gap-2">
                <?php if (auth_is_admin($conn)): ?>
                <a href="export_report.php?entity=stock" class="btn btn-success rounded-3 shadow-sm px-4 fw-medium" target="_blank">
                    <i class="fas fa-file-excel me-2"></i>Export CSV
                </a>
                <?php endif; ?>
                <?php if (auth_is_admin($conn)): ?>
                <button class="btn btn-primary rounded-3 shadow-sm px-4 fw-medium pulse-btn" data-bs-toggle="modal" data-bs-target="#addMovementModal">
                    <i class="fas fa-plus-circle me-2"></i>New Adjustment
                </button>
                <?php endif; ?>
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
                    <div class="col-md-2">
                        <label for="ledgerPageSize" class="form-label fw-semibold text-secondary">Per page</label>
                        <select name="page_size" id="ledgerPageSize" class="form-select rounded-3">
                            <?php foreach ($page_size_options as $option): ?>
                                <option value="<?php echo $option; ?>" <?php echo $page_size === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
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
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <span class="text-muted small">Showing <?php echo $range_start; ?>-<?php echo $range_end; ?> of <?php echo $total_movements; ?> records</span>
                        </div>
                        <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="bg-light text-secondary">
                            <tr>
                                <th scope="col" class="ui-col-id-80">ID</th>
                                <th scope="col">Product</th>
                                <th scope="col" class="text-center ui-col-movement-150">Quantity Change</th>
                                <th scope="col" class="text-center ui-col-movement-type-180">Movement Type</th>
                                <th scope="col">Reason</th>
                                <th scope="col">Recorded By</th>
                                <th scope="col" class="ui-col-date-200">Date &amp; Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($movements)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-history fa-3x mb-3 text-secondary ui-icon-opacity-50"></i>
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
                        <?php if ($total_pages > 1): ?>
                            <nav class="mt-4" aria-label="Stock movement pagination">
                                <ul class="pagination justify-content-center flex-wrap mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" aria-label="Previous page" href="<?php echo $page > 1 ? htmlspecialchars($stock_ledger_url($page - 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">Previous</a>
                                    </li>
                                    <?php foreach ($pagination_pages as $pagination_page): ?>
                                        <?php if ($pagination_page === '...'): ?>
                                            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                        <?php else: ?>
                                            <li class="page-item <?php echo $page === $pagination_page ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo htmlspecialchars($stock_ledger_url($pagination_page), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $page === $pagination_page ? 'aria-current="page"' : ''; ?>><?php echo $pagination_page; ?></a>
                                            </li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" aria-label="Next page" href="<?php echo $page < $total_pages ? htmlspecialchars($stock_ledger_url($page + 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
        </div>
    </div>
</div>

<!-- Add Movement Modal -->
<div class="modal fade" id="addMovementModal" tabindex="-1" aria-labelledby="addMovementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header py-3">
                <h5 class="modal-title fw-bold ui-modal-title" id="addMovementModalLabel"><i class="fas fa-plus-circle me-2 text-primary"></i>New Stock Adjustment</h5>
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
<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
    window.addEventListener('load', function() {
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: document.body.dataset.feedbackSuccess,
            confirmButtonColor: '#10b981',
            timer: 3000,
            timerProgressBar: true
        });
    });
</script>
<?php endif; ?>

<?php if (!empty($error)): ?>
<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
    window.addEventListener('load', function() {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: document.body.dataset.feedbackError,
            confirmButtonColor: '#ef4444'
        });
    });
</script>
<?php endif; ?>
