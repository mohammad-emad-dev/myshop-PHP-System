<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function auth_extraction_test_open_session(array $values): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        destroy_current_session();
    }

    if (session_status() !== PHP_SESSION_NONE) {
        throw new TestFailure('Authentication test could not reset the PHP session.');
    }

    if (session_id() !== '') {
        session_id('');
    }

    start_secure_session();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new TestFailure('Authentication test could not start a PHP session.');
    }

    $_SESSION = $values;
    unset($GLOBALS['current_staff_record']);
}

function auth_extraction_test_capture_errors(callable $callback): array
{
    $errors = [];
    set_error_handler(static function (int $severity, string $message) use (&$errors): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        $errors[] = $message;
        return true;
    });

    try {
        $result = $callback();
    } finally {
        restore_error_handler();
    }

    return [$result, $errors];
}

function auth_extraction_test_run_php_process(string $script, string $workingDirectory): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', '-r', $script],
        $descriptors,
        $pipes,
        $workingDirectory
    );
    if (!is_resource($process)) {
        throw new TestFailure('Authentication compatibility subprocess could not start.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'output' => (string)$stdout . (string)$stderr,
    ];
}

/**
 * Characterizes the transitional authentication boundary before callers move
 * away from the legacy facade.
 */
function run_auth_extraction_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $authPath = $repository . '/includes/auth.php';
    $httpPath = $repository . '/includes/http.php';

    $tests->assertTrue(is_file($authPath), 'Focused authentication module is missing.');
    $tests->assertTrue(is_file($httpPath), 'Focused HTTP module is missing.');

    $auth = file_get_contents($authPath);
    $http = file_get_contents($httpPath);
    $facade = file_get_contents($repository . '/includes/functions.php');
    $login = file_get_contents($repository . '/public/login.php');

    foreach ([$auth, $http, $facade, $login] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Authentication extraction source fixture could not be read.');
    }

    $tests->assertFalse(
        strpos($auth, "require_once __DIR__ . '/functions.php'") !== false,
        'Authentication module must not require the legacy compatibility facade.'
    );
    foreach (['security.php', 'audit.php', 'http.php'] as $dependency) {
        $tests->assertContains(
            "require_once __DIR__ . '/{$dependency}'",
            $auth,
            'Authentication module is missing an explicit dependency: ' . $dependency
        );
    }
    foreach (['http.php', 'auth.php'] as $module) {
        $tests->assertContains(
            "require_once __DIR__ . '/{$module}'",
            $facade,
            'Compatibility facade does not load the extracted module: ' . $module
        );
    }

    $explicitReadOnlyCallers = [
        'public/index.php' => [
            'auth_verify_login($conn);' => 1,
            'auth_is_admin($conn)' => 2,
        ],
        'public/audit_log.php' => [
            'auth_verify_login($conn);' => 1,
            'auth_require_admin($conn);' => 1,
        ],
        'public/order_history.php' => [
            'auth_verify_login($conn);' => 1,
            'auth_is_admin($conn)' => 3,
        ],
        'public/get_order_details.php' => [
            'auth_verify_login($conn, false)' => 1,
            'auth_is_admin($conn)' => 1,
        ],
        'public/print_invoice.php' => [
            'auth_verify_login($conn);' => 1,
            'auth_is_admin($conn)' => 1,
        ],
        'public/export_report.php' => [
            'auth_verify_login($conn);' => 1,
            'auth_require_admin($conn);' => 1,
        ],
        'public/pos_product_lookup.php' => [
            'auth_verify_login($conn);' => 1,
        ],
    ];
    foreach ($explicitReadOnlyCallers as $relativePath => $expectedCalls) {
        $source = file_get_contents($repository . '/' . $relativePath);
        $tests->assertTrue(is_string($source), 'Authentication caller source could not be read: ' . $relativePath);
        if (!is_string($source)) {
            continue;
        }

        foreach ($expectedCalls as $call => $expectedCount) {
            $tests->assertSame(
                $expectedCount,
                substr_count($source, $call),
                'Explicit authentication caller count changed for ' . $relativePath . ': ' . $call
            );
        }
        foreach (['verify_login', 'is_admin', 'require_admin'] as $legacyCall) {
            $tests->assertFalse(
                preg_match('/(?<!auth_)\\b' . $legacyCall . '\\s*\\(/', $source) === 1,
                'Read-only caller still uses a legacy authentication wrapper: ' . $relativePath . ' -> ' . $legacyCall
            );
        }
    }

    $explicitCrudCallers = [
        'public/products.php' => [
            'auth_verify_login($conn)',
            'auth_is_admin($conn)',
            'auth_is_admin($conn)',
            'auth_is_admin($conn)',
        ],
        'public/categories.php' => [
            'auth_verify_login($conn)',
            'auth_is_admin($conn)',
            'auth_require_admin($conn)',
        ],
        'public/customers.php' => [
            'auth_verify_login($conn)',
            'auth_is_admin($conn)',
            'auth_is_admin($conn)',
        ],
        'public/suppliers.php' => [
            'auth_verify_login($conn)',
            'auth_is_admin($conn)',
            'auth_is_admin($conn)',
        ],
    ];
    foreach ($explicitCrudCallers as $relativePath => $expectedSequence) {
        $source = file_get_contents($repository . '/' . $relativePath);
        $tests->assertTrue(is_string($source), 'CRUD authentication caller source could not be read: ' . $relativePath);
        if (!is_string($source)) {
            continue;
        }

        preg_match_all(
            '/\\b(?:auth_verify_login|auth_is_admin|auth_require_admin)\\s*\\([^)]*\\)/',
            $source,
            $authCalls
        );
        $tests->assertSame(
            $expectedSequence,
            $authCalls[0],
            'CRUD authentication call count or execution order changed: ' . $relativePath
        );
        foreach (['verify_login', 'is_admin', 'require_admin'] as $legacyCall) {
            $tests->assertFalse(
                preg_match('/(?<!auth_)\\b' . $legacyCall . '\\s*\\(/', $source) === 1,
                'CRUD caller still uses a legacy authentication wrapper: ' . $relativePath . ' -> ' . $legacyCall
            );
        }
    }

    $orders = file_get_contents($repository . '/public/orders.php');
    $tests->assertTrue(is_string($orders), 'Orders authentication caller source could not be read.');
    if (is_string($orders)) {
        preg_match_all(
            '/\b(?:auth_verify_login|auth_is_admin)\s*\([^)]*\)/',
            $orders,
            $orderAuthCalls
        );
        $tests->assertSame(
            [
                'auth_verify_login($conn)',
                'auth_is_admin($conn)',
                'auth_is_admin($conn)',
            ],
            $orderAuthCalls[0],
            'Orders authentication call count or execution order changed.'
        );
        $tests->assertContains(
            '$is_admin_user = auth_is_admin($conn);',
            $orders,
            'Orders page must preserve the explicit administrator state assignment.'
        );
        foreach (['verify_login', 'is_admin'] as $legacyCall) {
            $tests->assertFalse(
                preg_match('/(?<!auth_)\b' . $legacyCall . '\s*\(/', $orders) === 1,
                'Orders page still uses a legacy authentication wrapper: ' . $legacyCall
            );
        }

        $csrfOffset = strpos($orders, 'if (!verify_csrf_token($csrf_token))');
        $purchaseAuthorizationOffset = strpos($orders, "} elseif (\$order_type === 'purchase' && !auth_is_admin(\$conn))");
        $tests->assertTrue(
            $csrfOffset !== false
                && $purchaseAuthorizationOffset !== false
                && $csrfOffset < $purchaseAuthorizationOffset,
            'Orders CSRF validation must remain before purchase authorization.'
        );
        foreach ([
            "http_response_code(403)",
            "audit_log_denied(\$conn, 'purchase_order_create'",
            'catalog_get_product_by_id($conn',
            "\$actual_price = (float)\$prod['price'];",
            'create_order($conn',
            "\$_SESSION['last_order_time']",
        ] as $orderInvariant) {
            $tests->assertContains(
                $orderInvariant,
                $orders,
                'Order business or security invariant disappeared during auth caller migration: ' . $orderInvariant
            );
        }
    }

    $settings = file_get_contents($repository . '/public/settings.php');
    $tests->assertTrue(is_string($settings), 'Settings authentication caller source could not be read.');
    if (is_string($settings)) {
        preg_match_all(
            '/\b(?:auth_verify_login|auth_require_admin|auth_is_admin)\s*\([^)]*\)/',
            $settings,
            $settingsAuthCalls
        );
        $tests->assertSame(
            [
                'auth_verify_login($conn)',
                'auth_require_admin($conn)',
                'auth_is_admin($conn)',
            ],
            $settingsAuthCalls[0],
            'Settings authentication call count or execution order changed.'
        );
        foreach (['verify_login', 'require_admin', 'is_admin'] as $legacyCall) {
            $tests->assertFalse(
                preg_match('/(?<!auth_)\b' . $legacyCall . '\s*\(/', $settings) === 1,
                'Settings page still uses a legacy authentication wrapper: ' . $legacyCall
            );
        }

        $csrfOffset = strpos($settings, 'if (!verify_csrf_token($csrf_token))');
        $authorizationOffset = strpos($settings, 'auth_require_admin($conn)');
        $tests->assertTrue(
            $csrfOffset !== false && $authorizationOffset !== false && $csrfOffset < $authorizationOffset,
            'Settings CSRF validation must remain before authorization.'
        );
        foreach ([
            'password_verify($current_password',
            'password_meets_policy(',
            'create_staff_member($conn',
            'update_staff_member($conn',
            'delete_staff_member($conn',
            'set_staff_active($conn',
            '$conn->begin_transaction()',
            '$conn->commit()',
            '$conn->rollback()',
            "audit_log_current_actor(\$conn, 'settings_change'",
            "audit_log_current_actor(\$conn, 'staff_create'",
            "audit_log_current_actor(\$conn, 'staff_update'",
            "audit_log_current_actor(\$conn, 'staff_deactivate'",
            "audit_log_current_actor(\$conn, 'staff_status_change'",
        ] as $settingsInvariant) {
            $tests->assertContains(
                $settingsInvariant,
                $settings,
                'Settings security or business invariant disappeared during auth caller migration: ' . $settingsInvariant
            );
        }
    }

    $stockMovements = file_get_contents($repository . '/public/stock_movements.php');
    $tests->assertTrue(is_string($stockMovements), 'Stock movement authentication caller source could not be read.');
    if (is_string($stockMovements)) {
        preg_match_all(
            '/\b(?:auth_verify_login|auth_is_admin)\s*\([^)]*\)/',
            $stockMovements,
            $stockAuthCalls
        );
        $tests->assertSame(
            [
                'auth_verify_login($conn)',
                'auth_is_admin($conn)',
                'auth_is_admin($conn)',
                'auth_is_admin($conn)',
            ],
            $stockAuthCalls[0],
            'Stock movement authentication call count or execution order changed.'
        );
        foreach (['verify_login', 'is_admin'] as $legacyCall) {
            $tests->assertFalse(
                preg_match('/(?<!auth_)\b' . $legacyCall . '\s*\(/', $stockMovements) === 1,
                'Stock movement page still uses a legacy authentication wrapper: ' . $legacyCall
            );
        }

        $csrfOffset = strpos($stockMovements, 'if (!verify_csrf_token($csrf_token))');
        $authorizationOffset = strpos($stockMovements, '} elseif (!auth_is_admin($conn))');
        $tests->assertTrue(
            $csrfOffset !== false && $authorizationOffset !== false && $csrfOffset < $authorizationOffset,
            'Stock movement CSRF validation must remain before authorization.'
        );
        foreach ([
            'audit_log_denied($conn, \'stock_adjustment\'',
            '$conn->begin_transaction()',
            'SELECT stock FROM Product WHERE id = ? FOR UPDATE',
            'log_stock_movement($conn',
            '$conn->commit()',
            '$conn->rollback()',
            "audit_log_current_actor(\$conn, 'stock_adjustment'",
        ] as $stockInvariant) {
            $tests->assertContains(
                $stockInvariant,
                $stockMovements,
                'Stock movement invariant disappeared during auth caller migration: ' . $stockInvariant
            );
        }
    }

    foreach ([
        'public/products.php' => ['verify_csrf_token($csrf_token)', "audit_log_current_actor(\$conn, 'product_mutation'", "audit_log_denied(\$conn, 'product_mutation'", 'handle_image_upload('],
        'public/categories.php' => ['verify_csrf_token($csrf_token)', "audit_log_current_actor(\$conn, 'category_mutation'", "audit_log_denied(\$conn, 'category_mutation'"],
        'public/customers.php' => ['verify_csrf_token($csrf_token)', "audit_log_current_actor(\$conn, 'customer_mutation'", "audit_log_denied(\$conn, 'customer_mutation'"],
        'public/suppliers.php' => ['verify_csrf_token($csrf_token)', "audit_log_current_actor(\$conn, 'supplier_mutation'", "audit_log_denied(\$conn, 'supplier_mutation'"],
    ] as $relativePath => $securityContracts) {
        $source = file_get_contents($repository . '/' . $relativePath);
        foreach ($securityContracts as $securityContract) {
            $tests->assertContains(
                $securityContract,
                $source,
                'CRUD security contract changed for ' . $relativePath . ': ' . $securityContract
            );
        }
    }

    foreach ([
        'public/login.php' => 'verify_login(false)',
        'public/settings.php' => 'verify_login();',
        'public/backup_database.php' => 'verify_login(false)',
        'includes/layouts/sidebar.php' => 'is_admin()',
    ] as $relativePath => $legacyCall) {
        $source = file_get_contents($repository . '/' . $relativePath);
        $tests->assertTrue(is_string($source), 'Legacy authentication caller source could not be read: ' . $relativePath);
        if (is_string($source)) {
            $tests->assertContains($legacyCall, $source, 'Out-of-scope authentication caller changed: ' . $relativePath);
        }
    }

    foreach ([
        'auth_staff_record_is_active_with_supported_role',
        'auth_verify_login',
        'auth_is_admin',
        'auth_require_admin',
    ] as $functionName) {
        $tests->assertContains('function ' . $functionName, $auth, 'Authentication function is missing: ' . $functionName);
    }
    $tests->assertContains('function http_redirect', $http, 'HTTP redirect implementation is missing.');

    unset($GLOBALS['conn']);
    auth_extraction_test_open_session(['staff_id' => 123]);
    [$legacyVerifyResult, $legacyVerifyErrors] = auth_extraction_test_capture_errors(
        static fn() => verify_login(false)
    );
    $tests->assertFalse($legacyVerifyResult, 'Legacy verification must fail safely without a global database connection.');
    $tests->assertCount(0, $legacyVerifyErrors, 'Legacy verification emitted a PHP warning for an unset global connection.');

    unset($GLOBALS['conn']);
    auth_extraction_test_open_session(['staff_id' => 123]);
    [$legacyAdminResult, $legacyAdminErrors] = auth_extraction_test_capture_errors(
        static fn() => is_admin()
    );
    $tests->assertFalse($legacyAdminResult, 'Legacy admin checks must fail safely without a global database connection.');
    $tests->assertCount(0, $legacyAdminErrors, 'Legacy admin checks emitted a PHP warning for an unset global connection.');

    $requireAdminScript = 'require ' . var_export($repository . '/includes/functions.php', true) . ';'
        . ' start_secure_session();'
        . ' $_SESSION["staff_id"] = 123;'
        . ' unset($GLOBALS["conn"]);'
        . ' require_admin();';
    $legacyRequireAdmin = auth_extraction_test_run_php_process($requireAdminScript, $repository);
    $tests->assertSame(0, $legacyRequireAdmin['exit_code'], 'Legacy admin enforcement did not terminate through its redirect path.');
    $tests->assertFalse(
        strpos($legacyRequireAdmin['output'], 'Warning:') !== false,
        'Legacy admin enforcement emitted a PHP warning without a global database connection.'
    );
    $tests->assertFalse(
        strpos($legacyRequireAdmin['output'], 'Undefined variable $conn') !== false,
        'Legacy admin enforcement emitted a PHP warning for an unset global connection.'
    );

    foreach ([
        'verify_login' => 'auth_verify_login',
        'redirect' => 'http_redirect',
        'is_admin' => 'auth_is_admin',
        'require_admin' => 'auth_require_admin',
    ] as $legacyName => $moduleName) {
        $wrapperPattern = '/function ' . preg_quote($legacyName, '/') . '\\s*\\([^)]*\\)\\s*\\{(?<body>.*?)\\n\\}/s';
        $matched = preg_match($wrapperPattern, $facade, $matches) === 1;
        $tests->assertTrue($matched, 'Authentication compatibility wrapper is missing: ' . $legacyName);
        if ($matched) {
            $body = $matches['body'];
            $tests->assertContains($moduleName . '(', $body, 'Authentication wrapper does not delegate: ' . $legacyName);
            if (in_array($legacyName, ['verify_login', 'is_admin', 'require_admin'], true)) {
                $tests->assertContains(
                    '$database = $conn ?? null;',
                    $body,
                    'Authentication wrapper does not null-normalize the global connection: ' . $legacyName
                );
                $tests->assertContains(
                    $moduleName . '($database',
                    $body,
                    'Authentication wrapper does not delegate with its null-safe connection: ' . $legacyName
                );
            }
            foreach (['SELECT ', '$_SESSION', '$GLOBALS[', 'audit_log_denied(', 'http_response_code('] as $implementationDetail) {
                $tests->assertFalse(
                    strpos($body, $implementationDetail) !== false,
                    'Authentication wrapper retained implementation logic: ' . $legacyName
                );
            }
        }
    }

    $tests->assertContains(
        'SELECT id, full_name, role, is_active FROM Staff WHERE id = ? LIMIT 1',
        $auth,
        'Active staff lookup behavior changed during extraction.'
    );
    $tests->assertContains("['admin', 'cashier']", $auth, 'Supported authentication roles changed during extraction.');
    $tests->assertContains(
        '$GLOBALS[\'current_staff_record\']',
        $auth,
        'Current staff global compatibility was not preserved.'
    );
    foreach (['staff_id', 'full_name', 'role', 'last_activity'] as $sessionField) {
        $tests->assertContains(
            '$_SESSION[\'' . $sessionField . '\']',
            $auth,
            'Authenticated session field changed: ' . $sessionField
        );
    }
    foreach (['destroy_current_session()', "http_redirect('login.php')"] as $failureContract) {
        $tests->assertContains($failureContract, $auth, 'Authentication failure contract changed: ' . $failureContract);
    }
    foreach (['audit_log_denied(', 'http_response_code(403)', "exit('Access denied.')"] as $authorizationContract) {
        $tests->assertContains($authorizationContract, $auth, 'Admin denial contract changed: ' . $authorizationContract);
    }

    $tests->assertTrue(
        auth_staff_record_is_active_with_supported_role(['role' => 'admin', 'is_active' => 1]),
        'Active administrator records must remain valid.'
    );
    $tests->assertTrue(
        auth_staff_record_is_active_with_supported_role(['role' => 'cashier', 'is_active' => '1']),
        'Active cashier records must remain valid.'
    );
    $tests->assertFalse(
        auth_staff_record_is_active_with_supported_role(['role' => 'admin', 'is_active' => 0]),
        'Disabled staff records must remain invalid.'
    );
    $tests->assertFalse(
        auth_staff_record_is_active_with_supported_role(['role' => 'manager', 'is_active' => 1]),
        'Unsupported staff roles must remain invalid.'
    );
    $tests->assertFalse(auth_staff_record_is_active_with_supported_role(null), 'Missing staff records must remain invalid.');

    $GLOBALS['current_staff_record'] = ['role' => 'admin'];
    $tests->assertTrue(auth_is_admin(null), 'Administrator global compatibility changed.');
    $GLOBALS['current_staff_record'] = ['role' => 'cashier'];
    $tests->assertFalse(auth_is_admin(null), 'Cashier authorization behavior changed.');
    unset($GLOBALS['current_staff_record']);

    auth_extraction_test_open_session([]);
    $tests->assertFalse(auth_verify_login(null, false), 'A missing staff session must fail authentication.');
    $tests->assertSame(PHP_SESSION_NONE, session_status(), 'Missing-session authentication must invalidate the session.');

    auth_extraction_test_open_session(['staff_id' => 'invalid-staff-id']);
    $tests->assertFalse(auth_verify_login(null, false), 'An invalid staff identifier must fail authentication.');
    $tests->assertSame(PHP_SESSION_NONE, session_status(), 'Invalid staff identifiers must invalidate the session.');

    auth_extraction_test_open_session(['staff_id' => 123]);
    $tests->assertFalse(auth_verify_login(null, false), 'Authentication must fail without a database connection.');
    $tests->assertSame(PHP_SESSION_NONE, session_status(), 'Missing database connections must invalidate the session.');

    auth_extraction_test_open_session([
        'staff_id' => 123,
        'last_activity' => time() - SESSION_IDLE_TIMEOUT - 1,
    ]);
    $idleSessionId = session_id();
    session_write_close();
    session_id($idleSessionId);
    start_secure_session();
    $tests->assertSame(PHP_SESSION_NONE, session_status(), 'Idle sessions must still be destroyed.');
    $tests->assertFalse(isset($GLOBALS['current_staff_record']), 'Idle session invalidation must clear the current staff global.');

    foreach (['verify_csrf_token(', 'session_regenerate_id(true)', 'destroy_current_session()'] as $loginContract) {
        $tests->assertContains($loginContract, $login, 'Login/logout security contract changed: ' . $loginContract);
    }

    return $tests->assertions();
}
