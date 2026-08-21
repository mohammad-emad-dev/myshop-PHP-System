<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protect the Phase 5A operational configuration and runbook contracts.
 */
function run_operational_baseline_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $environmentExample = file_get_contents($repository . '/.env.example');
    $readme = file_get_contents($repository . '/README.md');
    $runbook = file_get_contents($repository . '/docs/PRODUCTION-DEPLOYMENT.md');
    $phaseDocument = file_get_contents($repository . '/docs/architecture/PHASE-5A-OPERATIONAL-BASELINE-TDD.md');

    foreach ([$environmentExample, $readme, $runbook, $phaseDocument] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Operational baseline fixture could not be read.');
    }

    $environmentExample = str_replace("\r\n", "\n", (string)$environmentExample);
    $readme = str_replace("\r\n", "\n", (string)$readme);
    $runbook = str_replace("\r\n", "\n", (string)$runbook);
    $phaseDocument = str_replace("\r\n", "\n", (string)$phaseDocument);

    $tests->assertContains(
        'PRODUCTION_APP_IMAGE=myshop-app:production',
        $environmentExample,
        'The local environment template must retain its documented development image example.'
    );
    $tests->assertContains(
        'preflight validates required production settings, rejects placeholder credentials and mutable image tags',
        $readme,
        'Production documentation must require preflight rejection of mutable image tags.'
    );

    $tests->assertFalse(
        strpos($readme, 'docker compose --env-file .env.production -f docker-compose.production.yml build') !== false,
        'The production deployment example must not build from the protected deploy environment.'
    );
    $tests->assertFalse(
        preg_match('/--env-file \/protected\/myshop\/production\.env\s+\\\s+--file docker-compose\.production\.yml\s+build/m', $runbook) === 1,
        'The production runbook must not build from the protected deploy environment.'
    );
    $releaseText = strtolower($readme . "\n" . $runbook);
    foreach ([
        'temporary ci build tag',
        'resolve the built image id to its immutable digest',
        'deploy only after the digest passes preflight',
    ] as $releaseContract) {
        $tests->assertContains($releaseContract, $releaseText, 'Release procedure contract is missing: ' . $releaseContract);
    }

    foreach ([
        'Database outage',
        'Failed application deployment',
        'Restore incident',
        'preserve the original database',
    ] as $incidentContract) {
        $tests->assertContains($incidentContract, $runbook, 'Incident response contract is missing: ' . $incidentContract);
    }

    foreach ([
        'Blocker',
        'High priority',
        'Medium priority',
        'Accepted risk',
        'Evidence missing',
        'Production readiness score',
    ] as $auditContract) {
        $tests->assertContains($auditContract, $phaseDocument, 'Phase 5A audit contract is missing: ' . $auditContract);
    }

    return $tests->assertions();
}
