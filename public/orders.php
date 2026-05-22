<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

verify_login();

$success = '';
$error = '';

// Handle Order Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_order'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security check failed. Invalid request token.";
    } else {
        $cart_json = $_POST['cart_data'] ?? '[]';
        $cart_items = json_decode($cart_json, true);
        $order_type = $_POST['order_type'] ?? 'sale';

        if (empty($cart_items) || !is_array($cart_items)) {
            $error = "Cart is empty. Please add products.";
        } else {
            $order_items = [];
            $valid_order = true;

            foreach ($cart_items as $item) {
                $prod = get_product_by_id($conn, $item['id']);
                
                // Stock validation only for sales
                if ($order_type === 'sale' && (!$prod || $prod['stock'] < $item['qty'])) {
                    $error = "Insufficient stock for product: " . htmlspecialchars($item['name']);
                    $valid_order = false;
                    break;
                }

                $order_items[] = [
                    'product_id' => $item['id'],
                    'quantity' => intval($item['qty']),
                    'unit_price' => floatval($item['price']),
                    'subtotal' => floatval($item['price'] * $item['qty'])
                ];
            }

            if ($valid_order) {
                $order_id = create_order($conn, $_SESSION['staff_id'], $order_items, $order_type);
                if ($order_id) {
                    $success = "Order #$order_id completed successfully!";
                } else {
                    $error = "Failed to process order transaction.";
                }
            }
        }
    }
}

$products = get_products($conn);
$categories = get_categories($conn);

$page_title = 'POS System';
$active_page = 'orders';
$header_title = 'POS Terminal';
$extra_css = ['https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.min.css'];

require_once '../includes/layouts/header.php';
?>
<style>
    .pos-container { height: calc(100vh - 80px); overflow: hidden; }
    .product-grid-container { height: 100%; overflow-y: auto; padding-right: 10px; }
    .cart-panel { height: 100%; display: flex; flex-direction: column; }
    .cart-items-container { flex-grow: 1; overflow-y: auto; }
    .product-card { 
        cursor: pointer; 
        transition: transform 0.2s, box-shadow 0.2s; 
    }
    .product-card:hover { 
        transform: translateY(-3px); 
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important; 
    }
    .product-card.disabled { 
        opacity: 0.6; cursor: not-allowed; 
    }
    .stock-badge { top: 10px; right: 10px; position: absolute; }
</style>

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
                        <div class="input-group input-group-lg shadow-sm">
                            <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text" id="searchProduct" class="form-control border-0" placeholder="Search products by name..." onkeyup="filterProducts()">
                        </div>
                    </div>
                </div>

                <!-- Category Navigation Pills -->
                <div class="mb-3 d-flex overflow-x-auto pb-2" style="gap: 8px; scrollbar-width: thin; -ms-overflow-style: none;">
                    <button class="btn btn-sm btn-primary category-pill rounded-pill px-3 fw-bold" data-category-id="all" onclick="selectCategory(this, 'all')">
                        All (الكل)
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
                                <div class="card h-100 border-0 shadow-sm <?php echo $cardClass; ?>" 
                                     <?php if ($hasStock): ?>
                                         onclick='addToCart(<?php echo json_encode($product); ?>)'
                                     <?php endif; ?>>
                                
                                    <div class="card-body text-center p-4 position-relative">
                                        <span class="badge rounded-pill <?php echo $badgeClass; ?> stock-badge">
                                            Stock: <?php echo $product['stock']; ?>
                                        </span>
                                    
                                        <div class="mb-3">
                                            <?php if ($product['image_path']): ?>
                                                <img src="<?php echo htmlspecialchars($product['image_path']); ?>" 
                                                     class="rounded-circle shadow-sm" style="width: 80px; height: 80px; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center mx-auto shadow-sm text-muted" 
                                                     style="width: 80px; height: 80px;">
                                                    <i class="fas fa-box fa-2x"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <h5 class="card-title text-dark fw-bold mb-1"><?php echo htmlspecialchars($product['name']); ?></h5>
                                        <p class="card-text text-primary fs-5 fw-bold mb-0">$<?php echo number_format($product['price'], 2); ?></p>
                                    </div>
                                    <?php if (!$hasStock): ?>
                                        <div class="card-footer bg-danger text-white text-center py-1">Out of Stock</div>
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
                        <h5 class="mb-0 text-secondary fw-bold"><i class="fas fa-shopping-cart me-2"></i>Current Order</h5>
                        <span class="badge bg-primary rounded-pill" id="cartCount">0 Items</span>
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
                            <button type="button" class="btn btn-success w-100 py-3 fs-5 fw-bold shadow-sm" onclick="submitOrder()">
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
                        <p>Select products from the left to start an order.</p>
                    </td>
                </tr>`;
        } else {
            cart.forEach((item, index) => {
                const lineTotal = item.price * item.qty;
                total += lineTotal;
                itemCount += item.qty;

                tbody.innerHTML += `
                    <tr>
                        <td>
                            <div class="fw-bold">${item.name}</div>
                            <small class="text-muted">$${item.price.toFixed(2)}</small>
                        </td>
                        <td width="120">
                            <div class="input-group input-group-sm">
                                <button class="btn btn-outline-secondary" onclick="updateQty(${index}, -1)">-</button>
                                <input type="text" class="form-control text-center bg-white" value="${item.qty}" readonly>
                                <button class="btn btn-outline-secondary" onclick="updateQty(${index}, 1)">+</button>
                            </div>
                        </td>
                        <td class="text-end fw-bold">$${lineTotal.toFixed(2)}</td>
                        <td class="text-end" width="40">
                            <button class="btn btn-sm text-danger" onclick="removeFromCart(${index})">
                                <i class="fas fa-times"></i>
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