<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the Customer mutation boundary before the legacy implementations
 * are moved out of the compatibility facade.
 */
function run_customer_mutation_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $modulePath = $repository . '/includes/customers.php';
    $validationPath = $repository . '/includes/validation.php';
    $facadePath = $repository . '/includes/functions.php';
    $peoplePath = $repository . '/includes/people.php';
    $pagePath = $repository . '/public/customers.php';
    $module = is_file($modulePath) ? file_get_contents($modulePath) : null;
    $validation = is_file($validationPath) ? file_get_contents($validationPath) : null;
    $facade = is_file($facadePath) ? file_get_contents($facadePath) : null;
    $people = is_file($peoplePath) ? file_get_contents($peoplePath) : null;
    $page = is_file($pagePath) ? file_get_contents($pagePath) : null;

    foreach ([$module, $validation, $facade, $people, $page] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Customer mutation source fixture could not be read.');
    }

    $tests->assertContains('declare(strict_types=1);', $module, 'Customer module must use strict typing.');
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Customer module must not require the compatibility facade.'
    );
    $tests->assertFalse(strpos($module, '$_SESSION') !== false, 'Customer module must not read session state.');
    $tests->assertFalse(strpos($module, '$GLOBALS') !== false, 'Customer module must not read global state.');
    $tests->assertContains(
        "require_once __DIR__ . '/validation.php';",
        $module,
        'Customer module must load pure validation helpers from the low-level module.'
    );

    foreach ([
        'function customers_create($conn, $name, $phone, $email, $address): bool',
        'function customers_update($conn, $id, $name, $phone, $email, $address): bool',
        'function customers_delete($conn, $id): bool',
        '$name = sanitize_input($name);',
        '$phone = sanitize_phone($phone);',
        '$email = sanitize_email($email);',
        '$address = sanitize_input($address);',
        '$id = sanitize_id($id);',
        'if ($id <= 1 || empty($name))',
        'if ($id <= 1)',
        'INSERT INTO Customer (name, phone, email, address) VALUES (?, ?, ?, ?)',
        'UPDATE Customer SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?',
        'DELETE FROM Customer WHERE id = ?',
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
        $tests->assertContains($contract, $module, 'Customer mutation contract is missing: ' . $contract);
    }

    foreach (['sanitize_input', 'sanitize_email', 'sanitize_phone', 'sanitize_id'] as $helper) {
        $tests->assertContains('function ' . $helper, $validation, 'Pure validation helper was not extracted: ' . $helper);
        $tests->assertFalse(
            strpos($facade, 'function ' . $helper) !== false,
            'Validation helper implementation must not remain in the compatibility facade: ' . $helper
        );
    }
    $tests->assertContains(
        "require_once __DIR__ . '/validation.php';",
        $facade,
        'Compatibility facade must load the pure validation helper module.'
    );
    $tests->assertContains(
        "require_once __DIR__ . '/customers.php';",
        $facade,
        'Compatibility facade must load the focused Customer module.'
    );

    foreach ([
        'create_customer' => 'customers_create',
        'update_customer' => 'customers_update',
        'delete_customer' => 'customers_delete',
    ] as $legacyName => $focusedName) {
        $wrapperPattern = '/function ' . preg_quote($legacyName, '/') . '\\s*\\([^)]*\\)\\s*\\{(?<body>.*?)\\n\\}/s';
        $matched = preg_match($wrapperPattern, $facade, $matches) === 1;
        $tests->assertTrue($matched, 'Customer compatibility wrapper is missing: ' . $legacyName);
        if (!$matched) {
            continue;
        }
        $wrapperArguments = $legacyName === 'create_customer'
            ? '$name, $phone, $email, $address'
            : ($legacyName === 'update_customer' ? '$id, $name, $phone, $email, $address' : '$id');
        $tests->assertSame(
            'return ' . $focusedName . '($conn, ' . $wrapperArguments . ');',
            trim($matches['body']),
            'Customer compatibility wrapper must delegate only: ' . $legacyName
        );
    }

    foreach ([
        'customers_create($conn, $name, $phone, $email, $address)',
        'customers_update($conn, $id, $name, $phone, $email, $address)',
        'customers_delete($conn, $id)',
    ] as $focusedCaller) {
        $tests->assertContains($focusedCaller, $page, 'Customers page must call the focused service directly: ' . $focusedCaller);
    }
    foreach (['create_customer', 'update_customer', 'delete_customer'] as $legacyMutation) {
        $tests->assertFalse(
            preg_match('/\\b' . $legacyMutation . '\\s*\\(/', $page) === 1,
            'Customers page must not call the legacy mutation wrapper: ' . $legacyMutation
        );
        $tests->assertFalse(
            strpos($people, 'function ' . $legacyMutation) !== false,
            'People read module must remain free of Customer writes: ' . $legacyMutation
        );
    }

    $csrfPosition = strpos($page, 'verify_csrf_token($csrf_token)');
    $authorizationPosition = strpos($page, 'auth_is_admin($conn)');
    $firstFocusedMutationPosition = min(
        strpos($page, 'customers_create('),
        strpos($page, 'customers_update('),
        strpos($page, 'customers_delete(')
    );
    $tests->assertTrue(
        $csrfPosition !== false && $csrfPosition < $firstFocusedMutationPosition,
        'Customers page must validate CSRF before dispatching focused mutations.'
    );
    $tests->assertTrue(
        $authorizationPosition !== false && $authorizationPosition < $firstFocusedMutationPosition,
        'Customers page must authorize administrators before dispatching focused mutations.'
    );
    foreach ([
        'Customer added successfully.',
        'Customer updated successfully.',
        'Customer deleted successfully. Past orders for this customer will show as walk-in orders.',
        'Modifying the default Walk-in Customer is prohibited.',
        'Deleting the default Walk-in Customer is prohibited.',
        "audit_log_current_actor($conn, 'customer_create'",
        "audit_log_current_actor($conn, 'customer_update'",
        "audit_log_current_actor($conn, 'customer_delete'",
    ] as $pageContract) {
        $tests->assertContains($pageContract, $page, 'Customers page contract is missing: ' . $pageContract);
    }

    return $tests->assertions();
}
