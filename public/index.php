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
$inventory_valuation  = dashboard_get_inventory_valuation($conn);
$top_selling_products = dashboard_get_top_selling_products($conn, 5, $dashboard_staff_id);
$category_sales       = dashboard_get_category_sales_distribution($conn, $dashboard_staff_id);
$low_stock_products   = inventory_get_low_stock_products($conn);
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

    <div class="container-fluid px-4 py-4 dashboard-page">

        <header class="dashboard-page-header">
            <div class="dashboard-page-heading">
                <p class="dashboard-page-kicker mb-2">Operations overview</p>
                <h2 class="dashboard-page-title mb-2">Dashboard</h2>
                <p class="dashboard-page-context mb-0">A focused view of sales activity, stock health, and daily work.</p>
            </div>
            <div class="dashboard-page-actions" aria-label="Dashboard actions">
                <a href="products.php" class="btn btn-outline-secondary dashboard-page-action">
                    <i class="fas fa-boxes-stacked me-2" aria-hidden="true"></i>Inventory
                </a>
                <a href="orders.php" class="btn btn-primary dashboard-page-action">
                    <i class="fas fa-cash-register me-2" aria-hidden="true"></i>Open POS
                </a>
            </div>
        </header>

        <!-- ═══════════════════════════════════════════
             SECTION 1: KPI STAT CARDS
             ═══════════════════════════════════════════ -->
        <section class="dashboard-kpi-grid" aria-label="Operations summary">
            <?php
            $kpi_cards = [
                [
                    'label' => 'Products',
                    'value' => number_format($stats['total_products']),
                    'icon'  => 'fa-box-archive',
                    'tone'  => 'brand',
                ],
                [
                    'label' => 'Orders',
                    'value' => number_format($stats['total_orders']),
                    'icon'  => 'fa-receipt',
                    'tone'  => 'success',
                ],
                [
                    'label' => 'Revenue',
                    'value' => '$' . number_format($stats['total_sales'], 2),
                    'icon'  => 'fa-dollar-sign',
                    'tone'  => 'brand',
                ],
                [
                    'label' => 'Total Stock',
                    'value' => number_format($stats['total_stock']),
                    'icon'  => 'fa-warehouse',
                    'tone'  => 'warning',
                ],
                [
                    'label' => 'Valuation',
                    'value' => '$' . number_format($inventory_valuation, 2),
                    'icon'  => 'fa-coins',
                    'tone'  => 'neutral',
                ],
            ];
            foreach ($kpi_cards as $card):
            ?>
            <article class="dashboard-kpi-card dashboard-kpi-card--<?php echo $card['tone']; ?>">
                <div class="dashboard-kpi-card__head">
                    <span class="dashboard-kpi-label"><?php echo $card['label']; ?></span>
                    <span class="dashboard-kpi-mark" aria-hidden="true">
                        <i class="fas <?php echo $card['icon']; ?>"></i>
                    </span>
                </div>
                <strong class="dashboard-kpi-number"><?php echo $card['value']; ?></strong>
                <span class="dashboard-kpi-caption">Current total</span>
            </article>
            <?php endforeach; ?>
        </section>

        <!-- ═══════════════════════════════════════════
             SECTION 2: SALES & PURCHASES CHART (7-DAY)
             ═══════════════════════════════════════════ -->
        <section class="dashboard-panel dashboard-panel--chart" aria-labelledby="dashboard-chart-title">
            <div class="dashboard-panel-header">
                <div>
                    <p class="dashboard-panel-kicker mb-2">Last 7 days</p>
                    <h2 id="dashboard-chart-title" class="dashboard-panel-title mb-1">
                        <i class="fas fa-chart-line me-2" aria-hidden="true"></i>Sales &amp; Purchases Flow
                    </h2>
                    <p class="dashboard-panel-subtitle mb-0">Compare completed sales with purchasing activity.</p>
                </div>
                <span class="dashboard-window-badge" aria-label="Reporting window: Last 7 days">
                    <i class="fas fa-calendar-days me-2" aria-hidden="true"></i>Last 7 Days
                </span>
            </div>
            <div class="dashboard-chart-frame" aria-busy="false">
                <?php if (!empty($chart_data)): ?>
                    <canvas id="salesChart" class="dashboard-chart-canvas" aria-label="Sales and purchases over the last 7 days"></canvas>
                <?php else: ?>
                    <div class="dashboard-state dashboard-state--empty" role="status">
                        <span class="dashboard-state-icon" aria-hidden="true"><i class="fas fa-chart-line"></i></span>
                        <strong>No sales or purchase activity in the last 7 days.</strong>
                        <span>Complete an order to populate this trend.</span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════
             SECTION 3: TOP SELLING + CATEGORY PIE
             ═══════════════════════════════════════════ -->
        <div class="dashboard-insight-grid">
            <section class="dashboard-panel" aria-labelledby="dashboard-top-selling-title">
                <div class="dashboard-panel-header">
                    <div>
                        <p class="dashboard-panel-kicker mb-2">Product momentum</p>
                        <h2 id="dashboard-top-selling-title" class="dashboard-panel-title mb-1">
                            <i class="fas fa-trophy me-2" aria-hidden="true"></i>Top Selling Products
                        </h2>
                        <p class="dashboard-panel-subtitle mb-0">The products moving most units in the selected scope.</p>
                    </div>
                </div>
                <div class="dashboard-panel-body">
                    <div class="dashboard-ranking-list">
                    <?php if (!empty($top_selling_products)): ?>
                        <?php
                        $max_qty = max(array_column($top_selling_products, 'total_qty'));
                        foreach ($top_selling_products as $index => $tp):
                            $percentage = $max_qty > 0 ? round(($tp['total_qty'] / $max_qty) * 100) : 0;
                        ?>
                            <div class="dashboard-ranking-row">
                                <div class="dashboard-ranking-main">
                                    <span class="dashboard-ranking-rank" aria-label="Rank <?php echo $index + 1; ?>"><?php echo $index + 1; ?></span>
                                    <div class="dashboard-ranking-copy">
                                        <strong class="dashboard-ranking-name"><?php echo htmlspecialchars($tp['name']); ?></strong>
                                        <span class="dashboard-ranking-summary">
                                            <?php echo number_format($tp['total_qty']); ?> sold · $<?php echo number_format($tp['total_sales'], 2); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="dashboard-ranking-meter" aria-hidden="true">
                                    <div class="dashboard-ranking-meter-fill" data-progress="<?php echo (int)$percentage; ?>"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="dashboard-state dashboard-state--empty" role="status">
                            <span class="dashboard-state-icon" aria-hidden="true"><i class="fas fa-chart-bar"></i></span>
                            <strong>No sales data recorded yet.</strong>
                            <span>Completed sales will appear here as product momentum builds.</span>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="dashboard-panel" aria-labelledby="dashboard-category-title">
                <div class="dashboard-panel-header">
                    <div>
                        <p class="dashboard-panel-kicker mb-2">Mix of revenue</p>
                        <h2 id="dashboard-category-title" class="dashboard-panel-title mb-1">
                            <i class="fas fa-chart-pie me-2" aria-hidden="true"></i>Category Distribution
                        </h2>
                        <p class="dashboard-panel-subtitle mb-0">Sales contribution by product category.</p>
                    </div>
                </div>
                <div class="dashboard-panel-body dashboard-panel-body--category">
                    <?php if (!empty($category_sales)): ?>
                        <div class="dashboard-category-chart">
                            <canvas id="categoryChart" aria-label="Sales distribution by product category"></canvas>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-state dashboard-state--empty" role="status">
                            <span class="dashboard-state-icon" aria-hidden="true"><i class="fas fa-chart-pie"></i></span>
                            <strong>No category sales data available.</strong>
                            <span>Category mix will appear after the first completed sale.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <!-- ═══════════════════════════════════════════
             SECTION 4: LOW STOCK ALERTS
             ═══════════════════════════════════════════ -->
        <section class="dashboard-panel dashboard-panel--alerts" aria-labelledby="dashboard-alerts-title">
            <div class="dashboard-panel-header dashboard-alert-header">
                <div>
                    <p class="dashboard-panel-kicker mb-2">Inventory attention</p>
                    <h2 id="dashboard-alerts-title" class="dashboard-panel-title mb-1">
                        <i class="fas fa-triangle-exclamation me-2" aria-hidden="true"></i>Low Stock Alerts
                    </h2>
                    <p class="dashboard-panel-subtitle mb-0">Prioritize products that need a replenishment decision.</p>
                </div>
                <?php if ($total_low_stock > 0): ?>
                    <span class="dashboard-alert-summary dashboard-alert-summary--critical" role="status">
                        <strong><?php echo $total_low_stock; ?></strong> Action Required
                    </span>
                <?php else: ?>
                    <span class="dashboard-alert-summary dashboard-alert-summary--stable" role="status">All Stock Stable</span>
                <?php endif; ?>
            </div>
            <div class="dashboard-panel-body">
                <?php if ($total_low_stock > 0): ?>
                    <div class="table-responsive dashboard-table-shell">
                        <table class="table align-middle dashboard-alert-table mb-0">
                            <thead>
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
                                        <td class="dashboard-alert-id">#<?php echo (int)$p['id']; ?></td>
                                        <td class="dashboard-alert-product">
                                            <?php if (!empty($p['image_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($p['image_path']); ?>" class="product-thumb me-2" alt="" loading="lazy">
                                            <?php endif; ?>
                                            <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                                        </td>
                                        <td class="text-center">
                                            <span class="dashboard-stock-level"><?php echo (int)$p['stock']; ?></span>
                                        </td>
                                        <td class="text-center dashboard-alert-threshold"><?php echo (int)$p['alert_threshold']; ?></td>
                                        <td class="text-end dashboard-stock-actions">
                                            <?php if (auth_is_admin($conn)): ?>
                                            <a href="orders.php?purchase_product_id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-success dashboard-table-action">
                                                <i class="fas fa-plus me-1" aria-hidden="true"></i>Restock
                                            </a>
                                            <a href="products.php?highlight=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-secondary dashboard-table-action">
                                                <i class="fas fa-edit me-1" aria-hidden="true"></i>Edit
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="dashboard-state dashboard-state--success" role="status">
                        <span class="dashboard-state-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
                        <strong>All product stock levels are above their thresholds.</strong>
                        <span>No replenishment action is needed right now.</span>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ═══════════════════════════════════════════
             SECTION 5: QUICK ACTIONS
             ═══════════════════════════════════════════ -->
        <section class="dashboard-quick-actions" aria-labelledby="dashboard-quick-actions-title">
            <div class="dashboard-quick-header">
                <div>
                    <p class="dashboard-panel-kicker mb-2">Common tasks</p>
                    <h2 id="dashboard-quick-actions-title" class="dashboard-quick-actions-title mb-1">
                        <i class="fas fa-bolt me-2" aria-hidden="true"></i>Quick Actions
                    </h2>
                    <p class="dashboard-panel-subtitle mb-0">Jump into the workbench areas used most often.</p>
                </div>
            </div>
            <?php
            $actions = [
                ['href' => 'products.php',      'icon' => 'fa-box',           'title' => 'Products',     'desc' => 'Add, edit & manage inventory items'],
                ['href' => 'orders.php',         'icon' => 'fa-cash-register', 'title' => 'POS Terminal', 'desc' => 'Process sales & purchase orders'],
                ['href' => 'customers.php',      'icon' => 'fa-users',         'title' => 'Customers',    'desc' => 'Manage customer records & contacts'],
                ['href' => 'order_history.php',  'icon' => 'fa-chart-bar',     'title' => 'Reports',      'desc' => 'View history & export CSV reports'],
            ];
            ?>
            <div class="dashboard-quick-list">
            <?php foreach ($actions as $action): ?>
                <a href="<?php echo $action['href']; ?>" class="dashboard-quick-link">
                    <span class="dashboard-quick-link__icon" aria-hidden="true"><i class="fas <?php echo $action['icon']; ?>"></i></span>
                    <span class="dashboard-quick-link__content">
                        <strong class="dashboard-quick-link__title"><?php echo $action['title']; ?></strong>
                        <span class="dashboard-quick-link__description"><?php echo $action['desc']; ?></span>
                    </span>
                    <i class="fas fa-arrow-right dashboard-quick-link__arrow" aria-hidden="true"></i>
                </a>
            <?php endforeach; ?>
            </div>
        </section>

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

            const dashboardStyles = getComputedStyle(document.documentElement);
            const dashboardToken = function (name, fallback) {
                return dashboardStyles.getPropertyValue(name).trim() || fallback;
            };
            const chartBrand = dashboardToken('--color-brand-600', '#0f766e');
            const chartBrandDark = dashboardToken('--color-brand-700', '#0b5f59');
            const chartSuccess = dashboardToken('--success', '#16836f');
            const chartWarning = dashboardToken('--warning', '#b7791f');
            const chartMuted = dashboardToken('--color-ink-muted', '#4b6268');
            const chartBorder = dashboardToken('--color-border', '#d9e5e3');
            const chartSurface = dashboardToken('--color-surface', '#ffffff');
            const chartInk = dashboardToken('--color-ink-strong', '#10252c');

            /* ── Sales & Purchases Line Chart ── */
            const chartDataElement = document.getElementById('dashboard-chart-data');
            const chartData = chartDataElement ? JSON.parse(chartDataElement.textContent || '[]') : [];
            if (Array.isArray(chartData) && chartData.length > 0) {
                const ctx    = document.getElementById('salesChart').getContext('2d');
                const labels = chartData.map(function(d) { return d.label; });
                const sales  = chartData.map(function(d) { return d.sales; });
                const purch  = chartData.map(function(d) { return d.purchases; });

                var fontStack = "'Outfit', 'Inter', 'Helvetica Neue', sans-serif";

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Sales',
                                data: sales,
                                borderColor: chartBrand,
                                backgroundColor: chartBrand,
                                fill: false,
                                tension: 0.4,
                                borderWidth: 3,
                                pointBackgroundColor: chartBrand,
                                pointBorderColor: chartSurface,
                                pointBorderWidth: 2,
                                pointRadius: 5,
                                pointHoverRadius: 7
                            },
                            {
                                label: 'Purchases',
                                data: purch,
                                borderColor: chartSuccess,
                                backgroundColor: chartSuccess,
                                fill: false,
                                tension: 0.4,
                                borderWidth: 3,
                                pointBackgroundColor: chartSuccess,
                                pointBorderColor: chartSurface,
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
                                    color: chartMuted
                                }
                            },
                            tooltip: {
                                backgroundColor: chartInk,
                                titleColor: chartSurface,
                                titleFont: { family: fontStack, weight: 'bold' },
                                bodyColor: chartSurface,
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
                                ticks: { font: { family: fontStack, size: 12 }, color: chartMuted }
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: chartBorder, borderDash: [4, 4] },
                                ticks: {
                                    font: { family: fontStack, size: 12 },
                                    color: chartMuted,
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
                    var palette   = [chartBrand, chartSuccess, chartWarning, chartMuted, chartBrandDark];

                    new Chart(catCtx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: catLabels,
                            datasets: [{
                                data: catSales,
                                backgroundColor: palette.slice(0, categoryData.length),
                                hoverBorderColor: chartSurface,
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
                                        color: chartMuted
                                    }
                                },
                                tooltip: {
                                    backgroundColor: chartInk,
                                    titleColor: chartSurface,
                                    bodyColor: chartSurface,
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
