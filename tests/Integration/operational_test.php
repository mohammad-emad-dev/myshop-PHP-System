<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function run_operational_tests(): int
{
    $tests = new TestContext();
    $database = new DisposableDatabase();
    $servers = [];

    try {
        $database->setup();

        $servers[] = test_start_local_server();
        [$liveStatus, $liveBody, $liveHeaders] = test_http_get($servers[0][1], '/health.php');
        $tests->assertSame(200, $liveStatus, 'Liveness endpoint must return HTTP 200.');
        $tests->assertContains('"status":"ok"', $liveBody, 'Liveness endpoint returned an unexpected status.');
        $tests->assertContains('"check":"liveness"', $liveBody, 'Liveness endpoint did not identify its check.');
        $tests->assertFalse(stripos($liveBody, 'password') !== false, 'Liveness response must not expose credentials.');
        $tests->assertFalse(stripos($liveBody, 'var/www') !== false, 'Liveness response must not expose filesystem paths.');
        $tests->assertTrue(
            count(array_filter($liveHeaders, static fn(string $header): bool => stripos($header, 'X-Request-ID: ') === 0)) === 1,
            'Liveness response must include one server-generated request ID.'
        );

        [$readyStatus, $readyBody] = test_http_get($servers[0][1], '/ready.php');
        $tests->assertSame(200, $readyStatus, 'Healthy readiness endpoint must return HTTP 200.');
        $tests->assertContains('"status":"ready"', $readyBody, 'Readiness endpoint did not report ready.');
        $tests->assertContains('"check":"database"', $readyBody, 'Readiness endpoint did not identify its database check.');
        $tests->assertFalse(stripos($readyBody, 'SQL') !== false, 'Readiness response must not expose SQL diagnostics.');

        $missingDatabase = 'myshop_health_missing_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));
        $servers[] = test_start_local_server(['DB_NAME' => $missingDatabase]);
        [$failedReadyStatus, $failedReadyBody] = test_http_get($servers[1][1], '/ready.php');
        $tests->assertSame(503, $failedReadyStatus, 'Database-failure readiness must return HTTP 503.');
        $tests->assertContains('"status":"not_ready"', $failedReadyBody, 'Database-failure readiness response must be generic.');
        $tests->assertFalse(stripos($failedReadyBody, 'mysqli') !== false, 'Readiness response must not expose driver errors.');
        $tests->assertFalse(stripos($failedReadyBody, $missingDatabase) !== false, 'Readiness response must not expose database names.');
        $tests->assertFalse(stripos($failedReadyBody, 'password') !== false, 'Readiness response must not expose credentials.');

        return $tests->assertions();
    } finally {
        foreach ($servers as $server) {
            if (is_array($server) && isset($server[0]) && is_resource($server[0])) {
                proc_terminate($server[0]);
                proc_close($server[0]);
            }
        }
        $database->cleanup();
    }
}
