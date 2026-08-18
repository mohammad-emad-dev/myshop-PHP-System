<!-- Page Content Wrapper -->
<div id="page-content-wrapper">
    <nav class="navbar navbar-expand-lg navbar-light px-4 ui-navbar">
        <div class="d-flex align-items-center">
            <i class="fas fa-bars fs-5 me-3" id="menu-toggle"></i>
            <div>
                <h2 class="m-0"><?php echo isset($header_title) ? htmlspecialchars($header_title) : 'Dashboard'; ?></h2>
            </div>
        </div>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse"
            data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
            aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-center">
                <?php
                $low_stock_notification_products = [];
                $low_stock_count = 0;
                if (isset($conn) && $conn instanceof mysqli) {
                    $low_stock_notification_products = get_low_stock_products($conn);
                    $low_stock_count = count($low_stock_notification_products);
                }
                ?>
                <!-- Notification Bell Dropdown -->
                <li class="nav-item dropdown me-2 align-self-center list-unstyled">
                    <a class="nav-link position-relative" href="#" id="notificationDropdown"
                        role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-bell fs-5 <?php echo $low_stock_count > 0 ? 'text-warning animate-bell' : 'text-secondary'; ?>"></i>
                        <?php if ($low_stock_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-2 border-white ui-notification-count">
                                <?php echo $low_stock_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end ui-notification-menu" aria-labelledby="notificationDropdown">
                        <li class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark ui-notification-title">Notifications</span>
                            <?php if ($low_stock_count > 0): ?>
                                <span class="badge bg-danger-subtle text-danger rounded-pill px-2 py-1 fs-8"><?php echo $low_stock_count; ?> Alert(s)</span>
                            <?php endif; ?>
                        </li>
                        <?php if ($low_stock_count > 0): ?>
                            <?php foreach (array_slice($low_stock_notification_products, 0, 5) as $low_prod): ?>
                                <li>
                                    <a class="dropdown-item px-3 py-2 d-flex align-items-center" href="products.php?highlight=<?php echo $low_prod['id']; ?>">
                                        <div class="flex-shrink-0 me-3">
                                            <?php if ($low_prod['image_path']): ?>
                                                <img src="<?php echo htmlspecialchars($low_prod['image_path']); ?>" class="rounded-circle ui-notification-image" alt="">
                                            <?php else: ?>
                                                <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center ui-notification-warning-icon">
                                                    <i class="fas fa-triangle-exclamation"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1 min-width-0">
                                            <h6 class="mb-0 text-truncate fw-bold ui-notification-product-name"><?php echo htmlspecialchars($low_prod['name']); ?></h6>
                                            <small class="text-danger fw-bold fs-8">Stock: <?php echo $low_prod['stock']; ?> / Threshold: <?php echo $low_prod['alert_threshold']; ?></small>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <li class="text-center py-2">
                                <a href="products.php?filter=low_stock" class="text-decoration-none text-primary fw-bold small">View All Alerts →</a>
                            </li>
                        <?php else: ?>
                            <li class="text-center py-4 text-muted">
                                <i class="fas fa-check-circle text-success fs-2 d-block mb-2"></i>
                                <p class="mb-0 small fw-500">All products well stocked!</p>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>

                <!-- Separator -->
                <li class="nav-item d-none d-lg-flex align-self-center mx-2">
                    <div class="ui-navbar-divider"></div>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center py-1 px-2 rounded-3 border-0" href="#" id="navbarDropdown"
                        role="button" data-bs-toggle="dropdown" aria-expanded="false"
                        class="ui-navbar-account-link">
                        <div class="d-flex align-items-center justify-content-center rounded-circle me-2 shadow-sm ui-avatar">
                            <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="d-none d-md-block text-start me-1">
                            <div class="fw-bold text-dark ui-account-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
                            <div class="text-muted mt-1 ui-account-role"><?php echo htmlspecialchars($_SESSION['role'] ?? 'cashier'); ?></div>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 ui-account-menu" aria-labelledby="navbarDropdown">
                        <li class="px-3 py-2 border-bottom mb-1 bg-light rounded-top ui-account-menu-header">
                            <span class="text-muted small d-block fw-semibold mb-1 ui-account-label">Signed in as</span>
                            <div class="d-flex align-items-center">
                                <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 text-uppercase ui-account-role-badge">
                                    <?php echo htmlspecialchars($_SESSION['role'] ?? 'cashier'); ?>
                                </span>
                            </div>
                        </li>
                        <li class="p-1">
                            <a class="dropdown-item rounded-3 d-flex align-items-center py-2" href="settings.php">
                                <i class="fas fa-cog fa-fw me-2 text-secondary fs-6"></i>
                                <span class="fw-semibold text-dark">Settings</span>
                            </a>
                        </li>
                        <li class="px-3"><hr class="dropdown-divider my-1"></li>
                        <li class="p-1">
                            <form method="POST" action="login.php" data-confirm-logout>
                                <input type="hidden" name="action" value="logout">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                <button type="submit" class="dropdown-item rounded-3 d-flex align-items-center py-2 text-danger border-0 bg-transparent w-100 text-start">
                                    <i class="fas fa-sign-out-alt fa-fw me-2 fs-6"></i>
                                    <span class="fw-bold">Logout</span>
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>
