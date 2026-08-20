<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the documented architecture seams until an intentional extraction
 * batch updates both the map and its tests. These are source-contract checks;
 * they do not open a database, start a server, or change application behavior.
 */
function run_architecture_baseline_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);

    $facade = file_get_contents($repository . '/includes/functions.php');
    $catalog = file_get_contents($repository . '/includes/catalog.php');
    $security = file_get_contents($repository . '/includes/security.php');
    $orders = file_get_contents($repository . '/public/orders.php');
    $categories = file_get_contents($repository . '/public/categories.php');
    $products = file_get_contents($repository . '/public/products.php');
    $backup = file_get_contents($repository . '/public/backup_database.php');
    $export = file_get_contents($repository . '/public/export_report.php');
    $ready = file_get_contents($repository . '/public/ready.php');
    $databaseConfig = file_get_contents($repository . '/config/db.php');

    foreach ([
        $facade,
        $catalog,
        $security,
        $orders,
        $categories,
        $products,
        $backup,
        $export,
        $ready,
        $databaseConfig,
    ] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Architecture baseline source fixture could not be read.');
    }

    foreach ([
        'public/login.php',
        'public/index.php',
        'public/products.php',
        'public/categories.php',
        'public/stock_movements.php',
        'public/orders.php',
        'public/order_history.php',
        'public/get_order_details.php',
        'public/pos_product_lookup.php',
        'public/customers.php',
        'public/suppliers.php',
        'public/audit_log.php',
        'public/export_report.php',
        'public/print_invoice.php',
        'public/settings.php',
        'public/backup_database.php',
        'public/health.php',
        'public/ready.php',
    ] as $route) {
        $tests->assertTrue(is_file($repository . '/' . $route), 'Documented public route is missing: ' . $route);
    }

    foreach (['security.php', 'pagination.php', 'audit.php', 'catalog.php'] as $module) {
        $tests->assertContains(
            "require_once __DIR__ . '/{$module}'",
            $facade,
            'Compatibility facade no longer loads documented shared module: ' . $module
        );
    }

    foreach ([
        'function verify_login',
        'function get_products_page',
        'function get_pos_products',
        'function create_order',
        'function handle_image_upload',
        'function create_staff_member',
        'function create_category',
        'function create_customer',
        'function create_supplier',
    ] as $functionContract) {
        $tests->assertContains($functionContract, $facade, 'Legacy compatibility function disappeared: ' . $functionContract);
    }

    foreach (['catalog_get_products_page', 'catalog_count_products', 'catalog_get_categories_for_selector', 'handle_image_upload', 'delete_product'] as $productFunction) {
        $tests->assertContains($productFunction . '(', $products, 'Products page dependency contract changed: ' . $productFunction);
    }
    foreach (['catalog_get_pos_products', 'catalog_get_categories_for_selector', 'catalog_get_product_by_id', 'create_order'] as $orderFunction) {
        $tests->assertContains($orderFunction . '(', $orders, 'Orders page dependency contract changed: ' . $orderFunction);
    }
    foreach (['catalog_count_categories', 'catalog_get_categories_page', 'create_category', 'update_category', 'delete_category'] as $categoryFunction) {
        $tests->assertContains($categoryFunction . '(', $categories, 'Categories page dependency contract changed: ' . $categoryFunction);
    }
    $tests->assertContains('catalog_get_pos_product_by_barcode(', file_get_contents($repository . '/public/pos_product_lookup.php'), 'Barcode endpoint dependency contract changed.');
    $tests->assertContains('stream_database_backup(', $backup, 'Backup endpoint must retain the streaming service boundary.');
    $tests->assertContains('export_stream_entity(', $export, 'Export endpoint must retain the streaming service boundary.');

    $tests->assertContains('global $conn', $facade, 'The current global database dependency must remain documented before extraction.');
    $tests->assertContains('$GLOBALS[\'current_staff_record\']', $facade, 'The current authentication global must remain documented before extraction.');
    $tests->assertContains('$_SESSION', $security, 'Session state ownership must remain in the security module.');

    foreach (['begin_transaction', 'FOR UPDATE', 'commit', 'rollback'] as $orderInvariant) {
        $tests->assertContains($orderInvariant, $facade, 'Order transaction invariant disappeared from the compatibility facade: ' . $orderInvariant);
    }

    $tests->assertContains('SELECT 1', $ready, 'Readiness must retain a database probe.');
    $tests->assertContains('http_response_code(503)', $ready, 'Readiness must retain its unavailable status contract.');
    $tests->assertContains('exit(\'{"status":"not_ready","check":"database"}\')', $databaseConfig, 'Database failure JSON contract changed.');

    foreach ([
        'docs/architecture/BASELINE.md',
        'docs/architecture/DEPENDENCY-MAP.md',
        'docs/architecture/RESPONSE-CONTRACTS.md',
        'docs/architecture/REFACTORING-CONTRACT.md',
    ] as $document) {
        $tests->assertTrue(is_file($repository . '/' . $document), 'Architecture baseline document is missing: ' . $document);
    }

    return $tests->assertions();
}
