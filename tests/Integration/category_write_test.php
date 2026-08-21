<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function run_category_write_integration_tests(): int
{
    $tests = new TestContext();
    $database = new DisposableDatabase();

    try {
        $database->setup();
        $conn = $database->runtime;
        $prefix = 'QA_CATEGORY_' . strtoupper(bin2hex(random_bytes(4)));

        $tests->assertFalse(categories_create($conn, '   ', 'empty'), 'Empty category names must be rejected.');
        $tests->assertFalse(categories_create($conn, '', 'empty'), 'Blank category names must be rejected.');

        $createdName = $prefix . '_CREATED';
        $tests->assertTrue(
            categories_create($conn, '  ' . $createdName . '  ', '  Created description  '),
            'Focused category creation should succeed.'
        );
        $created = test_fetch_one($conn, 'SELECT name, description FROM Category WHERE name = ?', 's', [$createdName]);
        $tests->assertSame($createdName, $created['name'] ?? null, 'Category creation must preserve trimmed names.');
        $tests->assertSame('Created description', $created['description'] ?? null, 'Category creation must preserve trimmed descriptions.');
        $tests->assertFalse(categories_create($conn, $createdName, 'duplicate'), 'Duplicate category names must be rejected.');

        $createdId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$createdName]);
        $updatedName = $prefix . '_UPDATED';
        $tests->assertTrue(
            categories_update($conn, $createdId, '  ' . $updatedName . '  ', '  Updated description  '),
            'Focused category update should succeed.'
        );
        $updated = test_fetch_one($conn, 'SELECT name, description FROM Category WHERE id = ?', 'i', [$createdId]);
        $tests->assertSame($updatedName, $updated['name'] ?? null, 'Category update must persist the trimmed name.');
        $tests->assertSame('Updated description', $updated['description'] ?? null, 'Category update must persist the trimmed description.');

        $duplicateName = $prefix . '_DUPLICATE';
        $tests->assertTrue(categories_create($conn, $duplicateName, 'duplicate target'), 'Duplicate-name fixture creation failed.');
        $duplicateId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$duplicateName]);
        $tests->assertFalse(
            categories_update($conn, $createdId, $duplicateName, 'should fail'),
            'Category updates must reject names owned by another category.'
        );
        $tests->assertSame($updatedName, test_scalar($conn, 'SELECT name FROM Category WHERE id = ?', 'i', [$createdId]), 'Duplicate update changed the original category.');

        $generalBefore = test_fetch_one($conn, 'SELECT name, description FROM Category WHERE id = 1');
        $tests->assertFalse(
            categories_update($conn, 1, $prefix . '_NOT_GENERAL', 'must fail'),
            'The General category must not be renamed.'
        );
        $generalAfter = test_fetch_one($conn, 'SELECT name, description FROM Category WHERE id = 1');
        $tests->assertSame($generalBefore, $generalAfter, 'Rejected General-category rename must not change the row.');

        // The existing update implementation returns true after a successful
        // UPDATE execution even when no row matches; preserve that contract.
        $missingId = 999999999;
        $tests->assertTrue(
            categories_update($conn, $missingId, $prefix . '_MISSING', 'missing'),
            'Missing-category update must preserve the existing affected-row return behavior.'
        );
        $tests->assertSame(null, test_scalar($conn, 'SELECT id FROM Category WHERE id = ?', 'i', [$missingId]), 'Missing-category update created a row.');
        $tests->assertFalse(categories_update($conn, 0, $prefix . '_INVALID', 'invalid'), 'Non-positive category IDs must be rejected.');

        $wrapperName = $prefix . '_WRAPPER';
        $tests->assertTrue(create_category($conn, $wrapperName, 'wrapper description'), 'Legacy create_category wrapper failed.');
        $wrapperId = (int)test_scalar($conn, 'SELECT id FROM Category WHERE name = ?', 's', [$wrapperName]);
        $tests->assertTrue(update_category($conn, $wrapperId, $wrapperName . '_UPDATED', 'wrapper updated'), 'Legacy update_category wrapper failed.');
        $tests->assertSame($wrapperName . '_UPDATED', test_scalar($conn, 'SELECT name FROM Category WHERE id = ?', 'i', [$wrapperId]), 'Legacy category wrappers did not preserve service behavior.');

        $closedConnection = mysqli_init();
        $closedConnection->close();
        $createFailure = true;
        $updateFailure = true;
        $createEscaped = false;
        $updateEscaped = false;
        try {
            $createFailure = categories_create($closedConnection, $prefix . '_CLOSED', 'closed');
        } catch (Throwable $exception) {
            $createEscaped = true;
        }
        try {
            $updateFailure = categories_update($closedConnection, $createdId, $updatedName, 'closed');
        } catch (Throwable $exception) {
            $updateEscaped = true;
        }
        $tests->assertFalse($createEscaped, 'Closed-connection category creation must not escape an exception.');
        $tests->assertFalse($updateEscaped, 'Closed-connection category update must not escape an exception.');
        $tests->assertFalse($createFailure, 'Closed-connection category creation must return false.');
        $tests->assertFalse($updateFailure, 'Closed-connection category update must return false.');
    } finally {
        $database->cleanup();
    }

    return $tests->assertions();
}
