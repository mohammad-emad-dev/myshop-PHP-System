<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterize the focused order-creation boundary before moving the
 * implementation out of the compatibility facade.
 */
function run_order_write_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $modulePath = $repository . '/includes/orders.php';
    $facadePath = $repository . '/includes/functions.php';
    $pagePath = $repository . '/public/orders.php';
    $module = is_file($modulePath) ? file_get_contents($modulePath) : null;
    $facade = is_file($facadePath) ? file_get_contents($facadePath) : null;
    $page = is_file($pagePath) ? file_get_contents($pagePath) : null;

    foreach ([$module, $facade, $page] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Order creation source fixture could not be read.');
    }

    $tests->assertContains('declare(strict_types=1);', $module, 'Orders module must use strict typing.');
    foreach ([
        "require_once __DIR__ . '/inventory.php';",
        "require_once __DIR__ . '/audit.php';",
        'function orders_create($conn, $staff_id, $items, $order_type = \'sale\', $customer_id = null, $supplier_id = null): int|false',
        '$conn->begin_transaction()',
        'SELECT role, is_active FROM Staff WHERE id = ? LIMIT 1',
        'SELECT id FROM {$party_table} WHERE id = ? LIMIT 1',
        'SELECT id, price, stock FROM Product WHERE id = ? FOR UPDATE',
        'INSERT INTO `Order` (total_amount, staff_id, order_type, customer_id)',
        'INSERT INTO `Order` (total_amount, staff_id, order_type, supplier_id)',
        'INSERT INTO OrderDetail (order_id, product_id, quantity, unit_price, subtotal)',
        'UPDATE Product SET stock = ? WHERE id = ? AND stock = ?',
        'inventory_log_stock_movement(',
        'Order #{$order_id} Sale',
        'Order #{$order_id} Purchase',
        'audit_log($conn, $staff_id, $audit_action, \'Order\', $order_id, true',
        '$conn->commit()',
        '$conn->rollback()',
        'inventory_rollback_error($conn)',
        'return $order_id;',
        'return false;',
    ] as $contract) {
        $tests->assertContains($contract, $module, 'Order creation contract is missing: ' . $contract);
    }
    $tests->assertFalse(strpos($module, "require_once __DIR__ . '/functions.php'") !== false, 'Orders module must not require the compatibility facade.');
    $tests->assertFalse(strpos($module, '$_SESSION') !== false, 'Orders module must not read session state.');
    $tests->assertFalse(strpos($module, '$GLOBALS') !== false, 'Orders module must not read global state.');
    $tests->assertFalse(
        preg_match('/(?<!inventory_)\blog_stock_movement\s*\(/', $module) === 1,
        'Orders module must call the focused inventory writer directly.'
    );

    $wrapperPattern = '/function create_order\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
    $wrapperMatched = preg_match($wrapperPattern, $facade, $matches) === 1;
    $tests->assertTrue($wrapperMatched, 'create_order compatibility wrapper is missing.');
    if ($wrapperMatched) {
        $tests->assertContains(
            'function create_order($conn, $staff_id, $items, $order_type = \'sale\', $customer_id = null, $supplier_id = null)',
            $facade,
            'create_order compatibility signature changed.'
        );
        $tests->assertContains(
            'return orders_create($conn, $staff_id, $items, $order_type, $customer_id, $supplier_id);',
            $matches['body'],
            'create_order must delegate to orders_create with the established argument order.'
        );
        foreach (['begin_transaction', 'SELECT role, is_active', 'INSERT INTO `Order`', 'OrderDetail', 'UPDATE Product', 'inventory_log_stock_movement', 'audit_log', 'commit', 'rollback'] as $implementationDetail) {
            $tests->assertFalse(
                strpos($matches['body'], $implementationDetail) !== false,
                'create_order wrapper still contains implementation detail: ' . $implementationDetail
            );
        }
    }

    $tests->assertContains('create_order($conn,', $page, 'Orders page must retain its compatibility wrapper caller in this batch.');
    $tests->assertFalse(strpos($page, 'orders_create(') !== false, 'Orders page must not migrate to the focused service in this batch.');

    return $tests->assertions();
}
