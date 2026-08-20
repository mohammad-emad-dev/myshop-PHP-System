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
    foreach ([
        'get_inventory_valuation($conn)',
        'get_top_selling_products($conn, 5, $dashboard_staff_id)',
        'get_category_sales_distribution($conn, $dashboard_staff_id)',
        'get_low_stock_products($conn)',
    ] as $unchangedCaller) {
        $tests->assertContains($unchangedCaller, $index, 'Unrelated dashboard caller changed: ' . $unchangedCaller);
    }

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

    return $tests->assertions();
}
