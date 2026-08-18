<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

$username = getenv('BOOTSTRAP_ADMIN_USERNAME');
$full_name = getenv('BOOTSTRAP_ADMIN_FULL_NAME');
$password = getenv('BOOTSTRAP_ADMIN_PASSWORD');

if ($username === false || $username === '' || $full_name === false || $full_name === '' || $password === false || $password === '') {
    fwrite(STDERR, "Set BOOTSTRAP_ADMIN_USERNAME, BOOTSTRAP_ADMIN_FULL_NAME, and BOOTSTRAP_ADMIN_PASSWORD before running this command.\n");
    exit(1);
}

if (!password_meets_policy($password)) {
    fwrite(STDERR, "BOOTSTRAP_ADMIN_PASSWORD must contain at least 12 characters.\n");
    exit(1);
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);
if ($password_hash === false) {
    fwrite(STDERR, "Unable to create the administrator password hash.\n");
    exit(1);
}

$stmt = $conn->prepare(
    "INSERT INTO Staff (username, password, full_name, role) VALUES (?, ?, ?, 'admin')"
);

if (!$stmt) {
    error_log('Administrator bootstrap prepare failed: ' . $conn->error);
    fwrite(STDERR, "Unable to create the administrator account.\n");
    exit(1);
}

$stmt->bind_param('sss', $username, $password_hash, $full_name);

if (!$stmt->execute()) {
    error_log('Administrator bootstrap insert failed: ' . $stmt->error);
    fwrite(STDERR, "Unable to create the administrator account. The username may already exist.\n");
    $stmt->close();
    exit(1);
}

$stmt->close();
fwrite(STDOUT, "Administrator account created.\n");
