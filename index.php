<?php
require_once 'includes/functions.php';
start_secure_session();
require_once 'includes/db.php';

verify_login();

$stats = get_dashboard_stats($conn);

$page_title = 'Dashboard';
$active_page = 'dashboard';
$header_title = 'Dashboard';

require_once 'includes/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once 'includes/sidebar.php'; ?>
    <?php require_once 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 py-5">
        <div class="row g-3 my-2">
            <div class="col-md-3">
                <div class="p-3 bg-white shadow-sm d-flex justify-content-around align-items-center rounded dashboard-card border-left-primary">
                    <div>
                        <h3 class="fs-2"><?php echo number_format($stats['total_products']); ?></h3>
                        <p class="fs-5 text-muted mb-0">Products</p>
                    </div>
                    <i class="fas fa-boxes fs-1 primary-text border rounded-full secondary-bg p-3"></i>
                </div>
            </div>

            <div class="col-md-3">
                <div class="p-3 bg-white shadow-sm d-flex justify-content-around align-items-center rounded dashboard-card border-left-success">
                    <div>
                        <h3 class="fs-2"><?php echo number_format($stats['total_orders']); ?></h3>
                        <p class="fs-5 text-muted mb-0">Orders</p>
                    </div>
                    <i class="fas fa-truck fs-1 success-text border rounded-full success-bg p-3"></i>
                </div>
            </div>

            <div class="col-md-3">
                <div class="p-3 bg-white shadow-sm d-flex justify-content-around align-items-center rounded dashboard-card border-left-info">
                    <div>
                        <h3 class="fs-2">$<?php echo number_format($stats['total_sales'], 2); ?></h3>
                        <p class="fs-5 text-muted mb-0">Sales</p>
                    </div>
                    <i class="fas fa-hand-holding-usd fs-1 info-text border rounded-full info-bg p-3"></i>
                </div>
            </div>

            <div class="col-md-3">
                <div class="p-3 bg-white shadow-sm d-flex justify-content-around align-items-center rounded dashboard-card border-left-warning">
                    <div>
                        <h3 class="fs-2"><?php echo number_format($stats['total_stock']); ?></h3>
                        <p class="fs-5 text-muted mb-0">Total Stock</p>
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
                        <h5 class="card-title text-primary"><i class="fas fa-box me-2"></i>Product Management</h5>
                        <p class="card-text text-muted">View, add, edit, and manage all products in your inventory.</p>
                        <a href="products.php" class="btn btn-primary"><i class="fas fa-arrow-right me-2"></i>Manage Products</a>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <h5 class="card-title text-success"><i class="fas fa-shopping-cart me-2"></i>Order Processing</h5>
                        <p class="card-text text-muted">Create new orders and view order history with details.</p>
                        <a href="orders.php" class="btn btn-success"><i class="fas fa-arrow-right me-2"></i>Manage Orders</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
require_once 'includes/footer.php';
?>