<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterize the bounded and single-record order-read boundary before
 * moving its implementation out of the compatibility facade.
 */
function run_order_read_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $modulePath = $repository . '/includes/orders.php';
    $facadePath = $repository . '/includes/functions.php';
    $historyPath = $repository . '/public/order_history.php';
    $detailsPagePath = $repository . '/public/get_order_details.php';
    $invoicePath = $repository . '/public/print_invoice.php';

    $module = is_file($modulePath) ? file_get_contents($modulePath) : null;
    $facade = is_file($facadePath) ? file_get_contents($facadePath) : null;
    $historyPage = is_file($historyPath) ? file_get_contents($historyPath) : null;
    $detailsPage = is_file($detailsPagePath) ? file_get_contents($detailsPagePath) : null;
    $invoicePage = is_file($invoicePath) ? file_get_contents($invoicePath) : null;

    foreach ([$module, $facade, $historyPage, $detailsPage, $invoicePage] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Order-read source fixture could not be read.');
    }

    $tests->assertContains(
        "require_once __DIR__ . '/pagination.php';",
        $module,
        'Orders module must explicitly require pagination for bounded reads.'
    );
    foreach ([
        'function orders_count($conn, $staff_id = null, $filter_type = \'all\')',
        'function orders_get_page($conn, $staff_id = null, $filter_type = \'all\', $limit = 25, $offset = 0)',
        'function orders_get_summary($conn, $staff_id = null, $filter_type = \'all\')',
        'function orders_get_by_id($conn, $order_id, $staff_id = null)',
        'function orders_get_details($conn, $order_id, $staff_id = null)',
        'SELECT COUNT(*) AS total FROM `Order`',
        'ORDER BY o.order_date DESC, o.id DESC LIMIT ? OFFSET ?',
        'SELECT o.*, s.full_name as staff_name',
        'SELECT od.*, p.name as product_name',
        'total_orders',
        'return $empty_summary;',
        'return $result->fetch_assoc() ?: null;',
        'return $result->fetch_all(MYSQLI_ASSOC);',
    ] as $contract) {
        $tests->assertContains($contract, $module, 'Order-read module contract is missing: ' . $contract);
    }
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Orders read module must not require the compatibility facade.'
    );
    $tests->assertFalse(strpos($module, '$_SESSION') !== false, 'Orders read module must not read session state.');
    $tests->assertFalse(strpos($module, '$GLOBALS') !== false, 'Orders read module must not read global state.');

    $wrapperContracts = [
        'count_orders' => 'return orders_count($conn, $staff_id, $filter_type);',
        'get_orders_page' => 'return orders_get_page($conn, $staff_id, $filter_type, $limit, $offset);',
        'get_order_summary' => 'return orders_get_summary($conn, $staff_id, $filter_type);',
        'get_order_by_id' => 'return orders_get_by_id($conn, $order_id, $staff_id);',
        'get_order_details' => 'return orders_get_details($conn, $order_id, $staff_id);',
    ];
    foreach ($wrapperContracts as $legacyFunction => $delegation) {
        $wrapperPattern = '/function ' . preg_quote($legacyFunction, '/') . '\s*\([^)]*\)\s*\{(?<body>.*?)\n\}/s';
        $wrapperMatched = preg_match($wrapperPattern, $facade, $matches) === 1;
        $tests->assertTrue($wrapperMatched, $legacyFunction . ' compatibility wrapper is missing.');
        if ($wrapperMatched) {
            $tests->assertContains($delegation, $matches['body'], $legacyFunction . ' must delegate to the focused order-read service.');
            foreach (['SELECT ', 'prepare(', 'bind_param', 'fetch_all', 'fetch_assoc'] as $implementationDetail) {
                $tests->assertFalse(
                    strpos($matches['body'], $implementationDetail) !== false,
                    $legacyFunction . ' wrapper still contains implementation detail: ' . $implementationDetail
                );
            }
        }
    }

    $tests->assertContains('count_orders($conn', $historyPage, 'Order history must remain on the compatibility count wrapper in this batch.');
    $tests->assertContains('get_orders_page($conn', $historyPage, 'Order history must remain on the compatibility page wrapper in this batch.');
    $tests->assertContains('get_order_summary($conn', $historyPage, 'Order history must remain on the compatibility summary wrapper in this batch.');
    $tests->assertContains('get_order_by_id($conn', $detailsPage, 'Order details endpoint must remain on the compatibility lookup wrapper in this batch.');
    $tests->assertContains('get_order_details($conn', $detailsPage, 'Order details endpoint must remain on the compatibility detail wrapper in this batch.');
    $tests->assertContains('get_order_by_id($conn', $invoicePage, 'Invoice page must remain on the compatibility lookup wrapper in this batch.');
    $tests->assertContains('get_order_details($conn', $invoicePage, 'Invoice page must remain on the compatibility detail wrapper in this batch.');

    $tests->assertContains('function get_orders($conn)', $facade, 'Legacy unbounded get_orders() loader must remain available.');
    $tests->assertContains('function get_orders_for_staff($conn, $staff_id)', $facade, 'Legacy get_orders_for_staff() loader must remain available.');
    $tests->assertFalse(strpos($module, 'function get_orders(') !== false, 'Unbounded get_orders() must not be extracted in this batch.');
    $tests->assertFalse(strpos($module, 'function get_orders_for_staff(') !== false, 'Unbounded get_orders_for_staff() must not be extracted in this batch.');

    return $tests->assertions();
}
