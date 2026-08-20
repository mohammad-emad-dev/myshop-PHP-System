<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

// Authenticate first so POST requests can return a consistent CSRF response
// before the administrator authorization check.
verify_login();

$success = '';
$error = '';

// Handle Actions (Create/Update via POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        http_response_code(403);
        audit_log_current_actor($conn, 'category_mutation', 'Category', null, false, ['reason' => 'csrf_validation_failed']);
        $error = 'Security check failed. Invalid request token.';
    } elseif (!is_admin()) {
        http_response_code(403);
        audit_log_denied($conn, 'category_mutation', 'Category', null);
        $error = 'Access denied. Administrator privileges are required for category changes.';
    } else {
        $action = $_POST['action'];

        if ($action === 'create') {
            $name = sanitize_input($_POST['name']);
            $description = sanitize_input($_POST['description']);

            if (empty($name)) {
                audit_log_current_actor($conn, 'category_create', 'Category', null, false, ['reason' => 'validation_failed']);
                $error = 'Category name is required.';
            } else {
                $operation_success = create_category($conn, $name, $description);
                audit_log_current_actor($conn, 'category_create', 'Category', null, $operation_success);
                if ($operation_success) {
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
                audit_log_current_actor($conn, 'category_update', 'Category', $id, false, ['reason' => 'validation_failed']);
                $error = 'Category ID and name are required.';
            } else {
                $operation_success = update_category($conn, $id, $name, $description);
                audit_log_current_actor($conn, 'category_update', 'Category', $id, $operation_success);
                if ($operation_success) {
                    $success = 'Category updated successfully.';
                } else {
                    $error = 'Failed to update category. The name might be taken or it is the default category.';
                }
            }
        } elseif ($action === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            $operation_success = $id > 0 && delete_category($conn, $id);
            audit_log_current_actor($conn, 'category_delete', 'Category', $id, $operation_success);
            if ($operation_success) {
                $success = 'Category deleted successfully. Associated products have been moved to General.';
            } else {
                $error = 'Failed to delete category. The default category "General" cannot be deleted.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_admin();
}

$search = truncate_list_search($_GET['search'] ?? '');
$page_size_options = [10, 25, 50, 100];
$page_size = normalize_page_size($_GET['page_size'] ?? 25, 25, $page_size_options);
$page = normalize_page_number($_GET['page'] ?? 1);
$total_categories = catalog_count_categories($conn, $search);
$total_pages = max(1, (int)ceil($total_categories / $page_size));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $page_size;
$categories = catalog_get_categories_page($conn, $search, $page_size, $offset);
$range_start = $total_categories > 0 ? $offset + 1 : 0;
$range_end = $total_categories > 0 ? min($offset + count($categories), $total_categories) : 0;
$category_page_url = static function ($target_page) use ($search, $page_size) {
    $query = ['page' => max(1, (int)$target_page), 'page_size' => $page_size];
    if ($search !== '') {
        $query['search'] = $search;
    }
    return 'categories.php?' . http_build_query($query);
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
                        <h2 class="h4 mb-0 fw-bold ui-page-heading">
                            <i class="fas fa-tags me-2 text-primary"></i>Product Categories
                            <span class="badge bg-primary rounded-pill ms-2 ui-count-text"><?php echo number_format($total_categories); ?></span>
                        </h2>
                        <button class="btn btn-primary shadow-sm fw-bold px-4 rounded-pill pulse-btn ui-transition" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="fas fa-plus-circle me-2 fs-5 align-middle"></i>Add Category
                        </button>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="categories.php" class="row mb-3 g-2 align-items-end">
                            <div class="col-md-4">
                                <label for="searchCategory" class="form-label">Search categories</label>
                                <div class="input-group shadow-sm rounded-3 overflow-hidden border">
                                    <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                                    <input type="search" id="searchCategory" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search categories by name..." class="form-control border-0 px-2 ui-search-input">
                                </div>
                            </div>
                            <div class="col-sm-4 col-md-2">
                                <label for="categoryPageSize" class="form-label">Items per page</label>
                                <select id="categoryPageSize" name="page_size" class="form-select">
                                    <?php foreach ($page_size_options as $option): ?>
                                        <option value="<?php echo $option; ?>" <?php echo $page_size === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="hidden" name="page" value="1">
                            <div class="col-auto"><button type="submit" class="btn btn-outline-primary">Apply</button></div>
                        </form>
                        <p class="text-muted small mb-3">Showing <?php echo $range_start; ?>-<?php echo $range_end; ?> of <?php echo $total_categories; ?> categories.</p>
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
                                                     <button type="button" class="btn btn-sm btn-outline-info me-1 edit-category-btn"
                                                             aria-label="Edit category" title="Edit category"
                                                             data-category-id="<?php echo (int)$category['id']; ?>"
                                                             data-category-name="<?php echo htmlspecialchars($category['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                             data-category-description="<?php echo htmlspecialchars($category['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" action="categories.php" class="d-inline delete-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int)$category['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete category" title="Delete category">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
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
                        <?php if ($total_pages > 1): ?>
                            <nav class="mt-4" aria-label="Category pagination">
                                <ul class="pagination justify-content-center flex-wrap mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" aria-label="Previous page" href="<?php echo $page > 1 ? htmlspecialchars($category_page_url($page - 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">Previous</a></li>
                                    <?php foreach ($pagination_pages as $pagination_page): ?>
                                        <?php if ($pagination_page === '...'): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php else: ?><li class="page-item <?php echo $page === $pagination_page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($category_page_url($pagination_page), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $page === $pagination_page ? 'aria-current="page"' : ''; ?>><?php echo $pagination_page; ?></a></li><?php endif; ?>
                                    <?php endforeach; ?>
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" aria-label="Next page" href="<?php echo $page < $total_pages ? htmlspecialchars($category_page_url($page + 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">Next</a></li>
                                </ul>
                            </nav>
                        <?php endif; ?>
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
                <h5 class="modal-title fw-bold ui-modal-title" id="addCategoryModalLabel"><i class="fas fa-plus me-2 text-primary"></i>Add Category</h5>
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
                <h5 class="modal-title fw-bold ui-modal-title" id="editCategoryModalLabel"><i class="fas fa-edit me-2 text-primary"></i>Edit Category Details</h5>
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
<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
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
                    row.classList.toggle('table-row-hidden', !(name.includes(value) || desc.includes(value)));
                }
            });
        });
    }
});

function openEditModal(button) {
    document.getElementById('edit_id').value = button.dataset.categoryId;
    document.getElementById('edit_name').value = button.dataset.categoryName;
    document.getElementById('edit_description').value = button.dataset.categoryDescription || '';
    var editModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
    editModal.show();
}

document.querySelectorAll('.edit-category-btn').forEach(function(button) {
    button.addEventListener('click', function() {
        openEditModal(button);
    });
});

document.querySelectorAll('.delete-form').forEach(function(form) {
    form.addEventListener('submit', function(event) {
        event.preventDefault();
        Swal.fire({
            title: 'Delete Category?',
            text: 'Are you sure you want to delete this category? Associated products will revert to General.',
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
