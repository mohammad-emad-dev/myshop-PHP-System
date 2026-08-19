<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
initialize_request_context();

define('DB_FAILURE_RESPONSE_JSON', true);
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$ready = false;
$probe = null;
try {
    $probe = $conn->query('SELECT 1');
    $ready = $probe !== false;
} catch (Throwable $exception) {
    log_application_error('Readiness database probe failed: ' . $exception->getMessage());
}

if ($probe instanceof mysqli_result) {
    $probe->free();
}

if (!$ready) {
    http_response_code(503);
    echo '{"status":"not_ready","check":"database"}';
    exit;
}

http_response_code(200);
echo '{"status":"ready","check":"database"}';
