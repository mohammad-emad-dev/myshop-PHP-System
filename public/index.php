<?php
/**
 * Dashboard — Main analytics overview.
 *
 * Displays KPI cards, 7-day sales/purchases chart, top-selling products,
 * category distribution doughnut, low-stock alerts, and quick-action links.
 *
 * Security: session-gated, all output escaped, JSON payloads hex-encoded.
 */

require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

auth_verify_login($conn);

// Scope order-derived dashboard analytics to the current cashier. Product and
// stock inventory totals remain global because they do not identify staff.
$dashboard_staff_id = auth_is_admin($conn) ? null : (int)$_SESSION['staff_id'];

/* ──────────────────────────────────────────────
 * DATA LAYER — Fetch all analytics in one pass.
 * ────────────────────────────────────────────── */
$stats               = dashboard_get_stats($conn, $dashboard_staff_id);
$chart_data           = dashboard_get_chart_data($conn, 7, $dashboard_staff_id);
$inventory_valuation  = get_inventory_valuation($conn);
$top_selling_products = get_top_selling_products($conn, 5, $dashboard_staff_id);
$category_sales       = get_category_sales_distribution($conn, $dashboard_staff_id);
$low_stock_products   = get_low_stock_products($conn);
$total_low_stock      = count($low_stock_products);

/* ──────────────────────────────────────────────
 * LAYOUT CONFIGURATION
 * ────────────────────────────────────────────── */
$page_title   = 'Dashboard';
$active_page  = 'dashboard';
$header_title = 'Dashboard';
$extra_js     = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'];

require_once '../includes/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once '../includes/layouts/sidebar.php'; ?>
    <?php require_once '../includes/layouts/navbar.php'; ?>

    <div class="container-fluid px-4 py-4">

        <!-- ═══════════════════════════════════════════
             SECTION 1: KPI STAT CARDS
             ═══════════════════════════════════════════ -->
        <div class="row g-3 my-2">
            <?php
            $kpi_cards = [
                [
                    'label' => 'Products',
                    'value' => number_format($stats['total_products']),
                    'icon'  => 'fa-box-archive',
                    'color' => 'primary',
                ],
                [
                    'label' => 'Orders',
                    'value' => number_format($stats['total_orders']),
                    'icon'  => 'fa-receipt',
                    'color' => 'success',
                ],
                [
                    'label' => 'Revenue',
                    'value' => '$' . number_format($stats['total_sales'], 2),
                    'icon'  => 'fa-dollar-sign',
                    'color' => 'info',
                ],
                [
                    'label' => 'Total Stock',
                    'value' => number_format($stats['total_stock']),
                    'icon'  => 'fa-warehouse',
                    'color' => 'warning',
                ],
                [
                    'label' => 'Valuation',
                    'value' => '$' . number_format($inventory_valuation, 2),
                    'icon'  => 'fa-coins',
                    'color' => 'purple',
                ],
            ];
            foreach ($kpi_cards as $card):
            ?>
            <div class="col-xl col-md-4 col-sm-6">
                <div class="p-4 bg-white shadow-sm d-flex justify-content-between align-items-center rounded-3 dashboard-card border-left-<?php echo $card['color']; ?> h-100">
                    <div>
                        <h2 class="fs-2 mb-1 dashboard-kpi-value"><?php echo $card['value']; ?></h2>
                        <p class="fs-6 text-muted mb-0 fw-medium"><?php echo $card['label']; ?></p>
                    </div>
                    <div class="rounded-full <?php echo $card['color']; ?>-bg p-3 d-flex align-items-center justify-content-center dashboard-kpi-icon">
                        <i class="fas <?php echo $card['icon']; ?> fs-4 <?php echo $card['color']; ?>-text"></i>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ═══════════════════════════════════════════
             SECTION 2: SALES & PURCHASES CHART (7-DAY)
             ═══════════════════════════════════════════ -->
        <div class="row my-4">
            <div class="col-md-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2 class="h5 card-title text-secondary mb-0 fw-bold dashboard-section-title">
                                <i class="fas fa-chart-line me-2 text-primary"></i>Sales &amp; Purchases Flow
                            </h2>
                            <span class="badge bg-light text-dark border">
                                <i class="fas fa-calendar-days me-1 text-primary"></i> Last 7 Days
                            </span>
                        </div>
                        <div class="dashboard-chart-large">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             SECTION 3: TOP SELLING + CATEGORY PIE
             ═══════════════════════════════════════════ -->
        <div class="row my-4 g-4">
            <!-- Top Selling Products -->
            <div class="col-lg-7 col-md-12">
                <div class="card shadow-sm border-0 h-100 rounded-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h2 class="h5 mb-0 text-secondary fw-bold dashboard-section-title">
                            <i class="fas fa-trophy text-warning me-2"></i>Top Selling Products
                        </h2>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!empty($top_selling_products)): ?>
                            <?php
                            $max_qty = max(array_column($top_selling_products, 'total_qty'));
                            foreach ($top_selling_products as $index => $tp):
                                $percentage = $max_qty > 0 ? round(($tp['total_qty'] / $max_qty) * 100) : 0;
                            ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-bold text-dark">
                                            <span class="badge bg-light text-secondary me-2">#<?php echo $index + 1; ?></span>
                                            <?php echo htmlspecialchars($tp['name']); ?>
                                        </span>
                                        <span class="text-muted small fw-bold">
                                            <?php echo number_format($tp['total_qty']); ?> sold ($<?php echo number_format($tp['total_sales'], 2); ?>)
                                        </span>
                                    </div>
                                    <div class="progress rounded-pill dashboard-progress">
                                        <div class="progress-bar rounded-pill dashboard-progress-bar" role="progressbar"
                                             data-progress="<?php echo (int)$percentage; ?>"
                                             aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-chart-bar fa-3x mb-3 text-secondary opacity-25 d-block"></i>
                                <p class="mb-0">No sales data recorded yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Category Sales Distribution -->
            <div class="col-lg-5 col-md-12">
                <div class="card shadow-sm border-0 h-100 rounded-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h2 class="h5 mb-0 text-secondary fw-bold dashboard-section-title">
                            <i class="fas fa-chart-pie text-info me-2"></i>Category Distribution
                        </h2>
                    </div>
                    <div class="card-body p-4 d-flex flex-column justify-content-center">
                        <?php if (!empty($category_sales)): ?>
                            <div class="dashboard-chart-category">
                                <canvas id="categoryChart"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-chart-pie fa-3x mb-3 text-secondary opacity-25 d-block"></i>
                                <p class="mb-0">No category sales data available.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             SECTION 4: LOW STOCK ALERTS
             ═══════════════════════════════════════════ -->
        <div class="row my-4">
            <div class="col-md-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0 text-secondary fw-bold dashboard-section-title">
                            <i class="fas fa-triangle-exclamation text-danger me-2"></i>Low Stock Alerts
                        </h2>
                        <?php if ($total_low_stock > 0): ?>
                            <span class="badge bg-danger rounded-pill px-3 py-2"><?php echo $total_low_stock; ?> Action Required</span>
                        <?php else: ?>
                            <span class="badge bg-success rounded-pill px-3 py-2">All Stock Stable</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($total_low_stock > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped align-middle mb-0">
                                    <thead class="bg-light text-secondary">
                                        <tr>
                                            <th scope="col">ID</th>
                                            <th scope="col">Product</th>
                                            <th scope="col" class="text-center">Current Stock</th>
                                            <th scope="col" class="text-center">Threshold</th>
                                            <th scope="col" class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($low_stock_products as $p): ?>
                                            <tr>
                                                <td class="text-muted">#<?php echo (int)$p['id']; ?></td>
                                                <td class="fw-bold">
                                                    <?php if (!empty($p['image_path'])): ?>
                                                        <img src="<?php echo htmlspecialchars($p['image_path']); ?>"
                                                             class="product-thumb me-2" alt="" loading="lazy">
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($p['name']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge rounded-pill bg-danger-subtle text-danger px-3 py-1 fw-bold">
                                                        <?php echo (int)$p['stock']; ?>
                                                    </span>
                                                </td>
                                                <td class="text-center fw-bold text-secondary"><?php echo (int)$p['alert_threshold']; ?></td>
                                                <td class="text-end">
                                                    <?php if (auth_is_admin($conn)): ?>
                                                    <a href="orders.php?purchase_product_id=<?php echo (int)$p['id']; ?>"
                                                       class="btn btn-sm btn-success rounded-3 fw-bold">
                                                        <i class="fas fa-plus me-1"></i> Restock
                                                    </a>
                                                    <a href="products.php?highlight=<?php echo (int)$p['id']; ?>"
                                                       class="btn btn-sm btn-outline-secondary rounded-3 ms-1 fw-bold">
                                                        <i class="fas fa-edit me-1"></i> Edit
                                                    </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-check-circle text-success fs-1 mb-2 d-block"></i>
                                <p class="mb-0 fw-bold">All product stock levels are above their thresholds.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             SECTION 5: QUICK ACTIONS
             ═══════════════════════════════════════════ -->
        <div class="row my-4">
            <h2 class="fs-5 mb-3 fw-bold dashboard-quick-heading">
                <i class="fas fa-bolt me-2 text-warning"></i>Quick Actions
            </h2>
            <?php
            $actions = [
                ['href' => 'products.php',      'icon' => 'fa-box',           'title' => 'Products',     'desc' => 'Add, edit & manage inventory items',       'class' => 'quick-action-products'],
                ['href' => 'orders.php',         'icon' => 'fa-cash-register', 'title' => 'POS Terminal', 'desc' => 'Process sales & purchase orders',          'class' => 'quick-action-pos'],
                ['href' => 'customers.php',      'icon' => 'fa-users',         'title' => 'Customers',    'desc' => 'Manage customer records & contacts',       'class' => 'quick-action-customers'],
                ['href' => 'order_history.php',  'icon' => 'fa-chart-bar',     'title' => 'Reports',      'desc' => 'View history & export CSV reports',        'class' => 'quick-action-reports'],
            ];
            foreach ($actions as $action):
            ?>
            <div class="col-lg-3 col-md-6 mb-3">
                <a href="<?php echo $action['href']; ?>" class="text-decoration-none">
                    <div class="card border-0 h-100 shadow-sm quick-action-card <?php echo htmlspecialchars($action['class'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="card-body p-4 text-white">
                            <div class="d-flex align-items-center mb-3">
                                <div class="rounded-circle d-flex align-items-center justify-content-center quick-action-icon">
                                    <i class="fas <?php echo $action['icon']; ?> fs-5"></i>
                                </div>
                            </div>
                            <h3 class="h5 fw-bold mb-1 quick-action-title"><?php echo $action['title']; ?></h3>
                            <p class="mb-0 small quick-action-description"><?php echo $action['desc']; ?></p>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>

    </div><!-- /.container-fluid -->

    <!-- ═══════════════════════════════════════════
         CHART.JS INITIALIZATION
         JSON_HEX_TAG prevents XSS via </script> injection.
         ═══════════════════════════════════════════ -->
    <script type="application/json" id="dashboard-chart-data" nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>"><?php echo json_encode($chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script type="application/json" id="dashboard-category-data" nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>"><?php echo json_encode($category_sales, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
        window.addEventListener('load', function () {
            if (typeof Chart === 'undefined') {
                console.error('Chart.js failed to load.');
                return;
            }

            /* ── Sales & Purchases Line Chart ── */
            const chartDataElement = document.getElementById('dashboard-chart-data');
            const chartData = chartDataElement ? JSON.parse(chartDataElement.textContent || '[]') : [];
            if (Array.isArray(chartData) && chartData.length > 0) {
                const ctx    = document.getElementById('salesChart').getContext('2d');
                const labels = chartData.map(function(d) { return d.label; });
                const sales  = chartData.map(function(d) { return d.sales; });
                const purch  = chartData.map(function(d) { return d.purchases; });

                var salesGrad = ctx.createLinearGradient(0, 0, 0, 350);
                salesGrad.addColorStop(0, 'rgba(79, 70, 229, 0.35)');
                salesGrad.addColorStop(1, 'rgba(79, 70, 229, 0.01)');

                var purchGrad = ctx.createLinearGradient(0, 0, 0, 350);
                purchGrad.addColorStop(0, 'rgba(16, 185, 129, 0.35)');
                purchGrad.addColorStop(1, 'rgba(16, 185, 129, 0.01)');

                var fontStack = "'Outfit', 'Inter', 'Helvetica Neue', sans-serif";

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Sales',
                                data: sales,
                                borderColor: '#4f46e5',
                                backgroundColor: salesGrad,
                                fill: true,
                                tension: 0.4,
                                borderWidth: 3,
                                pointBackgroundColor: '#4f46e5',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7
                            },
                            {
                                label: 'Purchases',
                                data: purch,
                                borderColor: '#10b981',
                                backgroundColor: purchGrad,
                                fill: true,
                                tension: 0.4,
                                borderWidth: 3,
                                pointBackgroundColor: '#10b981',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    boxWidth: 8,
                                    font: { family: fontStack, size: 13, weight: '500' },
                                    color: '#6c757d'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(26, 26, 46, 0.95)',
                                titleColor: '#fff',
                                titleFont: { family: fontStack, weight: 'bold' },
                                bodyColor: '#fff',
                                bodyFont: { family: fontStack },
                                padding: 12,
                                cornerRadius: 8,
                                displayColors: true,
                                callbacks: {
                                    label: function(ctx) {
                                        var lbl = ctx.dataset.label || '';
                                        if (ctx.parsed.y !== null) {
                                            lbl += ': ' + new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(ctx.parsed.y);
                                        }
                                        return lbl;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { font: { family: fontStack, size: 12 }, color: '#6c757d' }
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(224, 224, 224, 0.5)', borderDash: [5, 5] },
                                ticks: {
                                    font: { family: fontStack, size: 12 },
                                    color: '#6c757d',
                                    callback: function(v) { return '$' + v.toLocaleString(); }
                                }
                            }
                        }
                    }
                });
            }

            /* ── Category Sales Doughnut Chart ── */
            var categoryDataElement = document.getElementById('dashboard-category-data');
            var categoryData = categoryDataElement ? JSON.parse(categoryDataElement.textContent || '[]') : [];
            if (Array.isArray(categoryData) && categoryData.length > 0) {
                var catCtx    = document.getElementById('categoryChart');
                if (catCtx) {
                    var catLabels = categoryData.map(function(d) { return d.category_name; });
                    var catSales  = categoryData.map(function(d) { return parseFloat(d.total_sales); });
                    var palette   = ['#4f46e5','#10b981','#06b6d4','#8b5cf6','#f59e0b','#ef4444','#ec4899','#64748b'];

                    new Chart(catCtx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: catLabels,
                            datasets: [{
                                data: catSales,
                                backgroundColor: palette.slice(0, categoryData.length),
                                hoverBorderColor: '#fff',
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'right',
                                    labels: {
                                        usePointStyle: true,
                                        boxWidth: 8,
                                        font: { family: "'Outfit', 'Inter', sans-serif", size: 11, weight: '500' },
                                        color: '#6c757d'
                                    }
                                },
                                tooltip: {
                                    backgroundColor: 'rgba(26, 26, 46, 0.95)',
                                    titleColor: '#fff',
                                    bodyColor: '#fff',
                                    padding: 10,
                                    cornerRadius: 6,
                                    callbacks: {
                                        label: function(ctx) {
                                            var lbl = ctx.label || '';
                                            if (ctx.parsed !== null) {
                                                lbl += ': ' + new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(ctx.parsed);
                                            }
                                            return lbl;
                                        }
                                    }
                                }
                            },
                            cutout: '70%'
                        }
                    });
                }
            }
        });
    </script>

<?php require_once '../includes/layouts/footer.php'; ?>
