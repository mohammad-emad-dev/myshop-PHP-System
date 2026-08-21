<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterize the dashboard statistics and chart-data boundaries before
 * moving their implementations out of the compatibility facade.
 */
function run_dashboard_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $modulePath = $repository . '/includes/dashboard.php';
    $facadePath = $repository . '/includes/functions.php';
    $indexPath = $repository . '/public/index.php';

    $module = is_file($modulePath) ? file_get_contents($modulePath) : null;
    $facade = is_file($facadePath) ? file_get_contents($facadePath) : null;
    $index = is_file($indexPath) ? file_get_contents($indexPath) : null;

    foreach ([$module, $facade, $index] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Dashboard source fixture could not be read.');
    }

    $tests->assertContains(
        'function dashboard_get_stats($conn, $staff_id = null)',
        $module,
        'Dashboard module must expose the explicit statistics service.'
    );
    foreach ([
        "'total_products' => 0",
        "'total_orders'   => 0",
        "'total_sales'    => 0.0",
        "'total_stock'    => 0",
        'SELECT COUNT(*) as count FROM Product',
        'SELECT COUNT(*) as count FROM `Order`',
        "WHERE order_type = 'sale'",
        'WHERE staff_id = ?',
        'COALESCE(SUM(stock), 0)',
        'catch (Throwable $exception)',
        "error_log('Dashboard stats query failed: ' . \$exception->getMessage())",
    ] as $contract) {
        $tests->assertContains($contract, $module, 'Dashboard module contract is missing: ' . $contract);
    }
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Dashboard module must not require the compatibility facade.'
    );
    $tests->assertFalse(strpos($module, '$_SESSION') !== false, 'Dashboard module must not read session state.');
    $tests->assertFalse(strpos($module, '$GLOBALS') !== false, 'Dashboard module must not read global state.');

    $wrapperPattern = '/function get_dashboard_stats\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
    $wrapperMatched = preg_match($wrapperPattern, $facade, $matches) === 1;
    $tests->assertTrue($wrapperMatched, 'Dashboard compatibility wrapper is missing.');
    if ($wrapperMatched) {
        $tests->assertContains(
            'return dashboard_get_stats($conn, $staff_id);',
            $matches['body'],
            'Dashboard compatibility wrapper must delegate exactly once.'
        );
        $tests->assertSame(
            1,
            substr_count($matches['body'], 'dashboard_get_stats('),
            'Dashboard compatibility wrapper must contain one delegation.'
        );
        foreach (['SELECT ', 'query(', 'prepare(', 'bind_param', 'fetch_assoc'] as $implementationDetail) {
            $tests->assertFalse(
                strpos($matches['body'], $implementationDetail) !== false,
                'Dashboard compatibility wrapper still contains implementation detail: ' . $implementationDetail
            );
        }
    }

    $tests->assertContains(
        'dashboard_get_stats($conn, $dashboard_staff_id)',
        $index,
        'Dashboard page must call the focused statistics service directly.'
    );
    $tests->assertSame(
        0,
        preg_match('/\bget_dashboard_stats\s*\(/', $index),
        'Dashboard page must not call the legacy statistics function.'
    );
    $tests->assertContains(
        'inventory_get_low_stock_products($conn)',
        $index,
        'Dashboard page must call the focused Inventory low-stock service directly.'
    );
    $tests->assertSame(
        0,
        preg_match('/(?<!inventory_)\\bget_low_stock_products\\s*\\(/', $index),
        'Dashboard page must not call the legacy low-stock function.'
    );

    $tests->assertContains(
        'function dashboard_get_chart_data($conn, $days = 7, $staff_id = null)',
        $module,
        'Dashboard module must expose the explicit chart-data service.'
    );
    foreach ([
        'max(1, min((int)$days, 31))',
        "date('Y-m-d', strtotime(\"-\$i days\"))",
        "'label' => date('M d', strtotime(\$date))",
        "'sales' => 0.0",
        "'purchases' => 0.0",
        'DATE(order_date) as order_day',
        'DATE_SUB(CURDATE(), INTERVAL ? DAY)',
        'GROUP BY DATE(order_date), order_type',
        'ORDER BY DATE(order_date) ASC',
        "bind_param('i', \$days)",
        "bind_param('ii', \$days, \$staff_id)",
        'return array_values($data);',
        'finally',
        '$stmt->close();',
    ] as $contract) {
        $tests->assertContains($contract, $module, 'Dashboard chart-data contract is missing: ' . $contract);
    }

    $chartWrapperPattern = '/function get_chart_data\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
    $chartWrapperMatched = preg_match($chartWrapperPattern, $facade, $chartMatches) === 1;
    $tests->assertTrue($chartWrapperMatched, 'Chart-data compatibility wrapper is missing.');
    if ($chartWrapperMatched) {
        $tests->assertContains(
            'return dashboard_get_chart_data($conn, $days, $staff_id);',
            $chartMatches['body'],
            'Chart-data compatibility wrapper must delegate exactly once.'
        );
        $tests->assertSame(
            1,
            substr_count($chartMatches['body'], 'dashboard_get_chart_data('),
            'Chart-data compatibility wrapper must contain one delegation.'
        );
        foreach (['SELECT ', 'query(', 'prepare(', 'bind_param', 'fetch_assoc'] as $implementationDetail) {
            $tests->assertFalse(
                strpos($chartMatches['body'], $implementationDetail) !== false,
                'Chart-data compatibility wrapper still contains implementation detail: ' . $implementationDetail
            );
        }
    }

    $tests->assertContains(
        'dashboard_get_chart_data($conn, 7, $dashboard_staff_id)',
        $index,
        'Dashboard page must call the focused chart-data service directly.'
    );
    $tests->assertSame(
        0,
        preg_match('/\bget_chart_data\s*\(/', $index),
        'Dashboard page must not call the legacy chart-data function.'
    );

    $tests->assertContains(
        'function dashboard_get_inventory_valuation($conn)',
        $module,
        'Dashboard module must expose the explicit inventory valuation service.'
    );
    foreach ([
        'SELECT SUM(stock * price) as valuation FROM Product',
        "return (float)(\$row['valuation'] ?? 0.0);",
        "error_log('Inventory valuation query failed: ' . \$conn->error)",
        "error_log('Inventory valuation query failed: ' . \$exception->getMessage())",
        'return 0.0;',
    ] as $valuationContract) {
        $tests->assertContains(
            $valuationContract,
            $module,
            'Dashboard inventory valuation contract is missing: ' . $valuationContract
        );
    }

    $valuationWrapperPattern = '/function get_inventory_valuation\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
    $valuationWrapperMatched = preg_match($valuationWrapperPattern, $facade, $valuationMatches) === 1;
    $tests->assertTrue($valuationWrapperMatched, 'Inventory valuation compatibility wrapper is missing.');
    if ($valuationWrapperMatched) {
        $tests->assertContains(
            'return dashboard_get_inventory_valuation($conn);',
            $valuationMatches['body'],
            'Inventory valuation compatibility wrapper must delegate exactly once.'
        );
        $tests->assertSame(
            1,
            substr_count($valuationMatches['body'], 'dashboard_get_inventory_valuation('),
            'Inventory valuation compatibility wrapper must contain one delegation.'
        );
        foreach (['SELECT ', 'query(', 'prepare(', 'bind_param', 'fetch_assoc'] as $implementationDetail) {
            $tests->assertFalse(
                strpos($valuationMatches['body'], $implementationDetail) !== false,
                'Inventory valuation compatibility wrapper still contains implementation detail: ' . $implementationDetail
            );
        }
    }

    $tests->assertContains(
        'dashboard_get_inventory_valuation($conn)',
        $index,
        'Dashboard page must call the focused inventory valuation service directly.'
    );
    $tests->assertSame(
        0,
        preg_match('/\bget_inventory_valuation\s*\(/', $index),
        'Dashboard page must not call the legacy inventory valuation function.'
    );

    $tests->assertContains(
        'function dashboard_get_top_selling_products($conn, $limit = 5, $staff_id = null)',
        $module,
        'Dashboard module must expose the explicit top-selling products service.'
    );
    foreach ([
        'SELECT p.name, SUM(od.quantity) as total_qty, SUM(od.subtotal) as total_sales',
        'FROM OrderDetail od',
        'JOIN `Order` o ON od.order_id = o.id',
        'JOIN Product p ON od.product_id = p.id',
        "WHERE o.order_type = 'sale'",
        'AND o.staff_id = ?',
        'GROUP BY od.product_id, p.name',
        'ORDER BY total_qty DESC',
        'LIMIT ?',
        'max(1, min((int)$limit, 50))',
        "bind_param('i', \$limit)",
        "bind_param('ii', \$staff_id, \$limit)",
        "error_log('Top-selling products prepare failed: ' . \$conn->error)",
        "error_log('Scoped top-selling products bind failed: ' . \$stmt->error)",
        'fetch_all(MYSQLI_ASSOC)',
        'return [];',
    ] as $topSellingContract) {
        $tests->assertContains(
            $topSellingContract,
            $module,
            'Dashboard top-selling contract is missing: ' . $topSellingContract
        );
    }

    $topSellingFunctionPattern = '/function dashboard_get_top_selling_products\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
    $topSellingFunctionMatched = preg_match($topSellingFunctionPattern, $module, $topSellingFunctionMatches) === 1;
    $tests->assertTrue($topSellingFunctionMatched, 'Dashboard top-selling service body is missing.');
    if ($topSellingFunctionMatched) {
        $tests->assertFalse(
            strpos($topSellingFunctionMatches['body'], 'catch (Throwable') !== false,
            'Top-selling closed-connection behavior must preserve the existing uncaught mysqli failure.'
        );
    }

    $topSellingWrapperPattern = '/function get_top_selling_products\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
    $topSellingWrapperMatched = preg_match($topSellingWrapperPattern, $facade, $topSellingWrapperMatches) === 1;
    $tests->assertTrue($topSellingWrapperMatched, 'Top-selling compatibility wrapper is missing.');
    if ($topSellingWrapperMatched) {
        $tests->assertContains(
            'return dashboard_get_top_selling_products($conn, $limit, $staff_id);',
            $topSellingWrapperMatches['body'],
            'Top-selling compatibility wrapper must delegate exactly once.'
        );
        $tests->assertSame(
            1,
            substr_count($topSellingWrapperMatches['body'], 'dashboard_get_top_selling_products('),
            'Top-selling compatibility wrapper must contain one delegation.'
        );
        foreach (['SELECT ', 'query(', 'prepare(', 'bind_param', 'fetch_assoc', 'fetch_all'] as $implementationDetail) {
            $tests->assertFalse(
                strpos($topSellingWrapperMatches['body'], $implementationDetail) !== false,
                'Top-selling compatibility wrapper still contains implementation detail: ' . $implementationDetail
            );
        }
    }

    $tests->assertContains(
        'dashboard_get_top_selling_products($conn, 5, $dashboard_staff_id)',
        $index,
        'Dashboard page must call the focused top-selling service directly.'
    );
    $tests->assertSame(
        0,
        preg_match('/\bget_top_selling_products\s*\(/', $index),
        'Dashboard page must not call the legacy top-selling function.'
    );

    $tests->assertContains(
        'function dashboard_get_category_sales_distribution($conn, $staff_id = null, $limit = 100)',
        $module,
        'Dashboard module must expose the explicit category-sales service.'
    );
    foreach ([
        'normalize_page_size($limit, 100, [25, 50, 100])',
        "SELECT COALESCE(c.name, 'Uncategorized') as category_name, SUM(od.subtotal) as total_sales",
        'FROM OrderDetail od',
        'JOIN `Order` o ON od.order_id = o.id',
        'JOIN Product p ON od.product_id = p.id',
        'LEFT JOIN Category c ON p.category_id = c.id',
        "WHERE o.order_type = 'sale'",
        'AND o.staff_id = ?',
        'GROUP BY p.category_id, c.name',
        'ORDER BY total_sales DESC',
        'LIMIT ?',
        "bind_param('i', \$limit)",
        "bind_param('ii', \$staff_id, \$limit)",
        "error_log('Category sales distribution prepare failed: ' . \$conn->error)",
        "error_log('Scoped category sales distribution prepare failed: ' . \$conn->error)",
        "error_log('Category sales distribution query failed: ' . \$exception->getMessage())",
        "error_log('Scoped category sales distribution result failed: ' . \$stmt->error)",
        'return [];',
        'finally',
        '$stmt->close();',
    ] as $categorySalesContract) {
        $tests->assertContains(
            $categorySalesContract,
            $module,
            'Dashboard category-sales contract is missing: ' . $categorySalesContract
        );
    }

    $categorySalesFunctionPattern = '/function dashboard_get_category_sales_distribution\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
    $categorySalesFunctionMatched = preg_match($categorySalesFunctionPattern, $module, $categorySalesFunctionMatches) === 1;
    $tests->assertTrue($categorySalesFunctionMatched, 'Dashboard category-sales service body is missing.');
    if ($categorySalesFunctionMatched) {
        $tests->assertContains(
            'if ($staff_id === null)',
            $categorySalesFunctionMatches['body'],
            'Category-sales service must preserve separate global and scoped branches.'
        );
        $tests->assertContains(
            'catch (Throwable $exception)',
            $categorySalesFunctionMatches['body'],
            'Global category-sales failures must remain caught and converted to an empty array.'
        );
    }

    $categorySalesWrapperPattern = '/function get_category_sales_distribution\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
    $categorySalesWrapperMatched = preg_match($categorySalesWrapperPattern, $facade, $categorySalesWrapperMatches) === 1;
    $tests->assertTrue($categorySalesWrapperMatched, 'Category-sales compatibility wrapper is missing.');
    if ($categorySalesWrapperMatched) {
        $tests->assertContains(
            'return dashboard_get_category_sales_distribution($conn, $staff_id, $limit);',
            $categorySalesWrapperMatches['body'],
            'Category-sales compatibility wrapper must delegate exactly once.'
        );
        $tests->assertSame(
            1,
            substr_count($categorySalesWrapperMatches['body'], 'dashboard_get_category_sales_distribution('),
            'Category-sales compatibility wrapper must contain one delegation.'
        );
        foreach (['SELECT ', 'query(', 'prepare(', 'bind_param', 'fetch_assoc', 'fetch_all'] as $implementationDetail) {
            $tests->assertFalse(
                strpos($categorySalesWrapperMatches['body'], $implementationDetail) !== false,
                'Category-sales compatibility wrapper still contains implementation detail: ' . $implementationDetail
            );
        }
    }

    $tests->assertContains(
        'dashboard_get_category_sales_distribution($conn, $dashboard_staff_id)',
        $index,
        'Dashboard page must call the focused category-sales service directly.'
    );
    $tests->assertSame(
        0,
        preg_match('/\bget_category_sales_distribution\s*\(/', $index),
        'Dashboard page must not call the legacy category-sales function.'
    );

    return $tests->assertions();
}
