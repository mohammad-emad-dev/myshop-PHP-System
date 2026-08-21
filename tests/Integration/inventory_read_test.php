<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function run_inventory_read_integration_tests(): int
{
    $tests = new TestContext();
    $database = new DisposableDatabase();

    try {
        $database->setup();
        $conn = $database->runtime;
        $prefix = 'QA_3G_' . strtoupper(bin2hex(random_bytes(4)));

        test_execute(
            $conn,
            'INSERT INTO Category (name, description) VALUES (?, ?)',
            'ss',
            [$prefix . ' Category', 'Low-stock integration fixture']
        );
        $categoryId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$prefix . ' Category']);
        $tests->assertTrue($categoryId > 0, 'Low-stock category fixture was not created.');

        $products = [
            [$prefix . ' Zero B', 0, 0, $categoryId],
            [$prefix . ' Zero A', 0, 0, null],
            [$prefix . ' Low', 2, 5, $categoryId],
            [$prefix . ' Equal', 5, 5, null],
            [$prefix . ' Above', 6, 5, $categoryId],
        ];
        foreach ($products as $product) {
            test_execute(
                $conn,
                'INSERT INTO Product (name, description, price, stock, image_path, alert_threshold, category_id, barcode) VALUES (?, ?, ?, ?, NULL, ?, ?, ?)',
                'ssdiiis',
                [$product[0], 'Low-stock fixture', 10.00, $product[1], $product[2], $product[3], $prefix . '_' . strtoupper(str_replace(' ', '_', $product[0]))]
            );
        }

        $rows = inventory_get_low_stock_products($conn);
        $tests->assertCount(4, $rows, 'Low-stock products must include below-threshold and threshold-equal rows only.');
        $tests->assertSame($prefix . ' Zero A', $rows[0]['name'], 'Low-stock ordering must sort same-stock rows by name.');
        $tests->assertSame($prefix . ' Zero B', $rows[1]['name'], 'Low-stock secondary ordering is incorrect.');
        $tests->assertSame($prefix . ' Low', $rows[2]['name'], 'Below-threshold product is missing or misordered.');
        $tests->assertSame($prefix . ' Equal', $rows[3]['name'], 'Threshold equality must be included.');
        $tests->assertSame($prefix . ' Category', $rows[1]['category_name'], 'Category name alias is incorrect.');
        $tests->assertSame(null, $rows[0]['category_name'], 'Products without a category must retain a null category alias.');
        $tests->assertSame(null, $rows[3]['category_name'], 'Null category behavior must be preserved.');
        $tests->assertFalse(
            in_array($prefix . ' Above', array_column($rows, 'name'), true),
            'Products above alert_threshold must be excluded.'
        );
        $tests->assertSame(
            $rows,
            get_low_stock_products($conn),
            'The legacy low-stock wrapper must preserve the focused service result.'
        );

        foreach ([25, 50, 100] as $limit) {
            $tests->assertCount(
                4,
                inventory_get_low_stock_products($conn, $limit),
                'Allowed low-stock limit must preserve all matching fixture rows: ' . $limit
            );
        }
        $tests->assertCount(
            4,
            inventory_get_low_stock_products($conn, 10),
            'An unsupported low-stock limit must normalize to the default page size.'
        );

        $closedConnection = new mysqli(
            $database->hostForTests(),
            $database->runtimeUsername,
            $database->runtimePassword,
            $database->databaseName,
            $database->portForTests()
        );
        $closedConnection->close();
        $tests->assertSame(
            [],
            inventory_get_low_stock_products($closedConnection),
            'Closed low-stock connections must preserve the empty-array fallback.'
        );
    } finally {
        $database->cleanup();
    }

    return $tests->assertions();
}
