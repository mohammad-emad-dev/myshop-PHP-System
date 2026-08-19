<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/scripts/repository-security-check.php';

function run_repository_security_scan_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $fixtureDirectory = $repository . '/tests/fixtures';
    $secretFixture = $repository . '/quality-gate-controlled-secret-case.txt';
    $placeholderFixture = $fixtureDirectory . '/placeholder-case.env';
    $directoryCreated = false;

    try {
        $findings = repository_security_scan_repository($repository);
        $tests->assertSame([], $findings, 'The current tracked repository must pass its security scan.');

        $directoryCreated = is_dir($fixtureDirectory) || mkdir($fixtureDirectory, 0700, true);
        $tests->assertTrue($directoryCreated, 'The temporary security-scan fixture directory could not be created.');

        $fakeSecret = str_repeat('a', 40);
        file_put_contents($secretFixture, 'API_KEY=' . $fakeSecret . PHP_EOL);
        $findings = repository_security_scan_files(['quality-gate-controlled-secret-case.txt'], $repository);
        $tests->assertTrue($findings !== [], 'The security scan must reject a controlled high-entropy secret fixture.');
        $tests->assertFalse(
            strpos(json_encode($findings, JSON_THROW_ON_ERROR), $fakeSecret) !== false,
            'Security findings must never include secret contents.'
        );

        file_put_contents($placeholderFixture, "DB_PASSWORD=replace_with_a_long_random_runtime_password\n");
        $findings = repository_security_scan_files(['tests/fixtures/placeholder-case.env'], $repository);
        $tests->assertSame([], $findings, 'Fixture placeholders must not be reported as secrets.');
    } finally {
        if (is_file($secretFixture)) {
            unlink($secretFixture);
        }
        if (is_file($placeholderFixture)) {
            unlink($placeholderFixture);
        }
        if ($directoryCreated && is_dir($fixtureDirectory) && count(scandir($fixtureDirectory) ?: []) <= 2) {
            rmdir($fixtureDirectory);
        }
    }

    return $tests->assertions();
}
