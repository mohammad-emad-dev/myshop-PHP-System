<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

auth_verify_login($conn);

$is_admin_user = auth_is_admin($conn);
$filter_type = isset($_GET['type']) && is_scalar($_GET['type']) ? sanitize_input((string)$_GET['type']) : 'all';
if (!in_array($filter_type, ['all', 'sale', 'purchase'], true)) {
    $filter_type = 'all';
}
if (!$is_admin_user && $filter_type === 'purchase') {
    $filter_type = 'all';
}
$page_size_options = [10, 25, 50, 100];
$page_size = normalize_page_size($_GET['page_size'] ?? 25, 25, $page_size_options);
$page = normalize_page_number($_GET['page'] ?? 1);
$order_scope_staff_id = $is_admin_user ? null : (int)$_SESSION['staff_id'];
$total_orders = count_orders($conn, $order_scope_staff_id, $filter_type);
$total_pages = max(1, (int)ceil($total_orders / $page_size));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $page_size;
$orders = get_orders_page($conn, $order_scope_staff_id, $filter_type, $page_size, $offset);
$order_summary = get_order_summary($conn, $order_scope_staff_id, $filter_type);
$range_start = $total_orders > 0 ? $offset + 1 : 0;
$range_end = $total_orders > 0 ? min($offset + count($orders), $total_orders) : 0;
$order_history_url = static function ($target_page) use ($filter_type, $page_size) {
    return 'order_history.php?' . http_build_query([
        'type' => $filter_type,
        'page_size' => $page_size,
        'page' => max(1, (int)$target_page),
    ]);
};
$pagination_pages = [];
if ($total_pages <= 7) {
    $pagination_pages = range(1, $total_pages);
} else {
    $pagination_pages = [1];
    $window_start = max(2, $page - 2);
    $window_end = min($total_pages - 1, $page + 2);
    if ($window_start > 2) {
        $pagination_pages[] = '...';
    }
    for ($pagination_page = $window_start; $pagination_page <= $window_end; $pagination_page++) {
        $pagination_pages[] = $pagination_page;
    }
    if ($window_end < $total_pages - 1) {
        $pagination_pages[] = '...';
    }
    $pagination_pages[] = $total_pages;
}

$page_title = 'Order History';
$active_page = 'order_history';
$header_title = 'Order History';
$extra_js = ['https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js'];

require_once '../includes/layouts/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once '../includes/layouts/sidebar.php'; ?>
    <?php require_once '../includes/layouts/navbar.php'; ?>

    <div class="container-fluid px-4 py-4">
        <!-- Summary Stats Bar -->
        <?php
        $total_orders_count = $order_summary['total_orders'];
        $total_sales_amount = $order_summary['total_sales_amount'];
        $total_purchases_amount = $order_summary['total_purchases_amount'];
        $sales_count = $order_summary['sales_count'];
        $purchases_count = $order_summary['purchases_count'];
        ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="p-3 bg-white shadow-sm d-flex justify-content-between align-items-center rounded-3 dashboard-card border-left-primary">
                    <div>
                        <h2 class="fs-3 mb-0 fw-bold history-kpi-value"><?php echo $total_orders_count; ?></h2>
                        <p class="text-muted mb-0 small fw-medium">Total Orders</p>
                    </div>
                    <div class="rounded-full primary-bg p-3 d-flex align-items-center justify-content-center history-kpi-icon"><i class="fas fa-receipt primary-text"></i></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-white shadow-sm d-flex justify-content-between align-items-center rounded-3 dashboard-card border-left-success">
                    <div>
                        <h2 class="fs-3 mb-0 fw-bold history-kpi-value">$<?php echo number_format($total_sales_amount, 2); ?></h2>
                        <p class="text-muted mb-0 small fw-medium">Sales (<?php echo $sales_count; ?>)</p>
                    </div>
                    <div class="rounded-full success-bg p-3 d-flex align-items-center justify-content-center history-kpi-icon"><i class="fas fa-arrow-up success-text"></i></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-white shadow-sm d-flex justify-content-between align-items-center rounded-3 dashboard-card border-left-warning">
                    <div>
                        <h2 class="fs-3 mb-0 fw-bold history-kpi-value">$<?php echo number_format($total_purchases_amount, 2); ?></h2>
                        <p class="text-muted mb-0 small fw-medium">Purchases (<?php echo $purchases_count; ?>)</p>
                    </div>
                    <div class="rounded-full warning-bg p-3 d-flex align-items-center justify-content-center history-kpi-icon"><i class="fas fa-arrow-down warning-text"></i></div>
                </div>
            </div>
        </div>
        <div class="row my-2">
            <div class="col-md-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center flex-wrap g-2">
                        <h2 class="h4 mb-0 text-secondary fw-bold">Transaction History</h2>
                        <div class="d-flex align-items-center gap-2">
                            <div class="btn-group" role="group" aria-label="Order filters">
                                <a href="<?php echo htmlspecialchars('order_history.php?' . http_build_query(['type' => 'all', 'page_size' => $page_size, 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary <?php echo $filter_type === 'all' ? 'active' : ''; ?>">All</a>
                                <a href="<?php echo htmlspecialchars('order_history.php?' . http_build_query(['type' => 'sale', 'page_size' => $page_size, 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary <?php echo $filter_type === 'sale' ? 'active' : ''; ?>">Sales</a>
                                <?php if ($is_admin_user): ?>
                                    <a href="<?php echo htmlspecialchars('order_history.php?' . http_build_query(['type' => 'purchase', 'page_size' => $page_size, 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary <?php echo $filter_type === 'purchase' ? 'active' : ''; ?>">Purchases</a>
                                <?php endif; ?>
                            </div>
                            <?php if (auth_is_admin($conn)): ?>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#exportReportModal">
                                <i class="fas fa-file-excel me-1"></i> Export CSV
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <span class="text-muted small">Showing <?php echo $range_start; ?>-<?php echo $range_end; ?> of <?php echo $total_orders; ?> orders</span>
                            <form method="GET" action="order_history.php" class="d-flex align-items-center gap-2">
                                <input type="hidden" name="type" value="<?php echo htmlspecialchars($filter_type, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="page" value="1">
                                <label for="orderPageSize" class="form-label mb-0 small text-muted">Per page</label>
                                <select id="orderPageSize" name="page_size" class="form-select form-select-sm" aria-label="Orders per page" onchange="this.form.submit()">
                                    <?php foreach ($page_size_options as $option): ?>
                                        <option value="<?php echo $option; ?>" <?php echo $page_size === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped align-middle">
                                <thead class="bg-light text-secondary">
                                    <tr>
                                        <th scope="col">Order ID</th>
                                        <th scope="col">Date & Time</th>
                                        <th scope="col">Cashier</th>
                                        <th scope="col">Party</th>
                                        <th scope="col">Type</th>
                                        <th scope="col" class="text-end">Total Amount</th>
                                        <th scope="col" class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($orders)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-5 text-muted">
                                                <i class="fas fa-folder-open fa-3x mb-3 text-secondary opacity-50"></i>
                                                <p class="mb-0">No transaction records found matching the criteria.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($orders as $order): ?>
                                            <tr>
                                                <td class="fw-bold">#<?php echo $order['id']; ?></td>
                                                <td><?php echo date('Y-m-d h:i A', strtotime($order['order_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($order['staff_name']); ?></td>
                                                <td>
                                                    <?php 
                                                    if ($order['order_type'] === 'sale') {
                                                        echo htmlspecialchars($order['customer_name'] ?? 'Walk-in Customer');
                                                    } else {
                                                        echo htmlspecialchars($order['supplier_name'] ?? 'General Supplier');
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ($order['order_type'] === 'sale'): ?>
                                                        <span class="badge bg-primary rounded-pill px-3 py-2"><i class="fas fa-cash-register me-1"></i> Sale</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-box me-1"></i> Purchase</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end fw-bold text-dark">$<?php echo number_format($order['total_amount'], 2); ?></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-sm btn-outline-primary order-details-btn" data-order-id="<?php echo (int)$order['id']; ?>">
                                                        <i class="fas fa-eye me-1"></i> View Details
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($total_pages > 1): ?>
                            <nav class="mt-4" aria-label="Order history pagination">
                                <ul class="pagination justify-content-center flex-wrap mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" aria-label="Previous page" href="<?php echo $page > 1 ? htmlspecialchars($order_history_url($page - 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">Previous</a>
                                    </li>
                                    <?php foreach ($pagination_pages as $pagination_page): ?>
                                        <?php if ($pagination_page === '...'): ?>
                                            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                        <?php else: ?>
                                            <li class="page-item <?php echo $page === $pagination_page ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo htmlspecialchars($order_history_url($pagination_page), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $page === $pagination_page ? 'aria-current="page"' : ''; ?>><?php echo $pagination_page; ?></a>
                                            </li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" aria-label="Next page" href="<?php echo $page < $total_pages ? htmlspecialchars($order_history_url($page + 1), ENT_QUOTES, 'UTF-8') : '#'; ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Details Modal -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header py-3">
                    <h5 class="modal-title fw-bold ui-modal-title" id="orderDetailsModalLabel"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Transaction Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row mb-4 bg-light p-3 rounded-3 g-2">
                        <div class="col-sm-6">
                            <strong>Order Reference:</strong> <span id="modalOrderId" class="text-primary fw-bold"></span>
                        </div>
                        <div class="col-sm-6 text-sm-end">
                            <strong>Transaction Date:</strong> <span id="modalOrderDate" class="text-muted"></span>
                        </div>
                    </div>

                    <!-- Dynamic Customer/Supplier Section -->
                    <div id="modalPartyDetails" class="mb-4 p-3 rounded-3 border border-light-subtle bg-light bg-opacity-50 d-none">
                        <h6 class="fw-bold mb-2 text-secondary" id="modalPartyTitle"></h6>
                        <div class="row g-2 text-dark small">
                            <div class="col-md-6">
                                <strong>Name:</strong> <span id="modalPartyName" class="fw-semibold"></span><br>
                                <strong>Phone:</strong> <span id="modalPartyPhone"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Email:</strong> <span id="modalPartyEmail"></span><br>
                                <strong>Address:</strong> <span id="modalPartyAddress" class="text-muted"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light text-secondary">
                                <tr>
                                    <th>Product Name</th>
                                    <th class="text-center ui-col-qty-100">Quantity</th>
                                    <th class="text-end ui-col-price-150">Unit Price</th>
                                    <th class="text-end ui-col-total-150">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody id="modalDetailsTableBody">
                                <!-- Populated dynamically via JS/AJAX -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="d-flex justify-content-end border-top pt-3 mt-4">
                        <div class="text-end">
                            <span class="text-muted fs-6">Grand Total</span>
                            <h3 class="fw-bold text-success mt-1" id="modalOrderTotal"></h3>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary rounded-3 px-4" id="downloadPdfBtn"><i class="fas fa-file-pdf me-1"></i> Download PDF</button>
                </div>
            </div>
        </div>
    </div>

    <?php if (auth_is_admin($conn)): ?>
    <!-- Export Report Modal -->
    <div class="modal fade" id="exportReportModal" tabindex="-1" aria-labelledby="exportReportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <form action="export_report.php" method="GET" target="_blank">
                    <div class="modal-header py-3">
                        <h5 class="modal-title fw-bold ui-modal-title" id="exportReportModalLabel"><i class="fas fa-file-export me-2 text-primary"></i>Export Transaction Report</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label for="export_type" class="form-label fw-semibold">Transaction Type</label>
                            <select class="form-select" id="export_type" name="type">
                                <option value="all">All Transactions</option>
                                <option value="sale">Sales Only</option>
                                <option value="purchase">Purchases Only</option>
                            </select>
                        </div>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label for="start_date" class="form-label fw-semibold">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label for="end_date" class="form-label fw-semibold">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary rounded-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success rounded-3 px-4" id="exportReportBtn"><i class="fas fa-download me-1"></i> Export CSV</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hidden Print Container for PDF Generation -->
    <div id="invoicePrintWrapper">
        <div id="invoicePrintContainer">
            <div class="invoice-header-row">
                <div>
                    <h1 class="invoice-brand-title">MYSHOP SYSTEM</h1>
                    <p class="invoice-brand-subtitle">Enterprise Inventory &amp; POS Solution</p>
                </div>
                <div class="invoice-header-meta">
                    <h2 class="invoice-title">INVOICE</h2>
                    <p class="invoice-meta-text" id="printInvoiceRef"></p>
                </div>
            </div>

            <div class="invoice-parties-row">
                <div class="invoice-party-column">
                    <h4 class="invoice-section-label">Billed From:</h4>
                    <strong>MyShop Ltd.</strong><br>
                    123 Business Avenue<br>
                    Suite 400, Tech City<br>
                    support@myshop.com
                </div>
                <div class="invoice-party-middle">
                    <h4 class="invoice-section-label" id="printInvoicePartyTitle">Billed To:</h4>
                    <span id="printInvoicePartyDetails"></span>
                </div>
                <div class="invoice-transaction-column">
                    <h4 class="invoice-section-label">Transaction Details:</h4>
                    <strong>Date:</strong> <span id="printInvoiceDate"></span><br>
                    <strong>Type:</strong> <span id="printInvoiceType"></span><br>
                    <strong>Cashier:</strong> <span id="printInvoiceCashier"></span>
                </div>
            </div>

            <table class="invoice-items-table">
                <thead>
                    <tr class="invoice-items-header">
                        <th>Product</th>
                        <th class="invoice-th-qty">Qty</th>
                        <th class="invoice-th-price">Unit Price</th>
                        <th class="invoice-th-total">Subtotal</th>
                    </tr>
                </thead>
                <tbody id="printInvoiceItems">
                    <!-- Populated dynamically -->
                </tbody>
            </table>

            <div class="invoice-total-row">
                <div class="invoice-total-box">
                    <div class="invoice-summary-row">
                        <span>Subtotal:</span>
                        <span id="printInvoiceSubtotal"></span>
                    </div>
                    <div class="invoice-summary-row invoice-summary-row-spaced">
                        <span>Tax (0%):</span>
                        <span>$0.00</span>
                    </div>
                    <div class="invoice-grand-total">
                        <span>Total Amount:</span>
                        <span class="invoice-grand-total-value" id="printInvoiceTotal"></span>
                    </div>
                </div>
            </div>

            <div class="invoice-footer-note">
                Thank you for your business! If you have any questions, contact billing@myshop.com
            </div>
        </div>
    </div>

<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
    let activeInvoiceId = null;

    function createStatusRow(colspan, className, message, iconClass) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = colspan;
        cell.className = className;
        if (iconClass) {
            const icon = document.createElement('i');
            icon.className = iconClass;
            cell.append(icon);
        }
        const messageElement = document.createElement('p');
        messageElement.className = 'mt-2 mb-0';
        messageElement.textContent = message;
        cell.append(messageElement);
        row.append(cell);
        return row;
    }

    function setPartyHeading(element, iconClass, text) {
        element.replaceChildren();
        const icon = document.createElement('i');
        icon.className = iconClass;
        element.append(icon, document.createTextNode(' ' + text));
    }

    function setPrintPartyDetails(element, name, phone, email, address) {
        element.replaceChildren();
        const nameElement = document.createElement('strong');
        nameElement.textContent = name || 'N/A';
        element.append(nameElement, document.createElement('br'));

        const details = [
            ['Phone: ', phone],
            ['Email: ', email],
            ['Address: ', address]
        ];
        details.forEach(function(detail) {
            if (detail[1]) {
                element.append(document.createTextNode(detail[0] + detail[1]));
                if (detail[0] !== 'Address: ') {
                    element.append(document.createElement('br'));
                }
            }
        });
    }

    function showOrderDetails(orderId) {
        activeInvoiceId = orderId;
        
        document.getElementById('modalOrderId').textContent = '#' + orderId;
        
        const tbody = document.getElementById('modalDetailsTableBody');
        const printTbody = document.getElementById('printInvoiceItems');
        
        tbody.replaceChildren(createStatusRow(4, 'text-center py-4 text-muted', 'Fetching invoice items...', 'fas fa-spinner fa-spin fa-2x text-primary'));
        printTbody.replaceChildren();
        const detailsModalElement = document.getElementById('orderDetailsModal');
        let detailsModal = bootstrap.Modal.getInstance(detailsModalElement);
        if (!detailsModal) {
            detailsModal = new bootstrap.Modal(detailsModalElement);
        }
        detailsModal.show();
        
        fetch('get_order_details.php?id=' + encodeURIComponent(String(orderId)))
            .then(response => {
                if (!response.ok) {
                    throw new Error('API error');
                }
                return response.json();
            })
            .then(data => {
                tbody.replaceChildren();
                printTbody.replaceChildren();
                
                const order = data.order;
                const items = data.items;
                
                // Update elements from ajax response
                document.getElementById('modalOrderDate').textContent = order.order_date;
                document.getElementById('modalOrderTotal').textContent = '$' + parseFloat(order.total_amount).toFixed(2);
                
                document.getElementById('printInvoiceRef').textContent = 'REF-' + String(order.id).padStart(6, '0');
                document.getElementById('printInvoiceDate').textContent = order.order_date;
                document.getElementById('printInvoiceType').textContent = order.order_type === 'sale' ? 'Sale (Cash Inflow)' : 'Purchase (Cash Outflow)';
                document.getElementById('printInvoiceCashier').textContent = order.staff_name;
                document.getElementById('printInvoiceSubtotal').textContent = '$' + parseFloat(order.total_amount).toFixed(2);
                document.getElementById('printInvoiceTotal').textContent = '$' + parseFloat(order.total_amount).toFixed(2);

                // Render Party Details in Modal & Print Invoice
                const partyGroup = document.getElementById('modalPartyDetails');
                const partyTitle = document.getElementById('modalPartyTitle');
                const partyName = document.getElementById('modalPartyName');
                const partyPhone = document.getElementById('modalPartyPhone');
                const partyEmail = document.getElementById('modalPartyEmail');
                const partyAddress = document.getElementById('modalPartyAddress');
                
                const printPartyTitle = document.getElementById('printInvoicePartyTitle');
                const printPartyDetails = document.getElementById('printInvoicePartyDetails');
                
                if (order.order_type === 'sale') {
                    setPartyHeading(partyTitle, 'fas fa-user me-1 text-primary', 'Customer Details');
                    partyName.textContent = order.customer_name || 'N/A';
                    partyPhone.textContent = order.customer_phone || 'N/A';
                    partyEmail.textContent = order.customer_email || 'N/A';
                    partyAddress.textContent = order.customer_address || 'N/A';
                    partyGroup.classList.remove('d-none');
                    
                    printPartyTitle.textContent = 'Billed To:';
                    setPrintPartyDetails(printPartyDetails, order.customer_name, order.customer_phone, order.customer_email, order.customer_address);
                } else {
                    setPartyHeading(partyTitle, 'fas fa-truck me-1 text-success', 'Supplier Details');
                    partyName.textContent = order.supplier_name || 'N/A';
                    partyPhone.textContent = order.supplier_phone || 'N/A';
                    partyEmail.textContent = order.supplier_email || 'N/A';
                    partyAddress.textContent = order.supplier_address || 'N/A';
                    partyGroup.classList.remove('d-none');
                    
                    printPartyTitle.textContent = 'Purchased From:';
                    setPrintPartyDetails(printPartyDetails, order.supplier_name, order.supplier_phone, order.supplier_email, order.supplier_address);
                }

                if (items.length === 0) {
                    tbody.replaceChildren(createStatusRow(4, 'text-center py-3 text-muted', 'No items found in this order.'));
                    return;
                }
                items.forEach(item => {
                    const unitPrice = Number.parseFloat(item.unit_price);
                    const subtotal = Number.parseFloat(item.subtotal);
                    const unitPriceText = Number.isFinite(unitPrice) ? unitPrice.toFixed(2) : '0.00';
                    const subtotalText = Number.isFinite(subtotal) ? subtotal.toFixed(2) : '0.00';

                    const row = document.createElement('tr');
                    const productCell = document.createElement('td');
                    productCell.className = 'fw-bold';
                    productCell.textContent = item.product_name || '';
                    const quantityCell = document.createElement('td');
                    quantityCell.className = 'text-center';
                    quantityCell.textContent = String(item.quantity);
                    const unitPriceCell = document.createElement('td');
                    unitPriceCell.className = 'text-end';
                    unitPriceCell.textContent = '$' + unitPriceText;
                    const subtotalCell = document.createElement('td');
                    subtotalCell.className = 'text-end fw-bold text-dark';
                    subtotalCell.textContent = '$' + subtotalText;
                    row.append(productCell, quantityCell, unitPriceCell, subtotalCell);
                    tbody.append(row);

                    const printRow = document.createElement('tr');
                    printRow.className = 'invoice-item-row';
                    const printProductCell = document.createElement('td');
                    printProductCell.className = 'invoice-item-product';
                    printProductCell.textContent = item.product_name || '';
                    const printQuantityCell = document.createElement('td');
                    printQuantityCell.className = 'invoice-item-quantity';
                    printQuantityCell.textContent = String(item.quantity);
                    const printUnitPriceCell = document.createElement('td');
                    printUnitPriceCell.className = 'invoice-item-price';
                    printUnitPriceCell.textContent = '$' + unitPriceText;
                    const printSubtotalCell = document.createElement('td');
                    printSubtotalCell.className = 'invoice-item-subtotal';
                    printSubtotalCell.textContent = '$' + subtotalText;
                    printRow.append(printProductCell, printQuantityCell, printUnitPriceCell, printSubtotalCell);
                    printTbody.append(printRow);
                });
            })
            .catch(error => {
                tbody.replaceChildren(createStatusRow(4, 'text-center py-3 text-danger', 'Failed to load order details.', 'fas fa-exclamation-triangle me-2'));
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.order-details-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                showOrderDetails(Number.parseInt(button.dataset.orderId, 10));
            });
        });

        const exportReportButton = document.getElementById('exportReportBtn');
        if (exportReportButton) {
            exportReportButton.addEventListener('click', function() {
                const exportModalElement = document.getElementById('exportReportModal');
                const exportModal = bootstrap.Modal.getInstance(exportModalElement);
                if (exportModal) {
                    exportModal.hide();
                }
            });
        }

        const downloadPdfBtn = document.getElementById('downloadPdfBtn');
        if (downloadPdfBtn) {
            downloadPdfBtn.addEventListener('click', function () {
                if (!activeInvoiceId) return;

                const element = document.getElementById('invoicePrintContainer');
                
                // Show temporarily to capture correctly (html2pdf sometimes needs element in DOM layout)
                const wrapper = document.getElementById('invoicePrintWrapper');
                wrapper.classList.add('invoice-print-visible');

                const opt = {
                    margin:       [10, 10, 10, 10],
                    filename:     'invoice_ref_' + String(activeInvoiceId).padStart(6, '0') + '.pdf',
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2, useCORS: true },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };

                // Generate PDF and hide wrapper afterwards
                html2pdf().set(opt).from(element).save().then(() => {
                    wrapper.classList.remove('invoice-print-visible');
                }).catch(err => {
                    console.error('PDF generation failed:', err);
                    wrapper.classList.remove('invoice-print-visible');
                });
            });
        }
    });
</script>

<?php
require_once '../includes/layouts/footer.php';
?>
