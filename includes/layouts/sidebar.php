<?php
$current_page = $active_page ?? 'dashboard';
?>
<!-- Sidebar -->
<div id="sidebar-wrapper">
    <div class="sidebar-heading">
        <i class="fas fa-store"></i>myshop
    </div>
    <div class="list-group list-group-flush">
        <div class="sidebar-section-label">Main</div>
        <a href="index.php" class="list-group-item <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
            <i class="fas fa-gauge-high"></i>Dashboard
        </a>
        <a href="products.php" class="list-group-item <?php echo $current_page === 'products' ? 'active' : ''; ?>">
            <i class="fas fa-box"></i>Products
        </a>
        <?php if (is_admin()): ?>
        <a href="categories.php" class="list-group-item <?php echo $current_page === 'categories' ? 'active' : ''; ?>">
            <i class="fas fa-tags"></i>Categories
        </a>
        <?php endif; ?>

        <div class="sidebar-section-label">Sales</div>
        <a href="orders.php" class="list-group-item <?php echo $current_page === 'orders' ? 'active' : ''; ?>">
            <i class="fas fa-cash-register"></i>Orders (POS)
        </a>
        <a href="order_history.php" class="list-group-item <?php echo $current_page === 'order_history' ? 'active' : ''; ?>">
            <i class="fas fa-clock-rotate-left"></i>Order History
        </a>
        <a href="stock_movements.php" class="list-group-item <?php echo $current_page === 'stock_movements' ? 'active' : ''; ?>">
            <i class="fas fa-chart-line"></i>Stock Ledger
        </a>

        <div class="sidebar-section-label">People</div>
        <a href="customers.php" class="list-group-item <?php echo $current_page === 'customers' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>Customers
        </a>
        <a href="suppliers.php" class="list-group-item <?php echo $current_page === 'suppliers' ? 'active' : ''; ?>">
            <i class="fas fa-truck"></i>Suppliers
        </a>

        <div class="sidebar-section-label">System</div>
        <a href="settings.php" class="list-group-item <?php echo $current_page === 'settings' ? 'active' : ''; ?>">
            <i class="fas fa-gear"></i>Settings
        </a>
        <a href="login.php?logout=1&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"
           class="list-group-item sidebar-logout mt-auto"
           onclick="return confirm('Are you sure you want to logout?');">
            <i class="fas fa-power-off"></i>Logout
        </a>
    </div>
</div>
<!-- /#sidebar-wrapper -->
