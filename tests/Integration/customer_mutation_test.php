<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function customer_mutation_create_failure_database(DisposableDatabase $database): mysqli
{
    $schema = $database->schema();
    $emptyDatabaseName = 'myshop_customer_failure_' . bin2hex(random_bytes(5));
    $schema->query('CREATE DATABASE ' . test_sql_identifier($emptyDatabaseName));

    try {
        $connection = new mysqli(
            $database->hostForTests(),
            getenv('TEST_DB_ROOT_USER') ?: 'root',
            (string)getenv('TEST_DB_ROOT_PASSWORD'),
            $emptyDatabaseName,
            $database->portForTests()
        );
        $connection->set_charset('utf8mb4');
    } catch (Throwable $exception) {
        $schema->query('DROP DATABASE IF EXISTS ' . test_sql_identifier($emptyDatabaseName));
        throw $exception;
    }

    $GLOBALS['customer_mutation_failure_database_name'] = $emptyDatabaseName;
    return $connection;
}

function customer_mutation_cleanup_failure_database(DisposableDatabase $database, ?mysqli $connection): void
{
    if ($connection instanceof mysqli) {
        try {
            $connection->close();
        } catch (Throwable $exception) {
            // The disposable schema cleanup remains authoritative.
        }
    }

    $emptyDatabaseName = $GLOBALS['customer_mutation_failure_database_name'] ?? null;
    if (is_string($emptyDatabaseName) && $emptyDatabaseName !== '') {
        $database->schema()->query('DROP DATABASE IF EXISTS ' . test_sql_identifier($emptyDatabaseName));
    }
    unset($GLOBALS['customer_mutation_failure_database_name']);
}

function run_customer_mutation_integration_tests(): int
{
    $tests = new TestContext();
    $database = new DisposableDatabase();
    $failureConnection = null;

    try {
        $database->setup();
        $conn = $database->runtime;
        $schema = $database->schema();
        $prefix = 'QA_CUSTOMER_MUTATION_' . strtoupper(bin2hex(random_bytes(4)));

        $tests->assertFalse(customers_create($conn, '   ', '555-100', 'empty@example.test', 'empty'), 'Empty customer names must be rejected.');
        $tests->assertFalse(customers_update($conn, 0, 'Invalid', '555-101', 'invalid@example.test', 'invalid'), 'Customer updates must reject ID 0.');
        $tests->assertFalse(customers_update($conn, 1, 'Walk-in change', '555-102', 'invalid@example.test', 'invalid'), 'Customer updates must reject the Walk-in Customer.');
        $tests->assertFalse(customers_update($conn, 2, '   ', '555-103', 'invalid@example.test', 'invalid'), 'Customer updates must reject empty names.');
        $tests->assertFalse(customers_delete($conn, 0), 'Customer deletion must reject ID 0.');
        $tests->assertFalse(customers_delete($conn, 1), 'Customer deletion must reject the Walk-in Customer.');
        $tests->assertFalse(customers_delete($conn, 999999999), 'Customer deletion must reject missing IDs through affected-row semantics.');

        $createdName = $prefix . '_<CREATED>';
        $tests->assertTrue(
            customers_create($conn, '  ' . $createdName . '  ', '+1 (555)-200', '  created@example.test  ', '  Created address  '),
            'Focused customer creation should succeed.'
        );
        $storedName = sanitize_input($createdName);
        $created = test_fetch_one($conn, 'SELECT name, phone, email, address FROM Customer WHERE name = ?', 's', [$storedName]);
        $tests->assertSame($storedName, $created['name'] ?? null, 'Customer creation must preserve existing name sanitization.');
        $tests->assertSame('+1 555-200', $created['phone'] ?? null, 'Customer creation must preserve existing phone sanitization.');
        $tests->assertSame('created@example.test', $created['email'] ?? null, 'Customer creation must preserve existing email sanitization.');
        $tests->assertSame('Created address', $created['address'] ?? null, 'Customer creation must preserve existing address sanitization.');
        $createdId = (int)test_scalar($conn, 'SELECT id FROM Customer WHERE name = ?', 's', [$storedName]);
        $tests->assertTrue($createdId > 1, 'Created customer ID was not found.');

        $updatedName = $prefix . '_UPDATED';
        $tests->assertTrue(
            customers_update($conn, $createdId, '  ' . $updatedName . '  ', '+1 (555)-300', '  updated@example.test  ', '  Updated address  '),
            'Focused customer update should succeed.'
        );
        $updated = test_fetch_one($conn, 'SELECT name, phone, email, address FROM Customer WHERE id = ?', 'i', [$createdId]);
        $tests->assertSame($updatedName, $updated['name'] ?? null, 'Customer update must persist the sanitized name.');
        $tests->assertSame('+1 555-300', $updated['phone'] ?? null, 'Customer update must persist the sanitized phone.');
        $tests->assertSame('updated@example.test', $updated['email'] ?? null, 'Customer update must persist the sanitized email.');
        $tests->assertSame('Updated address', $updated['address'] ?? null, 'Customer update must persist the sanitized address.');

        $missingUpdateId = 999999998;
        $tests->assertTrue(
            customers_update($conn, $missingUpdateId, $prefix . '_MISSING', '555-400', 'missing@example.test', 'Missing'),
            'Missing customer updates must preserve execute-success behavior.'
        );
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Customer WHERE id = ?', 'i', [$missingUpdateId]), 'Missing customer update unexpectedly created a row.');

        $wrapperName = $prefix . '_WRAPPER';
        $tests->assertTrue(create_customer($conn, $wrapperName, '555-500', 'wrapper@example.test', 'Wrapper'), 'Legacy customer create wrapper failed.');
        $wrapperId = (int)test_scalar($conn, 'SELECT id FROM Customer WHERE name = ?', 's', [$wrapperName]);
        $tests->assertTrue(update_customer($conn, $wrapperId, $wrapperName . '_UPDATED', '555-501', 'wrapper2@example.test', 'Wrapper updated'), 'Legacy customer update wrapper failed.');
        $tests->assertTrue(delete_customer($conn, $wrapperId), 'Legacy customer delete wrapper failed.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Customer WHERE id = ?', 'i', [$wrapperId]), 'Legacy customer wrapper behavior changed.');

        $historicalName = $prefix . '_HISTORICAL';
        $tests->assertTrue(customers_create($conn, $historicalName, '555-600', 'historical@example.test', 'Historical'), 'Historical-order customer fixture creation failed.');
        $historicalId = (int)test_scalar($conn, 'SELECT id FROM Customer WHERE name = ?', 's', [$historicalName]);
        test_execute(
            $schema,
            'INSERT INTO Staff (username, password, full_name, role, is_active) VALUES (?, ?, ?, \'admin\', 1)',
            'sss',
            [$prefix . '_STAFF', password_hash($prefix . '_PASSWORD', PASSWORD_DEFAULT), 'Customer Mutation Staff']
        );
        $staffId = (int)$schema->insert_id;
        test_execute(
            $conn,
            'INSERT INTO `Order` (staff_id, order_type, total_amount, customer_id) VALUES (?, \'sale\', ?, ?)',
            'idi',
            [$staffId, 1.00, $historicalId]
        );
        $orderId = (int)$conn->insert_id;
        $tests->assertTrue(customers_delete($conn, $historicalId), 'Customer deletion with historical orders should preserve the existing foreign-key behavior.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Customer WHERE id = ?', 'i', [$historicalId]), 'Historical-order customer was not deleted.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT customer_id FROM `Order` WHERE id = ?', 'i', [$orderId]), 'Historical order was not converted to a walk-in order by the existing foreign key.');

        $failureConnection = customer_mutation_create_failure_database($database);
        $tests->assertFalse(customers_create($failureConnection, $prefix . '_PREPARE', '555-700', 'prepare@example.test', 'Prepare failure'), 'Customer create prepare failures must return false.');
        $tests->assertFalse(customers_update($failureConnection, 2, $prefix . '_PREPARE_UPDATE', '555-701', 'prepare-update@example.test', 'Prepare update failure'), 'Customer update prepare failures must return false.');
        $tests->assertFalse(customers_delete($failureConnection, 2), 'Customer delete prepare failures must return false.');
        customer_mutation_cleanup_failure_database($database, $failureConnection);
        $failureConnection = null;

        $closedConnection = mysqli_init();
        $closedConnection->close();
        foreach ([
            'create' => static fn() => customers_create($closedConnection, $prefix . '_CLOSED', '555-800', 'closed@example.test', 'Closed'),
            'update' => static fn() => customers_update($closedConnection, 2, $prefix . '_CLOSED_UPDATE', '555-801', 'closed-update@example.test', 'Closed update'),
            'delete' => static fn() => customers_delete($closedConnection, 2),
        ] as $operation => $call) {
            $escaped = false;
            $result = true;
            try {
                $result = $call();
            } catch (Throwable $exception) {
                $escaped = true;
            }
            $tests->assertFalse($escaped, 'Closed-connection customer ' . $operation . ' must not escape an exception.');
            $tests->assertFalse($result, 'Closed-connection customer ' . $operation . ' must return false.');
        }
    } finally {
        if ($failureConnection instanceof mysqli) {
            try {
                customer_mutation_cleanup_failure_database($database, $failureConnection);
            } catch (Throwable $exception) {
                // Disposable database cleanup remains the final boundary.
            }
        }
        $database->cleanup();
    }

    return $tests->assertions();
}
