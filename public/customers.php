<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

// Enforce authentication
verify_login();

$success = '';
$error = '';

// Handle CRUD operations via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        http_response_code(403);
        $error = 'Security check failed. Invalid request token.';
    } elseif (!is_admin()) {
        http_response_code(403);
        $error = 'Access denied. Administrator privileges are required for customer changes.';
    } else {
        $action = $_POST['action'];

        if ($action === 'create') {
            $name = sanitize_input($_POST['name']);
            $phone = sanitize_input($_POST['phone']);
            $email = sanitize_input($_POST['email']);
            $address = sanitize_input($_POST['address']);

            if (empty($name)) {
                $error = 'Customer name is required.';
            } else {
                if (create_customer($conn, $name, $phone, $email, $address)) {
                    $success = 'Customer added successfully.';
                } else {
                    $error = 'Failed to add customer. Please check your data.';
                }
            }
        } elseif ($action === 'update') {
            $id = intval($_POST['id']);
            $name = sanitize_input($_POST['name']);
            $phone = sanitize_input($_POST['phone']);
            $email = sanitize_input($_POST['email']);
            $address = sanitize_input($_POST['address']);

            if ($id <= 1) {
                $error = 'Modifying the default Walk-in Customer is prohibited.';
            } elseif (empty($name)) {
                $error = 'Customer name is required.';
            } else {
                if (update_customer($conn, $id, $name, $phone, $email, $address)) {
                    $success = 'Customer updated successfully.';
                } else {
                    $error = 'Failed to update customer details.';
                }
            }
        } elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 1) {
                $error = 'Deleting the default Walk-in Customer is prohibited.';
            } elseif (delete_customer($conn, $id)) {
                $success = 'Customer deleted successfully. Past orders for this customer will show as walk-in orders.';
            } else {
                $error = 'Failed to delete customer.';
            }
        }
    }
}

$customers = get_customers($conn);
$page_title = 'Customers Management';
$active_page = 'customers';
$header_title = 'Customers';
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
                            <h4 class="mb-0 fw-bold ui-page-heading">
                                <i class="fas fa-users me-2 text-primary"></i>Customers
                                <span class="badge bg-primary rounded-pill ms-2 ui-count-text"><?php echo count($customers); ?></span>
                            </h4>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if (is_admin()): ?>
                            <a href="export_report.php?entity=customers" class="btn btn-success rounded-3 shadow-sm px-4 fw-medium" target="_blank">
                                <i class="fas fa-file-excel me-2"></i>Export CSV
                            </a>
                            <?php endif; ?>
                            <button class="btn btn-primary shadow-sm fw-bold px-4 rounded-3 pulse-btn ui-transition" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                                <i class="fas fa-user-plus me-2 fs-5 align-middle"></i>Add Customer
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="input-group shadow-sm rounded-3 overflow-hidden border">
                                    <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" id="searchCustomer" placeholder="Search customers by name, phone..." class="form-control border-0 px-2 ui-search-input">
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle" id="customersTable">
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
                                    <?php foreach ($customers as $customer): ?>
                                        <?php $is_default = ($customer['id'] == 1); ?>
                                        <tr>
                                            <td><?php echo $customer['id']; ?></td>
                                            <td class="fw-bold text-dark">
                                                <?php echo htmlspecialchars($customer['name']); ?>
                                                <?php if ($is_default): ?>
                                                    <span class="badge bg-secondary ms-1">System Default</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($customer['phone'] ?: 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($customer['email'] ?: 'N/A'); ?></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($customer['address'] ?: 'N/A'); ?></td>
                                            <td class="small text-secondary"><?php echo date('Y-m-d H:i', strtotime($customer['created_at'])); ?></td>
                                            <td class="text-center">
                                                <?php if ($is_default): ?>
                                                    <button class="btn btn-sm btn-outline-secondary me-1" disabled title="Default customer cannot be edited">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-secondary" disabled title="Default customer cannot be deleted">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                     <button type="button" class="btn btn-sm btn-outline-primary me-1 edit-customer-btn"
                                                             aria-label="Edit customer" title="Edit customer"
                                                             data-customer-id="<?php echo (int)$customer['id']; ?>"
                                                             data-customer-name="<?php echo htmlspecialchars($customer['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                             data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                             data-customer-email="<?php echo htmlspecialchars($customer['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                             data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" action="customers.php" class="d-inline delete-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int)$customer['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete customer" title="Delete customer">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($customers)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-5">
                                                <i class="fas fa-users fa-3x mb-3 text-secondary opacity-25 d-block"></i>
                                                <p class="mb-0">No customers found. Click "Add Customer" to create one.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header py-3">
                <h5 class="modal-title fw-bold ui-modal-title" id="addCustomerModalLabel"><i class="fas fa-user-plus me-2 text-primary"></i>Add Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="customers.php" method="POST">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="add_name" class="form-label fw-bold">Customer Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-3" id="add_name" name="name" required placeholder="e.g. John Doe">
                    </div>
                    <div class="mb-3">
                        <label for="add_phone" class="form-label fw-bold">Phone Number</label>
                        <input type="text" class="form-control rounded-3" id="add_phone" name="phone" placeholder="e.g. +1 555 0199">
                    </div>
                    <div class="mb-3">
                        <label for="add_email" class="form-label fw-bold">Email Address</label>
                        <input type="email" class="form-control rounded-3" id="add_email" name="email" placeholder="e.g. john@example.com">
                    </div>
                    <div class="mb-3">
                        <label for="add_address" class="form-label fw-bold">Address</label>
                        <textarea class="form-control rounded-3" id="add_address" name="address" rows="3" placeholder="Billing or shipping address details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4">Save Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-labelledby="editCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header py-3">
                <h5 class="modal-title fw-bold ui-modal-title" id="editCustomerModalLabel"><i class="fas fa-user-edit me-2 text-primary"></i>Edit Customer Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="customers.php" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-bold">Customer Name <span class="text-danger">*</span></label>
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
    const searchInput = document.getElementById("searchCustomer");
    if (searchInput) {
        searchInput.addEventListener("keyup", function() {
            const value = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll("#customersTable tbody tr");
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
    document.getElementById('edit_id').value = button.dataset.customerId;
    document.getElementById('edit_name').value = button.dataset.customerName;
    document.getElementById('edit_phone').value = button.dataset.customerPhone || '';
    document.getElementById('edit_email').value = button.dataset.customerEmail || '';
    document.getElementById('edit_address').value = button.dataset.customerAddress || '';
    var editModal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
    editModal.show();
}

document.querySelectorAll('.edit-customer-btn').forEach(function(button) {
    button.addEventListener('click', function() {
        openEditModal(button);
    });
});

document.querySelectorAll('.delete-form').forEach(function(form) {
    form.addEventListener('submit', function(event) {
        event.preventDefault();
        Swal.fire({
            title: 'Delete Customer?',
            text: 'Are you sure you want to delete this customer? Past orders for this customer will show as walk-in orders.',
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
