<?php

declare(strict_types=1);

/**
 * Validate the deployment boundary without contacting external services or
 * printing configuration values. The normal web service must receive only
 * the restricted runtime database credentials.
 */
function production_preflight_required_environment(): array
{
    return [
        'APP_ENV',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_SCHEMA_USER',
        'DB_SCHEMA_PASSWORD',
        'MYSQL_ROOT_PASSWORD',
        'PHP_BASE_IMAGE',
        'PRODUCTION_APP_IMAGE',
        'PRODUCTION_MYSQL_IMAGE',
        'TRUSTED_PROXY_IPS',
        'HSTS_ENABLED',
        'HSTS_MAX_AGE',
    ];
}

function production_preflight_parse_env_file(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read the production environment file.');
    }

    $environment = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
            throw new RuntimeException('The production environment file contains an invalid assignment.');
        }

        $value = trim($matches[2]);
        if (
            strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $environment[$matches[1]] = $value;
    }

    return $environment;
}

function production_preflight_environment_from_process(): array
{
    $environment = [];
    foreach (production_preflight_required_environment() as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $environment[$key] = $value;
        }
    }

    return $environment;
}

function production_preflight_is_placeholder(string $value): bool
{
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return true;
    }

    if (preg_match('/(?:replace[_-]?with|change[_-]?me|changeme|placeholder|example|dummy|fake|test(?:[_-]?only)?|local(?:[_-]?only)?|default|your[_-]?|<[^>]+>)/', $normalized) === 1) {
        return true;
    }

    if (in_array($normalized, ['password', 'password123', 'root', 'admin', 'secret', 'letmein'], true)) {
        return true;
    }

    return preg_match('/^(.)\1{7,}$/', $normalized) === 1;
}

function production_preflight_is_immutable_image_reference(string $value): bool
{
    return preg_match(
        '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*@sha256:[a-f0-9]{64}$/',
        trim($value)
    ) === 1;
}

function production_preflight_extract_service(string $composeContents, string $service): ?string
{
    $pattern = '/^  ' . preg_quote($service, '/') . ':\s*\R(?<body>.*?)(?=^  [A-Za-z0-9][A-Za-z0-9_-]*:\s*$|\z)/ms';
    if (preg_match($pattern, $composeContents, $matches) !== 1) {
        return null;
    }

    return $matches['body'];
}

function production_preflight_validate(array $environment, string $composeContents): array
{
    $errors = [];
    $required = production_preflight_required_environment();

    foreach ($required as $key) {
        if (!array_key_exists($key, $environment) || !is_scalar($environment[$key]) || trim((string)$environment[$key]) === '') {
            $errors[] = 'Missing required production setting: ' . $key . '.';
        }
    }

    if (($environment['APP_ENV'] ?? null) !== 'production') {
        $errors[] = 'APP_ENV must be exactly production.';
    }

    $databasePort = filter_var(
        (string)($environment['DB_PORT'] ?? ''),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    );
    if ($databasePort === false) {
        $errors[] = 'DB_PORT must be an integer between 1 and 65535.';
    }

    foreach (['DB_PASSWORD', 'DB_SCHEMA_PASSWORD', 'MYSQL_ROOT_PASSWORD'] as $key) {
        if (!array_key_exists($key, $environment) || !is_scalar($environment[$key])) {
            continue;
        }

        $value = trim((string)$environment[$key]);
        if (production_preflight_is_placeholder($value)) {
            $errors[] = $key . ' must not use a placeholder or default credential.';
        }
        if (strlen($value) < 20) {
            $errors[] = $key . ' must be at least 20 characters.';
        }
    }

    foreach (['DB_USER', 'DB_SCHEMA_USER'] as $key) {
        if (array_key_exists($key, $environment) && is_scalar($environment[$key]) && production_preflight_is_placeholder((string)$environment[$key])) {
            $errors[] = $key . ' must not use a placeholder or default account name.';
        }
    }

    if (($environment['DB_USER'] ?? null) === 'root') {
        $errors[] = 'DB_USER must be a restricted runtime account, not root.';
    }
    if (isset($environment['DB_SCHEMA_USER'], $environment['DB_USER']) && $environment['DB_SCHEMA_USER'] === $environment['DB_USER']) {
        $errors[] = 'DB_SCHEMA_USER must be separate from DB_USER.';
    }
    if (($environment['DB_SCHEMA_USER'] ?? null) === 'root') {
        $errors[] = 'DB_SCHEMA_USER must be a separate deployment account, not root.';
    }

    if (
        isset($environment['DB_PASSWORD'], $environment['DB_SCHEMA_PASSWORD'])
        && $environment['DB_PASSWORD'] === $environment['DB_SCHEMA_PASSWORD']
    ) {
        $errors[] = 'DB_PASSWORD and DB_SCHEMA_PASSWORD must be different credentials.';
    }
    if (
        isset($environment['DB_PASSWORD'], $environment['MYSQL_ROOT_PASSWORD'])
        && $environment['DB_PASSWORD'] === $environment['MYSQL_ROOT_PASSWORD']
    ) {
        $errors[] = 'DB_PASSWORD and MYSQL_ROOT_PASSWORD must be different credentials.';
    }
    if (
        isset($environment['DB_SCHEMA_PASSWORD'], $environment['MYSQL_ROOT_PASSWORD'])
        && $environment['DB_SCHEMA_PASSWORD'] === $environment['MYSQL_ROOT_PASSWORD']
    ) {
        $errors[] = 'DB_SCHEMA_PASSWORD and MYSQL_ROOT_PASSWORD must be different credentials.';
    }

    foreach (['PHP_BASE_IMAGE', 'PRODUCTION_APP_IMAGE', 'PRODUCTION_MYSQL_IMAGE'] as $key) {
        if (array_key_exists($key, $environment) && !is_scalar($environment[$key])) {
            continue;
        }
        if (!production_preflight_is_immutable_image_reference((string)($environment[$key] ?? ''))) {
            $errors[] = $key . ' must use an immutable sha256 digest reference.';
        }
    }

    $hstsEnabled = strtolower(trim((string)($environment['HSTS_ENABLED'] ?? '')));
    if (!in_array($hstsEnabled, ['true', 'false'], true)) {
        $errors[] = 'HSTS_ENABLED must be true or false.';
    } elseif ($hstsEnabled !== 'true') {
        $errors[] = 'HSTS_ENABLED must be true for production.';
    }

    $hstsMaxAge = filter_var(
        (string)($environment['HSTS_MAX_AGE'] ?? ''),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 31536000, 'max_range' => 63072000]]
    );
    if ($hstsMaxAge === false) {
        $errors[] = 'HSTS_MAX_AGE must be an integer from 31536000 through 63072000.';
    }

    $trustedProxyIps = trim((string)($environment['TRUSTED_PROXY_IPS'] ?? ''));
    if ($hstsEnabled === 'true' && $trustedProxyIps === '') {
        $errors[] = 'TRUSTED_PROXY_IPS must contain the fixed reverse-proxy IP when HSTS is enabled.';
    }
    if ($trustedProxyIps !== '') {
        foreach (explode(',', $trustedProxyIps) as $candidate) {
            if (filter_var(trim($candidate), FILTER_VALIDATE_IP) === false) {
                $errors[] = 'TRUSTED_PROXY_IPS must contain only comma-separated exact IP addresses.';
                break;
            }
        }
    }

    $appService = production_preflight_extract_service($composeContents, 'app');
    $databaseService = production_preflight_extract_service($composeContents, 'db');
    if ($appService === null) {
        $errors[] = 'Production Compose must define an app service.';
    } else {
        foreach (['MYSQL_ROOT_PASSWORD', 'DB_SCHEMA_USER', 'DB_SCHEMA_PASSWORD', 'TEST_DB_ROOT_PASSWORD'] as $forbiddenKey) {
            if (preg_match('/^\s*(?:-\s*)?' . preg_quote($forbiddenKey, '/') . '\s*[:=]/m', $appService) === 1) {
                $errors[] = 'Production app service must not receive ' . $forbiddenKey . '.';
            }
        }
        foreach (['DB_USER', 'DB_PASSWORD'] as $runtimeKey) {
            if (preg_match('/^\s*(?:-\s*)?' . preg_quote($runtimeKey, '/') . '\s*[:=]/m', $appService) !== 1) {
                $errors[] = 'Production app service must receive the restricted ' . $runtimeKey . ' setting.';
            }
        }
    }

    if ($databaseService === null) {
        $errors[] = 'Production Compose must define a db service.';
    } elseif (preg_match('/^\s*MYSQL_ROOT_PASSWORD\s*:/m', $databaseService) !== 1) {
        $errors[] = 'Production database service must receive MYSQL_ROOT_PASSWORD only at the database boundary.';
    }

    return array_values(array_unique($errors));
}

function production_preflight_main(array $arguments): int
{
    $options = getopt('', ['env-file:', 'compose-file:', 'help']);
    if (isset($options['help'])) {
        fwrite(STDOUT, "Usage: php scripts/production-preflight.php --env-file PATH --compose-file PATH\n");
        return 0;
    }

    try {
        $environment = isset($options['env-file'])
            ? production_preflight_parse_env_file((string)$options['env-file'])
            : production_preflight_environment_from_process();
        $composePath = (string)($options['compose-file'] ?? dirname(__DIR__) . '/docker-compose.production.yml');
        $composeContents = @file_get_contents($composePath);
        if ($composeContents === false) {
            throw new RuntimeException('Unable to read the production Compose file.');
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, "Production preflight failed: unable to read the requested configuration input.\n");
        return 2;
    }

    $errors = production_preflight_validate($environment, $composeContents);
    if ($errors !== []) {
        fwrite(STDERR, "Production preflight failed:\n");
        foreach ($errors as $error) {
            fwrite(STDERR, '- ' . $error . "\n");
        }
        return 1;
    }

    fwrite(STDOUT, "PASS: production preflight configuration validated.\n");
    return 0;
}

if (
    PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    exit(production_preflight_main($argv));
}
