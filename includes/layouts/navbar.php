<!-- Page Content Wrapper -->
<div id="page-content-wrapper">
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm py-3 px-4">
        <div class="d-flex align-items-center">
            <i class="fas fa-align-left primary-text fs-4 me-3" id="menu-toggle"></i>
            <h2 class="fs-3 m-0 text-dark"><?php echo isset($header_title) ? htmlspecialchars($header_title) : 'Dashboard'; ?></h2>
        </div>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
            aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <?php
                $low_stock_notification_products = [];
                $low_stock_count = 0;
                if (isset($conn) && $conn instanceof mysqli) {
                    $low_stock_notification_products = get_low_stock_products($conn);
                    $low_stock_count = count($low_stock_notification_products);
                }
                ?>
                <!-- Notification Bell Dropdown -->
                <li class="nav-item dropdown me-3 align-self-center list-unstyled">
                    <a class="nav-link position-relative text-secondary" href="#" id="notificationDropdown"
                        role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-bell fs-4 <?php echo $low_stock_count > 0 ? 'text-warning animate-bell' : ''; ?>"></i>
                        <?php if ($low_stock_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-white" style="font-size: 0.65rem; padding: 0.25em 0.5em;">
                                <?php echo $low_stock_count; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end py-2" aria-labelledby="notificationDropdown" style="width: 320px; max-height: 400px; overflow-y: auto;">
                        <li class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-secondary">Notifications</span>
                            <?php if ($low_stock_count > 0): ?>
                                <span class="badge bg-danger-subtle text-danger rounded-pill px-2 py-1 fs-8"><?php echo $low_stock_count; ?> Alert(s)</span>
                            <?php endif; ?>
                        </li>
                        <?php if ($low_stock_count > 0): ?>
                            <?php foreach (array_slice($low_stock_notification_products, 0, 5) as $low_prod): ?>
                                <li>
                                    <a class="dropdown-item px-3 py-2 border-bottom d-flex align-items-center" href="products.php?highlight=<?php echo $low_prod['id']; ?>">
                                        <div class="flex-shrink-0 me-3">
                                            <?php if ($low_prod['image_path']): ?>
                                                <img src="<?php echo htmlspecialchars($low_prod['image_path']); ?>" class="rounded-circle" style="width: 40px; height: 40px; object-fit: cover;" alt="">
                                            <?php else: ?>
                                                <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1 min-width-0">
                                            <h6 class="mb-0 text-truncate text-dark fw-bold fs-7"><?php echo htmlspecialchars($low_prod['name']); ?></h6>
                                            <small class="text-danger fw-bold fs-8">Stock: <?php echo $low_prod['stock']; ?> / Threshold: <?php echo $low_prod['alert_threshold']; ?></small>
                                        </div>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <li class="text-center py-2 bg-light">
                                <a href="products.php?filter=low_stock" class="text-decoration-none text-primary fw-bold small">View All Alerts</a>
                            </li>
                        <?php else: ?>
                            <li class="text-center py-4 text-muted">
                                <i class="fas fa-check-circle text-success fs-1 mb-2"></i>
                                <p class="mb-0 small">All products are well stocked!</p>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-secondary fw-bold d-flex align-items-center" href="#" id="navbarDropdown"
                        role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-circle fs-4 me-2"></i><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end py-2" aria-labelledby="navbarDropdown">
                        <li class="dropdown-header border-bottom pb-2 mb-1 px-3">
                            <span class="text-muted small d-block mb-1">Signed in as</span>
                            <span class="badge primary-bg primary-text text-uppercase px-2.5 py-1 rounded-pill" style="font-size: 0.7rem; font-weight: 700; letter-spacing: 0.5px;"><?php echo htmlspecialchars($_SESSION['role'] ?? 'cashier'); ?></span>
                        </li>
                        <li><a class="dropdown-item px-3" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li>
                            <hr class="dropdown-divider my-1">
                        </li>
                        <li><a class="dropdown-item text-danger px-3" href="login.php?logout=1&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"><i class="fas fa-power-off me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>
