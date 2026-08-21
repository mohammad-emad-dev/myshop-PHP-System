<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/** @return array{0: mysqli, 1: string} */
function supplier_mutation_create_failure_database(DisposableDatabase $database): array
{
    $schema = $database->schema();
    $emptyDatabaseName = 'myshop_supplier_failure_' . bin2hex(random_bytes(5));
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

    return [$connection, $emptyDatabaseName];
}

function supplier_mutation_cleanup_failure_database(DisposableDatabase $database, ?mysqli $connection, string $databaseName): void
{
    if ($connection instanceof mysqli) {
        try {
            $connection->close();
        } catch (Throwable $exception) {
            // Disposable schema cleanup remains authoritative.
        }
    }
    $database->schema()->query('DROP DATABASE IF EXISTS ' . test_sql_identifier($databaseName));
}

function run_supplier_mutation_integration_tests(): int
{
    $tests = new TestContext();
    $database = new DisposableDatabase();
    $failureConnection = null;
    $failureDatabaseName = '';

    try {
        $database->setup();
        $conn = $database->runtime;
        $schema = $database->schema();
        $prefix = 'QA_SUPPLIER_MUTATION_' . strtoupper(bin2hex(random_bytes(4)));

        $tests->assertFalse(suppliers_create($conn, '   ', '555-100', 'empty@example.test', 'empty'), 'Empty supplier names must be rejected.');
        $tests->assertFalse(suppliers_update($conn, 0, 'Invalid', '555-101', 'invalid@example.test', 'invalid'), 'Supplier updates must reject ID 0.');
        $tests->assertFalse(suppliers_update($conn, 1, 'General change', '555-102', 'invalid@example.test', 'invalid'), 'Supplier updates must reject the General Supplier.');
        $tests->assertFalse(suppliers_update($conn, 2, '   ', '555-103', 'invalid@example.test', 'invalid'), 'Supplier updates must reject empty names.');
        $tests->assertFalse(suppliers_delete($conn, 0), 'Supplier deletion must reject ID 0.');
        $tests->assertFalse(suppliers_delete($conn, 1), 'Supplier deletion must reject the General Supplier.');
        $tests->assertFalse(suppliers_delete($conn, 999999999), 'Supplier deletion must reject missing IDs through affected-row semantics.');

        $createdName = $prefix . '_<CREATED>';
        $tests->assertTrue(
            suppliers_create($conn, '  ' . $createdName . '  ', '+1 (555)-200', '  created@example.test  ', '  Created address  '),
            'Focused supplier creation should succeed.'
        );
        $storedName = sanitize_input($createdName);
        $created = test_fetch_one($conn, 'SELECT name, phone, email, address FROM Supplier WHERE name = ?', 's', [$storedName]);
        $tests->assertSame($storedName, $created['name'] ?? null, 'Supplier creation must preserve name sanitization.');
        $tests->assertSame('+1 555-200', $created['phone'] ?? null, 'Supplier creation must preserve phone sanitization.');
        $tests->assertSame('created@example.test', $created['email'] ?? null, 'Supplier creation must preserve email sanitization.');
        $tests->assertSame('Created address', $created['address'] ?? null, 'Supplier creation must preserve address sanitization.');
        $createdId = (int)test_scalar($conn, 'SELECT id FROM Supplier WHERE name = ?', 's', [$storedName]);
        $tests->assertTrue($createdId > 1, 'Created supplier ID was not found.');

        $updatedName = $prefix . '_UPDATED';
        $tests->assertTrue(
            suppliers_update($conn, $createdId, '  ' . $updatedName . '  ', '+1 (555)-300', '  updated@example.test  ', '  Updated address  '),
            'Focused supplier update should succeed.'
        );
        $updated = test_fetch_one($conn, 'SELECT name, phone, email, address FROM Supplier WHERE id = ?', 'i', [$createdId]);
        $tests->assertSame($updatedName, $updated['name'] ?? null, 'Supplier update must persist the sanitized name.');
        $tests->assertSame('+1 555-300', $updated['phone'] ?? null, 'Supplier update must persist the sanitized phone.');
        $tests->assertSame('updated@example.test', $updated['email'] ?? null, 'Supplier update must persist the sanitized email.');
        $tests->assertSame('Updated address', $updated['address'] ?? null, 'Supplier update must persist the sanitized address.');

        $tests->assertFalse(
            suppliers_update($conn, $createdId, '   ', '555-301', 'failed@example.test', 'Failed update'),
            'A failed supplier update must return false.'
        );
        $tests->assertSame(
            $updated,
            test_fetch_one($conn, 'SELECT name, phone, email, address FROM Supplier WHERE id = ?', 'i', [$createdId]),
            'A failed supplier update must not leave partial supplier state.'
        );

        $missingUpdateId = 999999998;
        $tests->assertTrue(
            suppliers_update($conn, $missingUpdateId, $prefix . '_MISSING', '555-400', 'missing@example.test', 'Missing'),
            'Missing supplier updates must preserve execute-success behavior.'
        );
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Supplier WHERE id = ?', 'i', [$missingUpdateId]), 'Missing supplier update unexpectedly created a row.');
        $tests->assertFalse(suppliers_delete($conn, 999999999), 'A failed supplier deletion must return false.');
        $tests->assertSame($createdId, test_scalar($conn, 'SELECT id FROM Supplier WHERE id = ?', 'i', [$createdId]), 'A failed supplier deletion must not remove another supplier.');

        $wrapperName = $prefix . '_WRAPPER';
        $tests->assertTrue(create_supplier($conn, $wrapperName, '555-500', 'wrapper@example.test', 'Wrapper'), 'Legacy supplier create wrapper failed.');
        $wrapperId = (int)test_scalar($conn, 'SELECT id FROM Supplier WHERE name = ?', 's', [$wrapperName]);
        $tests->assertTrue(update_supplier($conn, $wrapperId, $wrapperName . '_UPDATED', '555-501', 'wrapper2@example.test', 'Wrapper updated'), 'Legacy supplier update wrapper failed.');
        $tests->assertTrue(delete_supplier($conn, $wrapperId), 'Legacy supplier delete wrapper failed.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Supplier WHERE id = ?', 'i', [$wrapperId]), 'Legacy supplier wrapper behavior changed.');

        $historicalName = $prefix . '_HISTORICAL';
        $tests->assertTrue(suppliers_create($conn, $historicalName, '555-600', 'historical@example.test', 'Historical'), 'Historical-order supplier fixture creation failed.');
        $historicalId = (int)test_scalar($conn, 'SELECT id FROM Supplier WHERE name = ?', 's', [$historicalName]);
        test_execute(
            $schema,
            'INSERT INTO Staff (username, password, full_name, role, is_active) VALUES (?, ?, ?, \'admin\', 1)',
            'sss',
            [$prefix . '_STAFF', password_hash($prefix . '_PASSWORD', PASSWORD_DEFAULT), 'Supplier Mutation Staff']
        );
        $staffId = (int)$schema->insert_id;
        test_execute(
            $conn,
            'INSERT INTO `Order` (staff_id, order_type, total_amount, supplier_id) VALUES (?, \'purchase\', ?, ?)',
            'idi',
            [$staffId, 1.00, $historicalId]
        );
        $orderId = (int)$conn->insert_id;
        $tests->assertTrue(suppliers_delete($conn, $historicalId), 'Supplier deletion with historical orders should preserve existing foreign-key behavior.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Supplier WHERE id = ?', 'i', [$historicalId]), 'Historical supplier was not deleted.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT supplier_id FROM `Order` WHERE id = ?', 'i', [$orderId]), 'Historical purchase order was not retained with a NULL supplier.');

        [$failureConnection, $failureDatabaseName] = supplier_mutation_create_failure_database($database);
        $tests->assertFalse(suppliers_create($failureConnection, $prefix . '_PREPARE', '555-700', 'prepare@example.test', 'Prepare failure'), 'Supplier create prepare failures must return false.');
        $tests->assertFalse(suppliers_update($failureConnection, 2, $prefix . '_PREPARE_UPDATE', '555-701', 'prepare-update@example.test', 'Prepare update failure'), 'Supplier update prepare failures must return false.');
        $tests->assertFalse(suppliers_delete($failureConnection, 2), 'Supplier delete prepare failures must return false.');
        $tests->assertSame(0, (int)test_scalar($schema, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = \'Supplier\'', 's', [$failureDatabaseName]), 'Prepared-statement failures must not create partial supplier schema state.');
        supplier_mutation_cleanup_failure_database($database, $failureConnection, $failureDatabaseName);
        $failureConnection = null;
        $failureDatabaseName = '';

        $closedConnection = mysqli_init();
        $closedConnection->close();
        foreach ([
            'create' => static fn() => suppliers_create($closedConnection, $prefix . '_CLOSED', '555-800', 'closed@example.test', 'Closed'),
            'update' => static fn() => suppliers_update($closedConnection, 2, $prefix . '_CLOSED_UPDATE', '555-801', 'closed-update@example.test', 'Closed update'),
            'delete' => static fn() => suppliers_delete($closedConnection, 2),
        ] as $operation => $call) {
            $escaped = false;
            $result = true;
            try {
                $result = $call();
            } catch (Throwable $exception) {
                $escaped = true;
            }
            $tests->assertFalse($escaped, 'Closed-connection supplier ' . $operation . ' must not escape an exception.');
            $tests->assertFalse($result, 'Closed-connection supplier ' . $operation . ' must return false.');
        }
    } finally {
        if ($failureConnection instanceof mysqli) {
            try {
                supplier_mutation_cleanup_failure_database($database, $failureConnection, $failureDatabaseName);
            } catch (Throwable $exception) {
                // Disposable database cleanup remains the final boundary.
            }
        }
        $database->cleanup();
    }

    return $tests->assertions();
}
