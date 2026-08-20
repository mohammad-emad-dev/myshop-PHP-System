<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Characterizes the customer read boundary during its incremental extraction.
 * These source contracts prevent pages from silently returning to the facade
 * and ensure the facade remains a delegation-only compatibility layer.
 */
function run_people_read_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);

    $module = file_get_contents($repository . '/includes/people.php');
    $facade = file_get_contents($repository . '/includes/functions.php');
    $customers = file_get_contents($repository . '/public/customers.php');
    $orders = file_get_contents($repository . '/public/orders.php');

    foreach ([$module, $facade, $customers, $orders] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'People extraction source fixture could not be read.');
    }

    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'People module must not require the legacy compatibility facade.'
    );

    foreach ([
        'people_count_customers',
        'people_get_customers_page',
        'people_get_customers_for_selector',
    ] as $functionName) {
        $tests->assertContains('function ' . $functionName, $module, 'People read function is missing: ' . $functionName);
    }

    foreach ([
        'people_count_customers',
        'people_get_customers_page',
    ] as $functionName) {
        $tests->assertContains($functionName . '(', $customers, 'Customers page was not migrated to the People module: ' . $functionName);
    }
    $tests->assertContains(
        'people_get_customers_for_selector(',
        $orders,
        'Orders page was not migrated to the People module customer selector.'
    );

    foreach ([
        '/(?<!people_)\\bcount_customers\\s*\\(/',
        '/(?<!people_)\\bget_customers_page\\s*\\(/',
    ] as $legacyCallPattern) {
        $tests->assertFalse(
            preg_match($legacyCallPattern, $customers) === 1,
            'Customers page still calls a legacy People read function directly.'
        );
    }
    $tests->assertFalse(
        preg_match('/(?<!people_)\\bget_customers_for_selector\\s*\\(/', $orders) === 1,
        'Orders page still calls the legacy customer selector directly.'
    );

    foreach ([
        'count_customers' => 'people_count_customers',
        'get_customers_page' => 'people_get_customers_page',
        'get_customers_for_selector' => 'people_get_customers_for_selector',
    ] as $legacyName => $peopleName) {
        $wrapperPattern = '/function ' . preg_quote($legacyName, '/') . '\\s*\\([^)]*\\)\\s*\\{(?<body>.*?)\\n\\}/s';
        $matched = preg_match($wrapperPattern, $facade, $matches) === 1;
        $tests->assertTrue($matched, 'Customer compatibility wrapper is missing: ' . $legacyName);
        if ($matched) {
            $body = $matches['body'];
            $tests->assertContains($peopleName . '(', $body, 'Customer wrapper does not delegate to People module: ' . $legacyName);
            $tests->assertFalse(strpos($body, 'SELECT ') !== false, 'Customer wrapper must not retain SQL: ' . $legacyName);
        }
    }

    foreach ([
        'function get_customers($conn)',
        'function get_customer_by_id($conn, $id)',
    ] as $legacyReadFunction) {
        $tests->assertContains($legacyReadFunction, $facade, 'Uncalled customer legacy function changed: ' . $legacyReadFunction);
    }

    foreach (['function create_customer', 'function update_customer', 'function delete_customer'] as $writeFunction) {
        $tests->assertFalse(strpos($module, $writeFunction) !== false, 'People read module must not contain customer writes: ' . $writeFunction);
    }

    return $tests->assertions();
}
