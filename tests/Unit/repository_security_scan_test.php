<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/scripts/repository-security-check.php';

function run_repository_security_scan_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myshop_security_' . bin2hex(random_bytes(8));
    $temporaryFiles = [];
    if (!mkdir($temporaryDirectory, 0700, true)) {
        throw new TestFailure('The temporary security-scan fixture directory could not be created.');
    }

    $writeFixture = static function (string $contents) use ($temporaryDirectory, &$temporaryFiles): string {
        $temporaryPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'fixture_' . count($temporaryFiles) . '.txt';
        if (file_put_contents($temporaryPath, $contents) === false) {
            throw new TestFailure('The temporary security-scan fixture could not be written.');
        }
        $temporaryFiles[] = $temporaryPath;
        return $temporaryPath;
    };

    $scanFixture = static function (string $virtualPath, string $contents) use ($writeFixture): array {
        $temporaryPath = $writeFixture($contents);
        $loadedContents = file_get_contents($temporaryPath);
        if (!is_string($loadedContents)) {
            throw new TestFailure('The temporary security-scan fixture could not be read.');
        }
        return repository_security_scan_content($virtualPath, $loadedContents);
    };

    try {
        $repositoryPath = str_replace('\\', '/', (string)realpath($repository));
        $temporaryPath = str_replace('\\', '/', (string)realpath($temporaryDirectory));
        $tests->assertFalse(
            str_starts_with(strtolower($temporaryPath), strtolower(rtrim($repositoryPath, '/') . '/')),
            'Security-scan fixtures must be stored outside the repository.'
        );

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
        $findings = $scanFixture(
            'quality-gate-hardcoded-password-case.php',
            '$' . 'password' . ' = "' . $hardcodedSecret . '";' . PHP_EOL
        );
        $tests->assertSame(1, count($findings), 'A hardcoded PHP password assignment must be reported exactly once.');
        $tests->assertFalse(
            strpos(json_encode($findings, JSON_THROW_ON_ERROR), $hardcodedSecret) !== false,
            'Security findings must never include secret contents.'
        );

        $findings = $scanFixture(
            'e2e/tests/quality-gate-safe-javascript-references.spec.js',
            "const credentials = {\n"
            . "  QA_ADMIN_PASSWORD: adminCredentials.password,\n"
            . "  QA_CASHIER_PASSWORD: cashierCredentials.password,\n"
            . "  DATABASE_PASSWORD: config.database.password,\n"
            . "  DB_PASSWORD: process.env.DB_PASSWORD,\n"
            . "};\n"
        );
        $tests->assertSame([], $findings, 'JavaScript property and process.env references must not be reported as hardcoded secrets.');

        $hardcodedJavaScriptSecret = str_repeat('b', 24);
        $javascriptPasswordKey = 'QA_' . 'ADMIN_PASSWORD';
        $findings = $scanFixture(
            'e2e/tests/quality-gate-hardcoded-password-case.js',
            'const credentials = { ' . $javascriptPasswordKey . ': "' . $hardcodedJavaScriptSecret . '" };' . PHP_EOL
        );
        $tests->assertSame(1, count($findings), 'A hardcoded JavaScript password assignment must be reported exactly once.');
        $tests->assertFalse(
            strpos(json_encode($findings, JSON_THROW_ON_ERROR), $hardcodedJavaScriptSecret) !== false,
            'JavaScript security findings must never include secret contents.'
        );

        $findings = $scanFixture(
            'quality-gate-environment-reference-case.php',
            "\$password = getenv('DB_PASSWORD');\n\$envPassword = \$_ENV['DB_PASSWORD'];\n\$password = \$environmentPassword;\n"
        );
        $tests->assertSame([], $findings, 'Environment and getenv references must not be reported as hardcoded secrets.');

        $findings = $scanFixture(
            'config/quality-gate-environment-reference.env',
            "DB_PASSWORD=\${DB_PASSWORD}\n"
        );
        $tests->assertSame([], $findings, 'Shell environment substitutions must not be reported as hardcoded secrets.');

        $findings = $scanFixture(
            'docs/quality-gate-documentation-example.txt',
            'API_' . 'KEY=' . str_repeat('a', 24) . PHP_EOL
        );
        $tests->assertSame([], $findings, 'Documentation examples must remain allowed.');

        $findings = $scanFixture(
            'tests/fixtures/quality-gate-placeholder.env',
            "DB_PASSWORD=replace_with_a_long_random_runtime_password\n"
        );
        $tests->assertSame([], $findings, 'Test fixtures and placeholders must remain allowed.');
    } finally {
        foreach ($temporaryFiles as $temporaryPath) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
        if (is_dir($temporaryDirectory)) {
            rmdir($temporaryDirectory);
        }
    }

    return $tests->assertions();
}
