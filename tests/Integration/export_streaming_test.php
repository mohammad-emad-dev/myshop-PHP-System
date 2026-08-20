<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/export.php';

function export_test_collect_rows(mysqli $conn, string $entity, ?array $filters = null): array
{
    $rows = [];
    $count = export_stream_entity(
        $conn,
        $entity,
        static function (array $row) use (&$rows): void {
            $rows[] = $row;
        },
        $filters
    );

    return [$count, $rows];
}

function export_test_sorted_ids(array $rows): array
{
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

function run_export_streaming_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $database = new DisposableDatabase();
    $rowCount = EXPORT_BATCH_SIZE + 1;

    try {
        $database->setup();
        $conn = $database->runtime;
        $schema = $database->schema();
        $prefix = 'QA_BATCH28_' . strtoupper(bin2hex(random_bytes(4)));
        $tests->assertTrue($rowCount > EXPORT_BATCH_SIZE, 'Streaming fixtures must cross the configured batch boundary.');

        $exportSource = file_get_contents($repository . '/includes/export.php');
        $endpointSource = file_get_contents($repository . '/public/export_report.php');
        $tests->assertTrue(is_string($exportSource), 'Streaming export helper could not be read.');
        $tests->assertTrue(is_string($endpointSource), 'CSV export endpoint could not be read.');
        $tests->assertSame(EXPORT_BATCH_SIZE, 250, 'CSV exports must use the reviewed bounded batch size.');
        $tests->assertTrue(substr_count($exportSource, 'LIMIT ?') >= 5, 'Every export query must use a bound LIMIT.');
        $tests->assertFalse(strpos($exportSource, 'fetch_all(') !== false, 'Streaming exports must not call fetch_all().');
        $tests->assertFalse(strpos($endpointSource, 'fetch_all(') !== false, 'The export endpoint must not call fetch_all().');
        foreach (['get_all_products(', 'get_stock_movements(', 'get_customers(', 'get_suppliers('] as $removedLoader) {
            $tests->assertFalse(strpos($endpointSource, $removedLoader) !== false, 'The export endpoint must not load a complete dataset with ' . $removedLoader);
        }
        foreach (['auth_verify_login($conn);', 'auth_require_admin($conn);', '\\xEF\\xBB\\xBF'] as $fixture) {
            $tests->assertContains($fixture, $endpointSource, 'The export response contract is incomplete.');
        }
        foreach (['myshop-products.csv', 'myshop-stock-movements.csv', 'myshop-customers.csv', 'myshop-suppliers.csv', 'myshop-orders.csv'] as $fixture) {
            $tests->assertContains($fixture, $exportSource, 'The export filename contract is incomplete.');
        }
        foreach ([
            'ID, Product Name, Category, Price ($), Current Stock, Alert Threshold, Valuation ($)',
            'Date & Time, Product Name, Staff Member, Movement Type, Quantity, Reason',
            'ID, Customer Name, Phone Number, Email Address, Physical Address, Added On',
            'Order ID, Date & Time, Cashier Name, Transaction Type, Total Amount ($)',
        ] as $header) {
            $tests->assertContains($header, str_replace(["'", '"'], '', $endpointSource), 'An existing CSV header is missing.');
        }

        $formulaStream = fopen('php://temp', 'w+b');
        if ($formulaStream === false) {
            throw new TestFailure('Unable to open the in-memory CSV formula test stream.');
        }
        export_csv_write_row($formulaStream, [export_csv_text('=SUM(A1:A2)'), export_csv_text('@command'), 'safe']);
        rewind($formulaStream);
        $formulaCsv = stream_get_contents($formulaStream);
        fclose($formulaStream);
        $tests->assertTrue(is_string($formulaCsv), 'The in-memory CSV formula test could not be read.');
        $tests->assertContains("'=SUM(A1:A2)", $formulaCsv, 'Formula-injection protection must remain active.');
        $tests->assertContains("'@command", $formulaCsv, 'Formula-injection protection must cover @-prefixed values.');

        $adminUsername = $prefix . '_ADMIN';
        test_execute(
            $schema,
            'INSERT INTO Staff (username, full_name, password, role, is_active) VALUES (?, ?, ?, ?, ?)',
            'ssssi',
            [$adminUsername, $prefix . ' Admin', password_hash($prefix . '_PASSWORD', PASSWORD_BCRYPT), 'admin', 1]
        );
        $staffId = (int)test_scalar($schema, 'SELECT id FROM Staff WHERE username = ?', 's', [$adminUsername]);

        $productIds = [];
        for ($index = 1; $index <= $rowCount; $index++) {
            $barcode = $prefix . '-BARCODE-' . $index;
            test_execute(
                $schema,
                'INSERT INTO Product (name, description, price, stock, alert_threshold, category_id, barcode) VALUES (?, ?, ?, ?, ?, ?, ?)',
                'ssdiiis',
                [$prefix . '_PRODUCT_' . str_pad((string)$index, 4, '0', STR_PAD_LEFT), 'Batch 28 export fixture', 10.50, $index, 5, 1, $barcode]
            );
            $productIds[] = (int)$schema->insert_id;
        }

        $customerIds = [];
        for ($index = 1; $index <= $rowCount; $index++) {
            test_execute(
                $schema,
                'INSERT INTO Customer (name, phone, email, address) VALUES (?, ?, ?, ?)',
                'ssss',
                [$prefix . '_CUSTOMER_' . str_pad((string)$index, 4, '0', STR_PAD_LEFT), '555-' . $index, $prefix . '_' . $index . '@example.test', 'Batch 28 export fixture']
            );
            $customerIds[] = (int)$schema->insert_id;
        }

        $supplierIds = [];
        for ($index = 1; $index <= $rowCount; $index++) {
            test_execute(
                $schema,
                'INSERT INTO Supplier (name, phone, email, address) VALUES (?, ?, ?, ?)',
                'ssss',
                [$prefix . '_SUPPLIER_' . str_pad((string)$index, 4, '0', STR_PAD_LEFT), '666-' . $index, $prefix . '_' . $index . '@example.test', 'Batch 28 export fixture']
            );
            $supplierIds[] = (int)$schema->insert_id;
        }

        $stockIds = [];
        foreach ($productIds as $productId) {
            test_execute(
                $schema,
                'INSERT INTO StockMovement (product_id, staff_id, quantity, movement_type, reason) VALUES (?, ?, ?, ?, ?)',
                'iiiss',
                [$productId, $staffId, 1, 'manual_adjustment', $prefix . '_STOCK_' . $productId]
            );
            $stockIds[] = (int)$schema->insert_id;
        }

        $orderIds = ['all' => [], 'sale' => [], 'purchase' => []];
        for ($index = 1; $index <= $rowCount; $index++) {
            $orderType = $index % 2 === 0 ? 'sale' : 'purchase';
            test_execute(
                $schema,
                'INSERT INTO `Order` (order_date, total_amount, staff_id, order_type) VALUES (?, ?, ?, ?)',
                'sdis',
                ['2026-01-15 12:00:00', 20.00 + $index, $staffId, $orderType]
            );
            $orderId = (int)$schema->insert_id;
            $orderIds['all'][] = $orderId;
            $orderIds[$orderType][] = $orderId;
        }

        [$productCount, $productRows] = export_test_collect_rows($conn, 'products');
        $tests->assertSame($rowCount, $productCount, 'Products must stream every fixture row.');
        $tests->assertSame($productIds, export_test_sorted_ids($productRows), 'Products must export every row exactly once.');
        [, $repeatProductRows] = export_test_collect_rows($conn, 'products');
        $tests->assertSame(array_column($productRows, 'id'), array_column($repeatProductRows, 'id'), 'Product export order must be deterministic.');

        [$stockCount, $stockRows] = export_test_collect_rows($conn, 'stock');
        $tests->assertSame($rowCount, $stockCount, 'Stock movements must stream every fixture row.');
        $tests->assertSame($stockIds, export_test_sorted_ids($stockRows), 'Stock movements must export every row exactly once.');

        [$customerCount, $customerRows] = export_test_collect_rows($conn, 'customers');
        $expectedCustomerIds = array_merge([1], $customerIds);
        sort($expectedCustomerIds, SORT_NUMERIC);
        $tests->assertSame($rowCount + 1, $customerCount, 'Customers must include the canonical walk-in customer and all fixture rows.');
        $tests->assertSame($expectedCustomerIds, export_test_sorted_ids($customerRows), 'Customers must export every row exactly once.');

        [$supplierCount, $supplierRows] = export_test_collect_rows($conn, 'suppliers');
        $expectedSupplierIds = array_merge([1], $supplierIds);
        sort($expectedSupplierIds, SORT_NUMERIC);
        $tests->assertSame($rowCount + 1, $supplierCount, 'Suppliers must include the canonical supplier and all fixture rows.');
        $tests->assertSame($expectedSupplierIds, export_test_sorted_ids($supplierRows), 'Suppliers must export every row exactly once.');

        $orderFilters = ['start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'type' => 'all'];
        [$orderCount, $orderRows] = export_test_collect_rows($conn, 'orders', $orderFilters);
        $tests->assertSame($rowCount, $orderCount, 'All order exports must stream every fixture row.');
        $tests->assertSame($orderIds['all'], export_test_sorted_ids($orderRows), 'All orders must export every row exactly once.');

        foreach (['sale', 'purchase'] as $orderType) {
            $orderFilters['type'] = $orderType;
            [$filteredCount, $filteredRows] = export_test_collect_rows($conn, 'orders', $orderFilters);
            $tests->assertSame(count($orderIds[$orderType]), $filteredCount, ucfirst($orderType) . ' order filtering must be preserved.');
            $tests->assertSame($orderIds[$orderType], export_test_sorted_ids($filteredRows), ucfirst($orderType) . ' orders must export exactly once.');
        }

        $tests->assertSame(
            'all',
            export_validate_order_filters('2026-01-01', '2026-01-31', 'unexpected')['type'],
            'Unknown order types must preserve the existing all-orders fallback.'
        );

        $invalidEntityRejected = false;
        try {
            export_validate_entity('not-an-entity');
        } catch (InvalidArgumentException $exception) {
            $invalidEntityRejected = true;
        }
        $tests->assertTrue($invalidEntityRejected, 'Invalid export entities must be rejected.');

        $invalidDateRejected = false;
        try {
            export_validate_order_filters('2026-01-01', 'not-a-date', 'all');
        } catch (InvalidArgumentException $exception) {
            $invalidDateRejected = true;
        }
        $tests->assertTrue($invalidDateRejected, 'Invalid order dates must be rejected.');

        $brokenConnection = new mysqli(
            $database->hostForTests(),
            $database->runtimeUsername,
            $database->runtimePassword,
            $database->databaseName,
            $database->portForTests()
        );
        $brokenConnection->close();
        $databaseFailureCaught = false;
        try {
            export_stream_entity($brokenConnection, 'products', static function (array $row): void {
            });
        } catch (Throwable $exception) {
            $databaseFailureCaught = true;
        }
        $tests->assertTrue($databaseFailureCaught, 'Database export failures must be handled as exceptions.');
        $tests->assertContains('Export is temporarily unavailable.', $exportSource, 'Export failures must use a generic user-facing message.');
        $tests->assertFalse(strpos($endpointSource, 'echo $exception->getMessage()') !== false, 'Export failures must not expose technical errors to users.');
    } finally {
        $database->cleanup();
    }

    return $tests->assertions();
}
