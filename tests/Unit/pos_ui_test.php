<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterizes the Phase 6C POS visual boundary before the orders page
 * migration. Existing IDs, names, data attributes, and inline behavior are
 * asserted so the visual redesign cannot silently change cashier contracts.
 */
function run_pos_ui_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $orders = file_get_contents($repository . '/public/orders.php');
    $stylesheet = file_get_contents($repository . '/public/assets/css/style.css');
    $browserTests = file_get_contents($repository . '/e2e/tests/critical-journeys.spec.js');

    foreach ([$orders, $stylesheet, $browserTests] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'POS Phase 6C source fixture could not be read.');
    }

    foreach ([
        'pos-page',
        'pos-page-header',
        'pos-page-kicker',
        'pos-page-context',
        'pos-workbench',
        'pos-catalog-zone',
        'pos-checkout-zone',
        'pos-barcode-panel',
        'pos-search-panel',
        'pos-category-bar',
        'pos-product-grid',
        'pos-product-card',
        'pos-product-stock',
        'pos-checkout-panel',
        'pos-cart-table',
        'pos-checkout-summary',
        'pos-order-controls',
        'pos-complete-button',
        'product-empty-state',
    ] as $newContract) {
        $tests->assertContains($newContract, $orders, 'POS Phase 6C structure is missing: ' . $newContract);
    }

    foreach ([
        'id="barcodeInput"',
        'id="searchProduct"',
        'name="product_search"',
        'id="productGrid"',
        'data-category-id=',
        'data-name=',
        'data-barcode=',
        'data-product-id=',
        'data-product-name=',
        'data-product-price=',
        'data-product-stock=',
        'id="clearCartBtn"',
        'id="cartCount"',
        'id="cartTableBody"',
        'id="emptyCartMsg"',
        'id="cartSubtotal"',
        'id="cartTotal"',
        'id="orderForm"',
        'name="csrf_token"',
        'name="cart_data"',
        'name="complete_order"',
        'name="order_type"',
        'name="customer_id"',
        'name="supplier_id"',
        'id="typeSale"',
        'id="typePurchase"',
        'id="formCustomerGroup"',
        'id="formSupplierGroup"',
        'id="customerSelect"',
        'id="supplierSelect"',
        'id="completeOrderBtn"',
    ] as $behaviorContract) {
        $tests->assertContains($behaviorContract, $orders, 'POS DOM contract disappeared: ' . $behaviorContract);
    }

    foreach ([
        'let cart = [];',
        "let orderType = 'sale';",
        "document.querySelectorAll('input[name=\"orderType\"]')",
        "document.querySelectorAll('.product-card[data-product-id]')",
        "document.querySelectorAll('.category-pill')",
        "document.getElementById('searchProduct')",
        "document.getElementById('barcodeInput')",
        'handleBarcodeScan(',
        'pos_product_lookup.php?barcode=',
        'addToCart(',
        'updateQty(',
        'removeFromCart(',
        'renderCart(',
        'filterProducts(',
        'submitOrder(',
        'productEmptyState',
        "keydown",
    ] as $scriptContract) {
        $tests->assertContains($scriptContract, $orders, 'POS JavaScript contract disappeared: ' . $scriptContract);
    }

    foreach ([
        '.pos-page',
        '.pos-workbench',
        '.pos-catalog-zone',
        '.pos-checkout-zone',
        '.pos-barcode-panel',
        '.pos-product-card',
        '.pos-product-stock',
        '.pos-checkout-panel',
        '.pos-cart-table',
        '.pos-checkout-summary',
        '.pos-empty-state',
        'var(--color-surface)',
        'var(--color-border)',
        'var(--color-brand-600)',
        'var(--focus-ring)',
        '@media (max-width: 991.98px)',
        '@media (max-width: 767.98px)',
        '@media (max-width: 575.98px)',
        '@media (prefers-reduced-motion: reduce)',
    ] as $stylesheetContract) {
        $tests->assertContains($stylesheetContract, $stylesheet, 'POS Phase 6C stylesheet contract is missing: ' . $stylesheetContract);
    }

    $phase6cStart = strpos($stylesheet, '/* Phase 6C: POS / Orders visual migration */');
    $tests->assertTrue($phase6cStart !== false, 'POS Phase 6C stylesheet boundary marker is missing.');
    if ($phase6cStart !== false) {
        $phase6cStyles = substr($stylesheet, $phase6cStart);
        $tests->assertFalse(
            strpos($phase6cStyles, 'linear-gradient') !== false,
            'POS Phase 6C must not reintroduce decorative gradients.'
        );
    }

    foreach ([
        'height: calc(100vh - 90px);',
        '.category-pill.btn-primary, .category-pill.btn-primary:hover',
        'transform: translateY(-6px) !important;',
        '.product-card:hover img { transform: scale(1.1); }',
    ] as $retiredStyle) {
        $tests->assertSame(0, substr_count($stylesheet, $retiredStyle), 'Legacy POS visual rule remains: ' . $retiredStyle);
    }

    foreach ([
        'catalog_get_pos_products($conn, $pos_search, 100)',
        'catalog_get_categories_for_selector($conn, 100)',
        'people_get_customers_for_selector($conn, 100)',
        'people_get_suppliers_for_selector($conn, 100)',
        'auth_verify_login($conn)',
        'elseif ($order_type === \'purchase\' && !auth_is_admin($conn))',
        'verify_csrf_token($csrf_token)',
        'orders_create($conn, $_SESSION[\'staff_id\'], $order_items, $order_type, $customer_id, $supplier_id)',
    ] as $serverContract) {
        $tests->assertContains($serverContract, $orders, 'POS server contract disappeared: ' . $serverContract);
    }

    foreach ([
        'cashier-orders-cart',
        "page.locator('#typePurchase')).toHaveCount(0)",
        '#barcodeInput',
        '#searchProduct',
        '#completeOrderBtn',
    ] as $browserContract) {
        $tests->assertContains($browserContract, $browserTests, 'POS browser contract is missing: ' . $browserContract);
    }

    return $tests->assertions();
}
