<?php

declare(strict_types=1);

require_once __DIR__ . '/Unit/validation_test.php';
require_once __DIR__ . '/Unit/architecture_baseline_test.php';
require_once __DIR__ . '/Unit/catalog_read_test.php';
require_once __DIR__ . '/Unit/product_write_test.php';
require_once __DIR__ . '/Unit/order_write_test.php';
require_once __DIR__ . '/Unit/order_read_test.php';
require_once __DIR__ . '/Unit/dashboard_test.php';
require_once __DIR__ . '/Unit/people_read_test.php';
require_once __DIR__ . '/Unit/inventory_read_test.php';
require_once __DIR__ . '/Unit/inventory_adjustment_test.php';
require_once __DIR__ . '/Unit/auth_extraction_test.php';
require_once __DIR__ . '/Unit/deployment_test.php';
require_once __DIR__ . '/Unit/http_harness_test.php';
require_once __DIR__ . '/Unit/repository_security_scan_test.php';
require_once __DIR__ . '/Unit/ci_supply_chain_test.php';
require_once __DIR__ . '/Unit/release_integrity_test.php';
require_once __DIR__ . '/Integration/database_test.php';
require_once __DIR__ . '/Integration/backup_restore_test.php';
require_once __DIR__ . '/Integration/operational_test.php';
require_once __DIR__ . '/Integration/export_streaming_test.php';
require_once __DIR__ . '/Integration/dashboard_test.php';

$started = microtime(true);

try {
    $unitAssertions = run_unit_tests();
    $architectureAssertions = run_architecture_baseline_unit_tests();
    $catalogAssertions = run_catalog_read_unit_tests();
    $productWriteAssertions = run_product_write_unit_tests();
    $orderWriteAssertions = run_order_write_unit_tests();
    $orderReadAssertions = run_order_read_unit_tests();
    $dashboardAssertions = run_dashboard_unit_tests();
    $peopleAssertions = run_people_read_unit_tests();
    $inventoryAssertions = run_inventory_read_unit_tests();
    $inventoryAdjustmentAssertions = run_inventory_adjustment_unit_tests();
    $authAssertions = run_auth_extraction_unit_tests();
    $deploymentAssertions = run_deployment_unit_tests();
    $httpHarnessAssertions = run_http_harness_unit_tests();
    $securityScanAssertions = run_repository_security_scan_unit_tests();
    $supplyChainAssertions = run_ci_supply_chain_unit_tests();
    $releaseIntegrityAssertions = run_release_integrity_unit_tests();
    $integrationAssertions = run_integration_tests();
    $backupAssertions = run_backup_restore_tests();
    $operationalAssertions = run_operational_tests();
    $exportAssertions = run_export_streaming_tests();
    $dashboardIntegrationAssertions = run_dashboard_integration_tests();
    $totalAssertions = $unitAssertions + $architectureAssertions + $catalogAssertions + $productWriteAssertions + $orderWriteAssertions + $orderReadAssertions + $dashboardAssertions + $peopleAssertions + $inventoryAssertions + $inventoryAdjustmentAssertions + $authAssertions + $deploymentAssertions + $httpHarnessAssertions + $securityScanAssertions + $supplyChainAssertions + $releaseIntegrityAssertions + $integrationAssertions + $backupAssertions + $operationalAssertions + $exportAssertions + $dashboardIntegrationAssertions;
    $duration = number_format(microtime(true) - $started, 2);
    echo "PASS: {$totalAssertions} assertions (" . ($unitAssertions + $architectureAssertions + $catalogAssertions + $productWriteAssertions + $orderWriteAssertions + $orderReadAssertions + $peopleAssertions + $inventoryAssertions + $inventoryAdjustmentAssertions + $authAssertions + $deploymentAssertions + $httpHarnessAssertions + $securityScanAssertions + $supplyChainAssertions + $releaseIntegrityAssertions) . " unit, " .
        ($integrationAssertions + $backupAssertions + $operationalAssertions + $exportAssertions + $dashboardIntegrationAssertions) . " integration) in {$duration}s\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
