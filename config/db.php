<?php

/**
 * Load database configuration from the process environment and open the
 * application connection. Schema creation and data seeding do not belong in
 * this request-time include; run database/schema.sql during deployment.
 */
$fail_database_connection = static function ($technical_message) {
    error_log('Database initialization failed: ' . $technical_message);

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }

    exit('Database connection is unavailable.');
};

if (!extension_loaded('mysqli')) {
    $fail_database_connection('The mysqli extension is not loaded.');
}

$required_environment = [
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'DB_PASSWORD'
];

$database_config = [];
foreach ($required_environment as $environment_key) {
    $environment_value = getenv($environment_key);

    if ($environment_value === false || $environment_value === '') {
        $fail_database_connection('Missing required environment variable: ' . $environment_key);
    }

    $database_config[$environment_key] = $environment_value;
}

$database_port = filter_var(
    $database_config['DB_PORT'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]
);

if ($database_port === false) {
    $fail_database_connection('DB_PORT must be an integer between 1 and 65535.');
}

try {
    $conn = @new mysqli(
        $database_config['DB_HOST'],
        $database_config['DB_USER'],
        $database_config['DB_PASSWORD'],
        $database_config['DB_NAME'],
        $database_port
    );

    if ($conn->connect_errno) {
        $fail_database_connection('MySQL connection error ' . $conn->connect_errno . ': ' . $conn->connect_error);
    }

    if (!$conn->set_charset('utf8mb4')) {
        $fail_database_connection('Unable to set the MySQL connection character set: ' . $conn->error);
    }
} catch (Throwable $exception) {
    $fail_database_connection('MySQL connection exception: ' . $exception->getMessage());
}

unset($database_config, $required_environment, $database_port, $environment_key, $environment_value);
