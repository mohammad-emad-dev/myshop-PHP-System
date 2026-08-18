<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

verify_login();

$success = '';
$error = '';
$completed_order_id = 0;

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
                    $completed_order_id = (int)$order_id;
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

$products = get_all_products($conn);
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
                <!-- Barcode Scanner Input -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="input-group input-group-lg shadow-sm border border-primary border-2 rounded-3 overflow-hidden">
                            <span class="input-group-text bg-primary text-white border-0"><i class="fas fa-barcode"></i></span>
                            <input type="text" id="barcodeInput" class="form-control border-0 fw-bold text-primary pos-barcode-input" placeholder="Scan barcode here... (Auto adds to cart)" aria-label="Scan product barcode" autofocus autocomplete="off">
                        </div>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="input-group shadow-sm border rounded-3 overflow-hidden">
                            <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="searchProduct" class="form-control border-0 pos-search-input" placeholder="Search products by name..." aria-label="Search products by name">
                        </div>
                    </div>
                </div>

                <!-- Category Navigation Pills -->
                <div class="mb-3 d-flex overflow-x-auto pb-2 category-pill-list">
                    <button type="button" class="btn btn-sm btn-primary category-pill rounded-pill px-3 fw-bold" data-category-id="all">
                        All
                    </button>
                    <?php foreach ($categories as $cat): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary category-pill rounded-pill px-3" data-category-id="<?php echo (int)$cat['id']; ?>">
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
                            <div class="col-md-4 col-sm-6 product-item"
                                 data-name="<?php echo htmlspecialchars(strtolower((string)$product['name']), ENT_QUOTES, 'UTF-8'); ?>"
                                 data-category-id="<?php echo (int)$product['category_id']; ?>"
                                 data-barcode="<?php echo htmlspecialchars($product['barcode'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="card h-100 border-0 <?php echo $cardClass; ?>"
                                     <?php if ($hasStock): ?>
                                         data-product-id="<?php echo (int)$product['id']; ?>"
                                         data-product-name="<?php echo htmlspecialchars($product['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                         data-product-price="<?php echo htmlspecialchars((string)$product['price'], ENT_QUOTES, 'UTF-8'); ?>"
                                         data-product-stock="<?php echo (int)$product['stock']; ?>"
                                     <?php endif; ?>>
                                
                                    <div class="card-body text-center p-4 position-relative">
                                        <span class="badge rounded-pill <?php echo $badgeClass; ?> stock-badge">
                                            Stock: <?php echo $product['stock']; ?>
                                        </span>
                                    
                                        <div class="mb-3 overflow-hidden rounded-circle mx-auto shadow-sm pos-product-image-wrapper">
                                            <?php if ($product['image_path']): ?>
                                                <img src="<?php echo htmlspecialchars($product['image_path']); ?>" 
                                                     class="img-fluid w-100 h-100 pos-product-image">
                                            <?php else: ?>
                                                <div class="w-100 h-100 bg-light d-flex align-items-center justify-content-center text-muted">
                                                    <i class="fas fa-box fa-2x"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <h5 class="card-title text-dark fw-bold mb-1 pos-product-title"><?php echo htmlspecialchars($product['name']); ?></h5>
                                        <p class="card-text text-primary fs-5 fw-bold mb-0">$<?php echo number_format($product['price'], 2); ?></p>
                                    </div>
                                    <?php if (!$hasStock): ?>
                                        <div class="card-footer bg-danger text-white text-center py-1 border-0 pos-out-of-stock">Out of Stock</div>
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
                        <h5 class="mb-0 text-secondary fw-bold pos-section-title"><i class="fas fa-shopping-cart me-2 text-primary"></i>Current Order</h5>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1 cart-clear-button" id="clearCartBtn">
                                <i class="fas fa-times me-1"></i>Clear
                            </button>
                            <span class="badge primary-bg primary-text rounded-pill px-2 py-1 cart-count-badge" id="cartCount">0 Items</span>
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
                            <div class="mb-3 supplier-form-hidden" id="formSupplierGroup">
                                <label for="supplierSelect" class="form-label fw-bold mb-1 text-secondary"><i class="fas fa-truck me-1 text-success"></i> Supplier</label>
                                <select class="form-select rounded-3" name="supplier_id" id="supplierSelect">
                                    <?php foreach ($suppliers as $supp): ?>
                                        <option value="<?php echo $supp['id']; ?>"><?php echo htmlspecialchars($supp['name'] . ($supp['phone'] ? ' - ' . $supp['phone'] : '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="button" class="btn btn-success w-100 py-3 fs-5 fw-bold shadow-sm" id="completeOrderBtn" disabled>
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
<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
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
                    document.getElementById('formCustomerGroup').classList.remove('supplier-form-hidden');
                    document.getElementById('formSupplierGroup').classList.add('supplier-form-hidden');
                } else {
                    document.getElementById('formCustomerGroup').classList.add('supplier-form-hidden');
                    document.getElementById('formSupplierGroup').classList.remove('supplier-form-hidden');
                }
                
                // Clear cart when transaction type changes to prevent stock validation confusion
                cart = [];
                renderCart();
            });
        });

        document.querySelectorAll('.product-card[data-product-id]').forEach(card => {
            card.addEventListener('click', function() {
                addToCart({
                    id: parseInt(card.dataset.productId, 10),
                    name: card.dataset.productName,
                    price: parseFloat(card.dataset.productPrice),
                    stock: parseInt(card.dataset.productStock, 10)
                });
            });
        });

        document.querySelectorAll('.category-pill').forEach(pill => {
            pill.addEventListener('click', function() {
                selectCategory(pill, pill.dataset.categoryId);
            });
        });

        const searchProduct = document.getElementById('searchProduct');
        if (searchProduct) {
            searchProduct.addEventListener('input', filterProducts);
        }

        const clearCartButton = document.getElementById('clearCartBtn');
        if (clearCartButton) {
            clearCartButton.addEventListener('click', clearCart);
        }

        const completeOrderButton = document.getElementById('completeOrderBtn');
        if (completeOrderButton) {
            completeOrderButton.addEventListener('click', submitOrder);
        }

        // Barcode Scanner Listener
        const barcodeInput = document.getElementById('barcodeInput');
        if (barcodeInput) {
            barcodeInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault(); // Prevent form submission
                    const scannedCode = this.value.trim();
                    if (scannedCode !== '') {
                        handleBarcodeScan(scannedCode);
                    }
                    this.value = ''; // Clear input for next scan
                }
            });
            
            // Keep focus on barcode scanner if user clicks outside but not on another input
            document.addEventListener('click', function(e) {
                if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'SELECT' && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'BUTTON') {
                    barcodeInput.focus();
                }
            });
        }
    });

    function handleBarcodeScan(barcode) {
        // Search all products for matching barcode
        // Product data is carried by escaped DOM data attributes and read by the card listener.
        const productItems = document.querySelectorAll('.product-item');
        let found = false;
        
        productItems.forEach(item => {
            if (item.dataset.barcode === barcode) {
                // Trigger the click event on the card if it's not disabled
                const card = item.querySelector('.card:not(.disabled)');
                if (card) {
                    card.click();
                    found = true;
                    // Optional: play a subtle beep
                    playBeep();
                } else {
                    // Out of stock
                    Swal.fire({
                        icon: 'error',
                        title: 'Out of Stock',
                        text: 'This product is scanned but currently out of stock!',
                        confirmButtonColor: '#198754'
                    });
                    found = true;
                }
            }
        });
        
        if (!found) {
            Swal.fire({
                icon: 'warning',
                title: 'Not Found',
                text: 'No product matches this barcode: ' + barcode,
                timer: 2000,
                showConfirmButton: false
            });
        }
    }
    
    function playBeep() {
        // Create a short beep sound using AudioContext
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gainNode = ctx.createGain();
            
            osc.type = 'sine';
            osc.frequency.setValueAtTime(800, ctx.currentTime); // Frequency in Hz
            
            gainNode.gain.setValueAtTime(0.1, ctx.currentTime); // Volume
            gainNode.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + 0.1);
            
            osc.connect(gainNode);
            gainNode.connect(ctx.destination);
            
            osc.start();
            osc.stop(ctx.currentTime + 0.1);
        } catch(e) {
            console.log("Audio not supported");
        }
    }

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

        tbody.replaceChildren();

        let total = 0;
        let itemCount = 0;

        if (cart.length === 0) {
            const emptyRow = document.createElement('tr');
            emptyRow.className = 'text-center text-muted';
            emptyRow.id = 'emptyCartMsg';
            const emptyCell = document.createElement('td');
            emptyCell.className = 'py-5';
            const emptyIcon = document.createElement('i');
            emptyIcon.className = 'fas fa-shopping-basket fa-3x mb-3 text-secondary opacity-50';
            const emptyText = document.createElement('p');
            emptyText.className = 'small mb-0';
            emptyText.textContent = 'Select products from the left to start an order.';
            emptyCell.append(emptyIcon, emptyText);
            emptyRow.append(emptyCell);
            tbody.append(emptyRow);
        } else {
            cart.forEach((item, index) => {
                const lineTotal = item.price * item.qty;
                total += lineTotal;
                itemCount += item.qty;

                const row = document.createElement('tr');
                row.className = 'cart-item-row';

                const productCell = document.createElement('td');
                const productName = document.createElement('div');
                productName.className = 'fw-bold text-dark mb-0';
                productName.textContent = item.name;
                const productPrice = document.createElement('small');
                productPrice.className = 'text-muted';
                productPrice.textContent = '$' + item.price.toFixed(2);
                productCell.append(productName, productPrice);

                const quantityCell = document.createElement('td');
                quantityCell.width = 100;
                const quantityControls = document.createElement('div');
                quantityControls.className = 'd-flex align-items-center justify-content-center';

                const decreaseButton = document.createElement('button');
                decreaseButton.type = 'button';
                decreaseButton.className = 'cart-qty-btn';
                decreaseButton.textContent = '-';
                decreaseButton.addEventListener('click', function() {
                    updateQty(index, -1);
                });

                const quantityInput = document.createElement('input');
                quantityInput.type = 'text';
                quantityInput.className = 'cart-qty-input';
                quantityInput.value = String(item.qty);
                quantityInput.readOnly = true;

                const increaseButton = document.createElement('button');
                increaseButton.type = 'button';
                increaseButton.className = 'cart-qty-btn';
                increaseButton.textContent = '+';
                increaseButton.addEventListener('click', function() {
                    updateQty(index, 1);
                });

                quantityControls.append(decreaseButton, quantityInput, increaseButton);
                quantityCell.append(quantityControls);

                const totalCell = document.createElement('td');
                totalCell.className = 'text-end fw-bold text-dark';
                totalCell.textContent = '$' + lineTotal.toFixed(2);

                const removeCell = document.createElement('td');
                removeCell.className = 'text-end';
                removeCell.width = 40;
                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'btn btn-sm text-danger p-0';
                removeButton.setAttribute('aria-label', 'Remove ' + item.name + ' from cart');
                const removeIcon = document.createElement('i');
                removeIcon.className = 'fas fa-trash-alt';
                removeButton.append(removeIcon);
                removeButton.addEventListener('click', function() {
                    removeFromCart(index);
                });
                removeCell.append(removeButton);

                row.append(productCell, quantityCell, totalCell, removeCell);
                tbody.append(row);
            });
        }

        const formattedTotal = '$' + total.toFixed(2);
        subtotalEl.textContent = formattedTotal;
        totalEl.textContent = formattedTotal;
        countEl.textContent = itemCount + ' Items';
        dataInput.value = JSON.stringify(cart);

        // Toggle clear cart button & complete button state
        const clearBtn = document.getElementById('clearCartBtn');
        const completeBtn = document.getElementById('completeOrderBtn');
        if (cart.length > 0) {
            clearBtn.classList.add('cart-clear-visible');
            completeBtn.classList.add('pulse-btn');
            completeBtn.disabled = false;
        } else {
            clearBtn.classList.remove('cart-clear-visible');
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
                item.classList.remove('product-filter-hidden');
            } else {
                item.classList.add('product-filter-hidden');
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
<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
    const completedOrderId = Number.parseInt(document.body.dataset.completedOrderId || '0', 10);
    window.addEventListener('load', function() {
        Swal.fire({
            icon: 'success',
            title: 'Order Completed!',
            text: document.body.dataset.feedbackSuccess,
            showCancelButton: true,
            confirmButtonColor: '#6366f1',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-print me-1"></i> Print Receipt',
            cancelButtonText: '<i class="fas fa-plus-circle me-1"></i> New Order',
            allowOutsideClick: false
        }).then((result) => {
            if (result.isConfirmed) {
                // Open print window
                window.open('print_invoice.php?id=' + encodeURIComponent(String(completedOrderId)), '_blank');
                // Reload current page for new order
                window.location.href = 'orders.php';
            } else {
                window.location.href = 'orders.php';
            }
        });
    });
</script>
<?php endif; ?>
<?php if (!empty($error)): ?>
<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
    window.addEventListener('load', function() {
        Swal.fire({
            icon: 'error',
            title: 'Order Failed',
            text: document.body.dataset.feedbackError,
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
    <script type="application/json" id="restock-product-data" nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>"><?php echo json_encode([
        'id' => intval($restock_prod['id']),
        'name' => $restock_prod['name'],
        'price' => floatval($restock_prod['price']),
        'stock' => intval($restock_prod['stock'])
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
        window.addEventListener('load', function() {
            // Trigger purchase type selection
            const typePurchaseRadio = document.getElementById('typePurchase');
            if (typePurchaseRadio) {
                typePurchaseRadio.checked = true;
                // Dispatch change event to update JS variables
                typePurchaseRadio.dispatchEvent(new Event('change'));
            }
            // Add product to cart
            const productDataElement = document.getElementById('restock-product-data');
            const productObj = productDataElement
                ? JSON.parse(productDataElement.textContent || '{}')
                : null;
            if (!productObj) {
                return;
            }
            addToCart(productObj);
        });
    </script>
    <?php endif; ?>
<?php endif; ?>
<?php
require_once '../includes/layouts/footer.php';
?>
