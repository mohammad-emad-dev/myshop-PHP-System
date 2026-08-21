<?php
$current_page = $active_page ?? 'dashboard';
?>
<!-- Sidebar -->
<aside id="sidebar-wrapper" class="app-sidebar" aria-label="Primary navigation">
    <div class="sidebar-heading">
        <i class="fas fa-store"></i>myshop
    </div>
    <div class="list-group list-group-flush">
        <div class="sidebar-section-label">Main</div>
        <a href="index.php" class="list-group-item <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>"<?php echo $current_page === 'dashboard' ? ' aria-current="page"' : ''; ?>>
            <i class="fas fa-gauge-high"></i>Dashboard
        </a>
        <a href="products.php" class="list-group-item <?php echo $current_page === 'products' ? 'active' : ''; ?>"<?php echo $current_page === 'products' ? ' aria-current="page"' : ''; ?>>
            <i class="fas fa-box"></i>Products
        </a>
        <?php if (is_admin()): ?>
        <a href="categories.php" class="list-group-item <?php echo $current_page === 'categories' ? 'active' : ''; ?>"<?php echo $current_page === 'categories' ? ' aria-current="page"' : ''; ?>>
            <i class="fas fa-tags"></i>Categories
        </a>
        <?php endif; ?>

        <div class="sidebar-section-label">Sales</div>
        <a href="orders.php" class="list-group-item <?php echo $current_page === 'orders' ? 'active' : ''; ?>"<?php echo $current_page === 'orders' ? ' aria-current="page"' : ''; ?>>
            <i class="fas fa-cash-register"></i>Orders (POS)
        </a>
        <a href="order_history.php" class="list-group-item <?php echo $current_page === 'order_history' ? 'active' : ''; ?>"<?php echo $current_page === 'order_history' ? ' aria-current="page"' : ''; ?>>
            <i class="fas fa-clock-rotate-left"></i>Order History
        </a>
        <a href="stock_movements.php" class="list-group-item <?php echo $current_page === 'stock_movements' ? 'active' : ''; ?>"<?php echo $current_page === 'stock_movements' ? ' aria-current="page"' : ''; ?>>
            <i class="fas fa-chart-line"></i>Stock Ledger
        </a>

        <div class="sidebar-section-label">People</div>
        <a href="customers.php" class="list-group-item <?php echo $current_page === 'customers' ? 'active' : ''; ?>"<?php echo $current_page === 'customers' ? ' aria-current="page"' : ''; ?>>
            <i class="fas fa-users"></i>Customers
        </a>
        <a href="suppliers.php" class="list-group-item <?php echo $current_page === 'suppliers' ? 'active' : ''; ?>"<?php echo $current_page === 'suppliers' ? ' aria-current="page"' : ''; ?>>
            <i class="fas fa-truck"></i>Suppliers
        </a>

        <div class="sidebar-section-label">System</div>
        <a href="settings.php" class="list-group-item <?php echo $current_page === 'settings' ? 'active' : ''; ?>"<?php echo $current_page === 'settings' ? ' aria-current="page"' : ''; ?>>
            <i class="fas fa-gear"></i>Settings
        </a>
        <?php if (is_admin()): ?>
        <a href="audit_log.php" class="list-group-item <?php echo $current_page === 'audit_log' ? 'active' : ''; ?>"<?php echo $current_page === 'audit_log' ? ' aria-current="page"' : ''; ?>>
            <i class="fas fa-shield-halved"></i>Audit Log
        </a>
        <?php endif; ?>
        <form method="POST" action="login.php" class="mt-auto" data-confirm-logout>
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
            <button type="submit" class="list-group-item sidebar-logout w-100 text-start">
                <i class="fas fa-power-off"></i>Logout
            </button>
        </form>
    </div>
</aside>
<!-- /#sidebar-wrapper -->
