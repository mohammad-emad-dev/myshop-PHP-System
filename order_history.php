<?php
require_once 'includes/functions.php';
start_secure_session();
require_once 'includes/db.php';

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

require_once 'includes/header.php';
?>

<div class="d-flex" id="wrapper">
    <?php require_once 'includes/sidebar.php'; ?>
    <?php require_once 'includes/navbar.php'; ?>

    <div class="container-fluid px-4 py-5">
        <div class="row my-2">
            <div class="col-md-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 text-secondary fw-bold">Transaction History</h4>
                        <div class="btn-group" role="group" aria-label="Order filters">
                            <a href="order_history.php?type=all" class="btn btn-outline-primary <?php echo $filter_type === 'all' ? 'active' : ''; ?>">All</a>
                            <a href="order_history.php?type=sale" class="btn btn-outline-primary <?php echo $filter_type === 'sale' ? 'active' : ''; ?>">Sales</a>
                            <a href="order_history.php?type=purchase" class="btn btn-outline-primary <?php echo $filter_type === 'purchase' ? 'active' : ''; ?>">Purchases</a>
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
                                                    <?php if ($order['order_type'] === 'sale'): ?>
                                                        <span class="badge bg-primary rounded-pill px-3 py-2"><i class="fas fa-cash-register me-1"></i> Sale</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-box me-1"></i> Purchase</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end fw-bold text-dark">$<?php echo number_format($order['total_amount'], 2); ?></td>
                                                <td class="text-center">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="showOrderDetails(<?php echo $order['id']; ?>, '<?php echo date('Y-m-d h:i A', strtotime($order['order_date'])); ?>', <?php echo $order['total_amount']; ?>)">
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
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-bold" id="orderDetailsModalLabel"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Transaction Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                <div class="modal-footer border-top bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

<script>
    function showOrderDetails(orderId, orderDate, orderTotal) {
        document.getElementById('modalOrderId').innerText = '#' + orderId;
        document.getElementById('modalOrderDate').innerText = orderDate;
        document.getElementById('modalOrderTotal').innerText = '$' + parseFloat(orderTotal).toFixed(2);
        
        const tbody = document.getElementById('modalDetailsTableBody');
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2 text-muted mb-0">Fetching invoice items...</p></td></tr>';
        
        var detailsModal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
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
                if (data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted">No items found in this order.</td></tr>';
                    return;
                }
                data.forEach(item => {
                    tbody.innerHTML += `
                        <tr>
                            <td class="fw-bold">${item.product_name}</td>
                            <td class="text-center">${item.quantity}</td>
                            <td class="text-end">$${parseFloat(item.unit_price).toFixed(2)}</td>
                            <td class="text-end fw-bold text-dark">$${parseFloat(item.subtotal).toFixed(2)}</td>
                        </tr>
                    `;
                });
            })
            .catch(error => {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Failed to load order details.</td></tr>';
            });
    }
</script>

<?php
require_once 'includes/footer.php';
?>
