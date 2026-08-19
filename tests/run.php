<?php

declare(strict_types=1);

require_once __DIR__ . '/Unit/validation_test.php';
require_once __DIR__ . '/Unit/deployment_test.php';
require_once __DIR__ . '/Unit/http_harness_test.php';
require_once __DIR__ . '/Unit/repository_security_scan_test.php';
require_once __DIR__ . '/Integration/database_test.php';
require_once __DIR__ . '/Integration/backup_restore_test.php';
require_once __DIR__ . '/Integration/operational_test.php';

$started = microtime(true);

try {
    $unitAssertions = run_unit_tests();
    $deploymentAssertions = run_deployment_unit_tests();
    $httpHarnessAssertions = run_http_harness_unit_tests();
    $securityScanAssertions = run_repository_security_scan_unit_tests();
    $integrationAssertions = run_integration_tests();
    $backupAssertions = run_backup_restore_tests();
    $operationalAssertions = run_operational_tests();
    $totalAssertions = $unitAssertions + $deploymentAssertions + $httpHarnessAssertions + $securityScanAssertions + $integrationAssertions + $backupAssertions + $operationalAssertions;
    $duration = number_format(microtime(true) - $started, 2);
    echo "PASS: {$totalAssertions} assertions (" . ($unitAssertions + $deploymentAssertions + $httpHarnessAssertions + $securityScanAssertions) . " unit, " .
        ($integrationAssertions + $backupAssertions + $operationalAssertions) . " integration) in {$duration}s\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
