<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

verify_login();

$success = '';
$error = '';

// Handle Actions (Create/Update via POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create') {
        $name = sanitize_input($_POST['name']);
        $description = sanitize_input($_POST['description']);
        $price = floatval($_POST['price']);
        $stock = intval($_POST['stock']);

        $image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $image_path = handle_image_upload($_FILES['image']);
        }

        if (create_product($conn, $name, $description, $price, $stock, $image_path)) {
            $success = 'Product created successfully';
        } else {
            $error = 'Failed to create product';
        }
    } else if ($action === 'update') {
        $id = intval($_POST['id']);
        $name = sanitize_input($_POST['name']);
        $description = sanitize_input($_POST['description']);
        $price = floatval($_POST['price']);
        $stock = intval($_POST['stock']);

        $image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $image_path = handle_image_upload($_FILES['image']);
        }

        if (update_product($conn, $id, $name, $description, $price, $stock, $image_path)) {
            $success = 'Product updated successfully';
        } else {
            $error = 'Failed to update product';
        }
    }
}

// Handle Delete via GET
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    // Check if deletion was successful
    if (delete_product($conn, $id)) {
        $success = 'Product deleted successfully';
    } else {
        $error = 'Failed to delete product (It might be in use by an order)';
    }
}

$products = get_products($conn);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - myshop</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="bg-light">

    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <div class="bg-dark text-white border-end" id="sidebar-wrapper">
            <div
                class="sidebar-heading text-center py-4 primary-text fs-4 fw-bold text-uppercase border-bottom border-secondary">
                <i class="fas fa-cubes me-2"></i>myshop
            </div>
            <div class="list-group list-group-flush my-3">
                <a href="index.php" class="list-group-item list-group-item-action bg-transparent text-white fw-bold">
                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                </a>
                <a href="products.php"
                    class="list-group-item list-group-item-action bg-transparent text-primary fw-bold active">
                    <i class="fas fa-box-open me-2"></i>Products
                </a>
                <a href="orders.php" class="list-group-item list-group-item-action bg-transparent text-white fw-bold">
                    <i class="fas fa-shopping-cart me-2"></i>Orders
                </a>
                <a href="login.php?logout=1"
                    class="list-group-item list-group-item-action bg-transparent text-danger fw-bold mt-5"
                    onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-power-off me-2"></i>Logout
                </a>
            </div>
        </div>
        <!-- /#sidebar-wrapper -->

        <!-- Page Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm py-4 px-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-align-left primary-text fs-4 me-3" id="menu-toggle"></i>
                    <h2 class="fs-2 m-0 text-dark">Product Management</h2>
                </div>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
                    aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle text-secondary fw-bold" href="#" id="navbarDropdown"
                                role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i
                                    class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($_SESSION['full_name']); ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                <li><a class="dropdown-item" href="#">Profile</a></li>
                                <li><a class="dropdown-item" href="#">Settings</a></li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item text-danger" href="login.php?logout=1">Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="container-fluid px-4 py-5">

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row my-2">
                    <div class="col-md-12">
                        <div class="card shadow-sm border-0 rounded-4">
                            <div
                                class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                                <h4 class="mb-0 text-secondary fw-bold">All Products</h4>
                                <button class="btn btn-primary" data-bs-toggle="modal"
                                    data-bs-target="#addProductModal">
                                    <i class="fas fa-plus me-2"></i>Add Product
                                </button>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <input type="text" id="searchProduct" placeholder="Search products..."
                                            class="form-control">
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle" id="productsTable">
                                        <thead class="bg-light text-secondary">
                                            <tr>
                                                <th scope="col">ID</th>
                                                <th scope="col">Name</th>
                                                <th scope="col">Description</th>
                                                <th scope="col">Price</th>
                                                <th scope="col">Stock</th>
                                                <th scope="col">Image</th>
                                                <th scope="col">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($products as $product): ?>
                                                <tr>
                                                    <td><?php echo $product['id']; ?></td>
                                                    <td class="fw-bold"><?php echo htmlspecialchars($product['name']); ?>
                                                    </td>
                                                    <td class="text-muted">
                                                        <?php echo htmlspecialchars(substr($product['description'], 0, 50)); ?>...
                                                    </td>
                                                    <td class="text-success fw-bold">
                                                        $<?php echo number_format($product['price'], 2); ?></td>
                                                    <td>
                                                        <?php if ($product['stock'] <= 5): ?>
                                                            <span class="badge rounded-pill bg-danger">Low Stock
                                                                (<?php echo $product['stock']; ?>)</span>
                                                        <?php else: ?>
                                                            <span class="badge rounded-pill bg-success">In Stock
                                                                (<?php echo $product['stock']; ?>)</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($product['image_path']): ?>
                                                            <img src="<?php echo htmlspecialchars($product['image_path']); ?>"
                                                                alt="Product" class="rounded"
                                                                style="width: 50px; height: 50px; object-fit: cover;">
                                                        <?php else: ?>
                                                            <span class="text-muted fst-italic small">No Image</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-info me-1"
                                                            onclick='openEditModal(<?php echo json_encode($product); ?>)'>
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <a href="products.php?delete=<?php echo $product['id']; ?>"
                                                            class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Are you sure you want to delete this product?');">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
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
        </div>
    </div>
    <!-- /#page-content-wrapper -->

    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addProductModalLabel">Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label for="name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"
                                required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="price" class="form-label">Price ($)</label>
                                <input type="number" class="form-control" id="price" name="price" step="0.01" min="0"
                                    required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="stock" class="form-label">Stock Quantity</label>
                                <input type="number" class="form-control" id="stock" name="stock" min="0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="image" class="form-label">Product Image</label>
                            <input class="form-control" type="file" id="image" name="image" accept="image/*">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1" aria-labelledby="editProductModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editProductModalLabel">Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" id="edit_id" name="id">

                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"
                                required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_price" class="form-label">Price ($)</label>
                                <input type="number" class="form-control" id="edit_price" name="price" step="0.01"
                                    min="0" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_stock" class="form-label">Stock Quantity</label>
                                <input type="number" class="form-control" id="edit_stock" name="stock" min="0" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_image" class="form-label">Product Image (leave empty to keep
                                current)</label>
                            <input class="form-control" type="file" id="edit_image" name="image" accept="image/*">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js"></script>
    <script>
        var el = document.getElementById("wrapper");
        var toggleButton = document.getElementById("menu-toggle");

        toggleButton.onclick = function () {
            el.classList.toggle("toggled");
        };

        // Custom function to open Bootstrap modal and populate data
        function openEditModal(product) {
            document.getElementById('edit_id').value = product.id;
            document.getElementById('edit_name').value = product.name;
            document.getElementById('edit_description').value = product.description;
            document.getElementById('edit_price').value = product.price;
            document.getElementById('edit_stock').value = product.stock;

            var editModal = new bootstrap.Modal(document.getElementById('editProductModal'));
            editModal.show();
        }
    </script>
</body>

</html>