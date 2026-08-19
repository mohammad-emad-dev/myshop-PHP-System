<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function run_http_harness_unit_tests(): int
{
    $tests = new TestContext();
    $environment = test_http_server_environment([
        'DB_NAME' => 'myshop_test_http_environment',
        'BOOTSTRAP_ADMIN_PASSWORD' => 'must-not-be-forwarded',
        'TEST_DB_ROOT_PASSWORD' => 'must-not-be-forwarded',
        'UNEXPECTED_TEST_SECRET' => 'must-not-be-forwarded',
    ]);

    $tests->assertSame(
        'myshop_test_http_environment',
        $environment['DB_NAME'] ?? null,
        'The temporary server must receive the explicitly selected test database.'
    );
    foreach (['TEST_DB_ROOT_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'BOOTSTRAP_ADMIN_PASSWORD', 'UNEXPECTED_TEST_SECRET'] as $forbidden) {
        $tests->assertFalse(array_key_exists($forbidden, $environment), 'Forbidden secret environment variable was forwarded: ' . $forbidden);
    }

    $tests->assertTrue(
        count(array_diff(array_keys($environment), test_http_server_environment_keys())) === 0,
        'The temporary server received an environment variable outside the explicit allow-list.'
    );

    return $tests->assertions();
}
