<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the Supplier mutation boundary before the legacy implementations
 * are moved out of the compatibility facade.
 */
function run_supplier_mutation_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $modulePath = $repository . '/includes/suppliers.php';
    $validationPath = $repository . '/includes/validation.php';
    $facadePath = $repository . '/includes/functions.php';
    $peoplePath = $repository . '/includes/people.php';
    $pagePath = $repository . '/public/suppliers.php';
    $module = is_file($modulePath) ? file_get_contents($modulePath) : null;
    $validation = is_file($validationPath) ? file_get_contents($validationPath) : null;
    $facade = is_file($facadePath) ? file_get_contents($facadePath) : null;
    $people = is_file($peoplePath) ? file_get_contents($peoplePath) : null;
    $page = is_file($pagePath) ? file_get_contents($pagePath) : null;

    foreach ([$module, $validation, $facade, $people, $page] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Supplier mutation source fixture could not be read.');
    }

    $tests->assertContains('declare(strict_types=1);', $module, 'Supplier module must use strict typing.');
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Supplier module must not require the compatibility facade.'
    );
    $tests->assertFalse(strpos($module, '$_SESSION') !== false, 'Supplier module must not read session state.');
    $tests->assertFalse(strpos($module, '$GLOBALS') !== false, 'Supplier module must not read global state.');
    $tests->assertContains(
        "require_once __DIR__ . '/validation.php';",
        $module,
        'Supplier module must load pure validation helpers from the low-level module.'
    );
    $tests->assertFalse(strpos($module, 'get_supplier_by_id') !== false, 'Supplier lookup must remain in the legacy facade.');

    foreach ([
        'function suppliers_create($conn, $name, $phone, $email, $address): bool',
        'function suppliers_update($conn, $id, $name, $phone, $email, $address): bool',
        'function suppliers_delete($conn, $id): bool',
        '$name = sanitize_input($name);',
        '$phone = sanitize_phone($phone);',
        '$email = sanitize_email($email);',
        '$address = sanitize_input($address);',
        '$id = sanitize_id($id);',
        'if ($id <= 1 || empty($name))',
        'if ($id <= 1)',
        'INSERT INTO Supplier (name, phone, email, address) VALUES (?, ?, ?, ?)',
        'UPDATE Supplier SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?',
        'DELETE FROM Supplier WHERE id = ?',
        '$stmt->bind_param(\'ssss\', $name, $phone, $email, $address)',
        '$stmt->bind_param(\'ssssi\', $name, $phone, $email, $address, $id)',
        '$stmt->bind_param(\'i\', $id)',
        '$stmt->affected_rows !== 1',
        'catch (Throwable $exception)',
        'finally',
        'if ($stmt instanceof mysqli_stmt)',
        '$stmt->close();',
        'return true;',
        'return false;',
    ] as $contract) {
        $tests->assertContains($contract, $module, 'Supplier mutation contract is missing: ' . $contract);
    }

    foreach (['sanitize_input', 'sanitize_email', 'sanitize_phone', 'sanitize_id'] as $helper) {
        $tests->assertContains('function ' . $helper, $validation, 'Pure validation helper is missing: ' . $helper);
        $tests->assertFalse(
            strpos($facade, 'function ' . $helper) !== false,
            'Validation helper implementation must not be duplicated in the facade: ' . $helper
        );
    }
    $tests->assertContains(
        "require_once __DIR__ . '/suppliers.php';",
        $facade,
        'Compatibility facade must load the focused Supplier module.'
    );

    foreach ([
        'create_supplier' => 'suppliers_create',
        'update_supplier' => 'suppliers_update',
        'delete_supplier' => 'suppliers_delete',
    ] as $legacyName => $focusedName) {
        $wrapperPattern = '/function ' . preg_quote($legacyName, '/') . '\\s*\\([^)]*\\)\\s*\\{(?<body>.*?)\\n\\}/s';
        $matched = preg_match($wrapperPattern, $facade, $matches) === 1;
        $tests->assertTrue($matched, 'Supplier compatibility wrapper is missing: ' . $legacyName);
        if (!$matched) {
            continue;
        }
        $wrapperArguments = $legacyName === 'create_supplier'
            ? '$name, $phone, $email, $address'
            : ($legacyName === 'update_supplier' ? '$id, $name, $phone, $email, $address' : '$id');
        $tests->assertSame(
            'return ' . $focusedName . '($conn, ' . $wrapperArguments . ');',
            trim($matches['body']),
            'Supplier compatibility wrapper must delegate only: ' . $legacyName
        );
    }

    foreach ([
        'suppliers_create($conn, $name, $phone, $email, $address)',
        'suppliers_update($conn, $id, $name, $phone, $email, $address)',
        'suppliers_delete($conn, $id)',
    ] as $focusedCaller) {
        $tests->assertContains($focusedCaller, $page, 'Suppliers page must call the focused service directly: ' . $focusedCaller);
    }
    foreach (['create_supplier', 'update_supplier', 'delete_supplier'] as $legacyMutation) {
        $tests->assertFalse(
            preg_match('/\\b' . $legacyMutation . '\\s*\\(/', $page) === 1,
            'Suppliers page must not call the legacy mutation wrapper: ' . $legacyMutation
        );
        $tests->assertFalse(
            strpos($people, 'function ' . $legacyMutation) !== false,
            'People read module must remain free of Supplier writes: ' . $legacyMutation
        );
    }

    $csrfPosition = strpos($page, 'verify_csrf_token($csrf_token)');
    $authorizationPosition = strpos($page, 'auth_is_admin($conn)');
    $firstFocusedMutationPosition = min(
        strpos($page, 'suppliers_create('),
        strpos($page, 'suppliers_update('),
        strpos($page, 'suppliers_delete(')
    );
    $tests->assertTrue(
        $csrfPosition !== false && $csrfPosition < $firstFocusedMutationPosition,
        'Suppliers page must validate CSRF before dispatching focused mutations.'
    );
    $tests->assertTrue(
        $authorizationPosition !== false && $authorizationPosition < $firstFocusedMutationPosition,
        'Suppliers page must authorize administrators before dispatching focused mutations.'
    );
    foreach ([
        'Supplier added successfully.',
        'Supplier updated successfully.',
        'Supplier deleted successfully. Past orders from this supplier will show as general supplier purchases.',
        'Modifying the default General Supplier is prohibited.',
        'Deleting the default General Supplier is prohibited.',
        "audit_log_current_actor(\$conn, 'supplier_create'",
        "audit_log_current_actor(\$conn, 'supplier_update'",
        "audit_log_current_actor(\$conn, 'supplier_delete'",
    ] as $pageContract) {
        $tests->assertContains($pageContract, $page, 'Suppliers page contract is missing: ' . $pageContract);
    }

    return $tests->assertions();
}
