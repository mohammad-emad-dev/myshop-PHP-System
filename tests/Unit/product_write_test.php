<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the product-create service boundary while the public page remains
 * on the legacy compatibility wrapper.
 */
function run_product_write_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $modulePath = $repository . '/includes/products.php';
    $facadePath = $repository . '/includes/functions.php';
    $pagePath = $repository . '/public/products.php';
    $module = is_file($modulePath) ? file_get_contents($modulePath) : null;
    $facade = is_file($facadePath) ? file_get_contents($facadePath) : null;
    $page = is_file($pagePath) ? file_get_contents($pagePath) : null;

    foreach ([$module, $facade, $page] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Product creation source fixture could not be read.');
    }

    $tests->assertContains('declare(strict_types=1);', $module, 'Product module must use strict typing.');
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Product module must not require the compatibility facade.'
    );
    foreach ([
        "require_once __DIR__ . '/inventory.php';",
        "require_once __DIR__ . '/audit.php';",
        'function products_create($conn, $staff_id, $name, $description, $price, $stock, $image_path = null, $alert_threshold = 10, $category_id = null, $barcode = null): bool',
        '$conn->begin_transaction()',
        "SELECT id FROM Category WHERE name = 'General' LIMIT 1",
        'empty(trim((string)$barcode))',
        'INSERT INTO Product',
        '$stmt->bind_param("ssdisiis"',
        'inventory_log_stock_movement($conn, $product_id, $staff_id, $stock, \'manual_adjustment\', \'Initial stock allocation\')',
        'audit_log($conn, $staff_id, \'product_create\', \'Product\', $product_id, true',
        '$conn->commit()',
        '$conn->rollback()',
        'inventory_rollback_error($conn)',
        'audit_log($conn, $staff_id, \'product_create\', \'Product\', null, false',
        'return true;',
        'return false;',
    ] as $contract) {
        $tests->assertContains($contract, $module, 'Product creation contract is missing: ' . $contract);
    }
    $tests->assertFalse(strpos($module, '$_SESSION') !== false, 'Product module must not read session state.');
    $tests->assertFalse(strpos($module, '$GLOBALS') !== false, 'Product module must not read global state.');

    $wrapperPattern = '/function create_product\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
    $wrapperMatched = preg_match($wrapperPattern, $facade, $matches) === 1;
    $tests->assertTrue($wrapperMatched, 'create_product compatibility wrapper is missing.');
    if ($wrapperMatched) {
        $tests->assertContains(
            'function create_product($conn, $staff_id, $name, $description, $price, $stock, $image_path = null, $alert_threshold = 10, $category_id = null, $barcode = null)',
            $facade,
            'create_product compatibility signature changed.'
        );
        $tests->assertContains('products_create(', $matches['body'], 'create_product does not delegate to products_create.');
        foreach (['begin_transaction', 'INSERT INTO Product', 'inventory_log_stock_movement', 'audit_log', 'commit', 'rollback'] as $implementationDetail) {
            $tests->assertFalse(
                strpos($matches['body'], $implementationDetail) !== false,
                'create_product wrapper still contains implementation detail: ' . $implementationDetail
            );
        }
    }

    $tests->assertContains('create_product($conn,', $page, 'Product page must retain the compatibility wrapper caller.');
    $tests->assertFalse(strpos($page, 'products_create(') !== false, 'Product page must not call the new service directly yet.');

    $tests->assertTrue(function_exists('products_create'), 'Product creation service is unavailable.');
    if (function_exists('products_create')) {
        $closedConnection = mysqli_init();
        $result = true;
        $escaped = false;
        try {
            $closedConnection->close();
            $result = products_create($closedConnection, 1, 'closed-connection', '', 1.00, 1);
        } catch (Throwable $exception) {
            $escaped = true;
        }
        $tests->assertFalse($escaped, 'Invalid product creation connections must fail without escaping an exception.');
        $tests->assertFalse($result, 'Invalid product creation connections must return false.');
    }

    return $tests->assertions();
}
