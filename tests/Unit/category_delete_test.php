<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the Category deletion boundary before its implementation is moved
 * out of the compatibility facade.
 */
function run_category_delete_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $modulePath = $repository . '/includes/categories.php';
    $facadePath = $repository . '/includes/functions.php';
    $pagePath = $repository . '/public/categories.php';
    $module = is_file($modulePath) ? file_get_contents($modulePath) : null;
    $facade = is_file($facadePath) ? file_get_contents($facadePath) : null;
    $page = is_file($pagePath) ? file_get_contents($pagePath) : null;

    foreach ([$module, $facade, $page] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Category delete source fixture could not be read.');
    }

    $tests->assertContains('declare(strict_types=1);', $module, 'Category module must use strict typing.');
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Category module must not require the compatibility facade.'
    );
    $tests->assertFalse(strpos($module, '$_SESSION') !== false, 'Category module must not read session state.');
    $tests->assertFalse(strpos($module, '$GLOBALS') !== false, 'Category module must not read global state.');

    foreach ([
        'function categories_delete($conn, $id): bool',
        '$id = (int)$id;',
        'if ($id <= 0)',
        'SELECT name FROM Category WHERE id = ?',
        '$stmt->bind_param(\'i\', $id)',
        '$conn->begin_transaction()',
        "SELECT id FROM Category WHERE name = 'General' LIMIT 1",
        'DELETE FROM Category WHERE id = ?',
        '$delete_stmt->bind_param(\'i\', $id)',
        'UPDATE Product SET category_id = ? WHERE category_id IS NULL',
        '$reassign_stmt->bind_param(\'i\', $gen_cat_id)',
        '$delete_stmt->affected_rows !== 1',
        '$reassign_stmt->affected_rows < 0',
        '$conn->commit()',
        '$conn->rollback()',
        'if ($stmt instanceof mysqli_stmt)',
        '$stmt->close();',
        'foreach ([$general_stmt, $delete_stmt, $reassign_stmt] as $open_stmt)',
        'if ($open_stmt instanceof mysqli_stmt)',
        '$open_stmt->close();',
        'delete_category rollback failed:',
        'delete_category failed:',
        'finally',
        'return true;',
        'return false;',
    ] as $contract) {
        $tests->assertContains($contract, $module, 'Category delete contract is missing: ' . $contract);
    }

    $lookupPosition = strpos($module, 'SELECT name FROM Category WHERE id = ?');
    $transactionPosition = strpos($module, '$conn->begin_transaction()');
    $generalPosition = strpos($module, "SELECT id FROM Category WHERE name = 'General' LIMIT 1");
    $deletePosition = strpos($module, 'DELETE FROM Category WHERE id = ?');
    $reassignPosition = strpos($module, 'UPDATE Product SET category_id = ? WHERE category_id IS NULL');
    $commitPosition = strpos($module, '$conn->commit()');
    $tests->assertTrue(
        $lookupPosition !== false
            && $transactionPosition !== false
            && $lookupPosition < $transactionPosition,
        'Category deletion must preserve lookup-before-transaction behavior.'
    );
    $tests->assertTrue(
        $generalPosition !== false
            && $deletePosition !== false
            && $reassignPosition !== false
            && $generalPosition < $deletePosition
            && $deletePosition < $reassignPosition,
        'Category deletion must verify General before delete and reassign products afterward.'
    );
    $tests->assertTrue(
        $commitPosition !== false && $reassignPosition !== false && $reassignPosition < $commitPosition,
        'Category deletion must commit only after product reassignment.'
    );

    $wrapperPattern = '/function delete_category\s*\(\$conn,\s*\$id\)\s*\{(?<body>.*?)\n\}/s';
    $matched = preg_match($wrapperPattern, $facade, $matches) === 1;
    $tests->assertTrue($matched, 'delete_category compatibility wrapper is missing.');
    if ($matched) {
        $tests->assertSame(
            'return categories_delete($conn, $id);',
            trim($matches['body']),
            'delete_category must be a delegation-only compatibility wrapper.'
        );
    }

    $tests->assertContains('categories_delete($conn, $id)', $page, 'Categories page must call categories_delete directly.');
    $tests->assertFalse(
        preg_match('/\bdelete_category\s*\(/', $page) === 1,
        'Categories page must not call the legacy delete_category wrapper.'
    );

    $csrfPosition = strpos($page, 'verify_csrf_token($csrf_token)');
    $authorizationPosition = strpos($page, 'auth_is_admin($conn)');
    $deleteCallerPosition = strpos($page, 'categories_delete($conn, $id)');
    $tests->assertTrue(
        $csrfPosition !== false && $csrfPosition < $deleteCallerPosition,
        'Categories page must validate CSRF before dispatching deletion.'
    );
    $tests->assertTrue(
        $authorizationPosition !== false && $authorizationPosition < $deleteCallerPosition,
        'Categories page must authorize administrators before dispatching deletion.'
    );
    $tests->assertContains(
        'Category deleted successfully. Associated products have been moved to General.',
        $page,
        'Categories page success message must remain unchanged.'
    );
    $tests->assertContains(
        'Failed to delete category. The default category "General" cannot be deleted.',
        $page,
        'Categories page deletion error message must remain unchanged.'
    );

    return $tests->assertions();
}
