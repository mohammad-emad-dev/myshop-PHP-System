<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

verify_login();

$success = '';
$error = '';

// Handle Actions (Create/Update via POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Invalid request token.';
    } elseif (!is_admin()) {
        $error = 'Access denied. You do not have permission to modify products.';
    } else {
        $action = $_POST['action'];

        if ($action === 'create') {
            $name = sanitize_input($_POST['name']);
            $description = sanitize_input($_POST['description']);
            $price = floatval($_POST['price']);
            $stock = intval($_POST['stock']);
            $alert_threshold = isset($_POST['alert_threshold']) ? max(0, intval($_POST['alert_threshold'])) : 10;
            $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : null;

            $image_path = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
                $image_path = handle_image_upload($_FILES['image']);
                if ($image_path === false) {
                    $error = 'Invalid image file or upload failed. Allowed formats: JPG, PNG, GIF (max 5MB).';
                }
            }

            if (empty($error)) {
                if (create_product($conn, $_SESSION['staff_id'], $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id)) {
                    $success = 'Product created successfully';
                } else {
                    $error = 'Failed to create product';
                }
            }
        } else if ($action === 'update') {
            $id = intval($_POST['id']);
            $name = sanitize_input($_POST['name']);
            $description = sanitize_input($_POST['description']);
            $price = floatval($_POST['price']);
            $stock = intval($_POST['stock']);
            $alert_threshold = isset($_POST['alert_threshold']) ? max(0, intval($_POST['alert_threshold'])) : 10;
            $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : null;

            $image_path = null;
            $upload_ok = true;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
                $image_path = handle_image_upload($_FILES['image']);
                if ($image_path === false) {
                    $error = 'Invalid image file or upload failed. Allowed formats: JPG, PNG, GIF (max 5MB).';
                    $upload_ok = false;
                }
            }

            if ($upload_ok) {
                if (update_product($conn, $_SESSION['staff_id'], $id, $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id)) {
                    $success = 'Product updated successfully';
                } else {
                    $error = 'Failed to update product';
                }
            }
        }
    }
}

// Handle Delete via GET (with CSRF verification)
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $csrf_token = $_GET['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security check failed. Invalid request token.';
    } elseif (!is_admin()) {
        $error = 'Access denied. You do not have permission to delete products.';
    } else {
        if (delete_product($conn, $id)) {
            $success = 'Product deleted successfully';
        } else {
            $error = 'Failed to delete product (It might be in use by an order)';
        }
    }
}

$filter = $_GET['filter'] ?? '';
if ($filter === 'low_stock') {
    $products = get_low_stock_products($conn);
    $header_title = 'Low Stock Alerts';
} else {
    $products = get_products($conn);
    $header_title = 'Product Management';
}

$categories = get_categories($conn);
$page_title = 'Products';
$active_page = 'products';


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
                        <h4 class="mb-0 text-secondary fw-bold">All Products</h4>
                        <?php if (is_admin()): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                            <i class="fas fa-plus me-2"></i>Add Product
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="input-group shadow-sm rounded-3 overflow-hidden border">
                                    <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                                    <input type="text" id="searchProduct" placeholder="Search products by name, category..." class="form-control border-0 px-2" style="box-shadow: none; font-size: 0.9rem;">
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="productsTable">
                                <thead class="bg-light text-secondary">
                                    <tr>
                                        <th scope="col">ID</th>
                                        <th scope="col">Name</th>
                                        <th scope="col">Category</th>
                                        <th scope="col">Description</th>
                                        <th scope="col">Price</th>
                                        <th scope="col">Stock</th>
                                        <th scope="col">Threshold</th>
                                        <th scope="col">Image</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $product): ?>
                                        <?php
                                        $highlight_id = isset($_GET['highlight']) ? intval($_GET['highlight']) : 0;
                                        $is_low = $product['stock'] <= $product['alert_threshold'];
                                        $row_class = '';
                                        if ($product['id'] == $highlight_id) {
                                            $row_class = 'table-warning border border-warning fw-bold';
                                        } else if ($is_low) {
                                            $row_class = 'table-danger-subtle';
                                        }
                                        ?>
                                        <tr class="<?php echo $row_class; ?>">
                                            <td><?php echo $product['id']; ?></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($product['name']); ?></td>
                                            <td>
                                                <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2">
                                                    <?php echo htmlspecialchars($product['category_name'] ?: 'Uncategorized'); ?>
                                                </span>
                                            </td>
                                            <td class="text-muted">
                                                <?php echo htmlspecialchars(substr($product['description'], 0, 50)); ?>...
                                            </td>
                                            <td class="text-success fw-bold">$<?php echo number_format($product['price'], 2); ?></td>
                                            <td>
                                                <?php if ($is_low): ?>
                                                    <span class="badge rounded-pill bg-danger shadow-sm">Low Stock (<?php echo $product['stock']; ?>)</span>
                                                <?php else: ?>
                                                    <span class="badge rounded-pill bg-success shadow-sm">In Stock (<?php echo $product['stock']; ?>)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold <?php echo $is_low ? 'text-danger' : 'text-secondary'; ?>">
                                                <?php echo $product['alert_threshold']; ?>
                                            </td>
                                            <td>
                                                <?php if ($product['image_path']): ?>
                                                    <img src="<?php echo htmlspecialchars($product['image_path']); ?>" alt="Product" class="rounded" style="width: 50px; height: 50px; object-fit: cover;">
                                                <?php else: ?>
                                                    <span class="text-muted fst-italic small">No Image</span>
                                                <?php endif; ?>
                                            </td>
                                             <td>
                                                 <a href="stock_movements.php?product_id=<?php echo $product['id']; ?>" class="btn btn-sm btn-outline-secondary me-1" title="Stock History">
                                                     <i class="fas fa-history"></i>
                                                 </a>
                                                 <?php if (is_admin()): ?>
                                                 <button class="btn btn-sm btn-outline-info me-1" onclick='openEditModal(<?php echo json_encode($product); ?>)'>
                                                     <i class="fas fa-edit"></i>
                                                 </button>
                                                 <a href="products.php?delete=<?php echo $product['id']; ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this product?');">
                                                     <i class="fas fa-trash"></i>
                                                 </a>
                                                 <?php endif; ?>
                                             </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header py-3">
                    <h5 class="modal-title fw-bold" id="addProductModalLabel" style="font-family: var(--font-heading); color: var(--slate-900);"><i class="fas fa-plus me-2 text-primary"></i>Add New Product</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label for="name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="category_id" class="form-label">Category</label>
                            <select class="form-select" id="category_id" name="category_id" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="price" class="form-label">Price ($)</label>
                                <input type="number" class="form-control" id="price" name="price" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="stock" class="form-label">Stock Quantity</label>
                                <input type="number" class="form-control" id="stock" name="stock" min="0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="alert_threshold" class="form-label">Low Stock Alert Threshold</label>
                            <input type="number" class="form-control" id="alert_threshold" name="alert_threshold" min="0" value="10" required>
                        </div>
                        <div class="mb-3">
                            <label for="image" class="form-label">Product Image</label>
                            <input class="form-control" type="file" id="image" name="image" accept="image/*">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-3 px-4">Add Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header py-3">
                    <h5 class="modal-title fw-bold" id="editProductModalLabel" style="font-family: var(--font-heading); color: var(--slate-900);"><i class="fas fa-edit me-2 text-primary"></i>Edit Product Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" id="edit_id" name="id">

                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_category_id" class="form-label">Category</label>
                            <select class="form-select" id="edit_category_id" name="category_id" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_price" class="form-label">Price ($)</label>
                                <input type="number" class="form-control" id="edit_price" name="price" step="0.01" min="0" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_stock" class="form-label">Stock Quantity</label>
                                <input type="number" class="form-control" id="edit_stock" name="stock" min="0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_alert_threshold" class="form-label">Low Stock Alert Threshold</label>
                            <input type="number" class="form-control" id="edit_alert_threshold" name="alert_threshold" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_image" class="form-label">Product Image (leave empty to keep current)</label>
                            <input class="form-control" type="file" id="edit_image" name="image" accept="image/*">
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
$extra_js = ['assets/js/script.js'];
?>
<script>
    // Custom function to open Bootstrap modal and populate data
    function openEditModal(product) {
        document.getElementById('edit_id').value = product.id;
        document.getElementById('edit_name').value = product.name;
        document.getElementById('edit_category_id').value = product.category_id || '';
        document.getElementById('edit_description').value = product.description;
        document.getElementById('edit_price').value = product.price;
        document.getElementById('edit_stock').value = product.stock;
        document.getElementById('edit_alert_threshold').value = product.alert_threshold || 10;

        var editModal = new bootstrap.Modal(document.getElementById('editProductModal'));
        editModal.show();
    }
</script>
<?php
require_once '../includes/layouts/footer.php';
?>