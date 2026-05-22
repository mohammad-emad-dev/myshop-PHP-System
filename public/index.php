<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

verify_login();

$stats = get_dashboard_stats($conn);
$chart_data = get_chart_data($conn);
$inventory_valuation = get_inventory_valuation($conn);
$top_selling_products = get_top_selling_products($conn, 5);
$category_sales = get_category_sales_distribution($conn);

$page_title = 'Dashboard';
$active_page = 'dashboard';
$header_title = 'Dashboard';
$extra_js = ['https://cdn.jsdelivr.net/npm/chart.js'];

require_once '../includes/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once '../includes/layouts/sidebar.php'; ?>
    <?php require_once '../includes/layouts/navbar.php'; ?>

    <div class="container-fluid px-4 py-4">
        <div class="row g-3 my-2">
            <div class="col-xl col-md-4 col-sm-6">
                <div class="p-4 bg-white shadow-sm d-flex justify-content-between align-items-center rounded-3 dashboard-card border-left-primary h-100">
                    <div>
                        <h3 class="fs-2 mb-1" style="font-family: var(--font-heading); font-weight: 800; color: var(--slate-900);"><?php echo number_format($stats['total_products']); ?></h3>
                        <p class="fs-6 text-muted mb-0 fw-medium">Products</p>
                    </div>
                    <div class="rounded-full primary-bg p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                        <i class="fas fa-boxes fs-4 primary-text"></i>
                    </div>
                </div>
            </div>

            <div class="col-xl col-md-4 col-sm-6">
                <div class="p-4 bg-white shadow-sm d-flex justify-content-between align-items-center rounded-3 dashboard-card border-left-success h-100">
                    <div>
                        <h3 class="fs-2 mb-1" style="font-family: var(--font-heading); font-weight: 800; color: var(--slate-900);"><?php echo number_format($stats['total_orders']); ?></h3>
                        <p class="fs-6 text-muted mb-0 fw-medium">Orders</p>
                    </div>
                    <div class="rounded-full success-bg p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                        <i class="fas fa-truck fs-4 success-text"></i>
                    </div>
                </div>
            </div>

            <div class="col-xl col-md-4 col-sm-6">
                <div class="p-4 bg-white shadow-sm d-flex justify-content-between align-items-center rounded-3 dashboard-card border-left-info h-100">
                    <div>
                        <h3 class="fs-2 mb-1" style="font-family: var(--font-heading); font-weight: 800; color: var(--slate-900);">$<?php echo number_format($stats['total_sales'], 2); ?></h3>
                        <p class="fs-6 text-muted mb-0 fw-medium">Sales</p>
                    </div>
                    <div class="rounded-full info-bg p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                        <i class="fas fa-hand-holding-usd fs-4 info-text"></i>
                    </div>
                </div>
            </div>

            <div class="col-xl col-md-6 col-sm-6">
                <div class="p-4 bg-white shadow-sm d-flex justify-content-between align-items-center rounded-3 dashboard-card border-left-warning h-100">
                    <div>
                        <h3 class="fs-2 mb-1" style="font-family: var(--font-heading); font-weight: 800; color: var(--slate-900);"><?php echo number_format($stats['total_stock']); ?></h3>
                        <p class="fs-6 text-muted mb-0 fw-medium">Total Stock</p>
                    </div>
                    <div class="rounded-full warning-bg p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                        <i class="fas fa-warehouse fs-4 warning-text"></i>
                    </div>
                </div>
            </div>

            <div class="col-xl col-md-6 col-sm-12">
                <div class="p-4 bg-white shadow-sm d-flex justify-content-between align-items-center rounded-3 dashboard-card border-left-purple h-100">
                    <div>
                        <h3 class="fs-2 mb-1" style="font-family: var(--font-heading); font-weight: 800; color: var(--slate-900);">$<?php echo number_format($inventory_valuation, 2); ?></h3>
                        <p class="fs-6 text-muted mb-0 fw-medium">Valuation</p>
                    </div>
                    <div class="rounded-full purple-bg p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                        <i class="fas fa-coins fs-4 purple-text"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sales and Purchases Chart -->
        <div class="row my-4">
            <div class="col-md-12">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="card-title text-secondary mb-0">
                                <i class="fas fa-chart-line me-2 text-primary"></i>Sales & Purchases Flow (Last 7 Days)
                            </h5>
                            <span class="badge bg-light text-dark border">
                                <i class="fas fa-calendar-alt me-1 text-primary"></i> 7-Day Performance
                            </span>
                        </div>
                        <div style="position: relative; height: 350px;">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Selling Products & Category Sales Distribution -->
        <div class="row my-4 g-4">
            <div class="col-lg-7 col-md-12">
                <div class="card shadow-sm border-0 h-100 rounded-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0 text-secondary fw-bold">
                            <i class="fas fa-trophy text-warning me-2"></i>Top Selling Products (Top 5)
                        </h5>
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
                                    <div class="progress rounded-pill" style="height: 6px; background-color: var(--slate-100);">
                                        <div class="progress-bar rounded-pill" role="progressbar" style="width: <?php echo $percentage; ?>%; background-color: var(--primary) !important;" aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-info-circle fs-1 mb-2 text-secondary"></i>
                                <p class="mb-0">No sales data recorded yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-5 col-md-12">
                <div class="card shadow-sm border-0 h-100 rounded-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="mb-0 text-secondary fw-bold">
                            <i class="fas fa-chart-pie text-info me-2"></i>Category Sales Distribution
                        </h5>
                    </div>
                    <div class="card-body p-4 d-flex flex-column justify-content-center">
                        <?php if (!empty($category_sales)): ?>
                            <div style="position: relative; height: 250px;">
                                <canvas id="categoryChart"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-info-circle fs-1 mb-2 text-secondary"></i>
                                <p class="mb-0">No sales distribution data available.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Alerts Panel -->
        <?php
        $low_stock_products = get_low_stock_products($conn);
        $total_low_stock = count($low_stock_products);
        ?>
        <div class="row my-4">
            <div class="col-md-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-secondary fw-bold">
                            <i class="fas fa-exclamation-triangle text-danger me-2"></i>Low Stock Inventory Alerts
                        </h5>
                        <?php if ($total_low_stock > 0): ?>
                            <span class="badge bg-danger rounded-pill px-3 py-2"><?php echo $total_low_stock; ?> Action Required</span>
                        <?php else: ?>
                            <span class="badge bg-success rounded-pill px-3 py-2">All Stock Stable</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($total_low_stock > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light text-secondary">
                                        <tr>
                                            <th scope="col">Product ID</th>
                                            <th scope="col">Product Name</th>
                                            <th scope="col" class="text-center">Current Stock</th>
                                            <th scope="col" class="text-center">Alert Threshold</th>
                                            <th scope="col" class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($low_stock_products as $p): ?>
                                            <tr>
                                                <td>#<?php echo $p['id']; ?></td>
                                                <td class="fw-bold">
                                                    <?php if ($p['image_path']): ?>
                                                        <img src="<?php echo htmlspecialchars($p['image_path']); ?>" class="rounded me-2" style="width: 35px; height: 35px; object-fit: cover;" alt="">
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($p['name']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <span class="badge rounded-pill bg-danger-subtle text-danger px-2.5 py-1.5 fw-bold fs-7">
                                                        <?php echo $p['stock']; ?>
                                                    </span>
                                                </td>
                                                <td class="text-center fw-bold text-secondary"><?php echo $p['alert_threshold']; ?></td>
                                                <td class="text-end">
                                                    <a href="orders.php?purchase_product_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-success rounded-3 fw-bold">
                                                        <i class="fas fa-plus me-1"></i> Restock
                                                    </a>
                                                    <?php if (is_admin()): ?>
                                                    <a href="products.php?highlight=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary rounded-3 ms-1 fw-bold">
                                                        <i class="fas fa-edit me-1"></i> Edit Threshold
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
                                <i class="fas fa-check-circle text-success fs-1 mb-2"></i>
                                <p class="mb-0 fw-bold">Awesome! All product stock levels are above their thresholds.</p>
                            </div>
                        <?php endif; ?>
                    </div>
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

    <!-- Chart.js Initialization -->
    <script>
        window.addEventListener('load', function () {
            if (typeof Chart === 'undefined') {
                console.error('Chart.js failed to load.');
                return;
            }

            const chartData = <?php echo json_encode($chart_data); ?>;
            if (!chartData || chartData.length === 0) {
                return;
            }

            const labels = chartData.map(item => item.label);
            const salesData = chartData.map(item => item.sales);
            const purchasesData = chartData.map(item => item.purchases);

            const ctx = document.getElementById('salesChart').getContext('2d');
            
            // Create gradients for lines
            const salesGradient = ctx.createLinearGradient(0, 0, 0, 350);
            salesGradient.addColorStop(0, 'rgba(79, 70, 229, 0.35)');
            salesGradient.addColorStop(1, 'rgba(79, 70, 229, 0.01)');

            const purchasesGradient = ctx.createLinearGradient(0, 0, 0, 350);
            purchasesGradient.addColorStop(0, 'rgba(16, 185, 129, 0.35)');
            purchasesGradient.addColorStop(1, 'rgba(16, 185, 129, 0.01)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Sales (Cash Inflow)',
                            data: salesData,
                            borderColor: '#4f46e5',
                            backgroundColor: salesGradient,
                            fill: true,
                            tension: 0.4,
                            borderWidth: 3,
                            pointBackgroundColor: '#4f46e5',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        },
                        {
                            label: 'Purchases (Cash Outflow)',
                            data: purchasesData,
                            borderColor: '#10b981',
                            backgroundColor: purchasesGradient,
                            fill: true,
                            tension: 0.4,
                            borderWidth: 3,
                            pointBackgroundColor: '#10b981',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                boxWidth: 8,
                                font: {
                                    family: "'Outfit', 'Inter', 'Helvetica Neue', sans-serif",
                                    size: 13,
                                    weight: '500'
                                },
                                color: '#6c757d'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(26, 26, 46, 0.95)',
                            titleColor: '#ffffff',
                            titleFont: {
                                family: "'Outfit', 'Inter', sans-serif",
                                weight: 'bold'
                            },
                            bodyColor: '#ffffff',
                            bodyFont: {
                                family: "'Outfit', 'Inter', sans-serif"
                            },
                            padding: 12,
                            cornerRadius: 8,
                            displayColors: true,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(context.parsed.y);
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    family: "'Outfit', 'Inter', sans-serif",
                                    size: 12
                                },
                                color: '#6c757d'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(224, 224, 224, 0.5)',
                                borderDash: [5, 5]
                            },
                            ticks: {
                                font: {
                                    family: "'Outfit', 'Inter', sans-serif",
                                    size: 12
                                },
                                color: '#6c757d',
                                callback: function(value) {
                                    return '$' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });

            // Category Sales Doughnut Chart
            const categoryData = <?php echo json_encode($category_sales); ?>;
            if (categoryData && categoryData.length > 0) {
                const catCtx = document.getElementById('categoryChart').getContext('2d');
                const catLabels = categoryData.map(item => item.category_name);
                const catSales = categoryData.map(item => item.total_sales);
                
                // Color palette for Doughnut segments
                const colors = [
                    '#4f46e5', // Indigo primary
                    '#10b981', // Emerald success
                    '#06b6d4', // Cyan info
                    '#8b5cf6', // Violet purple
                    '#f59e0b', // Amber warning
                    '#ef4444', // Red danger
                    '#ec4899', // Pink
                    '#64748b'  // Slate grey
                ];
                
                new Chart(catCtx, {
                    type: 'doughnut',
                    data: {
                        labels: catLabels,
                        datasets: [{
                            data: catSales,
                            backgroundColor: colors.slice(0, categoryData.length),
                            hoverBackgroundColor: colors.slice(0, categoryData.length),
                            hoverBorderColor: "rgba(255, 255, 255, 1)",
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
                                    font: {
                                        family: "'Outfit', 'Inter', sans-serif",
                                        size: 11,
                                        weight: '500'
                                    },
                                    color: '#6c757d'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(26, 26, 46, 0.95)',
                                titleColor: '#ffffff',
                                titleFont: {
                                    family: "'Outfit', 'Inter', sans-serif",
                                    weight: 'bold'
                                },
                                bodyColor: '#ffffff',
                                bodyFont: {
                                    family: "'Outfit', 'Inter', sans-serif"
                                },
                                padding: 10,
                                cornerRadius: 6,
                                callbacks: {
                                    label: function(context) {
                                        let label = context.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.parsed !== null) {
                                            label += new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(context.parsed);
                                        }
                                        return label;
                                    }
                                }
                            }
                        },
                        cutout: '70%'
                    }
                });
            }
        });
    </script>

<?php
require_once '../includes/layouts/footer.php';
?>