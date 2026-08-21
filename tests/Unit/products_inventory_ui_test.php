<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterizes the Phase 6D dense-data visual boundary before the Products
 * and Stock Ledger pages are migrated. The existing page contracts remain
 * asserted alongside the new shared presentation ownership markers.
 */
function run_products_inventory_ui_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $products = file_get_contents($repository . '/public/products.php');
    $inventory = file_get_contents($repository . '/public/stock_movements.php');
    $stylesheet = file_get_contents($repository . '/public/assets/css/style.css');
    $browserTests = file_get_contents($repository . '/e2e/tests/critical-journeys.spec.js');

    foreach ([$products, $inventory, $stylesheet, $browserTests] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Phase 6D source fixture could not be read.');
    }

    foreach ([
        'products-page',
        'data-page-header',
        'data-page-kicker',
        'data-page-context',
        'data-page-actions',
        'data-surface',
        'data-toolbar',
        'data-table-shell',
        'data-table',
        'data-pagination',
        'data-empty-state',
        'data-image-thumb',
        'data-action-group',
        'data-modal',
    ] as $sharedContract) {
        $tests->assertContains($sharedContract, $products, 'Products page is missing shared Phase 6D contract: ' . $sharedContract);
    }

    foreach ([
        'inventory-page',
        'data-page-header',
        'data-page-kicker',
        'data-page-context',
        'data-page-actions',
        'data-surface',
        'data-toolbar',
        'data-table-shell',
        'data-table',
        'data-pagination',
        'data-empty-state',
        'data-modal',
    ] as $sharedContract) {
        $tests->assertContains($sharedContract, $inventory, 'Stock Ledger is missing shared Phase 6D contract: ' . $sharedContract);
    }

    foreach ([
        'product-row',
        'product-status',
        'product-image-cell',
        'product-actions',
        'inventory-filter',
        'movement-quantity',
        'movement-type',
        'movement-reason',
        'movement-staff',
        'movement-date',
    ] as $operationalContract) {
        $tests->assertContains($operationalContract, $products . $inventory, 'Phase 6D operational contract is missing: ' . $operationalContract);
    }

    foreach ([
        'id="searchProduct"',
        'name="search"',
        'id="pageSize"',
        'id="productsTable"',
        'data-bs-target="#addProductModal"',
        'id="addProductModal"',
        'id="editProductModal"',
        'edit-product-btn',
        'data-product-id=',
        'data-product-name=',
        'data-product-category-id=',
        'data-product-barcode=',
        'data-product-description=',
        'data-product-price=',
        'data-product-stock=',
        'data-product-alert-threshold=',
        'enctype="multipart/form-data"',
        'name="image"',
        'name="action" value="delete"',
        'name="id"',
        'export_report.php?entity=products',
        'catalog_count_products($conn, $search, $filter)',
        'catalog_get_products_page($conn, $search, $filter, $page_size, $offset)',
    ] as $productBehaviorContract) {
        $tests->assertContains($productBehaviorContract, $products, 'Products behavior contract disappeared: ' . $productBehaviorContract);
    }

    foreach ([
        'id="product_id"',
        'name="product_id"',
        'id="ledgerPageSize"',
        'id="addMovementModal"',
        'id="adj_product_id"',
        'name="quantity"',
        'name="reason"',
        'name="action" value="adjust_stock"',
        'id="stockMovement',
        'inventory_count_stock_movements($conn, $selected_product_id)',
        'inventory_get_stock_movements_page($conn, $selected_product_id, $page_size, $offset)',
        'catalog_get_pos_products($conn, \'\', 100)',
        'export_report.php?entity=stock',
    ] as $inventoryBehaviorContract) {
        $tests->assertContains($inventoryBehaviorContract, $inventory, 'Stock Ledger behavior contract disappeared: ' . $inventoryBehaviorContract);
    }

    foreach ([
        'Phase 6D: Products and Inventory visual migration',
        '.data-surface',
        '.data-toolbar',
        '.data-table-shell',
        '.data-table',
        '.data-pagination',
        '.data-empty-state',
        '.data-image-thumb',
        '.data-modal',
        'var(--color-surface)',
        'var(--color-border)',
        'var(--focus-ring)',
        '@media (max-width: 767.98px)',
        '@media (max-width: 575.98px)',
        '@media (prefers-reduced-motion: reduce)',
    ] as $stylesheetContract) {
        $tests->assertContains($stylesheetContract, $stylesheet, 'Phase 6D stylesheet contract is missing: ' . $stylesheetContract);
    }

    $phase6dStart = strpos($stylesheet, '/* Phase 6D: Products and Inventory visual migration */');
    $tests->assertTrue($phase6dStart !== false, 'Phase 6D stylesheet boundary marker is missing.');
    if ($phase6dStart !== false) {
        $phase6dStyles = substr($stylesheet, $phase6dStart);
        $tests->assertFalse(
            strpos($phase6dStyles, 'linear-gradient') !== false,
            'Phase 6D data surfaces must not introduce decorative gradients.'
        );
    }

    foreach ([
        'admin-products',
        'admin-stock-ledger',
        'products.php?page_size=10',
        'stock_movements.php',
        'getByLabel(\'Search products\')',
        'getByLabel(\'Filter by Product\')',
        'addProductModal',
        'editProductModal',
        'product_id',
        'ledgerPageSize',
    ] as $browserContract) {
        $tests->assertContains($browserContract, $browserTests, 'Browser QA contract is missing: ' . $browserContract);
    }

    foreach ([
        'auth_verify_login($conn)',
        'verify_csrf_token($csrf_token)',
        'auth_is_admin($conn)',
        'uploads_handle_image($_FILES[\'image\'])',
        'uploads_delete_newly_uploaded_image($image_path)',
        'inventory_adjust_stock($conn',
    ] as $securityContract) {
        $tests->assertContains($securityContract, $products . $inventory, 'Security or mutation contract disappeared: ' . $securityContract);
    }

    return $tests->assertions();
}
