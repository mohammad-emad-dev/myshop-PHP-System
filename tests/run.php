<?php

declare(strict_types=1);

require_once __DIR__ . '/Unit/validation_test.php';
require_once __DIR__ . '/Integration/database_test.php';

$started = microtime(true);

try {
    $unitAssertions = run_unit_tests();
    $integrationAssertions = run_integration_tests();
    $totalAssertions = $unitAssertions + $integrationAssertions;
    $duration = number_format(microtime(true) - $started, 2);
    echo "PASS: {$totalAssertions} assertions ({$unitAssertions} unit, {$integrationAssertions} integration) in {$duration}s\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
