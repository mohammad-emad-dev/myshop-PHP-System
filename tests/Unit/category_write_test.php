<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the focused Category create/update write boundary.
 */
function run_category_write_unit_tests(): int
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
        $tests->assertTrue(is_string($fixture), 'Category write source fixture could not be read.');
    }

    $tests->assertContains('declare(strict_types=1);', $module, 'Category module must use strict typing.');
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Category module must not require the compatibility facade.'
    );
    $tests->assertFalse(strpos($module, '$_SESSION') !== false, 'Category module must not read session state.');
    $tests->assertFalse(strpos($module, '$GLOBALS') !== false, 'Category module must not read global state.');

    foreach ([
        'function categories_create($conn, $name, $description): bool',
        'function categories_update($conn, $id, $name, $description): bool',
        '$name = trim($name);',
        '$description = trim($description);',
        'SELECT id FROM Category WHERE name = ? LIMIT 1',
        'SELECT id FROM Category WHERE name = ? AND id != ? LIMIT 1',
        'SELECT name FROM Category WHERE id = ?',
        'INSERT INTO Category (name, description) VALUES (?, ?)',
        'UPDATE Category SET name = ?, description = ? WHERE id = ?',
        '$stmt->bind_param(\'ss\', $name, $description)',
        '$stmt->bind_param(\'si\', $name, $id)',
        '$stmt->bind_param(\'ssi\', $name, $description, $id)',
        '$stmt->affected_rows !== 1',
        "'General' && \$name !== 'General'",
        'catch (Throwable $exception)',
        'finally',
        'return true;',
        'return false;',
    ] as $contract) {
        $tests->assertContains($contract, $module, 'Category write contract is missing: ' . $contract);
    }

    $tests->assertContains(
        "require_once __DIR__ . '/categories.php';",
        $facade,
        'Compatibility facade must load the focused Category module.'
    );

    foreach ([
        'create_category' => 'categories_create',
        'update_category' => 'categories_update',
    ] as $legacyName => $focusedName) {
        $wrapperPattern = '/function ' . preg_quote($legacyName, '/') . '\\s*\\([^)]*\\)\\s*\\{(?<body>.*?)\\n\\}/s';
        $matched = preg_match($wrapperPattern, $facade, $matches) === 1;
        $tests->assertTrue($matched, 'Category compatibility wrapper is missing: ' . $legacyName);
        if (!$matched) {
            continue;
        }

        $tests->assertContains($focusedName . '(', $matches['body'], 'Category wrapper does not delegate: ' . $legacyName);
        foreach (['SELECT ', 'INSERT ', 'UPDATE ', 'prepare(', 'bind_param', 'fetch_assoc'] as $implementationDetail) {
            $tests->assertFalse(
                strpos($matches['body'], $implementationDetail) !== false,
                'Category wrapper contains implementation detail: ' . $legacyName . ' / ' . $implementationDetail
            );
        }
    }

    $tests->assertContains('categories_create($conn, $name, $description)', $page, 'Categories page must call categories_create directly.');
    $tests->assertContains('categories_update($conn, $id, $name, $description)', $page, 'Categories page must call categories_update directly.');

    foreach (['create_category', 'update_category'] as $legacyMutation) {
        $tests->assertFalse(
            preg_match('/\\b' . $legacyMutation . '\\s*\\(/', $page) === 1,
            'Categories page must not call the legacy write wrapper: ' . $legacyMutation
        );
    }

    $csrfPosition = strpos($page, 'verify_csrf_token($csrf_token)');
    $authorizationPosition = strpos($page, 'auth_is_admin($conn)');
    $firstFocusedMutationPosition = min(
        strpos($page, 'categories_create('),
        strpos($page, 'categories_update(')
    );
    $tests->assertTrue(
        $csrfPosition !== false && $csrfPosition < $firstFocusedMutationPosition,
        'Categories page must validate CSRF before dispatching create/update services.'
    );
    $tests->assertTrue(
        $authorizationPosition !== false && $authorizationPosition < $firstFocusedMutationPosition,
        'Categories page must authorize administrators before dispatching create/update services.'
    );

    return $tests->assertions();
}
