<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

// Enforce authentication
auth_verify_login($conn);

$success = '';
$error = '';

// Handle CRUD operations via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        http_response_code(403);
        audit_log_current_actor($conn, 'supplier_mutation', 'Supplier', null, false, ['reason' => 'csrf_validation_failed']);
        $error = 'Security check failed. Invalid request token.';
    } elseif (!auth_is_admin($conn)) {
        http_response_code(403);
        audit_log_denied($conn, 'supplier_mutation', 'Supplier', null);
        $error = 'Access denied. Administrator privileges are required for supplier changes.';
    } else {
        $action = $_POST['action'];

        if ($action === 'create') {
            $name = sanitize_input($_POST['name']);
            $phone = sanitize_input($_POST['phone']);
            $email = sanitize_input($_POST['email']);
            $address = sanitize_input($_POST['address']);

            if (empty($name)) {
                audit_log_current_actor($conn, 'supplier_create', 'Supplier', null, false, ['reason' => 'validation_failed']);
                $error = 'Supplier name is required.';
            } else {
                $operation_success = suppliers_create($conn, $name, $phone, $email, $address);
                audit_log_current_actor($conn, 'supplier_create', 'Supplier', null, $operation_success);
                if ($operation_success) {
                    $success = 'Supplier added successfully.';
                } else {
                    $error = 'Failed to add supplier. Please check your data.';
                }
            }
        } elseif ($action === 'update') {
            $id = intval($_POST['id']);
            $name = sanitize_input($_POST['name']);
            $phone = sanitize_input($_POST['phone']);
            $email = sanitize_input($_POST['email']);
            $address = sanitize_input($_POST['address']);

            if ($id <= 1) {
                audit_log_current_actor($conn, 'supplier_update', 'Supplier', $id, false, ['reason' => 'protected_record']);
                $error = 'Modifying the default General Supplier is prohibited.';
            } elseif (empty($name)) {
                audit_log_current_actor($conn, 'supplier_update', 'Supplier', $id, false, ['reason' => 'validation_failed']);
                $error = 'Supplier name is required.';
            } else {
                $operation_success = suppliers_update($conn, $id, $name, $phone, $email, $address);
                audit_log_current_actor($conn, 'supplier_update', 'Supplier', $id, $operation_success);
                if ($operation_success) {
                    $success = 'Supplier updated successfully.';
                } else {
                    $error = 'Failed to update supplier details.';
                }
            }
        } elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 1) {
                audit_log_current_actor($conn, 'supplier_delete', 'Supplier', $id, false, ['reason' => 'protected_record']);
                $error = 'Deleting the default General Supplier is prohibited.';
            } else {
                $operation_success = suppliers_delete($conn, $id);
                audit_log_current_actor($conn, 'supplier_delete', 'Supplier', $id, $operation_success);
                if ($operation_success) {
                    $success = 'Supplier deleted successfully. Past orders from this supplier will show as general supplier purchases.';
                } else {
                    $error = 'Failed to delete supplier.';
                }
            }
        }
    }
}

$search = truncate_list_search($_GET['search'] ?? '');
$page_size_options = [10, 25, 50, 100];
$page_size = normalize_page_size($_GET['page_size'] ?? 25, 25, $page_size_options);
$page = normalize_page_number($_GET['page'] ?? 1);
$total_suppliers = people_count_suppliers($conn, $search);
$total_pages = max(1, (int)ceil($total_suppliers / $page_size));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $page_size;
$suppliers = people_get_suppliers_page($conn, $search, $page_size, $offset);
$range_start = $total_suppliers > 0 ? $offset + 1 : 0;
$range_end = $total_suppliers > 0 ? min($offset + count($suppliers), $total_suppliers) : 0;
$supplier_page_url = static function ($target_page) use ($search, $page_size) {
    $query = ['page' => max(1, (int)$target_page), 'page_size' => $page_size];
    if ($search !== '') {
        $query['search'] = $search;
    }
    return 'suppliers.php?' . http_build_query($query);
};
$pagination_pages = $total_pages <= 7 ? range(1, $total_pages) : [1];
if ($total_pages > 7) {
    $window_start = max(2, $page - 2);
    $window_end = min($total_pages - 1, $page + 2);
    if ($window_start > 2) $pagination_pages[] = '...';
    for ($pagination_page = $window_start; $pagination_page <= $window_end; $pagination_page++) $pagination_pages[] = $pagination_page;
    if ($window_end < $total_pages - 1) $pagination_pages[] = '...';
    $pagination_pages[] = $total_pages;
}
$page_title = 'Suppliers Management';
$active_page = 'suppliers';
$header_title = 'Suppliers';
$extra_css = ['https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.min.css'];

require_once '../includes/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once '../includes/layouts/sidebar.php'; ?>
    <?php require_once '../includes/layouts/navbar.php'; ?>

    <div class="container-fluid px-4 py-4">
        <div class="row my-2">
            <div class="col-md-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center">
                            <h2 class="h4 mb-0 fw-bold ui-page-heading">
                                <i class="fas fa-truck me-2 text-success"></i>Suppliers
                                <span class="badge bg-success rounded-pill ms-2 ui-count-text"><?php echo number_format($total_suppliers); ?></span>
                            </h2>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if (auth_is_admin($conn)): ?>
                            <a href="export_report.php?entity=suppliers" class="btn btn-success rounded-3 shadow-sm px-4 fw-medium" target="_blank">
                                <i class="fas fa-file-excel me-2"></i>Export CSV
                            </a>
                            <?php endif; ?>
                            <button class="btn btn-primary shadow-sm fw-bold px-4 rounded-3 pulse-btn ui-transition" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                                <i class="fas fa-truck-loading me-2 fs-5 align-middle"></i>Add Supplier
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="suppliers.php" class="row mb-3 g-2 align-items-end">
                            <div class="col-md-4">
                                <label for="searchSupplier" class="form-label">Search suppliers</label>
                                <div class="input-group shadow-sm rounded-3 overflow-hidden border">
                                    <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                                    <input type="search" id="searchSupplier" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search suppliers by name, phone..." class="form-control border-0 px-2 ui-search-input">
                                </div>
                            </div>
                            <div class="col-sm-4 col-md-2">
                                <label for="supplierPageSize" class="form-label">Items per page</label>
                                <select id="supplierPageSize" name="page_size" class="form-select">
                                    <?php foreach ($page_size_options as $option): ?>
                                        <option value="<?php echo $option; ?>" <?php echo $page_size === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="hidden" name="page" value="1">
                            <div class="col-auto"><button type="submit" class="btn btn-outline-primary">Apply</button></div>
                        </form>
                        <p class="text-muted small mb-3">Showing <?php echo $range_start; ?>-<?php echo $range_end; ?> of <?php echo $total_suppliers; ?> suppliers.</p>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle" id="suppliersTable">
                                <thead class="bg-light text-secondary">
                                    <tr>
                                        <th scope="col" class="ui-col-id-80">ID</th>
                                        <th scope="col">Name</th>
                                        <th scope="col">Phone</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Address</th>
                                        <th scope="col">Created At</th>
                                        <th scope="col" class="text-center ui-col-actions-120">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <?php $is_default = ($supplier['id'] == 1); ?>
                                        <tr>
                                            <td><?php echo $supplier['id']; ?></td>
                                            <td class="fw-bold text-dark">
                                                <?php echo htmlspecialchars($supplier['name']); ?>
                                                <?php if ($is_default): ?>
                                                    <span class="badge bg-secondary ms-1">System Default</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($supplier['phone'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($supplier['email'] ?: 'N/A'); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($supplier['address'] ?: 'N/A'); ?></td>
                                            <td class="small text-secondary"><?php echo date('Y-m-d H:i', strtotime($supplier['created_at'])); ?></td>
                                            <td class="text-center">
                                                <?php if ($is_default): ?>
                                                    <button class="btn btn-sm btn-outline-secondary me-1" disabled title="Default supplier cannot be edited">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-secondary" disabled title="Default supplier cannot be deleted">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                     <button type="button" class="btn btn-sm btn-outline-primary me-1 edit-supplier-btn"
                                                             aria-label="Edit supplier" title="Edit supplier"
                                                             data-supplier-id="<?php echo (int)$supplier['id']; ?>"
                                                             data-supplier-name="<?php echo htmlspecialchars($supplier['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                             data-supplier-phone="<?php echo htmlspecialchars($supplier['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                             data-supplier-email="<?php echo htmlspecialchars($supplier['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                             data-supplier-address="<?php echo htmlspecialchars($supplier['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" action="suppliers.php" class="d-inline delete-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int)$supplier['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete supplier" title="Delete supplier">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($suppliers)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-5">
                                                <i class="fas fa-truck fa-3x mb-3 text-secondary opacity-25 d-block"></i>
                                                <p class="mb-0">No suppliers found. Click "Add Supplier" to create one.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($total_pages > 1): ?>
                            <nav class="mt-4" aria-label="Supplier pagination">
                                <ul class="pagination justify-content-center flex-wrap mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" aria-label="Previous page" href="<?php echo $page > 1 ? htmlspecialchars($supplier_page_url($page - 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">Previous</a></li>
                                    <?php foreach ($pagination_pages as $pagination_page): ?>
                                        <?php if ($pagination_page === '...'): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php else: ?><li class="page-item <?php echo $page === $pagination_page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($supplier_page_url($pagination_page), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $page === $pagination_page ? 'aria-current="page"' : ''; ?>><?php echo $pagination_page; ?></a></li><?php endif; ?>
                                    <?php endforeach; ?>
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" aria-label="Next page" href="<?php echo $page < $total_pages ? htmlspecialchars($supplier_page_url($page + 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">Next</a></li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-labelledby="addSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header py-3">
                <h5 class="modal-title fw-bold ui-modal-title" id="addSupplierModalLabel"><i class="fas fa-truck me-2 text-success"></i>Add Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="suppliers.php" method="POST">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="add_name" class="form-label fw-bold">Supplier Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-3" id="add_name" name="name" required placeholder="e.g. Acme Corp">
                    </div>
                    <div class="mb-3">
                        <label for="add_phone" class="form-label fw-bold">Phone Number</label>
                        <input type="text" class="form-control rounded-3" id="add_phone" name="phone" placeholder="e.g. +1 555 0188">
                    </div>
                    <div class="mb-3">
                        <label for="add_email" class="form-label fw-bold">Email Address</label>
                        <input type="email" class="form-control rounded-3" id="add_email" name="email" placeholder="e.g. info@acme.com">
                    </div>
                    <div class="mb-3">
                        <label for="add_address" class="form-label fw-bold">Address</label>
                        <textarea class="form-control rounded-3" id="add_address" name="address" rows="3" placeholder="Supplier factory or office address..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal fade" id="editSupplierModal" tabindex="-1" aria-labelledby="editSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header py-3">
                <h5 class="modal-title fw-bold ui-modal-title" id="editSupplierModalLabel"><i class="fas fa-edit me-2 text-primary"></i>Edit Supplier Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="suppliers.php" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-bold">Supplier Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-3" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_phone" class="form-label fw-bold">Phone Number</label>
                        <input type="text" class="form-control rounded-3" id="edit_phone" name="phone">
                    </div>
                    <div class="mb-3">
                        <label for="edit_email" class="form-label fw-bold">Email Address</label>
                        <input type="email" class="form-control rounded-3" id="edit_email" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="edit_address" class="form-label fw-bold">Address</label>
                        <textarea class="form-control rounded-3" id="edit_address" name="address" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById("searchSupplier");
    if (searchInput) {
        searchInput.addEventListener("keyup", function() {
            const value = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll("#suppliersTable tbody tr");
            rows.forEach(row => {
                if (row.cells.length > 1) {
                    const name = row.cells[1].textContent.toLowerCase();
                    const phone = row.cells[2].textContent.toLowerCase();
                    const email = row.cells[3].textContent.toLowerCase();
                    row.classList.toggle('table-row-hidden', !(name.includes(value) || phone.includes(value) || email.includes(value)));
                }
            });
        });
    }
});

function openEditModal(button) {
    document.getElementById('edit_id').value = button.dataset.supplierId;
    document.getElementById('edit_name').value = button.dataset.supplierName;
    document.getElementById('edit_phone').value = button.dataset.supplierPhone || '';
    document.getElementById('edit_email').value = button.dataset.supplierEmail || '';
    document.getElementById('edit_address').value = button.dataset.supplierAddress || '';
    var editModal = new bootstrap.Modal(document.getElementById('editSupplierModal'));
    editModal.show();
}

document.querySelectorAll('.edit-supplier-btn').forEach(function(button) {
    button.addEventListener('click', function() {
        openEditModal(button);
    });
});

document.querySelectorAll('.delete-form').forEach(function(form) {
    form.addEventListener('submit', function(event) {
        event.preventDefault();
        Swal.fire({
            title: 'Delete Supplier?',
            text: 'Are you sure you want to delete this supplier? Past orders from this supplier will show as general supplier purchases.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, delete it!'
        }).then(function(result) {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
});

</script>

<?php
$extra_js = [
    'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js'
];
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

<?php require_once '../includes/layouts/footer.php'; ?>
