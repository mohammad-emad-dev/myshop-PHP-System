<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function data_volume_insert_many(mysqli $conn, string $sql, string $types, int $count, callable $parameters_for_row): array
{
    $statement = $conn->prepare($sql);
    if (!$statement) {
        throw new TestFailure('Data-volume fixture statement could not be prepared.');
    }

    $ids = [];
    try {
        for ($index = 1; $index <= $count; $index++) {
            $parameters = $parameters_for_row($index);
            test_bind($statement, $types, $parameters);
            $statement->execute();
            $ids[] = (int)$conn->insert_id;
        }
    } finally {
        $statement->close();
    }

    return $ids;
}

function data_volume_assert_newest_first(TestContext $tests, array $rows, string $dateKey, string $idKey, string $label): void
{
    for ($index = 1, $rowCount = count($rows); $index < $rowCount; $index++) {
        $previousDate = (string)($rows[$index - 1][$dateKey] ?? '');
        $currentDate = (string)($rows[$index][$dateKey] ?? '');
        $previousId = (int)($rows[$index - 1][$idKey] ?? 0);
        $currentId = (int)($rows[$index][$idKey] ?? 0);
        $tests->assertTrue(
            $previousDate > $currentDate || ($previousDate === $currentDate && $previousId > $currentId),
            $label . ' ordering is not deterministic newest-first.'
        );
    }
}

function data_volume_assert_name_order(TestContext $tests, array $rows, string $label): void
{
    for ($index = 1, $rowCount = count($rows); $index < $rowCount; $index++) {
        $previousName = (string)($rows[$index - 1]['name'] ?? '');
        $currentName = (string)($rows[$index]['name'] ?? '');
        $previousId = (int)($rows[$index - 1]['id'] ?? 0);
        $currentId = (int)($rows[$index]['id'] ?? 0);
        $tests->assertTrue(
            $previousName < $currentName || ($previousName === $currentName && $previousId < $currentId),
            $label . ' ordering is not deterministic name/id order.'
        );
    }
}

function data_volume_explain_has_plan(TestContext $tests, mysqli $conn, string $sql, string $label): void
{
    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $tests->assertTrue(is_array($row) && array_key_exists('type', $row), 'EXPLAIN did not return a plan for ' . $label . '.');
}

function run_data_volume_integration_tests(): int
{
    $tests = new TestContext();
    $database = new DisposableDatabase();
    $fixtureCount = 600;

    try {
        $database->setup();
        $conn = $database->runtime;
        $schema = $database->schema();
        $prefix = 'QA_VOL_' . strtoupper(bin2hex(random_bytes(4)));

        $staffIds = data_volume_insert_many(
            $schema,
            'INSERT INTO Staff (username, password, full_name, role, is_active) VALUES (?, ?, ?, ?, ?)',
            'ssssi',
            2,
            static fn(int $index): array => [
                $prefix . '_STAFF_' . $index,
                password_hash($prefix . '_PASSWORD_' . $index, PASSWORD_BCRYPT),
                $prefix . ' Staff ' . $index,
                $index === 1 ? 'admin' : 'cashier',
                1,
            ]
        );
        $tests->assertSame(2, count($staffIds), 'Large-data staff fixtures were not created.');

        $categoryIds = data_volume_insert_many(
            $schema,
            'INSERT INTO Category (name, description) VALUES (?, ?)',
            'ss',
            $fixtureCount,
            static fn(int $index): array => [$prefix . '_CATEGORY_' . str_pad((string)$index, 4, '0', STR_PAD_LEFT), 'Phase 5C volume fixture']
        );
        $productIds = data_volume_insert_many(
            $schema,
            'INSERT INTO Product (name, description, price, stock, alert_threshold, category_id, barcode) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'ssdiiis',
            $fixtureCount,
            static fn(int $index): array => [
                $prefix . '_PRODUCT_' . str_pad((string)$index, 4, '0', STR_PAD_LEFT),
                'Phase 5C volume fixture',
                10.00 + ($index % 7),
                $index,
                5,
                $categoryIds[($index - 1) % count($categoryIds)],
                $prefix . '_BARCODE_' . $index,
            ]
        );
        $customerIds = data_volume_insert_many(
            $schema,
            'INSERT INTO Customer (name, phone, email, address) VALUES (?, ?, ?, ?)',
            'ssss',
            $fixtureCount,
            static fn(int $index): array => [
                $prefix . '_CUSTOMER_' . str_pad((string)$index, 4, '0', STR_PAD_LEFT),
                '555-' . $index,
                $prefix . '_' . $index . '@example.test',
                'Phase 5C volume fixture',
            ]
        );
        $supplierIds = data_volume_insert_many(
            $schema,
            'INSERT INTO Supplier (name, phone, email, address) VALUES (?, ?, ?, ?)',
            'ssss',
            $fixtureCount,
            static fn(int $index): array => [
                $prefix . '_SUPPLIER_' . str_pad((string)$index, 4, '0', STR_PAD_LEFT),
                '666-' . $index,
                $prefix . '_' . $index . '@supplier.test',
                'Phase 5C volume fixture',
            ]
        );
        $tests->assertSame($fixtureCount, count($productIds), 'Large-data product fixtures were not created.');
        $tests->assertSame($fixtureCount, count($customerIds), 'Large-data customer fixtures were not created.');
        $tests->assertSame($fixtureCount, count($supplierIds), 'Large-data supplier fixtures were not created.');

        $movementIds = data_volume_insert_many(
            $schema,
            'INSERT INTO StockMovement (product_id, staff_id, quantity, movement_type, reason) VALUES (?, ?, ?, ?, ?)',
            'iiiss',
            $fixtureCount,
            static fn(int $index): array => [
                $productIds[$index - 1],
                $staffIds[0],
                1,
                'manual_adjustment',
                $prefix . '_MOVEMENT_' . $index,
            ]
        );
        $orderIds = data_volume_insert_many(
            $schema,
            'INSERT INTO `Order` (order_date, total_amount, staff_id, order_type, customer_id, supplier_id) VALUES (?, ?, ?, ?, ?, ?)',
            'sdisii',
            $fixtureCount,
            static fn(int $index): array => [
                '2026-02-01 12:00:00',
                20.00 + $index,
                $staffIds[($index - 1) % count($staffIds)],
                $index % 2 === 0 ? 'sale' : 'purchase',
                $customerIds[$index - 1],
                $supplierIds[$index - 1],
            ]
        );
        $tests->assertSame($fixtureCount, count($movementIds), 'Large-data stock fixtures were not created.');
        $tests->assertSame($fixtureCount, count($orderIds), 'Large-data order fixtures were not created.');

        $productPage = catalog_get_products_page($conn, $prefix, '', 50, 0);
        $tests->assertSame(50, count($productPage), 'Product list page must clip the 600-row fixture to its configured bound.');
        data_volume_assert_newest_first($tests, $productPage, 'created_at', 'id', 'Product page');

        $posProducts = catalog_get_pos_products($conn, $prefix, 100);
        $tests->assertSame(100, count($posProducts), 'POS search must clip the 600-row fixture to its configured bound.');
        foreach ($posProducts as $row) {
            $tests->assertTrue(strpos((string)$row['name'], $prefix) !== false, 'POS search returned a row outside its search scope.');
        }

        $categoryPage = catalog_get_categories_page($conn, $prefix, 50, 0);
        $tests->assertSame(50, count($categoryPage), 'Category list page must clip the 600-row fixture to its configured bound.');
        data_volume_assert_name_order($tests, $categoryPage, 'Category page');
        $categorySelector = catalog_get_categories_for_selector($conn, 100);
        $tests->assertSame(100, count($categorySelector), 'Category selector must clip the 601-row dataset to its configured bound.');
        data_volume_assert_name_order($tests, $categorySelector, 'Category selector');

        $customerPage = people_get_customers_page($conn, $prefix, 50, 0);
        $tests->assertSame(50, count($customerPage), 'Customer list page must clip the 600-row fixture to its configured bound.');
        data_volume_assert_name_order($tests, $customerPage, 'Customer page');
        $customerSelector = people_get_customers_for_selector($conn, 100);
        $tests->assertSame(100, count($customerSelector), 'Customer selector must clip the 601-row dataset to its configured bound.');
        data_volume_assert_name_order($tests, $customerSelector, 'Customer selector');

        $supplierPage = people_get_suppliers_page($conn, $prefix, 50, 0);
        $tests->assertSame(50, count($supplierPage), 'Supplier list page must clip the 600-row fixture to its configured bound.');
        data_volume_assert_name_order($tests, $supplierPage, 'Supplier page');
        $supplierSelector = people_get_suppliers_for_selector($conn, 100);
        $tests->assertSame(100, count($supplierSelector), 'Supplier selector must clip the 601-row dataset to its configured bound.');
        data_volume_assert_name_order($tests, $supplierSelector, 'Supplier selector');

        $movementPage = inventory_get_stock_movements_page($conn, null, 50, 0);
        $tests->assertSame(50, count($movementPage), 'Stock movement page must clip the 600-row fixture to its configured bound.');
        data_volume_assert_newest_first($tests, $movementPage, 'created_at', 'id', 'Stock movement page');
        $scopedMovementPage = inventory_get_stock_movements_page($conn, $productIds[0], 50, 0);
        $tests->assertTrue(count($scopedMovementPage) <= 50, 'Scoped stock movement page exceeded its configured bound.');
        foreach ($scopedMovementPage as $row) {
            $tests->assertSame($productIds[0], (int)$row['product_id'], 'Scoped stock movement page crossed product scope.');
        }

        $orderPage = orders_get_page($conn, null, 'all', 50, 0);
        $tests->assertSame(50, count($orderPage), 'Order history page must clip the 600-row fixture to its configured bound.');
        data_volume_assert_newest_first($tests, $orderPage, 'order_date', 'id', 'Order page');
        $staffOrderPage = orders_get_page($conn, $staffIds[1], 'all', 50, 0);
        $tests->assertSame(50, count($staffOrderPage), 'Staff-scoped order page must clip its 300-row scope to the configured bound.');
        foreach ($staffOrderPage as $row) {
            $tests->assertSame($staffIds[1], (int)$row['staff_id'], 'Staff-scoped order page crossed authorization scope.');
        }

        $dashboardTop = dashboard_get_top_selling_products($conn, 5);
        $dashboardCategories = dashboard_get_category_sales_distribution($conn, null, 100);
        $dashboardChart = dashboard_get_chart_data($conn, 31);
        $tests->assertTrue(count($dashboardTop) <= 5, 'Dashboard top-selling report exceeded its configured bound.');
        $tests->assertTrue(count($dashboardCategories) <= 100, 'Dashboard category report exceeded its configured bound.');
        $tests->assertSame(31, count($dashboardChart), 'Dashboard chart window must remain explicitly bounded.');
        $tests->assertTrue(is_array(dashboard_get_stats($conn)), 'Dashboard aggregate report must preserve its array contract.');

        data_volume_explain_has_plan(
            $tests,
            $schema,
            'EXPLAIN SELECT id FROM Product WHERE category_id = 2 ORDER BY created_at DESC, id DESC LIMIT 50',
            'product category list'
        );
        data_volume_explain_has_plan(
            $tests,
            $schema,
            'EXPLAIN SELECT id FROM StockMovement WHERE product_id = 2 ORDER BY created_at DESC, id DESC LIMIT 50',
            'scoped stock history'
        );
        data_volume_explain_has_plan(
            $tests,
            $schema,
            'EXPLAIN SELECT id FROM `Order` WHERE staff_id = 2 ORDER BY order_date DESC, id DESC LIMIT 50',
            'staff-scoped order history'
        );
        data_volume_explain_has_plan(
            $tests,
            $schema,
            'EXPLAIN SELECT id FROM Customer ORDER BY name ASC, id ASC LIMIT 100',
            'customer selector'
        );
        data_volume_explain_has_plan(
            $tests,
            $schema,
            'EXPLAIN SELECT id FROM Supplier ORDER BY name ASC, id ASC LIMIT 100',
            'supplier selector'
        );
    } finally {
        $database->cleanup();
    }

    return $tests->assertions();
}
