<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function category_delete_create_product(mysqli $conn, string $prefix, int $categoryId): int
{
    $barcode = $prefix . '_BARCODE_' . bin2hex(random_bytes(3));
    test_execute(
        $conn,
        'INSERT INTO Product (name, price, stock, alert_threshold, category_id, barcode) VALUES (?, ?, ?, ?, ?, ?)',
        'sdiiis',
        [$prefix . '_PRODUCT', 1.25, 3, 5, $categoryId, $barcode]
    );

    return (int)$conn->insert_id;
}

function category_delete_create_failure_trigger(mysqli $schema, string $triggerName, string $timing, string $table, string $body): void
{
    $schema->query(
        'CREATE TRIGGER ' . test_sql_identifier($triggerName) . ' ' . $timing . ' ON ' . $table .
        ' FOR EACH ROW ' . $body
    );
}

function category_delete_drop_trigger(mysqli $schema, string $triggerName): void
{
    $schema->query('DROP TRIGGER IF EXISTS ' . test_sql_identifier($triggerName));
}

function run_category_delete_integration_tests(): int
{
    $tests = new TestContext();
    $database = new DisposableDatabase();
    $triggers = [];

    try {
        $database->setup();
        $conn = $database->runtime;
        $schema = $database->schema();
        $prefix = 'QA_CATEGORY_DELETE_' . strtoupper(bin2hex(random_bytes(4)));

        $tests->assertFalse(categories_delete($conn, 0), 'Non-positive category IDs must be rejected.');
        $tests->assertFalse(categories_delete($conn, -1), 'Negative category IDs must be rejected.');

        $missingId = 999999999;
        $tests->assertFalse(categories_delete($conn, $missingId), 'Missing categories must return false.');

        $tests->assertFalse(categories_delete($conn, 1), 'The General category must not be deleted.');
        $tests->assertSame('General', test_scalar($conn, 'SELECT name FROM Category WHERE id = 1'), 'General category was changed by a rejected deletion.');

        $missingGeneralName = $prefix . '_MISSING_GENERAL';
        $tests->assertTrue(categories_create($conn, $missingGeneralName, 'missing General fixture'), 'Missing-General category fixture creation failed.');
        $missingGeneralId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$missingGeneralName]);
        $schema->query('DELETE FROM Category WHERE id = 1');
        $tests->assertFalse(categories_delete($conn, $missingGeneralId), 'Deletion must fail when General is missing.');
        $tests->assertSame($missingGeneralName, test_scalar($conn, 'SELECT name FROM Category WHERE id = ?', 'i', [$missingGeneralId]), 'Missing-General failure deleted the target category.');
        $schema->query("INSERT INTO Category (id, name, description) VALUES (1, 'General', 'Default classification for uncategorized products')");

        $successName = $prefix . '_SUCCESS';
        $tests->assertTrue(categories_create($conn, $successName, 'successful deletion fixture'), 'Successful deletion fixture creation failed.');
        $successId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$successName]);
        $successProductId = category_delete_create_product($conn, $prefix . '_SUCCESS', $successId);
        $tests->assertTrue(categories_delete($conn, $successId), 'Focused category deletion should succeed.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Category WHERE id = ?', 'i', [$successId]), 'Successful category deletion left the category row.');
        $tests->assertSame(1, (int)test_scalar($conn, 'SELECT category_id FROM Product WHERE id = ?', 'i', [$successProductId]), 'Successful category deletion did not reassign products to General.');

        $wrapperName = $prefix . '_WRAPPER';
        $tests->assertTrue(categories_create($conn, $wrapperName, 'wrapper deletion fixture'), 'Wrapper deletion fixture creation failed.');
        $wrapperId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$wrapperName]);
        $wrapperProductId = category_delete_create_product($conn, $prefix . '_WRAPPER', $wrapperId);
        $tests->assertTrue(delete_category($conn, $wrapperId), 'Legacy delete_category wrapper failed.');
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Category WHERE id = ?', 'i', [$wrapperId]), 'Legacy wrapper did not delete through the focused service.');
        $tests->assertSame(1, (int)test_scalar($conn, 'SELECT category_id FROM Product WHERE id = ?', 'i', [$wrapperProductId]), 'Legacy wrapper did not preserve product reassignment.');

        $deleteFailureName = $prefix . '_DELETE_FAILURE';
        $tests->assertTrue(categories_create($conn, $deleteFailureName, 'delete failure fixture'), 'Delete-failure fixture creation failed.');
        $deleteFailureId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$deleteFailureName]);
        $deleteFailureProductId = category_delete_create_product($conn, $prefix . '_DELETE_FAILURE', $deleteFailureId);
        $deleteTrigger = 'qa_category_delete_' . strtolower(bin2hex(random_bytes(4)));
        category_delete_create_failure_trigger(
            $schema,
            $deleteTrigger,
            'BEFORE DELETE',
            'Category',
            "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'category delete test failure'"
        );
        $triggers[] = $deleteTrigger;
        $tests->assertFalse(categories_delete($conn, $deleteFailureId), 'Category delete query failures must return false.');
        $tests->assertSame($deleteFailureName, test_scalar($conn, 'SELECT name FROM Category WHERE id = ?', 'i', [$deleteFailureId]), 'Failed category deletion left partial category state.');
        $tests->assertSame($deleteFailureId, (int)test_scalar($conn, 'SELECT category_id FROM Product WHERE id = ?', 'i', [$deleteFailureProductId]), 'Failed category deletion changed product state.');
        category_delete_drop_trigger($schema, $deleteTrigger);
        array_pop($triggers);
        $tests->assertTrue(categories_delete($conn, $deleteFailureId), 'Connection was not reusable after category delete failure.');

        $reassignFailureName = $prefix . '_REASSIGN_FAILURE';
        $tests->assertTrue(categories_create($conn, $reassignFailureName, 'reassignment failure fixture'), 'Reassignment-failure fixture creation failed.');
        $reassignFailureId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$reassignFailureName]);
        $reassignFailureProductId = category_delete_create_product($conn, $prefix . '_REASSIGN_FAILURE', $reassignFailureId);
        $reassignTrigger = 'qa_category_reassign_' . strtolower(bin2hex(random_bytes(4)));
        category_delete_create_failure_trigger(
            $schema,
            $reassignTrigger,
            'BEFORE UPDATE',
            'Product',
            "BEGIN IF NEW.category_id = 1 AND OLD.category_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'product reassignment test failure'; END IF; END"
        );
        $triggers[] = $reassignTrigger;
        $tests->assertFalse(categories_delete($conn, $reassignFailureId), 'Product reassignment query failures must return false.');
        $tests->assertSame($reassignFailureName, test_scalar($conn, 'SELECT name FROM Category WHERE id = ?', 'i', [$reassignFailureId]), 'Reassignment failure did not roll back category deletion.');
        $tests->assertSame($reassignFailureId, (int)test_scalar($conn, 'SELECT category_id FROM Product WHERE id = ?', 'i', [$reassignFailureProductId]), 'Reassignment failure left a partially changed product.');
        category_delete_drop_trigger($schema, $reassignTrigger);
        array_pop($triggers);
        $tests->assertTrue(categories_delete($conn, $reassignFailureId), 'Connection was not reusable after reassignment failure.');

        $closedConnection = mysqli_init();
        $closedConnection->close();
        $closedEscaped = false;
        $closedResult = true;
        try {
            $closedResult = categories_delete($closedConnection, $missingGeneralId);
        } catch (Throwable $exception) {
            $closedEscaped = true;
        }
        $tests->assertFalse($closedEscaped, 'Closed-connection category deletion must not escape an exception.');
        $tests->assertFalse($closedResult, 'Closed-connection category deletion must return false.');
    } finally {
        if (isset($schema) && $schema instanceof mysqli) {
            foreach (array_reverse($triggers) as $trigger) {
                try {
                    category_delete_drop_trigger($schema, $trigger);
                } catch (Throwable $exception) {
                    // Disposable database cleanup remains the final boundary.
                }
            }
        }
        $database->cleanup();
    }

    return $tests->assertions();
}
