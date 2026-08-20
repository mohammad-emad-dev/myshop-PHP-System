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

        $valuation = dashboard_get_inventory_valuation($conn);
        $tests->assertSame(1000.0, $valuation, 'Inventory valuation must sum current stock multiplied by price.');
        $tests->assertTrue(is_float($valuation), 'Inventory valuation must remain a float.');
        $tests->assertSame(
            $valuation,
            get_inventory_valuation($conn),
            'The legacy inventory valuation wrapper must preserve the focused service result.'
        );
        test_execute($conn, 'UPDATE Product SET stock = 0 WHERE id = ?', 'i', [$productId]);
        $zeroValuation = dashboard_get_inventory_valuation($conn);
        $tests->assertSame(0.0, $zeroValuation, 'Zero-stock products must produce a zero valuation.');
        $tests->assertTrue(is_float($zeroValuation), 'Zero inventory valuation must remain a float.');
        test_execute($conn, 'UPDATE Product SET stock = 100 WHERE id = ?', 'i', [$productId]);

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

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
        test_execute(
            $conn,
            'INSERT INTO `Order` (order_date, total_amount, staff_id, order_type, customer_id, supplier_id) VALUES (?, ?, ?, \'sale\', ?, NULL)',
            'sdii',
            [$twoDaysAgo . ' 12:00:00', 12.25, $adminId, 1]
        );
        test_execute(
            $conn,
            'INSERT INTO `Order` (order_date, total_amount, staff_id, order_type, customer_id, supplier_id) VALUES (?, ?, ?, \'purchase\', NULL, ?)',
            'sdii',
            [$yesterday . ' 12:00:00', 9.75, $cashierId, 1]
        );

        $globalStats = dashboard_get_stats($conn);
        $tests->assertSame(
            ['total_products', 'total_orders', 'total_sales', 'total_stock'],
            array_keys($globalStats),
            'Dashboard statistics keys changed.'
        );
        $tests->assertSame(1, $globalStats['total_products'], 'Global product count is incorrect.');
        $tests->assertSame(6, $globalStats['total_orders'], 'Global order count is incorrect.');
        $tests->assertSame(39.75, $globalStats['total_sales'], 'Purchases must be excluded from global sales.');
        $tests->assertSame(100, $globalStats['total_stock'], 'Global stock total is incorrect.');
        $tests->assertTrue(is_int($globalStats['total_products']), 'Product count must remain an integer.');
        $tests->assertTrue(is_int($globalStats['total_orders']), 'Order count must remain an integer.');
        $tests->assertTrue(is_float($globalStats['total_sales']), 'Sales total must remain a float.');
        $tests->assertTrue(is_int($globalStats['total_stock']), 'Stock total must remain an integer.');

        $cashierStats = dashboard_get_stats($conn, $cashierId);
        $tests->assertSame(1, $cashierStats['total_products'], 'Cashier product total must remain global.');
        $tests->assertSame(3, $cashierStats['total_orders'], 'Cashier order count must be scoped.');
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
        $closedValuation = dashboard_get_inventory_valuation($closedConnection);
        $tests->assertSame(0.0, $closedValuation, 'Closed database connections must return zero valuation.');
        $tests->assertTrue(is_float($closedValuation), 'Closed-connection valuation fallback must remain a float.');
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

        $globalChart = dashboard_get_chart_data($conn, 3);
        $tests->assertCount(3, $globalChart, 'Chart data must contain the requested complete date series.');
        $tests->assertSame(date('M d', strtotime($twoDaysAgo)), $globalChart[0]['label'], 'Chart labels must be chronological.');
        $tests->assertSame(date('M d', strtotime($yesterday)), $globalChart[1]['label'], 'Chart labels must preserve date order.');
        $tests->assertSame(date('M d', strtotime($today)), $globalChart[2]['label'], 'Chart labels must include today.');
        $tests->assertSame(12.25, $globalChart[0]['sales'], 'Historical sales total is incorrect.');
        $tests->assertSame(0.0, $globalChart[0]['purchases'], 'Missing purchase days must be zero-filled.');
        $tests->assertSame(0.0, $globalChart[1]['sales'], 'Missing sales days must be zero-filled.');
        $tests->assertSame(9.75, $globalChart[1]['purchases'], 'Historical purchase total is incorrect.');
        $tests->assertSame(27.5, $globalChart[2]['sales'], 'Current-day global sales total is incorrect.');
        $tests->assertSame(70.0, $globalChart[2]['purchases'], 'Current-day global purchase total is incorrect.');
        $tests->assertTrue(is_float($globalChart[0]['sales']), 'Chart sales values must remain floats.');
        $tests->assertTrue(is_float($globalChart[1]['purchases']), 'Chart purchase values must remain floats.');

        $cashierChart = dashboard_get_chart_data($conn, 3, $cashierId);
        $tests->assertSame(0.0, $cashierChart[0]['sales'], 'Cashier chart must exclude another staff member\'s sale.');
        $tests->assertSame(0.0, $cashierChart[0]['purchases'], 'Cashier chart must exclude another staff member\'s purchase.');
        $tests->assertSame(0.0, $cashierChart[1]['sales'], 'Cashier chart must preserve scoped zero-fill.');
        $tests->assertSame(9.75, $cashierChart[1]['purchases'], 'Cashier chart purchase scope is incorrect.');
        $tests->assertSame(7.5, $cashierChart[2]['sales'], 'Cashier chart sale scope is incorrect.');
        $tests->assertSame(40.0, $cashierChart[2]['purchases'], 'Cashier current-day purchase scope is incorrect.');

        $tests->assertCount(1, dashboard_get_chart_data($conn, 0), 'Chart days must normalize to a minimum of one.');
        $tests->assertCount(31, dashboard_get_chart_data($conn, 50), 'Chart days must normalize to a maximum of 31.');
        $tests->assertSame(
            $globalChart,
            get_chart_data($conn, 3),
            'The legacy chart-data wrapper must preserve the focused service result.'
        );

        $closedChart = dashboard_get_chart_data($closedConnection, 3);
        $tests->assertCount(3, $closedChart, 'Closed connections must preserve the requested chart shape.');
        foreach ($closedChart as $point) {
            $tests->assertSame(0.0, $point['sales'], 'Closed-connection chart sales must default to zero.');
            $tests->assertSame(0.0, $point['purchases'], 'Closed-connection chart purchases must default to zero.');
        }
        $tests->assertSame(
            $closedChart,
            get_chart_data($closedConnection, 3),
            'The legacy chart wrapper must preserve closed-connection fallback behavior.'
        );
    } finally {
        $database->cleanup();
    }

    return $tests->assertions();
}
