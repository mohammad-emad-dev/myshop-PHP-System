<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the Catalog read-side seam while the legacy facade remains public.
 * These are source-contract checks; database behavior is characterized by the
 * existing disposable-database integration tests.
 */
function run_catalog_read_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);

    $module = file_get_contents($repository . '/includes/catalog.php');
    $facade = file_get_contents($repository . '/includes/functions.php');
    $products = file_get_contents($repository . '/public/products.php');
    $orders = file_get_contents($repository . '/public/orders.php');
    $lookup = file_get_contents($repository . '/public/pos_product_lookup.php');

    foreach ([$module, $facade, $products, $orders, $lookup] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Catalog extraction source fixture could not be read.');
    }

    $tests->assertContains('declare(strict_types=1);', $module, 'Catalog module must use strict typing.');
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Catalog module must not require the compatibility facade.'
    );

    foreach ([
        'catalog_build_product_filter_sql',
        'catalog_get_pos_products',
        'catalog_get_pos_product_by_barcode',
        'catalog_count_products',
        'catalog_get_products_page',
        'catalog_get_product_by_id',
        'catalog_get_categories_for_selector',
    ] as $functionName) {
        $tests->assertContains('function ' . $functionName, $module, 'Catalog read function is missing: ' . $functionName);
    }

    foreach ([
        'catalog_get_products_page',
        'catalog_count_products',
        'catalog_get_categories_for_selector',
    ] as $functionName) {
        $tests->assertContains($functionName . '(', $products, 'Products page was not migrated to the Catalog module: ' . $functionName);
    }
    foreach ([
        'catalog_get_pos_products',
        'catalog_get_categories_for_selector',
        'catalog_get_product_by_id',
    ] as $functionName) {
        $tests->assertContains($functionName . '(', $orders, 'Orders page was not migrated to the Catalog module: ' . $functionName);
    }
    $tests->assertContains('catalog_get_pos_product_by_barcode(', $lookup, 'Barcode endpoint was not migrated to the Catalog module.');

    foreach ([
        '/(?<!catalog_)\\bget_products_page\\s*\\(/',
        '/(?<!catalog_)\\bcount_products\\s*\\(/',
        '/(?<!catalog_)\\bget_categories_for_selector\\s*\\(/',
    ] as $legacyCallPattern) {
        $tests->assertFalse(
            preg_match($legacyCallPattern, $products) === 1,
            'Products page still calls a legacy Catalog read function directly.'
        );
    }
    foreach ([
        '/(?<!catalog_)\\bget_pos_products\\s*\\(/',
        '/(?<!catalog_)\\bget_categories_for_selector\\s*\\(/',
        '/(?<!catalog_)\\bget_product_by_id\\s*\\(/',
    ] as $legacyCallPattern) {
        $tests->assertFalse(
            preg_match($legacyCallPattern, $orders) === 1,
            'Orders page still calls a legacy Catalog read function directly.'
        );
    }
    $tests->assertFalse(
        preg_match('/(?<!catalog_)\\bget_pos_product_by_barcode\\s*\\(/', $lookup) === 1,
        'Barcode endpoint still calls the legacy Catalog read function directly.'
    );

    foreach ([
        'get_pos_products' => 'catalog_get_pos_products',
        'get_pos_product_by_barcode' => 'catalog_get_pos_product_by_barcode',
        'count_products' => 'catalog_count_products',
        'get_products_page' => 'catalog_get_products_page',
        'get_product_by_id' => 'catalog_get_product_by_id',
        'get_categories_for_selector' => 'catalog_get_categories_for_selector',
    ] as $legacyName => $catalogName) {
        $wrapperPattern = '/function ' . preg_quote($legacyName, '/') . '\\s*\\([^)]*\\)\\s*\\{(?<body>.*?)\\n\\}/s';
        $matched = preg_match($wrapperPattern, $facade, $matches) === 1;
        $tests->assertTrue($matched, 'Compatibility wrapper is missing: ' . $legacyName);
        if ($matched) {
            $tests->assertContains($catalogName . '(', $matches['body'], 'Compatibility wrapper does not delegate: ' . $legacyName);
            $tests->assertFalse(stripos($matches['body'], 'SELECT ') !== false, 'Compatibility wrapper contains duplicated SQL: ' . $legacyName);
        }
    }

    foreach ([
        'function create_product',
        'function update_product',
        'function delete_product',
        'function create_order',
    ] as $writeFunction) {
        $tests->assertContains($writeFunction, $facade, 'Catalog extraction moved a protected write function: ' . $writeFunction);
    }

    return $tests->assertions();
}
