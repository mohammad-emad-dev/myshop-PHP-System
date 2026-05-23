<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

verify_login();

$success = '';
$error = '';

// Handle Order Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_order'])) {
    // --- POS Transaction Flood Protection ---
    $current_time = time();
    if (isset($_SESSION['last_order_time']) && ($current_time - $_SESSION['last_order_time']) < 3) {
        // Prevent submissions faster than 3 seconds
        $error = "Transaction processing. Please wait a moment before submitting another order.";
    } else {
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!verify_csrf_token($csrf_token)) {
            $error = "Security check failed. Invalid request token.";
        } else {
            $cart_json = $_POST['cart_data'] ?? '[]';
            
            // Defend against excessively large payloads
            if (strlen($cart_json) > 50000) {
                $error = "Payload too large. Request rejected.";
            } else {
                $cart_items = json_decode($cart_json, true);
                
                // Ensure valid JSON array structure
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($cart_items) || isset($cart_items['id'])) {
                    $cart_items = [];
                }

        $order_type = $_POST['order_type'] ?? 'sale';
        if (!in_array($order_type, ['sale', 'purchase'], true)) {
            $order_type = 'sale';
        }

        if (empty($cart_items)) {
            $error = "Cart is empty or invalid. Please add products.";
        } else {
            $order_items = [];
            $valid_order = true;

            foreach ($cart_items as $item) {
                // Ensure valid integers
                $product_id = isset($item['id']) ? intval($item['id']) : 0;
                $quantity = isset($item['qty']) ? intval($item['qty']) : 0;

                if ($product_id <= 0 || $quantity <= 0) {
                    $error = "Invalid product or quantity detected.";
                    $valid_order = false;
                    break;
                }

                // Security: Fetch product from DB to ensure it exists and to get the true price.
                // NEVER trust client-side prices!
                $prod = get_product_by_id($conn, $product_id);
                if (!$prod) {
                    $error = "Product ID #{$product_id} does not exist.";
                    $valid_order = false;
                    break;
                }
                
                // Stock validation only for sales
                if ($order_type === 'sale' && $prod['stock'] < $quantity) {
                    $error = "Insufficient stock for product: " . htmlspecialchars($prod['name']);
                    $valid_order = false;
                    break;
                }

                $actual_price = (float)$prod['price'];
                $subtotal = $actual_price * $quantity;

                $order_items[] = [
                    'product_id' => $product_id,
                    'quantity'   => $quantity,
                    'unit_price' => $actual_price,
                    'subtotal'   => $subtotal
                ];
            }

            if ($valid_order) {
                $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : null;
                $supplier_id = isset($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;

                $order_id = create_order($conn, $_SESSION['staff_id'], $order_items, $order_type, $customer_id, $supplier_id);
                if ($order_id) {
                    $_SESSION['last_order_time'] = time(); // Record time to prevent flood
                    $success = "Order #$order_id completed successfully!";
                } else {
                    $error = "Failed to process order transaction.";
                }
            }
        }
    }
}
}
}

$products = get_products($conn);
$categories = get_categories($conn);
$customers = get_customers($conn);
$suppliers = get_suppliers($conn);

$page_title = 'POS System';
$active_page = 'orders';
$header_title = 'POS Terminal';
$extra_css = ['https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.min.css'];

require_once '../includes/layouts/header.php';
?>
<!-- Styles moved to style.css to keep page styling clean and standardized -->

<div class="d-flex" id="wrapper">
    <?php require_once '../includes/layouts/sidebar.php'; ?>
    <?php require_once '../includes/layouts/navbar.php'; ?>

    <div class="container-fluid py-3 px-4 pos-container">
        <div class="row h-100">
            <!-- Left Column: Product Catalog -->
            <div class="col-lg-8 h-100 d-flex flex-column">
                <!-- Search Bar -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="input-group input-group-lg shadow-sm border rounded-3 overflow-hidden">
                            <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="searchProduct" class="form-control border-0" placeholder="Search products by name..." onkeyup="filterProducts()" style="box-shadow: none;">
                        </div>
                    </div>
                </div>

                <!-- Category Navigation Pills -->
                <div class="mb-3 d-flex overflow-x-auto pb-2" style="gap: 8px; scrollbar-width: thin; -ms-overflow-style: none;">
                    <button class="btn btn-sm btn-primary category-pill rounded-pill px-3 fw-bold" data-category-id="all" onclick="selectCategory(this, 'all')">
                        All
                    </button>
                    <?php foreach ($categories as $cat): ?>
                        <button class="btn btn-sm btn-outline-secondary category-pill rounded-pill px-3" data-category-id="<?php echo $cat['id']; ?>" onclick="selectCategory(this, <?php echo $cat['id']; ?>)">
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Product Grid -->
                <div class="product-grid-container" id="productGrid">
                    <div class="row g-3">
                        <?php foreach ($products as $product): ?>
                            <?php
                            $hasStock = $product['stock'] > 0;
                            $cardClass = $hasStock ? 'product-card' : 'product-card disabled';
                            $badgeClass = $product['stock'] <= 5 ? 'bg-danger' : 'bg-success';
                            ?>
                            <div class="col-md-4 col-sm-6 product-item" data-name="<?php echo strtolower($product['name']); ?>" data-category-id="<?php echo $product['category_id']; ?>">
                                <div class="card h-100 border-0 <?php echo $cardClass; ?>" 
                                     <?php if ($hasStock): ?>
                                         onclick='addToCart(<?php echo json_encode($product); ?>)'
                                     <?php endif; ?>>
                                
                                    <div class="card-body text-center p-4 position-relative">
                                        <span class="badge rounded-pill <?php echo $badgeClass; ?> stock-badge">
                                            Stock: <?php echo $product['stock']; ?>
                                        </span>
                                    
                                        <div class="mb-3 overflow-hidden rounded-circle mx-auto shadow-sm" style="width: 80px; height: 80px;">
                                            <?php if ($product['image_path']): ?>
                                                <img src="<?php echo htmlspecialchars($product['image_path']); ?>" 
                                                     class="img-fluid w-100 h-100" style="object-fit: cover;">
                                            <?php else: ?>
                                                <div class="w-100 h-100 bg-light d-flex align-items-center justify-content-center text-muted">
                                                    <i class="fas fa-box fa-2x"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <h5 class="card-title text-dark fw-bold mb-1" style="font-size: 1.05rem; font-family: var(--font-heading);"><?php echo htmlspecialchars($product['name']); ?></h5>
                                        <p class="card-text text-primary fs-5 fw-bold mb-0">$<?php echo number_format($product['price'], 2); ?></p>
                                    </div>
                                    <?php if (!$hasStock): ?>
                                        <div class="card-footer bg-danger text-white text-center py-1 border-0" style="font-size: 0.8rem; font-weight: bold; border-bottom-left-radius: var(--radius-md); border-bottom-right-radius: var(--radius-md);">Out of Stock</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column: Cart -->
            <div class="col-lg-4 h-100">
                <div class="card shadow-sm border-0 rounded-4 cart-panel">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center border-bottom">
                        <h5 class="mb-0 text-secondary fw-bold" style="font-family: var(--font-heading);"><i class="fas fa-shopping-cart me-2 text-primary"></i>Current Order</h5>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" id="clearCartBtn" style="display:none;font-size:0.72rem;" onclick="clearCart()">
                                <i class="fas fa-times me-1"></i>Clear
                            </button>
                            <span class="badge primary-bg primary-text rounded-pill px-2 py-1" style="font-size: 0.75rem; font-weight: 700;" id="cartCount">0 Items</span>
                        </div>
                    </div>
                    
                    <div class="card-body border-bottom py-3">
                        <label class="form-label fw-bold mb-2"><i class="fas fa-exchange-alt me-2"></i>Transaction Type</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="orderType" id="typeSale" value="sale" checked>
                            <label class="btn btn-outline-primary" for="typeSale"><i class="fas fa-cash-register me-1"></i> Sale</label>
                            
                            <input type="radio" class="btn-check" name="orderType" id="typePurchase" value="purchase">
                            <label class="btn btn-outline-success" for="typePurchase"><i class="fas fa-box me-1"></i> Purchase</label>
                        </div>
                    </div>
                    
                    <div class="cart-items-container p-3">
                        <table class="table table-hover align-middle mb-0">
                            <tbody id="cartTableBody">
                                <tr class="text-center text-muted" id="emptyCartMsg">
                                    <td class="py-5">
                                        <i class="fas fa-shopping-basket fa-3x mb-3 text-secondary opacity-50"></i>
                                        <p>Select products from the left to start an order.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-4 bg-white border-top">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Subtotal</span>
                            <span class="fw-bold" id="cartSubtotal">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-4">
                            <span class="fs-4 fw-bold text-dark">Total</span>
                            <span class="fs-3 fw-bold text-success" id="cartTotal">$0.00</span>
                        </div>
                        
                        <form method="POST" id="orderForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="cart_data" id="cartDataInput">
                            <input type="hidden" name="complete_order" value="1">
                            <input type="hidden" name="order_type" id="orderTypeInput" value="sale">
                            
                            <!-- Customer Selection Dropdown -->
                            <div class="mb-3" id="formCustomerGroup">
                                <label for="customerSelect" class="form-label fw-bold mb-1 text-secondary"><i class="fas fa-user me-1 text-primary"></i> Customer</label>
                                <select class="form-select rounded-3" name="customer_id" id="customerSelect">
                                    <?php foreach ($customers as $cust): ?>
                                        <option value="<?php echo $cust['id']; ?>"><?php echo htmlspecialchars($cust['name'] . ($cust['phone'] ? ' - ' . $cust['phone'] : '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Supplier Selection Dropdown -->
                            <div class="mb-3" id="formSupplierGroup" style="display: none;">
                                <label for="supplierSelect" class="form-label fw-bold mb-1 text-secondary"><i class="fas fa-truck me-1 text-success"></i> Supplier</label>
                                <select class="form-select rounded-3" name="supplier_id" id="supplierSelect">
                                    <?php foreach ($suppliers as $supp): ?>
                                        <option value="<?php echo $supp['id']; ?>"><?php echo htmlspecialchars($supp['name'] . ($supp['phone'] ? ' - ' . $supp['phone'] : '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="button" class="btn btn-success w-100 py-3 fs-5 fw-bold shadow-sm" id="completeOrderBtn" onclick="submitOrder()" disabled>
                                <i class="fas fa-check-circle me-2"></i>Complete Order
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
$extra_js = [
    'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js'
];
?>
<script>
    // --- POS Logic ---
    let cart = [];
    let orderType = 'sale'; // Default to sale

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[name="orderType"]').forEach(radio => {
            radio.addEventListener('change', function() {
                orderType = this.value;
                document.getElementById('orderTypeInput').value = orderType;
                
                // Toggle visible selector groups dynamically
                if (orderType === 'sale') {
                    document.getElementById('formCustomerGroup').style.display = 'block';
                    document.getElementById('formSupplierGroup').style.display = 'none';
                } else {
                    document.getElementById('formCustomerGroup').style.display = 'none';
                    document.getElementById('formSupplierGroup').style.display = 'block';
                }
                
                // Clear cart when transaction type changes to prevent stock validation confusion
                cart = [];
                renderCart();
            });
        });
    });

    function addToCart(product) {
        if (orderType === 'sale' && product.stock <= 0) {
            Swal.fire({
                icon: 'error',
                title: 'Out of Stock',
                text: 'This product is currently out of stock!',
                confirmButtonColor: '#198754'
            });
            return;
        }

        const existingItem = cart.find(item => item.id === product.id);

        if (existingItem) {
            if (orderType === 'sale' && existingItem.qty >= product.stock) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Stock Limit Reached',
                    text: `Cannot add more. Only ${product.stock} in stock!`,
                    confirmButtonColor: '#198754'
                });
                return;
            }
            existingItem.qty++;
        } else {
            cart.push({
                id: product.id,
                name: product.name,
                price: parseFloat(product.price),
                stock: parseInt(product.stock),
                qty: 1
            });
        }
        renderCart();
    }

    function updateQty(index, delta) {
        const item = cart[index];
        const newQty = item.qty + delta;

        if (orderType === 'sale' && newQty > item.stock) {
            Swal.fire({
                icon: 'warning',
                title: 'Stock Limit',
                text: `Max stock available: ${item.stock}`,
                confirmButtonColor: '#198754'
            });
            return;
        }

        if (newQty <= 0) {
            removeFromCart(index);
            return;
        }

        item.qty = newQty;
        renderCart();
    }

    function removeFromCart(index) {
        cart.splice(index, 1);
        renderCart();
    }

    function renderCart() {
        const tbody = document.getElementById('cartTableBody');
        const subtotalEl = document.getElementById('cartSubtotal');
        const totalEl = document.getElementById('cartTotal');
        const countEl = document.getElementById('cartCount');
        const dataInput = document.getElementById('cartDataInput');

        tbody.innerHTML = '';

        let total = 0;
        let itemCount = 0;

        if (cart.length === 0) {
            tbody.innerHTML = `
                <tr class="text-center text-muted" id="emptyCartMsg">
                    <td class="py-5">
                        <i class="fas fa-shopping-basket fa-3x mb-3 text-secondary opacity-50"></i>
                        <p class="small mb-0">Select products from the left to start an order.</p>
                    </td>
                </tr>`;
        } else {
            cart.forEach((item, index) => {
                const lineTotal = item.price * item.qty;
                total += lineTotal;
                itemCount += item.qty;

                tbody.innerHTML += `
                    <tr class="cart-item-row">
                        <td>
                            <div class="fw-bold text-dark mb-0">${item.name}</div>
                            <small class="text-muted">$${item.price.toFixed(2)}</small>
                        </td>
                        <td width="100">
                            <div class="d-flex align-items-center justify-content-center">
                                <button type="button" class="cart-qty-btn" onclick="updateQty(${index}, -1)">-</button>
                                <input type="text" class="cart-qty-input" value="${item.qty}" readonly>
                                <button type="button" class="cart-qty-btn" onclick="updateQty(${index}, 1)">+</button>
                            </div>
                        </td>
                        <td class="text-end fw-bold text-dark">$${lineTotal.toFixed(2)}</td>
                        <td class="text-end" width="40">
                            <button type="button" class="btn btn-sm text-danger p-0" onclick="removeFromCart(${index})">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        const formattedTotal = '$' + total.toFixed(2);
        subtotalEl.innerText = formattedTotal;
        totalEl.innerText = formattedTotal;
        countEl.innerText = itemCount + ' Items';
        dataInput.value = JSON.stringify(cart);

        // Toggle clear cart button & complete button state
        const clearBtn = document.getElementById('clearCartBtn');
        const completeBtn = document.getElementById('completeOrderBtn');
        if (cart.length > 0) {
            clearBtn.style.display = 'inline-block';
            completeBtn.classList.add('pulse-btn');
            completeBtn.disabled = false;
        } else {
            clearBtn.style.display = 'none';
            completeBtn.classList.remove('pulse-btn');
            completeBtn.disabled = true;
        }
    }

    function clearCart() {
        if (cart.length === 0) return;
        Swal.fire({
            title: 'Clear Cart?',
            text: 'Remove all items from the current order?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, clear it'
        }).then((result) => {
            if (result.isConfirmed) {
                cart = [];
                renderCart();
            }
        });
    }

    let activeCategoryId = 'all';

    function selectCategory(el, catId) {
        activeCategoryId = catId;
        
        // Remove primary from all pills and add outline
        document.querySelectorAll('.category-pill').forEach(pill => {
            pill.classList.remove('btn-primary');
            pill.classList.add('btn-outline-secondary');
            pill.classList.remove('fw-bold');
        });
        
        // Mark current pill as active
        el.classList.remove('btn-outline-secondary');
        el.classList.add('btn-primary');
        el.classList.add('fw-bold');
        
        filterProducts();
    }

    function filterProducts() {
        const query = document.getElementById('searchProduct').value.toLowerCase();
        const items = document.querySelectorAll('.product-item');

        items.forEach(item => {
            const name = item.dataset.name;
            const categoryId = item.dataset.categoryId;
            
            const matchesQuery = name.includes(query);
            const matchesCategory = (activeCategoryId === 'all' || categoryId == activeCategoryId);
            
            if (matchesQuery && matchesCategory) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function submitOrder() {
        if (cart.length === 0) {
            Swal.fire({
                icon: 'error',
                title: 'Cart Empty',
                text: 'Please add products to the cart first!',
                confirmButtonColor: '#198754'
            });
            return;
        }
        
        const typeText = orderType === 'sale' ? 'Sale' : 'Purchase';
        const icon = orderType === 'sale' ? 'question' : 'info';
        
        Swal.fire({
            title: `Complete ${typeText}?`,
            text: `Process this ${typeText.toLowerCase()} transaction?`,
            icon: icon,
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, complete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('orderForm').submit();
            }
        });
    }
</script>
<?php if (!empty($success)): ?>
<script>
    window.addEventListener('load', function() {
        Swal.fire({
            icon: 'success',
            title: 'Order Completed!',
            text: <?php echo json_encode($success); ?>,
            confirmButtonColor: '#6366f1',
            timer: 4000,
            timerProgressBar: true
        });
    });
</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
<script>
    window.addEventListener('load', function() {
        Swal.fire({
            icon: 'error',
            title: 'Order Failed',
            text: <?php echo json_encode($error); ?>,
            confirmButtonColor: '#ef4444'
        });
    });
</script>
<?php endif; ?>
<?php if (isset($_GET['purchase_product_id'])): ?>
    <?php
    $restock_prod_id = intval($_GET['purchase_product_id']);
    $restock_prod = get_product_by_id($conn, $restock_prod_id);
    if ($restock_prod):
    ?>
    <script>
        window.addEventListener('load', function() {
            // Trigger purchase type selection
            const typePurchaseRadio = document.getElementById('typePurchase');
            if (typePurchaseRadio) {
                typePurchaseRadio.checked = true;
                // Dispatch change event to update JS variables
                typePurchaseRadio.dispatchEvent(new Event('change'));
            }
            // Add product to cart
            const productObj = <?php echo json_encode([
                'id' => intval($restock_prod['id']),
                'name' => $restock_prod['name'],
                'price' => floatval($restock_prod['price']),
                'stock' => intval($restock_prod['stock'])
            ]); ?>;
            addToCart(productObj);
        });
    </script>
    <?php endif; ?>
<?php endif; ?>
<?php
require_once '../includes/layouts/footer.php';
?>