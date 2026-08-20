<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function run_dashboard_integration_tests(): int
{
    $tests = new TestContext();
    $database = new DisposableDatabase();

    try {
        $database->setup();
        $conn = $database->runtime;
        $prefix = 'QA_3B_' . strtoupper(bin2hex(random_bytes(4)));
        $passwordHash = password_hash('fixture-only', PASSWORD_BCRYPT);

        test_execute(
            $conn,
            'INSERT INTO Staff (username, full_name, password, role, is_active) VALUES (?, ?, ?, ?, ?)',
            'ssssi',
            [$prefix . '_ADMIN', $prefix . ' Admin', $passwordHash, 'admin', 1]
        );
        test_execute(
            $conn,
            'INSERT INTO Staff (username, full_name, password, role, is_active) VALUES (?, ?, ?, ?, ?)',
            'ssssi',
            [$prefix . '_CASHIER', $prefix . ' Cashier', $passwordHash, 'cashier', 1]
        );
        $adminId = (int)test_scalar($conn, 'SELECT id FROM Staff WHERE username = ?', 's', [$prefix . '_ADMIN']);
        $cashierId = (int)test_scalar($conn, 'SELECT id FROM Staff WHERE username = ?', 's', [$prefix . '_CASHIER']);
        $tests->assertTrue($adminId > 0 && $cashierId > 0, 'Dashboard staff fixtures were not created.');

        $barcode = $prefix . '_PRODUCT';
        test_execute(
            $conn,
            'INSERT INTO Product (name, description, price, stock, image_path, alert_threshold, category_id, barcode) VALUES (?, ?, ?, ?, NULL, ?, ?, ?)',
            'ssdiiis',
            [$prefix . ' Product', 'Dashboard fixture', 10.00, 100, 10, 1, $barcode]
        );
        $productId = (int)test_scalar($conn, 'SELECT id FROM Product WHERE barcode = ?', 's', [$barcode]);
        $tests->assertTrue($productId > 0, 'Dashboard product fixture was not created.');

        test_execute(
            $conn,
            'INSERT INTO `Order` (total_amount, staff_id, order_type, customer_id, supplier_id) VALUES (?, ?, \'sale\', ?, NULL)',
            'dii',
            [20.00, $adminId, 1]
        );
        test_execute(
            $conn,
            'INSERT INTO `Order` (total_amount, staff_id, order_type, customer_id, supplier_id) VALUES (?, ?, \'sale\', ?, NULL)',
            'dii',
            [7.50, $cashierId, 1]
        );
        test_execute(
            $conn,
            'INSERT INTO `Order` (total_amount, staff_id, order_type, customer_id, supplier_id) VALUES (?, ?, \'purchase\', NULL, ?)',
            'dii',
            [30.00, $adminId, 1]
        );
        test_execute(
            $conn,
            'INSERT INTO `Order` (total_amount, staff_id, order_type, customer_id, supplier_id) VALUES (?, ?, \'purchase\', NULL, ?)',
            'dii',
            [40.00, $cashierId, 1]
        );

        $globalStats = dashboard_get_stats($conn);
        $tests->assertSame(
            ['total_products', 'total_orders', 'total_sales', 'total_stock'],
            array_keys($globalStats),
            'Dashboard statistics keys changed.'
        );
        $tests->assertSame(1, $globalStats['total_products'], 'Global product count is incorrect.');
        $tests->assertSame(4, $globalStats['total_orders'], 'Global order count is incorrect.');
        $tests->assertSame(27.5, $globalStats['total_sales'], 'Purchases must be excluded from global sales.');
        $tests->assertSame(100, $globalStats['total_stock'], 'Global stock total is incorrect.');
        $tests->assertTrue(is_int($globalStats['total_products']), 'Product count must remain an integer.');
        $tests->assertTrue(is_int($globalStats['total_orders']), 'Order count must remain an integer.');
        $tests->assertTrue(is_float($globalStats['total_sales']), 'Sales total must remain a float.');
        $tests->assertTrue(is_int($globalStats['total_stock']), 'Stock total must remain an integer.');

        $cashierStats = dashboard_get_stats($conn, $cashierId);
        $tests->assertSame(1, $cashierStats['total_products'], 'Cashier product total must remain global.');
        $tests->assertSame(2, $cashierStats['total_orders'], 'Cashier order count must be scoped.');
        $tests->assertSame(7.5, $cashierStats['total_sales'], 'Cashier sales must exclude purchases and other staff.');
        $tests->assertSame(100, $cashierStats['total_stock'], 'Cashier stock total must remain global.');

        $wrapperStats = get_dashboard_stats($conn, $cashierId);
        $tests->assertSame($cashierStats, $wrapperStats, 'The legacy dashboard wrapper changed the service result.');

        $closedConnection = new mysqli(
            $database->hostForTests(),
            $database->runtimeUsername,
            $database->runtimePassword,
            $database->databaseName,
            $database->portForTests()
        );
        $closedConnection->close();
        $defaultStats = [
            'total_products' => 0,
            'total_orders' => 0,
            'total_sales' => 0.0,
            'total_stock' => 0,
        ];
        $tests->assertSame(
            $defaultStats,
            dashboard_get_stats($closedConnection),
            'Closed database connections must return the documented zero defaults.'
        );
        $tests->assertSame(
            $defaultStats,
            get_dashboard_stats($closedConnection),
            'The compatibility wrapper must preserve closed-connection defaults.'
        );
    } finally {
        $database->cleanup();
    }

    return $tests->assertions();
}
