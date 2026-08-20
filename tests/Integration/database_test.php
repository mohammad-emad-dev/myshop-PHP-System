<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function run_integration_tests(): int
{
    $tests = new TestContext();
    $database = new DisposableDatabase();
    $server = null;

    try {
        $database->setup();
        $conn = $database->runtime;
        $schema = $database->schema();
        $prefix = 'QA_BATCH20_' . strtoupper(bin2hex(random_bytes(4)));

        $expectedTables = [
            'Staff', 'Category', 'Customer', 'Supplier', 'Product',
            'Order', 'OrderDetail', 'StockMovement', 'LoginRateLimit', 'AuditLog'
        ];
        $tableRows = test_fetch_all(
            $schema,
            "SELECT table_name AS table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        );
        $actualTables = array_map(static fn(array $row): string => $row['table_name'], $tableRows);
        sort($actualTables);
        $expectedSorted = $expectedTables;
        sort($expectedSorted);
        $tests->assertSame($expectedSorted, $actualTables, 'Canonical schema/migration table set is incomplete.');

        $rateLimitPasswordColumns = (int)test_scalar(
            $schema,
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'LoginRateLimit'
               AND column_name LIKE '%password%'"
        );
        $tests->assertSame(0, $rateLimitPasswordColumns, 'The rate-limit table must not contain password fields.');
        $auditPasswordColumns = (int)test_scalar(
            $schema,
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'AuditLog'
               AND column_name LIKE '%password%'"
        );
        $tests->assertSame(0, $auditPasswordColumns, 'The audit table must not contain password fields.');
        $auditIndexes = array_map(
            static fn(array $row): string => (string)$row['audit_index_name'],
            test_fetch_all(
                $schema,
                "SELECT DISTINCT index_name AS audit_index_name FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'AuditLog'
                 ORDER BY audit_index_name"
            )
        );
        sort($auditIndexes);
        $expectedAuditIndexes = ['PRIMARY', 'idx_audit_action_created', 'idx_audit_actor_created', 'idx_audit_created_at', 'idx_audit_entity_created', 'idx_audit_outcome_created'];
        sort($expectedAuditIndexes);
        $tests->assertSame(
            $expectedAuditIndexes,
            $auditIndexes,
            'Audit table indexes are incomplete or incorrectly named.'
        );
        $tests->assertSame(
            1,
            (int)test_scalar(
                $schema,
                "SELECT COUNT(*) FROM information_schema.referential_constraints
                 WHERE constraint_schema = DATABASE() AND table_name = 'AuditLog'
                   AND constraint_name = 'fk_audit_actor'"
            ),
            'Audit actor foreign-key protection is missing.'
        );

        $grantResult = $schema->query(
            "SHOW GRANTS FOR " . test_sql_string($schema, $database->runtimeUsername) . "@'%'"
        );
        $grantLines = [];
        while ($grantRow = $grantResult->fetch_row()) {
            $grantLines[] = (string)$grantRow[0];
        }
        $grantResult->free();
        $grantText = implode("\n", $grantLines);
        $tests->assertTrue(
            preg_match('/SELECT, INSERT, UPDATE, DELETE ON `'.preg_quote($database->databaseName, '/').'`\.\*/i', $grantText) === 1,
            'The disposable runtime account must have only application CRUD privileges.'
        );
        $tests->assertFalse(stripos($grantText, 'ALL PRIVILEGES') !== false, 'The test runtime account must not have ALL PRIVILEGES.');
        $tests->assertFalse(
            preg_match('/\b(CREATE|ALTER|DROP|INDEX|GRANT OPTION)\b/i', $grantText) === 1,
            'The test runtime account must not have schema or grant privileges.'
        );

        $adminUsername = $prefix . '_ADMIN';
        $cashierUsername = $prefix . '_CASHIER';
        $adminPassword = $prefix . '_' . bin2hex(random_bytes(16));
        $cashierPassword = $prefix . '_' . bin2hex(random_bytes(16));
        test_execute(
            $schema,
            'INSERT INTO Staff (username, full_name, password, role, is_active) VALUES (?, ?, ?, ?, ?)',
            'ssssi',
            [$adminUsername, $prefix . ' Admin', password_hash($adminPassword, PASSWORD_BCRYPT), 'admin', 1]
        );
        test_execute(
            $schema,
            'INSERT INTO Staff (username, full_name, password, role, is_active) VALUES (?, ?, ?, ?, ?)',
            'ssssi',
            [$cashierUsername, $prefix . ' Cashier', password_hash($cashierPassword, PASSWORD_BCRYPT), 'cashier', 1]
        );
        $adminId = (int)test_scalar($conn, 'SELECT id FROM Staff WHERE username = ?', 's', [$adminUsername]);
        $cashierId = (int)test_scalar($conn, 'SELECT id FROM Staff WHERE username = ?', 's', [$cashierUsername]);
        $tests->assertTrue($adminId > 0 && $cashierId > 0, 'Disposable staff fixtures were not created.');
        $tests->assertTrue(count(get_staff_members($conn, 100, 0)) <= 100, 'Staff list loading must be bounded.');
        $adminRecord = test_fetch_one($conn, 'SELECT password FROM Staff WHERE id = ?', 'i', [$adminId]);
        $tests->assertTrue(password_verify($adminPassword, $adminRecord['password']), 'Seeded password hashes must verify.');

        $customerName = $prefix . '_CUSTOMER';
        $supplierName = $prefix . '_SUPPLIER';
        $tests->assertTrue(create_customer($conn, $customerName, '+1 (555)-100', $prefix . '@example.com', 'Test address'), 'Customer creation failed.');
        $customerId = (int)test_scalar($conn, 'SELECT id FROM Customer WHERE name = ?', 's', [$customerName]);
        $tests->assertTrue($customerId > 1, 'Created customer ID was not found.');
        $tests->assertTrue(update_customer($conn, $customerId, $customerName . '_UPDATED', '555-200', 'updated@example.com', 'Updated address'), 'Customer update failed.');
        $tests->assertSame($customerName . '_UPDATED', test_scalar($conn, 'SELECT name FROM Customer WHERE id = ?', 'i', [$customerId]), 'Customer update was not persisted.');
        $tests->assertTrue(delete_customer($conn, $customerId), 'Customer deletion failed.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Customer WHERE id = ?', 'i', [$customerId]), 'Customer deletion was not persisted.');
        $customerId = (int)test_scalar($conn, 'SELECT id FROM Customer WHERE id = 1');

        $tests->assertTrue(create_supplier($conn, $supplierName, '+1 (555)-300', 'supplier@example.com', 'Supplier address'), 'Supplier creation failed.');
        $supplierId = (int)test_scalar($conn, 'SELECT id FROM Supplier WHERE name = ?', 's', [$supplierName]);
        $tests->assertTrue($supplierId > 1, 'Created supplier ID was not found.');
        $tests->assertTrue(update_supplier($conn, $supplierId, $supplierName . '_UPDATED', '555-400', 'supplier2@example.com', 'Updated supplier address'), 'Supplier update failed.');
        $tests->assertSame($supplierName . '_UPDATED', test_scalar($conn, 'SELECT name FROM Supplier WHERE id = ?', 'i', [$supplierId]), 'Supplier update was not persisted.');
        $tests->assertTrue(delete_supplier($conn, $supplierId), 'Supplier deletion failed.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Supplier WHERE id = ?', 'i', [$supplierId]), 'Supplier deletion was not persisted.');
        $supplierId = (int)test_scalar($conn, 'SELECT id FROM Supplier WHERE id = 1');

        $categoryName = $prefix . '_CATEGORY';
        $tests->assertTrue(create_category($conn, $categoryName, 'Batch 20 category'), 'Category creation failed.');
        $categoryId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$categoryName]);
        $tests->assertTrue($categoryId > 1, 'Created category ID was not found.');
        $tests->assertTrue(update_category($conn, $categoryId, $categoryName . '_UPDATED', 'Updated category'), 'Category update failed.');
        $tests->assertFalse(update_category($conn, 1, $prefix . '_NOT_GENERAL', 'Invalid General rename'), 'The default General category must not be renamed.');
        $tests->assertFalse(delete_category($conn, 1), 'The default General category must not be deleted.');

        $historyBarcode = $prefix . '-HISTORY';
        $tests->assertTrue(create_product($conn, $adminId, $prefix . '_HISTORY_PRODUCT', 'History product', 12.34, 20, null, 5, $categoryId, $historyBarcode), 'Historical product creation failed.');
        $historyProductId = (int)test_scalar($conn, 'SELECT id FROM Product WHERE barcode = ?', 's', [$historyBarcode]);
        $tests->assertTrue($historyProductId > 0, 'Historical product ID was not found.');
        $tests->assertTrue(update_product($conn, $adminId, $historyProductId, $prefix . '_HISTORY_PRODUCT_UPDATED', 'Updated history product', 13.34, 22, null, 6, $categoryId, $historyBarcode), 'Product update failed.');
        $tests->assertSame(22, (int)test_scalar($conn, 'SELECT stock FROM Product WHERE id = ?', 'i', [$historyProductId]), 'Product stock update was not persisted.');

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SESSION = ['staff_id' => $adminId];
        $tests->assertTrue(
            audit_log_current_actor($conn, 'qa_audit_success', 'Product', $historyProductId, true, [
                'safe_reason' => 'test-success',
                'password' => 'must-not-be-stored',
                'csrf_token' => 'must-not-be-stored',
                'session_id' => 'must-not-be-stored',
            ]),
            'Successful audit insertion failed.'
        );
        $tests->assertTrue(
            audit_log($conn, $cashierId, 'qa_audit_failure', 'Order', null, false, ['reason' => 'test-failure']),
            'Failed audit insertion failed.'
        );
        $auditSuccess = get_audit_logs_page($conn, ['action' => 'qa_audit_success'], 10, 0);
        $tests->assertCount(1, $auditSuccess, 'Audit success filter did not return one event.');
        $tests->assertSame($adminId, (int)$auditSuccess[0]['actor_staff_id'], 'Audit actor was not sourced from the authenticated staff ID.');
        $tests->assertSame('success', $auditSuccess[0]['outcome'], 'Audit success outcome is incorrect.');
        $tests->assertContains('safe_reason', (string)$auditSuccess[0]['metadata'], 'Safe audit metadata was not stored.');
        $tests->assertFalse(
            preg_match('/password|csrf|token|session/i', (string)$auditSuccess[0]['metadata']) === 1,
            'Sensitive audit metadata was stored.'
        );
        $auditDate = substr((string)$auditSuccess[0]['created_at'], 0, 10);
        $tests->assertSame(
            1,
            count_audit_logs($conn, [
                'action' => 'qa_audit_success',
                'actor_staff_id' => $adminId,
                'entity_type' => 'Product',
                'outcome' => 'success',
                'date_from' => $auditDate,
                'date_to' => $auditDate,
            ]),
            'Audit action, actor, entity, outcome, and date filters are inconsistent.'
        );
        $tests->assertSame(1, count_audit_logs($conn, ['action' => 'qa_audit_failure', 'outcome' => 'failure']), 'Audit failure filter is incorrect.');
        $GLOBALS['current_staff_record'] = ['role' => 'cashier'];
        $_SESSION['staff_id'] = $cashierId;
        $tests->assertFalse(is_admin(), 'Cashiers must not satisfy the admin audit-log authorization check.');
        $GLOBALS['current_staff_record'] = ['role' => 'admin'];
        $_SESSION['staff_id'] = $adminId;
        $tests->assertTrue(is_admin(), 'Admins must satisfy the admin audit-log authorization check.');
        $tests->assertTrue(count(get_audit_logs_page($conn, [], 100, 0)) <= 100, 'Audit log page must be bounded.');
        $tests->assertCount(0, get_audit_logs_page($conn, [], 999999, 999999), 'Empty audit log pages must return an empty list.');

        $reassignmentProductId = $historyProductId;
        $tests->assertTrue(delete_category($conn, $categoryId), 'Category deletion/reassignment failed.');
        $tests->assertSame(1, (int)test_scalar($conn, 'SELECT category_id FROM Product WHERE id = ?', 'i', [$reassignmentProductId]), 'Product category was not reassigned to General.');

        $tests->assertFalse(delete_product($conn, $historyProductId), 'Products with stock history must not be deleted.');
        $tests->assertSame($historyProductId, (int)test_scalar($conn, 'SELECT id FROM Product WHERE id = ?', 'i', [$historyProductId]), 'Historical product was unexpectedly deleted.');

        $noHistoryBarcode = $prefix . '-NOHISTORY';
        $tests->assertTrue(create_product($conn, $adminId, $prefix . '_NO_HISTORY_PRODUCT', 'No history product', 4.00, 0, null, 5, null, $noHistoryBarcode), 'No-history product creation failed.');
        $noHistoryProductId = (int)test_scalar($conn, 'SELECT id FROM Product WHERE barcode = ?', 's', [$noHistoryBarcode]);
        $tests->assertTrue(delete_product($conn, $noHistoryProductId), 'Product without historical rows should be deletable.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Product WHERE id = ?', 'i', [$noHistoryProductId]), 'No-history product deletion was not persisted.');

        $paginationCategoryName = $prefix . '_PAGINATION_CATEGORY';
        $tests->assertTrue(create_category($conn, $paginationCategoryName, 'Pagination category'), 'Pagination category creation failed.');
        $paginationCategoryId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$paginationCategoryName]);
        for ($index = 1; $index <= 11; $index++) {
            $barcode = $prefix . '-PAGE-' . $index;
            $tests->assertTrue(
                create_product($conn, $adminId, $prefix . '_PAGE_' . $index, 'Paged product', 2.50 + $index, 0, null, 5, $paginationCategoryId, $barcode),
                'Paged product creation failed.'
            );
        }
        $tests->assertSame(11, count_products($conn, $prefix . '_PAGE_', ''), 'Product search count is incorrect.');
        $tests->assertSame(11, count_products($conn, $paginationCategoryName, ''), 'Category-name product search is incorrect.');
        $tests->assertCount(10, get_products_page($conn, $prefix . '_PAGE_', '', 10, 0), 'First product page size is incorrect.');
        $tests->assertCount(1, get_products_page($conn, $prefix . '_PAGE_', '', 10, 10), 'Last product page size is incorrect.');
        $tests->assertSame(11, count_products($conn, $prefix . '_PAGE_', 'low_stock'), 'Low-stock count is incorrect.');
        $tests->assertCount(1, get_products_page($conn, $prefix . '-PAGE-7', '', 10, 0), 'Barcode search did not return one matching product.');
        $tests->assertSame(0, count_products($conn, $prefix . '_DOES_NOT_EXIST', ''), 'Empty product search should return zero results.');
        $tests->assertSame(1, count_categories($conn, $paginationCategoryName), 'Category search count is incorrect.');
        $paginationCategoryRows = get_categories_page($conn, $paginationCategoryName, 10, 0);
        $tests->assertCount(1, $paginationCategoryRows, 'Category search page is incorrect.');
        $tests->assertSame(11, (int)$paginationCategoryRows[0]['product_count'], 'Category product count is incorrect.');
        $categoryRows = get_categories_page($conn, '', 100, 0);
        $categoryNames = array_map(static fn(array $row): string => (string)$row['name'], $categoryRows);
        $sortedCategoryNames = $categoryNames;
        sort($sortedCategoryNames, SORT_NATURAL | SORT_FLAG_CASE);
        $tests->assertSame($sortedCategoryNames, $categoryNames, 'Categories must be ordered by name.');
        $tests->assertTrue(count(get_categories_page($conn, '', 10, 0)) <= 10, 'Category first page is not bounded.');
        $tests->assertCount(0, get_categories_page($conn, '', 10, 999), 'Empty category page should return an empty list.');
        $tests->assertCount(0, get_categories_page($conn, $prefix . '_MISSING_CATEGORY', 10, 0), 'Empty category search should return zero results.');
        $tests->assertTrue(count(get_categories_for_selector($conn, 100)) <= 100, 'Category selector must be bounded.');
        $tests->assertTrue(count(get_pos_products($conn, '', 100)) <= 100, 'POS product loading must be bounded.');
        $tests->assertCount(1, get_pos_products($conn, $prefix . '-PAGE-7', 100), 'POS barcode/name search did not return the matching product.');
        $tests->assertSame($historyProductId, (int)get_pos_product_by_barcode($conn, $historyBarcode)['id'], 'POS exact barcode lookup failed.');

        $pagedCustomerOne = $prefix . '_CUSTOMER_PAGE_1';
        $pagedCustomerTwo = $prefix . '_CUSTOMER_PAGE_2';
        $tests->assertTrue(create_customer($conn, $pagedCustomerOne, '555-501', 'page1@example.com', 'Page 1'), 'Paged customer fixture one failed.');
        $tests->assertTrue(create_customer($conn, $pagedCustomerTwo, '555-502', 'page2@example.com', 'Page 2'), 'Paged customer fixture two failed.');
        $tests->assertTrue(count_customers($conn, $prefix . '_CUSTOMER_PAGE_') >= 2, 'Customer search count is incorrect.');
        $tests->assertCount(1, get_customers_page($conn, $pagedCustomerOne, 10, 0), 'Customer search page is incorrect.');
        $customerPageRows = get_customers_page($conn, $prefix . '_CUSTOMER_PAGE_', 10, 0);
        $tests->assertTrue(count($customerPageRows) <= 10, 'Customer first page is not bounded.');
        $tests->assertCount(1, get_customers_page($conn, $prefix . '_CUSTOMER_PAGE_', 10, 1), 'Customer middle/last page size is incorrect.');
        $tests->assertCount(0, get_customers_page($conn, $prefix . '_CUSTOMER_PAGE_', 10, 99), 'Empty customer page should return an empty list.');
        $tests->assertTrue(count(get_customers_for_selector($conn, 100)) <= 100, 'Customer selector must be bounded.');

        $pagedSupplierOne = $prefix . '_SUPPLIER_PAGE_1';
        $pagedSupplierTwo = $prefix . '_SUPPLIER_PAGE_2';
        $tests->assertTrue(create_supplier($conn, $pagedSupplierOne, '555-601', 'supplier1@example.com', 'Page 1'), 'Paged supplier fixture one failed.');
        $tests->assertTrue(create_supplier($conn, $pagedSupplierTwo, '555-602', 'supplier2@example.com', 'Page 2'), 'Paged supplier fixture two failed.');
        $tests->assertTrue(count_suppliers($conn, $prefix . '_SUPPLIER_PAGE_') >= 2, 'Supplier search count is incorrect.');
        $tests->assertCount(1, get_suppliers_page($conn, $pagedSupplierOne, 10, 0), 'Supplier search page is incorrect.');
        $tests->assertTrue(count(get_suppliers_page($conn, $prefix . '_SUPPLIER_PAGE_', 10, 0)) <= 10, 'Supplier first page is not bounded.');
        $tests->assertCount(1, get_suppliers_page($conn, $prefix . '_SUPPLIER_PAGE_', 10, 1), 'Supplier middle/last page size is incorrect.');
        $tests->assertCount(0, get_suppliers_page($conn, $prefix . '_SUPPLIER_PAGE_', 10, 99), 'Empty supplier page should return an empty list.');
        $tests->assertTrue(count(get_suppliers_for_selector($conn, 100)) <= 100, 'Supplier selector must be bounded.');
        $tests->assertSame(1, normalize_page_number('not-a-page'), 'Invalid page values must normalize to page one.');
        $tests->assertSame(25, normalize_page_size(999), 'Oversized page values must normalize to the default page size.');

        $orderBarcode = $prefix . '-ORDER';
        $tests->assertTrue(create_product($conn, $adminId, $prefix . '_ORDER_PRODUCT', 'Order product', 12.34, 20, null, 5, null, $orderBarcode), 'Order product creation failed.');
        $orderProductId = (int)test_scalar($conn, 'SELECT id FROM Product WHERE barcode = ?', 's', [$orderBarcode]);
        $tamperedSaleId = create_order(
            $conn,
            $cashierId,
            [[
                'product_id' => $orderProductId,
                'quantity' => 2,
                'unit_price' => 0.01,
                'subtotal' => 0.02,
                'total' => 0.02,
            ]],
            'sale',
            $customerId,
            null
        );
        $tests->assertTrue(is_int($tamperedSaleId) && $tamperedSaleId > 0, 'Cashier sale creation failed.');
        $detail = test_fetch_one($conn, 'SELECT unit_price, subtotal FROM OrderDetail WHERE order_id = ?', 'i', [$tamperedSaleId]);
        $sale = test_fetch_one($conn, 'SELECT total_amount FROM `Order` WHERE id = ?', 'i', [$tamperedSaleId]);
        $tests->assertSame(12.34, round((float)$detail['unit_price'], 2), 'Client unit price tampering was accepted.');
        $tests->assertSame(24.68, round((float)$detail['subtotal'], 2), 'Client subtotal tampering was accepted.');
        $tests->assertSame(24.68, round((float)$sale['total_amount'], 2), 'Client total tampering was accepted.');
        $tests->assertSame(18, (int)test_scalar($conn, 'SELECT stock FROM Product WHERE id = ?', 'i', [$orderProductId]), 'Sale stock update was incorrect.');

        $orderCountBeforePurchase = (int)test_scalar($conn, 'SELECT COUNT(*) FROM `Order`');
        $tests->assertFalse(create_order($conn, $cashierId, [['product_id' => $orderProductId, 'quantity' => 1]], 'purchase', null, $supplierId), 'Cashiers must not create purchases.');
        $tests->assertSame($orderCountBeforePurchase, (int)test_scalar($conn, 'SELECT COUNT(*) FROM `Order`'), 'Rejected cashier purchase mutated orders.');
        $adminPurchaseId = create_order($conn, $adminId, [['product_id' => $orderProductId, 'quantity' => 3]], 'purchase', null, $supplierId);
        $tests->assertTrue(is_int($adminPurchaseId) && $adminPurchaseId > 0, 'Admin purchase creation failed.');
        $tests->assertSame(21, (int)test_scalar($conn, 'SELECT stock FROM Product WHERE id = ?', 'i', [$orderProductId]), 'Purchase stock update was incorrect.');
        $tests->assertFalse(create_order($conn, $adminId, [['product_id' => $orderProductId, 'quantity' => 1]], 'invalid', $customerId, null), 'Invalid order types must be rejected by the database-facing function.');
        $tests->assertSame(2, count_orders($conn, null, 'all'), 'Admin order count is incorrect.');
        $tests->assertSame(1, count_orders($conn, null, 'sale'), 'Admin sales count is incorrect.');
        $tests->assertSame(1, count_orders($conn, $cashierId, 'sale'), 'Cashier sales count is incorrect.');
        $tests->assertCount(1, get_orders_page($conn, null, 'sale', 10, 0), 'Admin order page filter is incorrect.');
        $tests->assertCount(1, get_orders_page($conn, $cashierId, 'all', 10, 0), 'Cashier order page scope is incorrect.');
        $tests->assertTrue(count(get_orders_page($conn, null, 'all', 10, 0)) <= 10, 'Order first page is not bounded.');
        $tests->assertCount(1, get_orders_page($conn, null, 'all', 10, 1), 'Order middle/last page size is incorrect.');
        $tests->assertCount(0, get_orders_page($conn, null, 'all', 10, 99), 'Empty order page should return an empty list.');
        $orderSummary = get_order_summary($conn, $cashierId, 'all');
        $tests->assertSame(1, $orderSummary['total_orders'], 'Cashier order summary scope is incorrect.');
        $tests->assertSame(24.68, round($orderSummary['total_sales_amount'], 2), 'Cashier order summary total is incorrect.');
        $tests->assertTrue(count_stock_movements($conn) > 0, 'Stock movement count should include transaction history.');
        $tests->assertTrue(count(get_stock_movements_page($conn, null, 10, 0)) <= 10, 'Stock movement page is not bounded.');
        $tests->assertTrue(count(get_stock_movements_page($conn, null, 10, 10)) <= 10, 'Stock movement middle page is not bounded.');
        $tests->assertCount(0, get_stock_movements_page($conn, null, 10, 999999), 'Empty stock movement pages must return an empty list.');

        $tests->assertCount(1, get_orders_for_staff($conn, $cashierId), 'Cashier order history scope is incorrect.');
        $tests->assertSame(null, get_order_by_id($conn, $tamperedSaleId, $adminId), 'A cashier order must not be visible to another staff scope.');
        $tests->assertCount(0, get_order_details($conn, $tamperedSaleId, $adminId), 'Unauthorized order details must be empty.');
        $tests->assertTrue(is_array(get_order_by_id($conn, $tamperedSaleId)), 'Admin/global order lookup failed.');
        $tests->assertCount(1, get_order_details($conn, $tamperedSaleId, $cashierId), 'Cashier-owned order details were not visible to the owner.');

        $ratePrefix = $prefix . '_RATE';
        $keyA = build_login_rate_limit_key($ratePrefix . '_A', '198.51.100.11');
        $keyB = build_login_rate_limit_key($ratePrefix . '_A', '198.51.100.12');
        $keyC = build_login_rate_limit_key($ratePrefix . '_B', '198.51.100.11');
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $state = login_rate_limit_record_failure($conn, $keyA);
            $tests->assertSame('recorded', $state['status'], 'Rate-limit failures below the threshold must be recorded.');
        }
        $blockedState = login_rate_limit_record_failure($conn, $keyA);
        $tests->assertSame('blocked', $blockedState['status'], 'The fifth failed login must block the account/IP pair.');
        $tests->assertSame('blocked', login_rate_limit_check($conn, $keyA)['status'], 'Blocked account/IP pairs must remain blocked.');
        $tests->assertSame('allowed', login_rate_limit_check($conn, $keyB)['status'], 'A different source IP must not share a counter.');
        $tests->assertSame('allowed', login_rate_limit_check($conn, $keyC)['status'], 'A different account must not share a counter.');
        $tests->assertTrue(login_rate_limit_reset($conn, $keyA), 'Successful-login rate-limit reset failed.');
        $tests->assertSame('allowed', login_rate_limit_check($conn, $keyA)['status'], 'Successful-login reset did not clear the block.');
        $tests->assertSame(0, (int)test_scalar($conn, 'SELECT COUNT(*) FROM LoginRateLimit WHERE username_hash = ?', 's', [$keyA['username_hash']]), 'Rate-limit reset left stale state.');

        $server = test_start_local_server();
        $invalidCsrfStatus = test_http_post($server[1], '/login.php', [
            'action' => 'login',
            'username' => $ratePrefix . '_HTTP',
            'password' => 'not-a-real-password',
            'csrf_token' => 'invalid-token',
        ]);
        $tests->assertSame(403, $invalidCsrfStatus, 'The login endpoint must return HTTP 403 for invalid CSRF.');
        $httpKey = build_login_rate_limit_key($ratePrefix . '_HTTP', '127.0.0.1');
        $tests->assertSame(0, (int)test_scalar($conn, 'SELECT COUNT(*) FROM LoginRateLimit WHERE username_hash = ?', 's', [$httpKey['username_hash']]), 'Invalid CSRF must not increment login rate-limit counters.');

        $failureConnection = new mysqli(
            $database->hostForTests(),
            $database->runtimeUsername,
            $database->runtimePassword,
            $database->databaseName,
            $database->portForTests()
        );
        $failureConnection->close();
        $tests->assertSame(null, get_product_by_id($failureConnection, 1), 'Single-record DB failures must return null.');
        $tests->assertCount(0, get_orders($failureConnection), 'List DB failures must return an empty array.');
        $tests->assertCount(0, get_order_details($failureConnection, 1), 'Order-detail DB failures must return an empty array.');
        $tests->assertFalse(create_product($failureConnection, $adminId, $prefix . '_DB_FAILURE', 'Failure', 1.00, 1), 'Create DB failures must return false.');
        $tests->assertFalse(delete_category($failureConnection, 2), 'Delete DB failures must return false.');

        $rollbackBarcode = $prefix . '-ROLLBACK';
        $tests->assertFalse(create_product($conn, 999999999, $prefix . '_ROLLBACK_PRODUCT', 'Rollback', 3.00, 4, null, 5, null, $rollbackBarcode), 'A stock-ledger failure must fail product creation.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Product WHERE barcode = ?', 's', [$rollbackBarcode]), 'Failed product creation left a partial product row.');

        $auditRollbackBarcode = $prefix . '-AUDIT-ROLLBACK';
        $productCountBeforeAuditFailure = (int)test_scalar($conn, 'SELECT COUNT(*) FROM Product');
        $schema->query('DROP TABLE AuditLog');
        $tests->assertFalse(
            create_product($conn, $adminId, $prefix . '_AUDIT_ROLLBACK_PRODUCT', 'Audit rollback', 3.00, 4, null, 5, null, $auditRollbackBarcode),
            'A failed audit insert must fail the transactional product operation.'
        );
        $tests->assertSame($productCountBeforeAuditFailure, (int)test_scalar($conn, 'SELECT COUNT(*) FROM Product'), 'Audit failure left a partial product row.');
        test_load_sql_file($schema, dirname(__DIR__, 2) . '/database/batch22_audit_log.sql');
        $tests->assertSame(1, (int)test_scalar($schema, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'AuditLog'"), 'Audit migration did not restore the disposable table.');

        return $tests->assertions();
    } finally {
        if ($server !== null) {
            test_stop_local_server($server);
        }
        $database->cleanup();
    }
}
