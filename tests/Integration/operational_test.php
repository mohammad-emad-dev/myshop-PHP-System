<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function run_operational_scenario(TestContext $tests): void
{
    $database = new DisposableDatabase();
    $servers = [];
    $blocker = null;

    try {
        $database->setup();

        $servers[] = test_start_local_server();
        $server = $servers[0];

        [$liveStatus, $liveBody, $liveHeaders] = test_http_get($server[1], '/health.php');
        $tests->assertSame(200, $liveStatus, 'Liveness endpoint must return HTTP 200.');
        $tests->assertContains('"status":"ok"', $liveBody, 'Liveness endpoint returned an unexpected status.');
        $tests->assertContains('"check":"liveness"', $liveBody, 'Liveness endpoint did not identify its check.');
        $tests->assertFalse(stripos($liveBody, 'password') !== false, 'Liveness response must not expose credentials.');
        $tests->assertFalse(stripos($liveBody, 'var/www') !== false, 'Liveness response must not expose filesystem paths.');
        $tests->assertTrue(
            count(array_filter($liveHeaders, static fn(string $header): bool => stripos($header, 'X-Request-ID: ') === 0)) === 1,
            'Liveness response must include one server-generated request ID.'
        );

        [$readyStatus, $readyBody] = test_http_get($server[1], '/ready.php');
        $tests->assertSame(200, $readyStatus, 'Healthy readiness endpoint must return HTTP 200.');
        $tests->assertContains('"status":"ready"', $readyBody, 'Readiness endpoint did not report ready.');
        $tests->assertContains('"check":"database"', $readyBody, 'Readiness endpoint did not identify its database check.');
        $tests->assertFalse(stripos($readyBody, 'SQL') !== false, 'Readiness response must not expose SQL diagnostics.');

        [$notFoundStatus, $notFoundBody] = test_http_get($server[1], '/qa-batch26-missing-route.php');
        $tests->assertSame(404, $notFoundStatus, 'The HTTP helper must preserve a 404 response.');
        $tests->assertFalse(stripos($notFoundBody, 'var/www') !== false, 'Error responses must not expose filesystem paths.');

        [$redirectStatus, $redirectBody, $redirectHeaders] = test_http_get($server[1], '/index.php');
        $tests->assertSame(302, $redirectStatus, 'Protected dashboard access must preserve its redirect response.');
        $tests->assertSame('', $redirectBody, 'Redirect handling must not follow or replace the response body.');
        $tests->assertTrue(
            count(array_filter($redirectHeaders, static fn(string $header): bool => stripos($header, 'Location: ') === 0)) === 1,
            'The HTTP helper must retain the redirect location header.'
        );

        $missingDatabase = 'myshop_health_missing_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));
        $servers[] = test_start_local_server(['DB_NAME' => $missingDatabase]);
        [$failedReadyStatus, $failedReadyBody] = test_http_get($servers[1][1], '/ready.php');
        $tests->assertSame(503, $failedReadyStatus, 'Database-failure readiness must return HTTP 503.');
        $tests->assertContains('"status":"not_ready"', $failedReadyBody, 'Database-failure readiness response must be generic.');
        $tests->assertFalse(stripos($failedReadyBody, 'mysqli') !== false, 'Readiness response must not expose driver errors.');
        $tests->assertFalse(stripos($failedReadyBody, $missingDatabase) !== false, 'Readiness response must not expose database names.');
        $tests->assertFalse(stripos($failedReadyBody, 'password') !== false, 'Readiness response must not expose credentials.');

        $errorNumber = 0;
        $errorMessage = '';
        $blocker = @stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        if (!is_resource($blocker)) {
            throw new TestFailure('Unable to reserve a local TCP port for the occupied-port test.');
        }
        $socketName = stream_socket_get_name($blocker, false);
        $separatorPosition = strrpos((string)$socketName, ':');
        $occupiedPort = $separatorPosition === false ? 0 : (int)substr((string)$socketName, $separatorPosition + 1);
        if ($occupiedPort < 1024 || $occupiedPort > 65535) {
            throw new TestFailure('The occupied-port test received an invalid local port.');
        }

        $retriedServer = test_start_local_server([], $occupiedPort);
        $servers[] = $retriedServer;
        $tests->assertNotSame($occupiedPort, $retriedServer[1], 'The HTTP harness must retry an occupied port.');
        [$retriedStatus, $retriedBody] = test_http_get($retriedServer[1], '/health.php');
        $tests->assertSame(200, $retriedStatus, 'The retried server must serve the expected liveness endpoint.');
        $tests->assertContains('"check":"liveness"', $retriedBody, 'The retried server must be the expected PHP test server.');
    } finally {
        foreach ($servers as $server) {
            test_stop_local_server($server);
            $tests->assertFalse(test_local_server_is_running($server[0] ?? null), 'Temporary HTTP server cleanup must terminate the process.');
            foreach ([2, 3] as $pathIndex) {
                $diagnosticPath = $server[$pathIndex] ?? null;
                if (is_string($diagnosticPath) && $diagnosticPath !== '') {
                    $tests->assertFalse(is_file($diagnosticPath), 'Temporary HTTP server diagnostics must be removed during cleanup.');
                }
            }
        }

        if (is_resource($blocker)) {
            fclose($blocker);
        }

        $database->cleanup();
    }
}

function run_operational_tests(): int
{
    $tests = new TestContext();
    $originalDatabaseName = getenv('DB_NAME');

    for ($iteration = 1; $iteration <= 2; $iteration++) {
        run_operational_scenario($tests);
        $tests->assertSame(
            $originalDatabaseName,
            getenv('DB_NAME'),
            'Disposable operational test ' . $iteration . ' must restore the runtime database environment.'
        );
    }

    return $tests->assertions();
}
