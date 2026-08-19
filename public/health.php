<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
initialize_request_context();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
http_response_code(200);
echo '{"status":"ok","check":"liveness"}';
