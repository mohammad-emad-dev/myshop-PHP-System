<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the Phase 5B localhost installation, security, and recovery contracts.
 */
function run_localhost_readiness_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $readme = file_get_contents($repository . '/README.md');
    $runbook = file_get_contents($repository . '/docs/PRODUCTION-DEPLOYMENT.md');
    $phaseDocument = file_get_contents($repository . '/docs/architecture/PHASE-5B-LOCALHOST-READINESS-TDD.md');
    $compose = file_get_contents($repository . '/docker-compose.yml');
    $environmentExample = file_get_contents($repository . '/.env.example');
    $gitignore = file_get_contents($repository . '/.gitignore');
    $healthEndpoint = file_get_contents($repository . '/public/health.php');
    $readinessEndpoint = file_get_contents($repository . '/public/ready.php');
    $backupModule = file_get_contents($repository . '/includes/backup.php');

    foreach ([$readme, $runbook, $phaseDocument, $compose, $environmentExample, $gitignore, $healthEndpoint, $readinessEndpoint, $backupModule] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Localhost readiness fixture could not be read.');
    }

    $readme = str_replace("\r\n", "\n", (string)$readme);
    $runbook = str_replace("\r\n", "\n", (string)$runbook);
    $phaseDocument = str_replace("\r\n", "\n", (string)$phaseDocument);
    $compose = str_replace("\r\n", "\n", (string)$compose);
    $environmentExample = str_replace("\r\n", "\n", (string)$environmentExample);
    $gitignore = str_replace("\r\n", "\n", (string)$gitignore);
    $healthEndpoint = str_replace("\r\n", "\n", (string)$healthEndpoint);
    $readinessEndpoint = str_replace("\r\n", "\n", (string)$readinessEndpoint);
    $backupModule = str_replace("\r\n", "\n", (string)$backupModule);

    foreach ([
        'Phase 5B: Localhost Readiness',
        'localhost-first application',
        'not intended to be exposed directly to the public internet',
        'cloud controls',
        'XAMPP',
        'port conflicts',
        'restart and recovery',
        'local backup',
    ] as $phaseContract) {
        $tests->assertContains($phaseContract, $phaseDocument, 'Phase 5B evidence document is missing: ' . $phaseContract);
    }

    foreach ([
        'localhost-first application',
        'XAMPP',
        'APP_PORT',
        'MYSQL_PORT',
        '127.0.0.1',
        'docker compose --env-file .env config --quiet',
        'docker compose --env-file .env up --build -d',
        'docker compose --env-file .env restart',
        'MYSHOP_BACKUP_COMPLETE',
        'cloud controls',
    ] as $readmeContract) {
        $tests->assertContains($readmeContract, $readme, 'README local-operating contract is missing: ' . $readmeContract);
    }

    foreach ([
        '# MyShop localhost deployment and operations runbook',
        'XAMPP',
        '127.0.0.1',
        'port conflicts',
        'restart and recovery',
        'corrupted or replaced local database',
        'MYSHOP_BACKUP_COMPLETE',
        'not intended to be exposed directly to the public internet',
        'local operators are responsible for protecting the machine',
        'local backup files',
    ] as $runbookContract) {
        $tests->assertContains($runbookContract, $runbook, 'Local runbook contract is missing: ' . $runbookContract);
    }
    $tests->assertSame(
        1,
        substr_count($runbook, 'local operators are responsible for protecting the machine'),
        'The local operator responsibility statement must not be duplicated.'
    );

    $tests->assertContains('"127.0.0.1:${APP_PORT:-8080}:80"', $compose, 'The Docker app must bind to loopback by default.');
    $tests->assertContains('"127.0.0.1:${MYSQL_PORT:-3307}:3306"', $compose, 'The Docker database must bind to loopback by default.');
    $tests->assertContains('condition: service_healthy', $compose, 'The app must wait for a healthy local database.');
    $tests->assertContains('mysql_data:/var/lib/mysql', $compose, 'The local database must use persistent storage.');
    $tests->assertContains('source: ./public/uploads', $compose, 'Local uploads must use the persistent project upload directory.');
    $tests->assertContains('DB_HOST=127.0.0.1', $environmentExample, 'The native local workflow must document a loopback database host.');
    $tests->assertContains('APP_PORT=8080', $environmentExample, 'The local application port must be documented.');
    $tests->assertContains('MYSQL_PORT=3307', $environmentExample, 'The local database port must be documented.');
    $tests->assertContains(".env\n", $gitignore, 'Local environment files must remain ignored.');
    $tests->assertContains("*.dump\n", $gitignore, 'Local database dumps must remain ignored.');

    $tests->assertContains('"status":"ok"', $healthEndpoint, 'Liveness must retain its generic success response.');
    $tests->assertContains('"check":"liveness"', $healthEndpoint, 'Liveness must identify itself without database access.');
    $tests->assertContains('SELECT 1', $readinessEndpoint, 'Readiness must verify the local database.');
    $tests->assertContains('http_response_code(503)', $readinessEndpoint, 'Readiness must fail safely while MySQL is unavailable.');
    $tests->assertContains('MYSHOP_BACKUP_COMPLETE', $backupModule, 'Local backup output must retain its completeness marker.');

    return $tests->assertions();
}
