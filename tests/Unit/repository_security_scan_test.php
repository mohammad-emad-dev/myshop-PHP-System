<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/scripts/repository-security-check.php';

function run_repository_security_scan_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $temporaryFiles = [
        'quality-gate-hardcoded-password-case.php',
        'quality-gate-environment-reference-case.php',
        'docs/quality-gate-documentation-example.txt',
        'tests/fixtures/quality-gate-placeholder.env',
    ];
    $temporaryDirectories = [];

    $writeFixture = static function (string $relativePath, string $contents) use ($repository, &$temporaryDirectories): void {
        $fullPath = $repository . '/' . $relativePath;
        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0700, true)) {
                throw new TestFailure('The temporary security-scan fixture directory could not be created.');
            }
            $temporaryDirectories[] = $directory;
        }
        if (file_put_contents($fullPath, $contents) === false) {
            throw new TestFailure('The temporary security-scan fixture could not be written.');
        }
    };

    try {
        $safeRepositoryFiles = [
            '.env.example',
            'README.md',
            '.github/workflows/quality.yml',
            'docker-compose.yml',
            'docker-compose.production.yml',
        ];
        $findings = repository_security_scan_files($safeRepositoryFiles, $repository);
        $tests->assertSame([], $findings, 'Known-safe repository examples and configuration must pass the scanner.');

        $hardcodedSecret = str_repeat('a', 21);
        $writeFixture(
            'quality-gate-hardcoded-password-case.php',
            '$' . 'password' . ' = "' . $hardcodedSecret . '";' . PHP_EOL
        );
        $findings = repository_security_scan_files(['quality-gate-hardcoded-password-case.php'], $repository);
        $tests->assertTrue($findings !== [], 'A hardcoded PHP password assignment must be reported.');
        $tests->assertFalse(
            strpos(json_encode($findings, JSON_THROW_ON_ERROR), $hardcodedSecret) !== false,
            'Security findings must never include secret contents.'
        );

        $writeFixture(
            'quality-gate-environment-reference-case.php',
            "\$password = getenv('DB_PASSWORD');\n\$envPassword = \$_ENV['DB_PASSWORD'];\n"
        );
        $findings = repository_security_scan_files(['quality-gate-environment-reference-case.php'], $repository);
        $tests->assertSame([], $findings, 'Environment and getenv references must not be reported as hardcoded secrets.');

        $writeFixture(
            'docs/quality-gate-documentation-example.txt',
            'API_' . 'KEY=' . str_repeat('a', 24) . PHP_EOL
        );
        $findings = repository_security_scan_files(['docs/quality-gate-documentation-example.txt'], $repository);
        $tests->assertSame([], $findings, 'Documentation examples must remain allowed.');

        $writeFixture(
            'tests/fixtures/quality-gate-placeholder.env',
            "DB_PASSWORD=replace_with_a_long_random_runtime_password\n"
        );
        $findings = repository_security_scan_files(['tests/fixtures/quality-gate-placeholder.env'], $repository);
        $tests->assertSame([], $findings, 'Test fixtures and placeholders must remain allowed.');
    } finally {
        foreach ($temporaryFiles as $relativePath) {
            $fullPath = $repository . '/' . $relativePath;
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
        foreach (array_reverse($temporaryDirectories) as $directory) {
            if (is_dir($directory) && count(scandir($directory) ?: []) <= 2) {
                rmdir($directory);
            }
        }
    }

    return $tests->assertions();
}
