<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the bounded stock-movement read seam and compatibility writer while
 * stock-mutation callers remain on the legacy facade and page.
 */
function run_inventory_read_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);

    $modulePath = $repository . '/includes/inventory.php';
    $module = is_file($modulePath) ? file_get_contents($modulePath) : null;
    $facade = file_get_contents($repository . '/includes/functions.php');
    $stockPage = file_get_contents($repository . '/public/stock_movements.php');
    $index = file_get_contents($repository . '/public/index.php');
    $navbar = file_get_contents($repository . '/includes/layouts/navbar.php');

    foreach ([$module, $facade, $stockPage, $index, $navbar] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Inventory extraction source fixture could not be read.');
    }

    $tests->assertContains('declare(strict_types=1);', $module, 'Inventory module must use strict typing.');
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Inventory module must not require the compatibility facade.'
    );
    foreach (['inventory_count_stock_movements', 'inventory_get_stock_movements_page', 'inventory_log_stock_movement'] as $functionName) {
        $tests->assertContains('function ' . $functionName, $module, 'Inventory function is missing: ' . $functionName);
    }
    $tests->assertContains(
        'function inventory_log_stock_movement($conn, $product_id, $staff_id, $quantity, $movement_type, $reason = null)',
        $module,
        'Inventory stock movement writer arguments changed.'
    );
    foreach (['WHERE sm.product_id = ?', 'ORDER BY sm.created_at DESC, sm.id DESC', 'LIMIT ? OFFSET ?'] as $queryContract) {
        $tests->assertContains($queryContract, $module, 'Inventory query contract changed: ' . $queryContract);
    }

    foreach (['inventory_count_stock_movements($conn', 'inventory_get_stock_movements_page($conn'] as $functionCall) {
        $tests->assertContains($functionCall, $stockPage, 'Stock movement page was not migrated to the Inventory read module: ' . $functionCall);
    }
    foreach ([
        '/(?<!inventory_)\bcount_stock_movements\s*\(/',
        '/(?<!inventory_)\bget_stock_movements_page\s*\(/',
    ] as $legacyCallPattern) {
        $tests->assertFalse(
            preg_match($legacyCallPattern, $stockPage) === 1,
            'Stock movement page still calls a legacy bounded Inventory read function directly.'
        );
    }

    foreach ([
        'count_stock_movements' => 'inventory_count_stock_movements',
        'get_stock_movements_page' => 'inventory_get_stock_movements_page',
        'log_stock_movement' => 'inventory_log_stock_movement',
    ] as $legacyName => $inventoryName) {
        $wrapperPattern = '/function ' . preg_quote($legacyName, '/') . '\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
        $matched = preg_match($wrapperPattern, $facade, $matches) === 1;
        $tests->assertTrue($matched, 'Inventory compatibility wrapper is missing: ' . $legacyName);
        if ($matched) {
            $tests->assertContains($inventoryName . '(', $matches['body'], 'Inventory wrapper does not delegate: ' . $legacyName);
            $tests->assertFalse(stripos($matches['body'], 'SELECT ') !== false, 'Inventory wrapper contains duplicated SQL: ' . $legacyName);
            if ($legacyName === 'log_stock_movement') {
                $tests->assertContains(
                    'return inventory_log_stock_movement($conn, $product_id, $staff_id, $quantity, $movement_type, $reason);',
                    $matches['body'],
                    'Stock movement wrapper arguments changed.'
                );
            }
        }
    }

    foreach ([
        "INSERT INTO `StockMovement`",
        '(product_id, staff_id, quantity, movement_type, reason)',
        'VALUES (?, ?, ?, ?, ?)',
        "bind_param('iiiss'",
        "Stock movement prepare failed: '",
        "Stock movement bind failed: '",
        "Stock movement insert failed: '",
        'Stock movement insert affected an unexpected number of rows.',
        'return true;',
        'return false;',
    ] as $writeContract) {
        $tests->assertContains($writeContract, $module, 'Stock movement write contract changed during extraction: ' . $writeContract);
    }
    foreach ([
        'public/stock_movements.php' => 'inventory_adjust_stock($conn, (int)$adj_product_id',
        'includes/functions.php' => 'log_stock_movement($conn, $product_id, $staff_id',
    ] as $callerPath => $callerContract) {
        $callerSource = $callerPath === 'public/stock_movements.php' ? $stockPage : $facade;
        $tests->assertContains($callerContract, $callerSource, 'Existing stock movement caller changed: ' . $callerPath);
    }

    foreach (['function get_stock_movements($conn, $product_id = null)', 'function get_low_stock_products($conn, $limit = 100)', 'function get_inventory_valuation($conn)'] as $legacyFunction) {
        $tests->assertContains($legacyFunction, $facade, 'Out-of-scope Inventory read function changed: ' . $legacyFunction);
    }
    $tests->assertContains('fetch_all(MYSQLI_ASSOC)', $facade, 'Unbounded stock-movement compatibility behavior was unexpectedly removed.');
    $tests->assertContains('get_inventory_valuation($conn)', $index, 'Dashboard inventory valuation caller changed out of scope.');
    $tests->assertContains('get_low_stock_products($conn)', $index, 'Dashboard low-stock caller changed out of scope.');
    $tests->assertContains('get_low_stock_products($conn)', $navbar, 'Navbar low-stock caller changed out of scope.');

    $countOffset = strpos($stockPage, 'inventory_count_stock_movements($conn');
    $pageOffset = strpos($stockPage, 'inventory_get_stock_movements_page($conn');
    $tests->assertTrue(
        $countOffset !== false && $pageOffset !== false && $countOffset < $pageOffset,
        'Stock movement count must remain before page retrieval.'
    );

    foreach ([
        'get_product_by_id($conn, $selected_product_id)',
        'auth_verify_login($conn)',
        'verify_csrf_token($csrf_token)',
        'auth_is_admin($conn)',
        'inventory_adjust_stock($conn, (int)$adj_product_id',
    ] as $stockInvariant) {
        $tests->assertContains(
            $stockInvariant,
            $stockPage,
            'Stock movement read or authorization invariant disappeared during adjustment extraction: ' . $stockInvariant
        );
    }
    foreach ([
        '$conn->begin_transaction()',
        'SELECT stock FROM Product WHERE id = ? FOR UPDATE',
        'UPDATE Product SET stock = ? WHERE id = ? AND stock = ?',
        'log_stock_movement($conn',
        '$conn->commit()',
        '$conn->rollback()',
        'audit_log_current_actor($conn, \'stock_adjustment\', \'Product\', $adj_product_id',
    ] as $removedMutation) {
        $tests->assertFalse(
            strpos($stockPage, $removedMutation) !== false,
            'Stock movement page still owns the extracted adjustment operation: ' . $removedMutation
        );
    }

    return $tests->assertions();
}
