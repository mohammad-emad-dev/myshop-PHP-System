<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

// Enforce admin access
require_admin();

$success = '';
$error = '';

// Handle Actions (Create/Update via POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Invalid request token.';
    } else {
        $action = $_POST['action'];

        if ($action === 'create') {
            $name = sanitize_input($_POST['name']);
            $description = sanitize_input($_POST['description']);

            if (empty($name)) {
                $error = 'Category name is required.';
            } else {
                if (create_category($conn, $name, $description)) {
                    $success = 'Category created successfully.';
                } else {
                    $error = 'Failed to create category. A category with this name might already exist.';
                }
            }
        } elseif ($action === 'update') {
            $id = intval($_POST['id']);
            $name = sanitize_input($_POST['name']);
            $description = sanitize_input($_POST['description']);

            if ($id <= 0 || empty($name)) {
                $error = 'Category ID and name are required.';
            } else {
                if (update_category($conn, $id, $name, $description)) {
                    $success = 'Category updated successfully.';
                } else {
                    $error = 'Failed to update category. The name might be taken or it is the default category.';
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
        if (delete_category($conn, $id)) {
            $success = 'Category deleted successfully. Associated products have been moved to General.';
        } else {
            $error = 'Failed to delete category. The default category "General" cannot be deleted.';
        }
    }
}

$categories = get_categories($conn);
$page_title = 'Categories';
$active_page = 'categories';
$header_title = 'Categories';
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
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 fw-bold" style="color: var(--slate-700); font-family: var(--font-heading);">
                            <i class="fas fa-tags me-2 text-primary"></i>Product Categories
                            <span class="badge bg-primary rounded-pill ms-2" style="font-size: 0.7rem;"><?php echo count($categories); ?></span>
                        </h4>
                        <button class="btn btn-primary shadow-sm fw-bold px-4 rounded-pill pulse-btn" data-bs-toggle="modal" data-bs-target="#addCategoryModal" style="transition: all 0.3s ease;">
                            <i class="fas fa-plus-circle me-2 fs-5 align-middle"></i>Add Category
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="input-group shadow-sm rounded-3 overflow-hidden border">
                                    <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" id="searchCategory" placeholder="Search categories by name..." class="form-control border-0 px-2" style="box-shadow: none; font-size: 0.9rem;">
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle" id="categoriesTable">
                                <thead class="bg-light text-secondary">
                                    <tr>
                                        <th scope="col">ID</th>
                                        <th scope="col">Name</th>
                                        <th scope="col">Description</th>
                                        <th scope="col">Product Count</th>
                                        <th scope="col">Created At</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $category): ?>
                                        <?php $is_default = ($category['name'] === 'General'); ?>
                                        <tr>
                                            <td><?php echo $category['id']; ?></td>
                                            <td class="fw-bold">
                                                <?php echo htmlspecialchars($category['name']); ?>
                                                <?php if ($is_default): ?>
                                                    <span class="badge bg-secondary ms-1">Default</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-muted">
                                                <?php echo htmlspecialchars($category['description'] ?: 'No description provided'); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info-subtle text-info fw-bold rounded-pill px-3">
                                                    <?php echo $category['product_count']; ?> products
                                                </span>
                                            </td>
                                            <td class="small text-secondary">
                                                <?php echo date('Y-m-d H:i', strtotime($category['created_at'])); ?>
                                            </td>
                                            <td>
                                                <?php if ($is_default): ?>
                                                    <button class="btn btn-sm btn-outline-secondary me-1" disabled title="Default category cannot be edited">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-secondary" disabled title="Default category cannot be deleted">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-info me-1" onclick='openEditModal(<?php echo json_encode($category); ?>)'>
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($categories)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-5">
                                                <i class="fas fa-tags fa-3x mb-3 text-secondary opacity-25 d-block"></i>
                                                <p class="mb-0">No categories found. Click "Add Category" to create one.</p>
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

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header py-3">
                <h5 class="modal-title fw-bold" id="addCategoryModalLabel" style="font-family: var(--font-heading); color: var(--slate-900);"><i class="fas fa-plus me-2 text-primary"></i>Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="categories.php" method="POST">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label for="add_name" class="form-label fw-bold">Category Name</label>
                        <input type="text" class="form-control rounded-3" id="add_name" name="name" required placeholder="e.g. Beverages">
                    </div>
                    <div class="mb-3">
                        <label for="add_description" class="form-label fw-bold">Description</label>
                        <textarea class="form-control rounded-3" id="add_description" name="description" rows="3" placeholder="Category description details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4">Create Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <div class="modal-header py-3">
                <h5 class="modal-title fw-bold" id="editCategoryModalLabel" style="font-family: var(--font-heading); color: var(--slate-900);"><i class="fas fa-edit me-2 text-primary"></i>Edit Category Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="categories.php" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-bold">Category Name</label>
                        <input type="text" class="form-control rounded-3" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-bold">Description</label>
                        <textarea class="form-control rounded-3" id="edit_description" name="description" rows="3"></textarea>
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

<?php
$extra_js = [
    'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js'
];
?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const searchInput = document.getElementById("searchCategory");
    if (searchInput) {
        searchInput.addEventListener("keyup", function() {
            const value = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll("#categoriesTable tbody tr");
            rows.forEach(row => {
                if (row.cells.length > 1) {
                    const name = row.cells[1].textContent.toLowerCase();
                    const desc = row.cells[2].textContent.toLowerCase();
                    row.style.display = (name.includes(value) || desc.includes(value)) ? "" : "none";
                }
            });
        });
    }
});

function openEditModal(category) {
    document.getElementById('edit_id').value = category.id;
    document.getElementById('edit_name').value = category.name;
    document.getElementById('edit_description').value = category.description || '';
    var editModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
    editModal.show();
}

function confirmDelete(id, token) {
    Swal.fire({
        title: 'Delete Category?',
        text: 'Are you sure you want to delete this category? Associated products will revert to General.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `categories.php?delete=${id}&csrf_token=${token}`;
        }
    });
}
</script>

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

<?php require_once '../includes/layouts/footer.php'; ?>
