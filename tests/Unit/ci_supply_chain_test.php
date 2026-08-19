<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/scripts/ci-supply-chain-check.php';

function run_ci_supply_chain_unit_tests(): int
{
    $tests = new TestContext();
    $sha = str_repeat('a', 40);

    $pinned = "jobs:\n  test:\n    steps:\n      - uses: actions/checkout@{$sha} # v4.4.0\n";
    $tests->assertSame([], ci_supply_chain_scan_content('.github/workflows/virtual.yml', $pinned), 'A full action SHA with a release comment must be accepted.');

    $mutable = "steps:\n  - uses: actions/checkout@v4\n";
    $mutableFindings = ci_supply_chain_scan_content('.github/workflows/virtual.yml', $mutable);
    $tests->assertSame(1, count($mutableFindings), 'A mutable action tag must be rejected.');

    $missingComment = "steps:\n  - uses: actions/checkout@{$sha}\n";
    $tests->assertSame(1, count(ci_supply_chain_scan_content('.github/workflows/virtual.yml', $missingComment)), 'A pinned action without a release comment must be rejected.');

    $comment = "# - uses: actions/checkout@v4\n";
    $tests->assertSame([], ci_supply_chain_scan_content('.github/workflows/virtual.yml', $comment), 'Commented workflow examples must be ignored.');

    $localAction = "steps:\n  - uses: ./actions/local-check\n";
    $tests->assertSame([], ci_supply_chain_scan_content('.github/workflows/virtual.yml', $localAction), 'Local repository actions are not third-party references.');

    $immutableImage = "services:\n  app:\n    image: registry.example/myshop@sha256:" . str_repeat('b', 64) . "\n";
    $tests->assertSame([], ci_supply_chain_scan_content('docker-compose.production.yml', $immutableImage), 'A production image digest must be accepted.');

    $mutableImage = "services:\n  app:\n    image: registry.example/myshop:latest\n";
    $imageFindings = ci_supply_chain_scan_content('docker-compose.production.yml', $mutableImage);
    $tests->assertSame(1, count($imageFindings), 'A mutable production image tag must be rejected.');

    $commentedImage = "services:\n  app:\n    # image: registry.example/myshop:latest\n";
    $tests->assertSame([], ci_supply_chain_scan_content('docker-compose.production.yml', $commentedImage), 'Commented image examples must be ignored.');

    $tests->assertSame([], ci_supply_chain_scan_content('docs/PRODUCTION-DEPLOYMENT.md', 'image: registry.example/myshop:latest'), 'Documentation image examples must be ignored.');
    $tests->assertSame([], ci_supply_chain_scan_content('docker-compose.yml', 'image: mysql:8.4.3'), 'Local development image tags must be allowed.');

    $temporaryCiImage = "PRODUCTION_APP_IMAGE=myshop-app:ci-\${GITHUB_SHA}\n";
    $tests->assertSame([], ci_supply_chain_scan_content('.github/workflows/virtual.yml', $temporaryCiImage), 'Temporary CI build tags must be allowed only with the CI commit reference.');

    $unsafeWorkflowImage = "PRODUCTION_APP_IMAGE=myshop-app:production\n";
    $tests->assertSame(1, count(ci_supply_chain_scan_content('.github/workflows/virtual.yml', $unsafeWorkflowImage)), 'A deployable workflow image tag must be rejected.');

    return $tests->assertions();
}
