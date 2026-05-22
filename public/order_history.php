<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

verify_login();

$orders = get_orders($conn);

// Filter by transaction type
$filter_type = isset($_GET['type']) ? sanitize_input($_GET['type']) : 'all';
if ($filter_type !== 'all' && ($filter_type === 'sale' || $filter_type === 'purchase')) {
    $orders = array_filter($orders, function($o) use ($filter_type) {
        return $o['order_type'] === $filter_type;
    });
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

    <div class="container-fluid px-4 py-5">
        <div class="row my-2">
            <div class="col-md-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center flex-wrap g-2">
                        <h4 class="mb-0 text-secondary fw-bold">Transaction History</h4>
                        <div class="d-flex align-items-center gap-2">
                            <div class="btn-group" role="group" aria-label="Order filters">
                                <a href="order_history.php?type=all" class="btn btn-outline-primary <?php echo $filter_type === 'all' ? 'active' : ''; ?>">All</a>
                                <a href="order_history.php?type=sale" class="btn btn-outline-primary <?php echo $filter_type === 'sale' ? 'active' : ''; ?>">Sales</a>
                                <a href="order_history.php?type=purchase" class="btn btn-outline-primary <?php echo $filter_type === 'purchase' ? 'active' : ''; ?>">Purchases</a>
                            </div>
                            <?php if (is_admin()): ?>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#exportReportModal">
                                <i class="fas fa-file-excel me-1"></i> Export CSV
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
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
                                                    <button class="btn btn-sm btn-outline-primary" onclick="showOrderDetails(<?php echo $order['id']; ?>)">
                                                        <i class="fas fa-eye me-1"></i> View Details
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
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
                    <h5 class="modal-title fw-bold" id="orderDetailsModalLabel" style="font-family: var(--font-heading); color: var(--slate-900);"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Transaction Details</h5>
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
                    <div id="modalPartyDetails" class="mb-4 p-3 rounded-3 border border-light-subtle bg-light bg-opacity-50" style="display: none;">
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
                                    <th class="text-center" style="width: 100px;">Quantity</th>
                                    <th class="text-end" style="width: 150px;">Unit Price</th>
                                    <th class="text-end" style="width: 150px;">Subtotal</th>
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

    <?php if (is_admin()): ?>
    <!-- Export Report Modal -->
    <div class="modal fade" id="exportReportModal" tabindex="-1" aria-labelledby="exportReportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <form action="export_report.php" method="GET" target="_blank">
                    <div class="modal-header py-3">
                        <h5 class="modal-title fw-bold" id="exportReportModalLabel" style="font-family: var(--font-heading); color: var(--slate-900);"><i class="fas fa-file-export me-2 text-primary"></i>Export Transaction Report</h5>
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
                        <button type="submit" class="btn btn-success rounded-3 px-4" onclick="bootstrap.Modal.getInstance(document.getElementById('exportReportModal')).hide();"><i class="fas fa-download me-1"></i> Export CSV</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hidden Print Container for PDF Generation -->
    <div id="invoicePrintWrapper" style="display: none;">
        <div id="invoicePrintContainer" style="padding: 40px; font-family: 'Outfit', 'Inter', sans-serif; color: #333; background: #fff;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #eaeaea; padding-bottom: 20px; margin-bottom: 30px;">
                <div>
                    <h1 style="margin: 0; color: #009dff; font-size: 28px; font-weight: bold; letter-spacing: -0.5px;">MYSHOP SYSTEM</h1>
                    <p style="margin: 5px 0 0 0; color: #6c757d; font-size: 14px;">Enterprise Inventory & POS Solution</p>
                </div>
                <div style="text-align: right;">
                    <h2 style="margin: 0; color: #2d3748; font-size: 20px; font-weight: bold;">INVOICE</h2>
                    <p style="margin: 5px 0 0 0; color: #6c757d; font-size: 14px; font-weight: 500;" id="printInvoiceRef"></p>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; margin-bottom: 40px; font-size: 14px; line-height: 1.5;">
                <div style="flex: 1;">
                    <h4 style="margin: 0 0 10px 0; color: #4a5568; font-size: 12px; text-transform: uppercase; font-weight: bold; letter-spacing: 0.5px;">Billed From:</h4>
                    <strong>MyShop Ltd.</strong><br>
                    123 Business Avenue<br>
                    Suite 400, Tech City<br>
                    support@myshop.com
                </div>
                <div style="flex: 1; padding-left: 20px; border-left: 1px solid #eaeaea; border-right: 1px solid #eaeaea; margin-left: 20px; margin-right: 20px;">
                    <h4 style="margin: 0 0 10px 0; color: #4a5568; font-size: 12px; text-transform: uppercase; font-weight: bold; letter-spacing: 0.5px;" id="printInvoicePartyTitle">Billed To:</h4>
                    <span id="printInvoicePartyDetails"></span>
                </div>
                <div style="text-align: right; min-width: 200px; flex: 1;">
                    <h4 style="margin: 0 0 10px 0; color: #4a5568; font-size: 12px; text-transform: uppercase; font-weight: bold; letter-spacing: 0.5px;">Transaction Details:</h4>
                    <strong>Date:</strong> <span id="printInvoiceDate"></span><br>
                    <strong>Type:</strong> <span id="printInvoiceType"></span><br>
                    <strong>Cashier:</strong> <span id="printInvoiceCashier"></span>
                </div>
            </div>

            <table style="width: 100%; border-collapse: collapse; margin-bottom: 40px; font-size: 14px;">
                <thead>
                    <tr style="border-bottom: 2px solid #eaeaea; text-align: left;">
                        <th style="padding: 12px 8px; color: #4a5568; text-transform: uppercase; font-size: 12px; font-weight: bold; letter-spacing: 0.5px;">Product</th>
                        <th style="padding: 12px 8px; color: #4a5568; text-transform: uppercase; font-size: 12px; font-weight: bold; letter-spacing: 0.5px; text-align: center; width: 100px;">Qty</th>
                        <th style="padding: 12px 8px; color: #4a5568; text-transform: uppercase; font-size: 12px; font-weight: bold; letter-spacing: 0.5px; text-align: right; width: 150px;">Unit Price</th>
                        <th style="padding: 12px 8px; color: #4a5568; text-transform: uppercase; font-size: 12px; font-weight: bold; letter-spacing: 0.5px; text-align: right; width: 150px;">Subtotal</th>
                    </tr>
                </thead>
                <tbody id="printInvoiceItems">
                    <!-- Populated dynamically -->
                </tbody>
            </table>

            <div style="display: flex; justify-content: flex-end; border-top: 2px solid #eaeaea; padding-top: 20px;">
                <div style="text-align: right; width: 250px;">
                    <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 10px; color: #4a5568;">
                        <span>Subtotal:</span>
                        <span id="printInvoiceSubtotal"></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 15px; color: #4a5568;">
                        <span>Tax (0%):</span>
                        <span>$0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 18px; font-weight: bold; color: #2d3748; border-top: 1px solid #eee; padding-top: 15px;">
                        <span>Total Amount:</span>
                        <span style="color: #1cc88a; font-weight: bold;" id="printInvoiceTotal"></span>
                    </div>
                </div>
            </div>

            <div style="margin-top: 80px; border-top: 1px solid #eaeaea; padding-top: 20px; text-align: center; color: #a0aec0; font-size: 12px;">
                Thank you for your business! If you have any questions, contact billing@myshop.com
            </div>
        </div>
    </div>

<script>
    let activeInvoiceId = null;

    function showOrderDetails(orderId) {
        activeInvoiceId = orderId;
        
        document.getElementById('modalOrderId').innerText = '#' + orderId;
        
        const tbody = document.getElementById('modalDetailsTableBody');
        const printTbody = document.getElementById('printInvoiceItems');
        
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2 text-muted mb-0">Fetching invoice items...</p></td></tr>';
        printTbody.innerHTML = '';
        const detailsModalElement = document.getElementById('orderDetailsModal');
        let detailsModal = bootstrap.Modal.getInstance(detailsModalElement);
        if (!detailsModal) {
            detailsModal = new bootstrap.Modal(detailsModalElement);
        }
        detailsModal.show();
        
        fetch('get_order_details.php?id=' + orderId)
            .then(response => {
                if (!response.ok) {
                    throw new Error('API error');
                }
                return response.json();
            })
            .then(data => {
                tbody.innerHTML = '';
                printTbody.innerHTML = '';
                
                const order = data.order;
                const items = data.items;
                
                // Update elements from ajax response
                document.getElementById('modalOrderDate').innerText = order.order_date;
                document.getElementById('modalOrderTotal').innerText = '$' + parseFloat(order.total_amount).toFixed(2);
                
                document.getElementById('printInvoiceRef').innerText = 'REF-' + String(order.id).padStart(6, '0');
                document.getElementById('printInvoiceDate').innerText = order.order_date;
                document.getElementById('printInvoiceType').innerText = order.order_type === 'sale' ? 'Sale (Cash Inflow)' : 'Purchase (Cash Outflow)';
                document.getElementById('printInvoiceCashier').innerText = order.staff_name;
                document.getElementById('printInvoiceSubtotal').innerText = '$' + parseFloat(order.total_amount).toFixed(2);
                document.getElementById('printInvoiceTotal').innerText = '$' + parseFloat(order.total_amount).toFixed(2);

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
                    partyTitle.innerHTML = '<i class="fas fa-user me-1 text-primary"></i> Customer Details';
                    partyName.innerText = order.customer_name;
                    partyPhone.innerText = order.customer_phone || 'N/A';
                    partyEmail.innerText = order.customer_email || 'N/A';
                    partyAddress.innerText = order.customer_address || 'N/A';
                    partyGroup.style.display = 'block';
                    
                    printPartyTitle.innerText = 'Billed To:';
                    printPartyDetails.innerHTML = `
                        <strong>${order.customer_name}</strong><br>
                        ${order.customer_phone ? 'Phone: ' + order.customer_phone + '<br>' : ''}
                        ${order.customer_email ? 'Email: ' + order.customer_email + '<br>' : ''}
                        ${order.customer_address ? 'Address: ' + order.customer_address : ''}
                    `;
                } else {
                    partyTitle.innerHTML = '<i class="fas fa-truck me-1 text-success"></i> Supplier Details';
                    partyName.innerText = order.supplier_name;
                    partyPhone.innerText = order.supplier_phone || 'N/A';
                    partyEmail.innerText = order.supplier_email || 'N/A';
                    partyAddress.innerText = order.supplier_address || 'N/A';
                    partyGroup.style.display = 'block';
                    
                    printPartyTitle.innerText = 'Purchased From:';
                    printPartyDetails.innerHTML = `
                        <strong>${order.supplier_name}</strong><br>
                        ${order.supplier_phone ? 'Phone: ' + order.supplier_phone + '<br>' : ''}
                        ${order.supplier_email ? 'Email: ' + order.supplier_email + '<br>' : ''}
                        ${order.supplier_address ? 'Address: ' + order.supplier_address : ''}
                    `;
                }

                if (items.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted">No items found in this order.</td></tr>';
                    return;
                }
                items.forEach(item => {
                    const rowHtml = `
                        <tr>
                            <td class="fw-bold">${item.product_name}</td>
                            <td class="text-center">${item.quantity}</td>
                            <td class="text-end">$${parseFloat(item.unit_price).toFixed(2)}</td>
                            <td class="text-end fw-bold text-dark">$${parseFloat(item.subtotal).toFixed(2)}</td>
                        </tr>
                    `;
                    tbody.innerHTML += rowHtml;

                    const printRowHtml = `
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px 8px; font-weight: bold; color: #2d3748;">${item.product_name}</td>
                            <td style="padding: 12px 8px; text-align: center; color: #4a5568;">${item.quantity}</td>
                            <td style="padding: 12px 8px; text-align: right; color: #4a5568;">$${parseFloat(item.unit_price).toFixed(2)}</td>
                            <td style="padding: 12px 8px; text-align: right; font-weight: bold; color: #2d3748;">$${parseFloat(item.subtotal).toFixed(2)}</td>
                        </tr>
                    `;
                    printTbody.innerHTML += printRowHtml;
                });
            })
            .catch(error => {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Failed to load order details.</td></tr>';
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const downloadPdfBtn = document.getElementById('downloadPdfBtn');
        if (downloadPdfBtn) {
            downloadPdfBtn.addEventListener('click', function () {
                if (!activeInvoiceId) return;

                const element = document.getElementById('invoicePrintContainer');
                
                // Show temporarily to capture correctly (html2pdf sometimes needs element in DOM layout)
                const wrapper = document.getElementById('invoicePrintWrapper');
                wrapper.style.display = 'block';

                const opt = {
                    margin:       [10, 10, 10, 10],
                    filename:     'invoice_ref_' + String(activeInvoiceId).padStart(6, '0') + '.pdf',
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2, useCORS: true },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };

                // Generate PDF and hide wrapper afterwards
                html2pdf().set(opt).from(element).save().then(() => {
                    wrapper.style.display = 'none';
                }).catch(err => {
                    console.error('PDF generation failed:', err);
                    wrapper.style.display = 'none';
                });
            });
        }
    });
</script>

<?php
require_once '../includes/layouts/footer.php';
?>
