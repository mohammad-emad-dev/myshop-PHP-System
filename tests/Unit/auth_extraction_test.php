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

    foreach ([
        'auth_staff_record_is_active_with_supported_role',
        'auth_verify_login',
        'auth_is_admin',
        'auth_require_admin',
    ] as $functionName) {
        $tests->assertContains('function ' . $functionName, $auth, 'Authentication function is missing: ' . $functionName);
    }
    $tests->assertContains('function http_redirect', $http, 'HTTP redirect implementation is missing.');

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
