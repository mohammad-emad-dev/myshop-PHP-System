<?php
$current_page = $active_page ?? 'dashboard';
?>
<!-- Sidebar -->
<div class="bg-dark text-white border-end" id="sidebar-wrapper">
    <div class="sidebar-heading text-center py-4 primary-text fs-4 fw-bold text-uppercase border-bottom border-secondary">
        <i class="fas fa-cubes me-2"></i>myshop
    </div>
    <div class="list-group list-group-flush my-3">
        <a href="index.php" class="list-group-item list-group-item-action bg-transparent fw-bold <?php echo $current_page === 'dashboard' ? 'text-primary active' : 'text-white'; ?>">
            <i class="fas fa-tachometer-alt me-2"></i>Dashboard
        </a>
        <a href="products.php" class="list-group-item list-group-item-action bg-transparent fw-bold <?php echo $current_page === 'products' ? 'text-primary active' : 'text-white'; ?>">
            <i class="fas fa-box-open me-2"></i>Products
        </a>
        <?php if (is_admin()): ?>
        <a href="categories.php" class="list-group-item list-group-item-action bg-transparent fw-bold <?php echo $current_page === 'categories' ? 'text-primary active' : 'text-white'; ?>">
            <i class="fas fa-tags me-2"></i>Categories
        </a>
        <?php endif; ?>
        <a href="orders.php" class="list-group-item list-group-item-action bg-transparent fw-bold <?php echo $current_page === 'orders' ? 'text-primary active' : 'text-white'; ?>">
            <i class="fas fa-shopping-cart me-2"></i>Orders (POS)
        </a>
        <a href="order_history.php" class="list-group-item list-group-item-action bg-transparent fw-bold <?php echo $current_page === 'order_history' ? 'text-primary active' : 'text-white'; ?>">
            <i class="fas fa-history me-2"></i>Order History
        </a>
        <a href="stock_movements.php" class="list-group-item list-group-item-action bg-transparent fw-bold <?php echo $current_page === 'stock_movements' ? 'text-primary active' : 'text-white'; ?>">
            <i class="fas fa-exchange-alt me-2"></i>Stock Ledger
        </a>
        <a href="settings.php" class="list-group-item list-group-item-action bg-transparent fw-bold <?php echo $current_page === 'settings' ? 'text-primary active' : 'text-white'; ?>">
            <i class="fas fa-cog me-2"></i>Settings
        </a>
        <a href="login.php?logout=1&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"
           class="list-group-item list-group-item-action bg-transparent text-danger fw-bold mt-5"
           onclick="return confirm('Are you sure you want to logout?');">
            <i class="fas fa-power-off me-2"></i>Logout
        </a>
    </div>
</div>
<!-- /#sidebar-wrapper -->
