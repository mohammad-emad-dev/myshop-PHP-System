<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/backup.php';

/**
 * Verify the application backup format against two disposable databases.
 * This test never selects DB_NAME/ioms_db and never writes a backup artifact
 * inside the repository or public web root.
 */
function run_backup_restore_tests(): int
{
    $tests = new TestContext();
    $source = new DisposableDatabase();
    $root = null;
    $targetRuntime = null;
    $targetDatabase = null;
    $targetRuntimeUsername = null;
    $backupPath = null;
    $failedPath = null;
    $cleanupErrors = [];

    try {
        $source->setup();
        $sourceConnection = $source->runtime;
        $sourceSchema = $source->schema();
        $repositoryRoot = dirname(__DIR__, 2);
        $normalDatabase = getenv('DB_NAME') ?: 'ioms_db';

        $allowlist = get_backup_table_allowlist();
        $tests->assertSame(
            ['Staff', 'Category', 'Customer', 'Supplier', 'Product', 'Order', 'OrderDetail', 'StockMovement', 'AuditLog'],
            $allowlist,
            'Backup table allow-list must remain canonical and ordered for foreign-key-safe restore.'
        );
        $tests->assertSame(null, quote_backup_table('LoginRateLimit'), 'Ephemeral LoginRateLimit must not be allow-listed.');
        $tests->assertSame(null, quote_backup_table('UnknownTable'), 'Unknown backup identifiers must be rejected.');
        $tests->assertSame('`AuditLog`', quote_backup_table('AuditLog'), 'Canonical table identifiers must be quoted safely.');

        $adminUsername = 'QA_BATCH23_ADMIN_' . strtoupper(bin2hex(random_bytes(4)));
        $adminPassword = bin2hex(random_bytes(24));
        $adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
        test_execute(
            $sourceSchema,
            'INSERT INTO Staff (username, password, full_name, role, is_active) VALUES (?, ?, ?, ?, ?)',
            'ssssi',
            [$adminUsername, $adminHash, 'Batch 23 Backup Admin', 'admin', 1]
        );
        $adminId = (int)test_scalar($sourceConnection, 'SELECT id FROM Staff WHERE username = ?', 's', [$adminUsername]);

        $customerName = 'QA_BATCH23_CUSTOMER_' . strtoupper(bin2hex(random_bytes(3))) . "'quoted";
        $tests->assertTrue(
            create_customer($sourceConnection, $customerName, '555-2300', 'qa23@example.invalid', 'Backup verification'),
            'Backup fixture customer creation failed.'
        );
        $storedCustomerName = sanitize_input($customerName);
        $customerId = (int)test_scalar($sourceConnection, 'SELECT id FROM Customer WHERE name = ?', 's', [$storedCustomerName]);

        $barcode = 'QA23-' . strtoupper(bin2hex(random_bytes(4)));
        $tests->assertTrue(
            create_product($sourceConnection, $adminId, 'QA_BATCH23_PRODUCT', 'Backup verification product', 12.34, 10, null, 5, null, $barcode),
            'Backup fixture product creation failed.'
        );
        $productId = (int)test_scalar($sourceConnection, 'SELECT id FROM Product WHERE barcode = ?', 's', [$barcode]);
        $saleId = create_order(
            $sourceConnection,
            $adminId,
            [['product_id' => $productId, 'quantity' => 2, 'unit_price' => 0.01, 'subtotal' => 0.02, 'total' => 0.02]],
            'sale',
            $customerId,
            null
        );
        $tests->assertTrue(is_int($saleId) && $saleId > 0, 'Backup fixture order creation failed.');
        $tests->assertTrue(
            audit_log($sourceConnection, $adminId, 'qa_backup_verification', 'Product', $productId, true, [
                'quoted_value' => "backup ' quote \\ slash",
            ]),
            'Backup fixture audit entry failed.'
        );

        $rateKey = build_login_rate_limit_key('QA_BATCH23_RATE', '203.0.113.23');
        $tests->assertSame('recorded', login_rate_limit_record_failure($sourceConnection, $rateKey)['status'], 'Rate-limit fixture failed.');

        $backupPath = tempnam(sys_get_temp_dir(), 'myshop_backup_qa_');
        if ($backupPath === false) {
            throw new TestFailure('Unable to allocate a temporary backup path.');
        }
        $repositoryRootReal = realpath($repositoryRoot);
        $backupPathReal = realpath(dirname($backupPath));
        $tests->assertTrue(
            $repositoryRootReal === false || $backupPathReal === false || strpos($backupPathReal, $repositoryRootReal) !== 0,
            'Temporary backup must be outside the repository.'
        );
        $tests->assertTrue(
            strpos(str_replace('\\', '/', $backupPath), str_replace('\\', '/', $repositoryRoot . '/public/')) !== 0,
            'Temporary backup must be outside the public web root.'
        );

        $output = fopen($backupPath, 'wb');
        if ($output === false) {
            throw new TestFailure('Unable to open the temporary backup path.');
        }
        try {
            stream_database_backup($sourceConnection, $output);
        } finally {
            if (!fclose($output)) {
                throw new TestFailure('Temporary backup output could not be closed.');
            }
        }

        $dump = file_get_contents($backupPath);
        if ($dump === false || $dump === '') {
            throw new TestFailure('Generated backup was empty.');
        }
        $tests->assertContains('CREATE TABLE `AuditLog`', $dump, 'Generated backup omitted AuditLog.');
        $tests->assertFalse(strpos($dump, 'CREATE TABLE `LoginRateLimit`') !== false, 'Generated backup included LoginRateLimit.');
        $tests->assertFalse(strpos($dump, 'INSERT INTO `LoginRateLimit`') !== false, 'Generated backup included LoginRateLimit rows.');
        $tests->assertTrue(strpos($dump, 'SET FOREIGN_KEY_CHECKS=0;') !== false, 'Backup did not disable foreign keys before restore.');
        $tests->assertTrue(strpos($dump, 'SET FOREIGN_KEY_CHECKS=1;') !== false, 'Backup did not restore foreign-key checks.');
        $tests->assertContains('-- MYSHOP_BACKUP_COMPLETE', $dump, 'Backup completion marker is missing.');
        $tests->assertTrue(
            strpos($dump, 'DROP TABLE IF EXISTS `AuditLog`') < strpos($dump, 'DROP TABLE IF EXISTS `Staff`'),
            'Backup drop order must remove dependants before Staff.'
        );
        $tests->assertTrue(
            strpos($dump, 'CREATE TABLE `Staff`') < strpos($dump, 'CREATE TABLE `AuditLog`'),
            'Backup create order must create Staff before AuditLog.'
        );
        $tests->assertContains('QA_BATCH23_CUSTOMER_', $dump, 'Backup did not include escaped fixture data.');
        $tests->assertContains($adminHash, $dump, 'Backup omitted the staff password hash required for restore integrity.');
        $tests->assertFalse(strpos($dump, $adminPassword) !== false, 'Backup exposed a plaintext staff password.');

        $rootPassword = getenv('TEST_DB_ROOT_PASSWORD');
        if ($rootPassword === false) {
            throw new TestFailure('TEST_DB_ROOT_PASSWORD is required for restore verification.');
        }
        $root = new mysqli(
            $source->hostForTests(),
            getenv('TEST_DB_ROOT_USER') ?: 'root',
            $rootPassword,
            '',
            $source->portForTests()
        );
        $root->set_charset('utf8mb4');

        $targetDatabase = 'myshop_restore_qa_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
        $targetRuntimeUsername = 'myshop_restore_rt_' . bin2hex(random_bytes(4));
        $targetRuntimePassword = bin2hex(random_bytes(24));
        $tests->assertFalse(strcasecmp($targetDatabase, 'ioms_db') === 0, 'Restore target must never be ioms_db.');
        $tests->assertFalse(strcasecmp($targetDatabase, $normalDatabase) === 0, 'Restore target must differ from the configured database.');
        $tests->assertContains('myshop_restore_qa_', $targetDatabase, 'Restore target must be uniquely named and disposable.');

        $root->query('CREATE DATABASE ' . test_sql_identifier($targetDatabase) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $root->query(
            'CREATE USER ' . test_sql_string($root, $targetRuntimeUsername) . "@'%' IDENTIFIED BY " .
            test_sql_string($root, $targetRuntimePassword)
        );
        $root->select_db($targetDatabase);

        $databaseDirectory = $repositoryRoot . '/database';
        test_load_sql_file($root, $databaseDirectory . '/schema.sql');
        test_load_sql_file($root, $databaseDirectory . '/batch2_staff_active.sql');
        test_load_sql_file($root, $databaseDirectory . '/batch3_product_history.sql');
        $root->query(
            'SET @myshop_runtime_user = ' . test_sql_string($root, $targetRuntimeUsername) .
            ", @myshop_runtime_host = '%'"
        );
        test_load_sql_file($root, $databaseDirectory . '/batch14_runtime_privileges.sql');
        test_load_sql_file($root, $databaseDirectory . '/batch17_login_rate_limit.sql');
        test_load_sql_file($root, $databaseDirectory . '/batch22_audit_log.sql');

        if (!$root->multi_query($dump)) {
            throw new TestFailure('Generated backup could not be restored into the isolated target.');
        }
        do {
            $result = $root->store_result();
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        } while ($root->more_results() && $root->next_result());
        if ($root->errno !== 0) {
            throw new TestFailure('Generated backup restore returned a database error.');
        }

        $targetRuntime = new mysqli(
            $source->hostForTests(),
            $targetRuntimeUsername,
            $targetRuntimePassword,
            $targetDatabase,
            $source->portForTests()
        );
        $targetRuntime->set_charset('utf8mb4');

        $targetTables = test_fetch_all(
            $root,
            "SELECT table_name AS table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
        );
        $targetTableNames = array_map(static fn(array $row): string => $row['table_name'], $targetTables);
        $expectedTargetTables = array_merge($allowlist, ['LoginRateLimit']);
        sort($targetTableNames);
        sort($expectedTargetTables);
        $tests->assertSame($expectedTargetTables, $targetTableNames, 'Restored table set differs from the initialized schema and backup allow-list.');
        $tests->assertSame(0, (int)test_scalar($targetRuntime, 'SELECT COUNT(*) FROM LoginRateLimit'), 'Excluded LoginRateLimit state must not be restored.');

        foreach ($allowlist as $table) {
            $quoted = quote_backup_table($table);
            $sourceCount = (int)test_scalar($sourceConnection, 'SELECT COUNT(*) FROM ' . $quoted);
            $targetCount = (int)test_scalar($targetRuntime, 'SELECT COUNT(*) FROM ' . $quoted);
            $tests->assertSame($sourceCount, $targetCount, 'Restored row count differs for ' . $table . '.');

            $sourceColumns = test_fetch_all(
                $sourceConnection,
                "SELECT column_name, column_type, is_nullable, column_default, column_key, extra, ordinal_position
                 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position",
                's',
                [$table]
            );
            $targetColumns = test_fetch_all(
                $root,
                "SELECT column_name, column_type, is_nullable, column_default, column_key, extra, ordinal_position
                 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position",
                's',
                [$table]
            );
            $tests->assertSame($sourceColumns, $targetColumns, 'Restored column definition differs for ' . $table . '.');

            $sourceIndexes = test_fetch_all(
                $sourceConnection,
                "SELECT index_name, seq_in_index, column_name, non_unique, index_type
                 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ?
                 ORDER BY index_name, seq_in_index",
                's',
                [$table]
            );
            $targetIndexes = test_fetch_all(
                $root,
                "SELECT index_name, seq_in_index, column_name, non_unique, index_type
                 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ?
                 ORDER BY index_name, seq_in_index",
                's',
                [$table]
            );
            $tests->assertSame($sourceIndexes, $targetIndexes, 'Restored indexes differ for ' . $table . '.');
        }

        $sourceForeignKeys = test_fetch_all(
            $sourceConnection,
            "SELECT constraint_name, table_name, referenced_table_name, update_rule, delete_rule
             FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()
             ORDER BY constraint_name"
        );
        $targetForeignKeys = test_fetch_all(
            $root,
            "SELECT constraint_name, table_name, referenced_table_name, update_rule, delete_rule
             FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()
             ORDER BY constraint_name"
        );
        $tests->assertSame($sourceForeignKeys, $targetForeignKeys, 'Restored foreign-key definitions differ.');

        $sourceOrderRelationship = (int)test_scalar(
            $sourceConnection,
            'SELECT COUNT(*) FROM OrderDetail od INNER JOIN `Order` o ON o.id = od.order_id INNER JOIN Product p ON p.id = od.product_id'
        );
        $targetOrderRelationship = (int)test_scalar(
            $targetRuntime,
            'SELECT COUNT(*) FROM OrderDetail od INNER JOIN `Order` o ON o.id = od.order_id INNER JOIN Product p ON p.id = od.product_id'
        );
        $tests->assertTrue($sourceOrderRelationship > 0 && $sourceOrderRelationship === $targetOrderRelationship, 'Order relationships did not survive restore.');

        $sourceStockRelationship = (int)test_scalar(
            $sourceConnection,
            'SELECT COUNT(*) FROM StockMovement sm INNER JOIN Product p ON p.id = sm.product_id INNER JOIN Staff s ON s.id = sm.staff_id'
        );
        $targetStockRelationship = (int)test_scalar(
            $targetRuntime,
            'SELECT COUNT(*) FROM StockMovement sm INNER JOIN Product p ON p.id = sm.product_id INNER JOIN Staff s ON s.id = sm.staff_id'
        );
        $tests->assertTrue($sourceStockRelationship > 0 && $sourceStockRelationship === $targetStockRelationship, 'Stock relationships did not survive restore.');
        $tests->assertSame($storedCustomerName, (string)test_scalar($targetRuntime, 'SELECT name FROM Customer WHERE id = ?', 'i', [$customerId]), 'Escaped text did not restore correctly.');

        $grantResult = $root->query(
            'SHOW GRANTS FOR ' . test_sql_string($root, $targetRuntimeUsername) . "@'%'"
        );
        $grantText = '';
        while ($grantRow = $grantResult->fetch_row()) {
            $grantText .= ' ' . (string)($grantRow[0] ?? '');
        }
        $grantResult->free();
        $tests->assertContains('USAGE ON *.*', strtoupper($grantText), 'Restored runtime account is missing USAGE.');
        $tests->assertContains('SELECT, INSERT, UPDATE, DELETE', strtoupper($grantText), 'Restored runtime account is missing CRUD privileges.');
        foreach (['ALL PRIVILEGES', 'CREATE', 'ALTER', 'DROP', 'INDEX', 'GRANT OPTION'] as $forbiddenGrant) {
            $tests->assertFalse(strpos(strtoupper($grantText), $forbiddenGrant) !== false, 'Restricted restore runtime account has forbidden privilege ' . $forbiddenGrant . '.');
        }
        $tests->assertTrue((int)test_scalar($targetRuntime, 'SELECT COUNT(*) FROM Product') > 0, 'Restricted runtime account could not query restored data.');

        $failedPath = tempnam(sys_get_temp_dir(), 'myshop_backup_failed_qa_');
        if ($failedPath === false) {
            throw new TestFailure('Unable to allocate the backup failure fixture path.');
        }
        $failedOutput = fopen($failedPath, 'wb');
        if ($failedOutput === false) {
            throw new TestFailure('Unable to open the backup failure fixture path.');
        }
        if (!fclose($failedOutput)) {
            throw new TestFailure('Unable to close the backup failure fixture path.');
        }
        try {
            stream_database_backup($sourceConnection, $failedOutput);
            throw new TestFailure('A closed backup output stream did not fail safely.');
        } catch (RuntimeException $exception) {
            $tests->assertTrue(true, 'Backup failure path returned a controlled exception.');
        }

        $tests->assertFalse(strcasecmp($source->databaseName, 'ioms_db') === 0, 'Source verification database must never be ioms_db.');
        $tests->assertFalse(strcasecmp($targetDatabase, 'ioms_db') === 0, 'Restore verification database must never be ioms_db.');
        return $tests->assertions();
    } finally {
        if ($targetRuntime instanceof mysqli) {
            $targetRuntime->close();
        }

        if ($root instanceof mysqli) {
            try {
                $root->select_db('mysql');
                if ($targetDatabase !== null) {
                    $root->query('DROP DATABASE IF EXISTS ' . test_sql_identifier($targetDatabase));
                }
            } catch (Throwable $exception) {
                $cleanupErrors[] = 'restore database ' . ($targetDatabase ?? 'unknown');
            }

            try {
                if ($targetRuntimeUsername !== null) {
                    $root->query(
                        'DROP USER IF EXISTS ' . test_sql_string($root, $targetRuntimeUsername) . "@'%'"
                    );
                }
            } catch (Throwable $exception) {
                $cleanupErrors[] = 'restore runtime user ' . ($targetRuntimeUsername ?? 'unknown');
            }
            $root->close();
        }

        if ($backupPath !== null && file_exists($backupPath) && !unlink($backupPath)) {
            $cleanupErrors[] = 'temporary backup ' . $backupPath;
        }
        if ($failedPath !== null && file_exists($failedPath) && !unlink($failedPath)) {
            $cleanupErrors[] = 'failed-backup fixture ' . $failedPath;
        }

        try {
            $source->cleanup();
        } catch (Throwable $exception) {
            $cleanupErrors[] = 'source database ' . $source->databaseName;
        }

        if ($cleanupErrors !== []) {
            throw new TestFailure('Backup verification cleanup failed for: ' . implode(', ', $cleanupErrors));
        }
    }
}
