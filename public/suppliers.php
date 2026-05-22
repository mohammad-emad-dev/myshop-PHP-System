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
        $error = 'Security check failed. Invalid request token.';
    } else {
        $action = $_POST['action'];

        if ($action === 'create') {
            $name = sanitize_input($_POST['name']);
            $phone = sanitize_input($_POST['phone']);
            $email = sanitize_input($_POST['email']);
            $address = sanitize_input($_POST['address']);

            if (empty($name)) {
                $error = 'Supplier name is required.';
            } else {
                if (create_supplier($conn, $name, $phone, $email, $address)) {
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
                $error = 'Modifying the default General Supplier is prohibited.';
            } elseif (empty($name)) {
                $error = 'Supplier name is required.';
            } else {
                if (update_supplier($conn, $id, $name, $phone, $email, $address)) {
                    $success = 'Supplier updated successfully.';
                } else {
                    $error = 'Failed to update supplier details.';
                }
            }
        }
    }
}

// Handle Delete via GET
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Invalid request token.';
    } else {
        if ($id <= 1) {
            $error = 'Deleting the default General Supplier is prohibited.';
        } else {
            if (delete_supplier($conn, $id)) {
                $success = 'Supplier deleted successfully. Past orders from this supplier will show as general supplier purchases.';
            } else {
                $error = 'Failed to delete supplier.';
            }
        }
    }
}

$suppliers = get_suppliers($conn);
$page_title = 'Suppliers Management';
$active_page = 'suppliers';

require_once '../includes/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once '../includes/layouts/sidebar.php'; ?>
    <?php require_once '../includes/layouts/navbar.php'; ?>

    <div class="container-fluid px-4 py-5">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row my-2">
            <div class="col-md-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 text-secondary fw-bold">Suppliers Management</h4>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                            <i class="fas fa-plus me-2"></i>Add Supplier
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="input-group shadow-sm rounded-3 overflow-hidden border">
                                    <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" id="searchSupplier" placeholder="Search suppliers by name, phone..." class="form-control border-0 px-2" style="box-shadow: none; font-size: 0.9rem;">
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="suppliersTable">
                                <thead class="bg-light text-secondary">
                                    <tr>
                                        <th scope="col" style="width: 80px;">ID</th>
                                        <th scope="col">Name</th>
                                        <th scope="col">Phone</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Address</th>
                                        <th scope="col">Created At</th>
                                        <th scope="col" class="text-center" style="width: 120px;">Actions</th>
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
                                                    <button class="btn btn-sm btn-outline-primary me-1" onclick='openEditModal(<?php echo json_encode($supplier); ?>)'>
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="suppliers.php?delete=<?php echo $supplier['id']; ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>" 
                                                       class="btn btn-sm btn-outline-danger" 
                                                       onclick="return confirm('Are you sure you want to delete this supplier? This action cannot be undone.');">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($suppliers)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">No suppliers found.</td>
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
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-labelledby="addSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header py-3">
                <h5 class="modal-title fw-bold" id="addSupplierModalLabel" style="font-family: var(--font-heading); color: var(--slate-900);"><i class="fas fa-truck-loading me-2 text-primary"></i>Add Supplier</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
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
                <h5 class="modal-title fw-bold" id="editSupplierModalLabel" style="font-family: var(--font-heading); color: var(--slate-900);"><i class="fas fa-edit me-2 text-primary"></i>Edit Supplier Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="suppliers.php" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-bold">Supplier Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control rounded-3" id="edit_id_name" name="name" required>
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

<script>
document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById("searchSupplier");
    if (searchInput) {
        searchInput.addEventListener("keyup", function() {
            const value = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll("#suppliersTable tbody tr");
            
            rows.forEach(row => {
                if (row.cells.length > 1) { // Skip empty state row
                    const name = row.cells[1].textContent.toLowerCase();
                    const phone = row.cells[2].textContent.toLowerCase();
                    const email = row.cells[3].textContent.toLowerCase();
                    if (name.includes(value) || phone.includes(value) || email.includes(value)) {
                        row.style.display = "";
                    } else {
                        row.style.display = "none";
                    }
                }
            });
        });
    }
});

function openEditModal(supplier) {
    document.getElementById('edit_id').value = supplier.id;
    document.getElementById('edit_id_name').value = supplier.name;
    document.getElementById('edit_phone').value = supplier.phone || '';
    document.getElementById('edit_email').value = supplier.email || '';
    document.getElementById('edit_address').value = supplier.address || '';
    
    var editModal = new bootstrap.Modal(document.getElementById('editSupplierModal'));
    editModal.show();
}
</script>

<?php require_once '../includes/layouts/footer.php'; ?>
