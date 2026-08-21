<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the Phase 5C read boundary before the disposable volume fixture is
 * enabled. These source contracts make the audit decisions executable: the
 * legacy complete-array loaders remain available, while interactive callers
 * use bounded services and exports retain their cursor-batched contract.
 */
function run_data_volume_readiness_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $facade = file_get_contents($repository . '/includes/functions.php');
    $catalog = file_get_contents($repository . '/includes/catalog.php');
    $inventory = file_get_contents($repository . '/includes/inventory.php');
    $orders = file_get_contents($repository . '/includes/orders.php');
    $people = file_get_contents($repository . '/includes/people.php');
    $dashboard = file_get_contents($repository . '/includes/dashboard.php');
    $export = file_get_contents($repository . '/includes/export.php');
    $runner = file_get_contents($repository . '/tests/run.php');
    $integration = file_get_contents($repository . '/tests/Integration/data_volume_test.php');

    foreach ([$facade, $catalog, $inventory, $orders, $people, $dashboard, $export, $runner, $integration] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Phase 5C source fixture could not be read.');
    }

    foreach ([
        'get_all_products($conn)',
        'get_stock_movements($conn, $product_id = null)',
        'get_orders($conn)',
        'get_orders_for_staff($conn, $staff_id)',
        'get_categories($conn)',
        'get_customers($conn)',
        'get_suppliers($conn)',
    ] as $legacyLoader) {
        $tests->assertContains('function ' . $legacyLoader, $facade, 'Audited compatibility loader changed: ' . $legacyLoader);
    }

    foreach ([
        ['catalog_get_pos_products', $catalog, 'LIMIT ?', 'ORDER BY p.created_at DESC, p.id DESC'],
        ['catalog_get_products_page', $catalog, 'LIMIT ? OFFSET ?', 'ORDER BY p.created_at DESC, p.id DESC'],
        ['catalog_get_categories_for_selector', $catalog, 'LIMIT ?', 'ORDER BY name ASC, id ASC'],
        ['catalog_get_categories_page', $catalog, 'LIMIT ? OFFSET ?', 'ORDER BY c.name ASC, c.id ASC'],
        ['inventory_get_stock_movements_page', $inventory, 'LIMIT ? OFFSET ?', 'ORDER BY sm.created_at DESC, sm.id DESC'],
        ['orders_get_page', $orders, 'LIMIT ? OFFSET ?', 'ORDER BY o.order_date DESC, o.id DESC'],
        ['people_get_customers_page', $people, 'LIMIT ? OFFSET ?', 'ORDER BY name ASC, id ASC'],
        ['people_get_customers_for_selector', $people, 'LIMIT ?', 'ORDER BY name ASC, id ASC'],
        ['people_get_suppliers_page', $people, 'LIMIT ? OFFSET ?', 'ORDER BY name ASC, id ASC'],
        ['people_get_suppliers_for_selector', $people, 'LIMIT ?', 'ORDER BY name ASC, id ASC'],
    ] as [$functionName, $source, $limitClause, $orderClause]) {
        $tests->assertContains('function ' . $functionName, $source, 'Bounded read service is missing: ' . $functionName);
        $tests->assertContains($limitClause, $source, 'Bounded read service lost its limit contract: ' . $functionName);
        $tests->assertContains($orderClause, $source, 'Bounded read service lost deterministic ordering: ' . $functionName);
    }

    foreach (['catalog_get_pos_products(', 'catalog_get_categories_for_selector('] as $selectorService) {
        $tests->assertContains($selectorService, $integration, 'Volume test fixture must cover selector service: ' . $selectorService);
    }
    foreach (['people_get_customers_page(', 'people_get_suppliers_page(', 'orders_get_page(', 'inventory_get_stock_movements_page('] as $pageService) {
        $tests->assertContains($pageService, $integration, 'Volume test fixture must cover page service: ' . $pageService);
    }

    foreach ([
        'public/products.php' => ['catalog_get_products_page(', 'get_products_page'],
        'public/categories.php' => ['catalog_get_categories_page(', 'get_categories_page'],
        'public/stock_movements.php' => ['inventory_get_stock_movements_page(', 'get_stock_movements_page'],
        'public/customers.php' => ['people_get_customers_page(', 'get_customers_page'],
        'public/suppliers.php' => ['people_get_suppliers_page(', 'get_suppliers_page'],
        'public/order_history.php' => ['orders_get_page(', 'get_orders_page'],
    ] as $page => [$focusedCall, $legacyFunction]) {
        $pageSource = file_get_contents($repository . '/' . $page);
        $tests->assertTrue(is_string($pageSource), 'Audited public page could not be read: ' . $page);
        $tests->assertContains($focusedCall, $pageSource, 'Public page is not owned by the focused read service: ' . $page);
        $tests->assertFalse(
            preg_match('/(?<![A-Za-z0-9_])' . preg_quote($legacyFunction, '/') . '\\s*\\(/', $pageSource) === 1,
            'Public page still calls the legacy read facade: ' . $page
        );
    }

    $tests->assertContains('export_stream_batches(', $export, 'Exports must retain cursor-batched streaming.');
    $tests->assertContains('LIMIT ?', $export, 'Export batches must retain a bound batch limit.');
    $tests->assertFalse(strpos($export, 'fetch_all(') !== false, 'Streaming exports must not regress to full-array reads.');
    $tests->assertContains('dashboard_get_top_selling_products', $dashboard, 'Dashboard top-selling report was not audited.');
    $tests->assertContains('dashboard_get_category_sales_distribution', $dashboard, 'Dashboard category report was not audited.');
    $tests->assertContains('LIMIT ?', $dashboard, 'Dashboard list reports must retain explicit limits.');

    $tests->assertContains('require_once __DIR__ . \'/Integration/data_volume_test.php\';', $runner, 'Disposable volume fixture is not registered.');
    $tests->assertContains('run_data_volume_integration_tests()', $runner, 'Disposable volume test is not executed.');
    $tests->assertTrue(
        is_file($repository . '/docs/architecture/PHASE-5C-LOCAL-PERFORMANCE-TDD.md'),
        'Phase 5C must add its current architecture document after the RED commit.'
    );

    return $tests->assertions();
}
