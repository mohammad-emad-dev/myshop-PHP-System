<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

verify_login();

$stats = get_dashboard_stats($conn);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - myshop</title>
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
                <a href="index.php"
                    class="list-group-item list-group-item-action bg-transparent text-primary fw-bold active">
                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                </a>
                <a href="products.php" class="list-group-item list-group-item-action bg-transparent text-white fw-bold">
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
                    <h2 class="fs-2 m-0 text-dark">Dashboard</h2>
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

            <?php
            if (isset($_GET['logout'])) {
                session_destroy();
                redirect('login.php');
            }
            ?>

            <div class="container-fluid px-4 py-5">
                <div class="row g-3 my-2">
                    <div class="col-md-3">
                        <div
                            class="p-3 bg-white shadow-sm d-flex justify-content-around align-items-center rounded dashboard-card border-left-primary">
                            <div>
                                <h3 class="fs-2"><?php echo number_format($stats['total_products']); ?></h3>
                                <p class="fs-5 text-muted">Products</p>
                            </div>
                            <i class="fas fa-boxes fs-1 primary-text border rounded-full secondary-bg p-3"></i>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div
                            class="p-3 bg-white shadow-sm d-flex justify-content-around align-items-center rounded dashboard-card border-left-success">
                            <div>
                                <h3 class="fs-2"><?php echo number_format($stats['total_orders']); ?></h3>
                                <p class="fs-5 text-muted">Orders</p>
                            </div>
                            <i class="fas fa-truck fs-1 success-text border rounded-full success-bg p-3"></i>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div
                            class="p-3 bg-white shadow-sm d-flex justify-content-around align-items-center rounded dashboard-card border-left-info">
                            <div>
                                <h3 class="fs-2">$<?php echo number_format($stats['total_sales'], 2); ?></h3>
                                <p class="fs-5 text-muted">Sales</p>
                            </div>
                            <i class="fas fa-hand-holding-usd fs-1 info-text border rounded-full info-bg p-3"></i>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div
                            class="p-3 bg-white shadow-sm d-flex justify-content-around align-items-center rounded dashboard-card border-left-warning">
                            <div>
                                <h3 class="fs-2"><?php echo number_format($stats['total_stock']); ?></h3>
                                <p class="fs-5 text-muted">Total Stock</p>
                            </div>
                            <i class="fas fa-warehouse fs-1 warning-text border rounded-full warning-bg p-3"></i>
                        </div>
                    </div>
                </div>

                <div class="row my-5">
                    <h3 class="fs-4 mb-3 text-secondary">Quick Actions</h3>
                    <div class="col-md-6">
                        <div class="card shadow-sm border-0">
                            <div class="card-body p-4">
                                <h5 class="card-title text-primary"><i class="fas fa-box me-2"></i>Product Management
                                </h5>
                                <p class="card-text text-muted">View, add, edit, and manage all products in your
                                    inventory.</p>
                                <a href="products.php" class="btn btn-primary"><i
                                        class="fas fa-arrow-right me-2"></i>Manage Products</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card shadow-sm border-0">
                            <div class="card-body p-4">
                                <h5 class="card-title text-success"><i class="fas fa-shopping-cart me-2"></i>Order
                                    Processing</h5>
                                <p class="card-text text-muted">Create new orders and view order history with details.
                                </p>
                                <a href="orders.php" class="btn btn-success"><i
                                        class="fas fa-arrow-right me-2"></i>Manage Orders</a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <!-- /#page-content-wrapper -->

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js"></script>
    <script>
        var el = document.getElementById("wrapper");
        var toggleButton = document.getElementById("menu-toggle");

        toggleButton.onclick = function () {
            el.classList.toggle("toggled");
        };
    </script>
</body>

</html>