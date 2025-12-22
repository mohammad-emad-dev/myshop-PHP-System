<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/functions.php';

verify_login();

$success = '';
$error = '';

// Handle Order Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_order'])) {

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

$products = get_products($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS System - myshop</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/style.css">
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
</head>
<body class="bg-light">

    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <div class="bg-dark text-white border-end" id="sidebar-wrapper">
            <div class="sidebar-heading text-center py-4 primary-text fs-4 fw-bold text-uppercase border-bottom border-secondary">
                <i class="fas fa-cubes me-2"></i>myshop
            </div>
            <div class="list-group list-group-flush my-3">
                <a href="index.php" class="list-group-item list-group-item-action bg-transparent text-white fw-bold">
                    <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                </a>
                <a href="products.php" class="list-group-item list-group-item-action bg-transparent text-white fw-bold">
                    <i class="fas fa-box-open me-2"></i>Products
                </a>
                <a href="orders.php" class="list-group-item list-group-item-action bg-transparent text-primary fw-bold active">
                    <i class="fas fa-shopping-cart me-2"></i>Orders (POS)
                </a>
                <a href="login.php?logout=1" class="list-group-item list-group-item-action bg-transparent text-danger fw-bold mt-5"
                   onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-power-off me-2"></i>Logout
                </a>
            </div>
        </div>
        <!-- /#sidebar-wrapper -->

        <!-- Page Content -->
        <div id="page-content-wrapper">
            <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm py-4 px-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-align-left primary-text fs-4 me-3" id="menu-toggle"></i>
                    <h2 class="fs-2 m-0 text-dark">POS Terminal</h2>
                </div>

                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <div class="ms-auto d-flex align-items-center">
                        <?php if ($success): ?>
                                <div class="alert alert-success alert-dismissible fade show m-0 py-1 px-3 me-3">
                                    <i class="fas fa-check-circle me-1"></i> <?php echo $success; ?>
                                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                                </div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                                <div class="alert alert-danger alert-dismissible fade show m-0 py-1 px-3 me-3">
                                    <i class="fas fa-exclamation-circle me-1"></i> <?php echo $error; ?>
                                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                                </div>
                        <?php endif; ?>
                        <span class="fw-bold text-secondary">Cashier: <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
                    </div>
                </div>
            </nav>

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

                        <!-- Product Grid -->
                        <div class="product-grid-container" id="productGrid">
                            <div class="row g-3">
                                <?php foreach ($products as $product): ?>
                                        <?php
                                        $hasStock = $product['stock'] > 0;
                                        $cardClass = $hasStock ? 'product-card' : 'product-card disabled';
                                        $badgeClass = $product['stock'] <= 5 ? 'bg-danger' : 'bg-success';
                                        ?>
                                        <div class="col-md-4 col-sm-6 product-item" data-name="<?php echo strtolower($product['name']); ?>">
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
                                        <!-- Javascript will populate this -->
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
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js"></script>
    <script>
        var el = document.getElementById("wrapper");
        var toggleButton = document.getElementById("menu-toggle");

        toggleButton.onclick = function () {
            el.classList.toggle("toggled");
        };

        // --- POS Logic ---
        let cart = [];
        let orderType = 'sale'; // Default to sale

        // Update order type when radio buttons change
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[name="orderType"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    orderType = this.value;
                    document.getElementById('orderTypeInput').value = orderType;
                });
            });
        });

        function addToCart(product) {
            // For sales, check stock availability
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
                // Only check stock limit for sales
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

            // Only check stock limit for sales
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

        function filterProducts() {
            const query = document.getElementById('searchProduct').value.toLowerCase();
            const items = document.querySelectorAll('.product-item');

            items.forEach(item => {
                const name = item.dataset.name;
                if (name.includes(query)) {
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
</body>
</html>