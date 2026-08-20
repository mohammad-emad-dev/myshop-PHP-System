<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the manual stock-adjustment service boundary before any page caller
 * is migrated.
 */
function run_inventory_adjustment_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $modulePath = $repository . '/includes/inventory.php';
    $pagePath = $repository . '/public/stock_movements.php';
    $module = is_file($modulePath) ? file_get_contents($modulePath) : null;
    $page = is_file($pagePath) ? file_get_contents($pagePath) : null;

    $tests->assertTrue(is_string($module), 'Inventory module source fixture could not be read.');
    $tests->assertTrue(is_string($page), 'Stock movement page source fixture could not be read.');

    $tests->assertContains(
        'function inventory_adjust_stock($conn, $product_id, $staff_id, $quantity, $reason): bool',
        $module,
        'Inventory adjustment service signature is missing or changed.'
    );
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Inventory adjustment service must not require the compatibility facade.'
    );
    foreach ([
        "require_once __DIR__ . '/audit.php';",
        "require_once __DIR__ . '/security.php';",
        '$conn->begin_transaction()',
        'SELECT stock FROM Product WHERE id = ? FOR UPDATE',
        'filter_var($product[\'stock\'], FILTER_VALIDATE_INT',
        'Stock adjustment would exceed the supported range.',
        'UPDATE Product SET stock = ? WHERE id = ? AND stock = ?',
        'inventory_log_stock_movement($conn, $product_id, $staff_id, $quantity, \'manual_adjustment\', $reason)',
        'audit_log($conn, $staff_id, \'stock_adjustment\', \'Product\', $product_id, true',
        '$conn->commit()',
        '$conn->rollback()',
        'audit_log($conn, $staff_id, \'stock_adjustment\', \'Product\', $product_id, false',
        'Stock adjustment rollback failed:',
        'Stock adjustment failed:',
        'return true;',
        'return false;',
    ] as $contract) {
        $tests->assertContains($contract, $module, 'Inventory adjustment contract is missing: ' . $contract);
    }
    $tests->assertFalse(
        strpos($module, '$_SESSION') !== false,
        'Inventory adjustment service must not read session state.'
    );

    $adjustmentStart = strpos($page, '// Handle Manual Stock Adjustment');
    $readPathStart = strpos($page, '// Handle read-only filter');
    $adjustmentBlock = ($adjustmentStart !== false && $readPathStart !== false)
        ? substr($page, $adjustmentStart, $readPathStart - $adjustmentStart)
        : '';
    $tests->assertContains(
        'inventory_adjust_stock($conn, (int)$adj_product_id, (int)($_SESSION[\'staff_id\'] ?? 0), (int)$adj_quantity, $adj_reason)',
        $adjustmentBlock,
        'Stock movement page must delegate the validated adjustment with an explicit staff ID.'
    );
    foreach ([
        '$conn->begin_transaction()',
        'SELECT stock FROM Product WHERE id = ? FOR UPDATE',
        'UPDATE Product SET stock = ? WHERE id = ? AND stock = ?',
        'log_stock_movement($conn',
        '$conn->commit()',
        '$conn->rollback()',
        'audit_log_current_actor($conn, \'stock_adjustment\', \'Product\', $adj_product_id',
    ] as $removedOperation) {
        $tests->assertFalse(
            strpos($adjustmentBlock, $removedOperation) !== false,
            'Stock movement page still owns extracted database operation: ' . $removedOperation
        );
    }
    $tests->assertContains('verify_csrf_token($csrf_token)', $adjustmentBlock, 'CSRF validation moved out of the page boundary.');
    $tests->assertContains('auth_is_admin($conn)', $adjustmentBlock, 'Administrator authorization moved out of the page boundary.');
    $csrfOffset = strpos($adjustmentBlock, 'verify_csrf_token($csrf_token)');
    $authorizationOffset = strpos($adjustmentBlock, 'auth_is_admin($conn)');
    $tests->assertTrue(
        $csrfOffset !== false && $authorizationOffset !== false && $csrfOffset < $authorizationOffset,
        'Stock adjustment CSRF validation must remain before authorization.'
    );
    $tests->assertContains(
        "Unable to complete the stock adjustment right now.",
        $adjustmentBlock,
        'Stock adjustment generic failure message changed.'
    );
    $tests->assertContains('Stock adjusted successfully.', $adjustmentBlock, 'Stock adjustment success message changed.');

    return $tests->assertions();
}
