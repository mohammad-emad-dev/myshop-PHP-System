<?php

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../includes/functions.php';

final class TestFailure extends RuntimeException
{
}

final class TestContext
{
    private int $assertionCount = 0;

    public function assertTrue(bool $condition, string $message): void
    {
        $this->assertionCount++;
        if (!$condition) {
            throw new TestFailure($message);
        }
    }

    public function assertFalse(bool $condition, string $message): void
    {
        $this->assertTrue(!$condition, $message);
    }

    public function assertSame($expected, $actual, string $message): void
    {
        $this->assertionCount++;
        if ($expected !== $actual) {
            throw new TestFailure($message);
        }
    }

    public function assertNotSame($unexpected, $actual, string $message): void
    {
        $this->assertionCount++;
        if ($unexpected === $actual) {
            throw new TestFailure($message);
        }
    }

    public function assertContains(string $needle, string $haystack, string $message): void
    {
        $this->assertTrue(strpos($haystack, $needle) !== false, $message);
    }

    public function assertCount(int $expected, array $actual, string $message): void
    {
        $this->assertSame($expected, count($actual), $message);
    }

    public function assertions(): int
    {
        return $this->assertionCount;
    }
}

function test_sql_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new TestFailure('Unsafe test SQL identifier generated.');
    }

    return '`' . $identifier . '`';
}

function test_sql_string(mysqli $conn, string $value): string
{
    return "'" . $conn->real_escape_string($value) . "'";
}

function test_bind(mysqli_stmt $statement, string $types, array &$parameters): void
{
    $references = [$types];
    foreach ($parameters as &$parameter) {
        $references[] = &$parameter;
    }

    if (!call_user_func_array([$statement, 'bind_param'], $references)) {
        throw new TestFailure('Test prepared-statement binding failed.');
    }
}

function test_execute(mysqli $conn, string $sql, string $types = '', array $parameters = []): void
{
    $statement = $conn->prepare($sql);
    try {
        if ($types !== '') {
            test_bind($statement, $types, $parameters);
        }
        $statement->execute();
    } finally {
        $statement->close();
    }
}

function test_fetch_one(mysqli $conn, string $sql, string $types = '', array $parameters = []): ?array
{
    $statement = $conn->prepare($sql);
    try {
        if ($types !== '') {
            test_bind($statement, $types, $parameters);
        }
        $statement->execute();
        $result = $statement->get_result();
        $row = $result->fetch_assoc() ?: null;
        $result->free();
        return $row;
    } finally {
        $statement->close();
    }
}

function test_fetch_all(mysqli $conn, string $sql, string $types = '', array $parameters = []): array
{
    $statement = $conn->prepare($sql);
    try {
        if ($types !== '') {
            test_bind($statement, $types, $parameters);
        }
        $statement->execute();
        $result = $statement->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    } finally {
        $statement->close();
    }
}

function test_scalar(mysqli $conn, string $sql, string $types = '', array $parameters = [])
{
    $row = test_fetch_one($conn, $sql, $types, $parameters);
    return $row === null ? null : array_values($row)[0];
}

function test_load_sql_file(mysqli $conn, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new TestFailure('Unable to read SQL fixture: ' . basename($path));
    }

    $delimiter = ';';
    $buffer = '';
    $lines = preg_split('/\R/', $sql);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $matches)) {
            $delimiter = trim($matches[1]);
            continue;
        }

        $buffer .= $line . "\n";
        $ends_statement = $delimiter === ';'
            ? preg_match('/;\s*$/', $line) === 1
            : preg_match('/' . preg_quote($delimiter, '/') . '\s*$/', $line) === 1;

        if (!$ends_statement) {
            continue;
        }

        $statement = trim($buffer);
        $statement = substr($statement, 0, -strlen($delimiter));
        $statement = trim($statement);
        $buffer = '';

        if ($statement !== '') {
            $conn->query($statement);
        }
    }

    if (trim($buffer) !== '') {
        $conn->query(trim($buffer));
    }
}

final class DisposableDatabase
{
    public string $databaseName;
    public string $runtimeUsername;
    public string $runtimePassword;
    public mysqli $runtime;

    private ?mysqli $schemaConnection = null;
    private string $host;
    private int $port;
    private string $schemaUsername;
    private string $schemaPassword;
    private bool $databaseCreated = false;
    private bool $runtimeUserCreated = false;
    private array $originalRuntimeEnvironment = [];

    public function __construct()
    {
        $this->host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = filter_var(getenv('TEST_DB_PORT') ?: '3307', FILTER_VALIDATE_INT);
        if ($port === false || $port < 1 || $port > 65535) {
            throw new TestFailure('TEST_DB_PORT must be a valid TCP port.');
        }
        $this->port = $port;
        $this->schemaUsername = getenv('TEST_DB_ROOT_USER') ?: 'root';
        $password = getenv('TEST_DB_ROOT_PASSWORD');
        if ($password === false) {
            throw new TestFailure('TEST_DB_ROOT_PASSWORD is required; no database test will run without it.');
        }
        $this->schemaPassword = $password;

        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $environmentKey) {
            $this->originalRuntimeEnvironment[$environmentKey] = getenv($environmentKey);
        }

        $suffix = gmdate('YmdHis') . '_' . bin2hex(random_bytes(5));
        $this->databaseName = 'myshop_test_' . $suffix;
        $this->runtimeUsername = 'myshop_test_rt_' . bin2hex(random_bytes(5));
        $this->runtimePassword = bin2hex(random_bytes(24));

        $normalDatabase = getenv('DB_NAME');
        if ($normalDatabase !== false && strcasecmp($this->databaseName, $normalDatabase) === 0) {
            throw new TestFailure('The disposable database name collided with DB_NAME.');
        }
        if (strcasecmp($this->databaseName, 'ioms_db') === 0) {
            throw new TestFailure('The disposable database guard rejected the normal database name.');
        }
    }

    public function setup(): void
    {
        $this->schemaConnection = new mysqli(
            $this->host,
            $this->schemaUsername,
            $this->schemaPassword,
            '',
            $this->port
        );
        $this->schemaConnection->set_charset('utf8mb4');
        $this->schemaConnection->query('CREATE DATABASE ' . test_sql_identifier($this->databaseName) .
            ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->databaseCreated = true;
        $this->schemaConnection->select_db($this->databaseName);

        $rootUser = test_sql_string($this->schemaConnection, $this->runtimeUsername);
        $this->schemaConnection->query(
            'CREATE USER ' . $rootUser . "@'%' IDENTIFIED BY " .
            test_sql_string($this->schemaConnection, $this->runtimePassword)
        );
        $this->runtimeUserCreated = true;

        $databaseDirectory = dirname(__DIR__);
        test_load_sql_file($this->schemaConnection, $databaseDirectory . '/database/schema.sql');
        test_load_sql_file($this->schemaConnection, $databaseDirectory . '/database/batch2_staff_active.sql');
        test_load_sql_file($this->schemaConnection, $databaseDirectory . '/database/batch3_product_history.sql');

        $this->schemaConnection->query(
            'SET @myshop_runtime_user = ' . test_sql_string($this->schemaConnection, $this->runtimeUsername) .
            ", @myshop_runtime_host = '%'"
        );
        test_load_sql_file($this->schemaConnection, $databaseDirectory . '/database/batch14_runtime_privileges.sql');
        test_load_sql_file($this->schemaConnection, $databaseDirectory . '/database/batch17_login_rate_limit.sql');
        test_load_sql_file($this->schemaConnection, $databaseDirectory . '/database/batch22_audit_log.sql');

        $this->runtime = new mysqli(
            $this->host,
            $this->runtimeUsername,
            $this->runtimePassword,
            $this->databaseName,
            $this->port
        );
        $this->runtime->set_charset('utf8mb4');

        putenv('DB_HOST=' . $this->host);
        putenv('DB_PORT=' . $this->port);
        putenv('DB_NAME=' . $this->databaseName);
        putenv('DB_USER=' . $this->runtimeUsername);
        putenv('DB_PASSWORD=' . $this->runtimePassword);
    }

    public function schema(): mysqli
    {
        if (!$this->schemaConnection instanceof mysqli) {
            throw new TestFailure('Schema connection is not available.');
        }

        return $this->schemaConnection;
    }

    public function hostForTests(): string
    {
        return $this->host;
    }

    public function portForTests(): int
    {
        return $this->port;
    }

    private function restoreRuntimeEnvironment(): void
    {
        foreach ($this->originalRuntimeEnvironment as $environmentKey => $value) {
            if ($value === false) {
                putenv($environmentKey);
            } else {
                putenv($environmentKey . '=' . $value);
            }
        }
    }

    public function cleanup(): void
    {
        $errors = [];
        try {
            if (isset($this->runtime) && $this->runtime instanceof mysqli) {
                try {
                    $this->runtime->close();
                } catch (Throwable $exception) {
                    $errors[] = 'runtime connection';
                }
            }

            if ($this->schemaConnection instanceof mysqli) {
                try {
                    if ($this->databaseCreated) {
                        $this->schemaConnection->query('DROP DATABASE ' . test_sql_identifier($this->databaseName));
                    }
                } catch (Throwable $exception) {
                    $errors[] = 'database ' . $this->databaseName;
                }

                try {
                    if ($this->runtimeUserCreated) {
                        $user = test_sql_string($this->schemaConnection, $this->runtimeUsername);
                        $this->schemaConnection->query('DROP USER IF EXISTS ' . $user . "@'%'");
                    }
                } catch (Throwable $exception) {
                    $errors[] = 'runtime user ' . $this->runtimeUsername;
                }

                try {
                    $this->schemaConnection->close();
                } catch (Throwable $exception) {
                    $errors[] = 'schema connection';
                }
            }
        } finally {
            $this->restoreRuntimeEnvironment();
        }

        if ($errors !== []) {
            throw new TestFailure('Cleanup failed for: ' . implode(', ', $errors));
        }
    }
}

function test_http_server_environment_keys(): array
{
    return [
        'APP_ENV',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'TRUSTED_PROXY_IPS',
        'HSTS_ENABLED',
        'HSTS_MAX_AGE',
    ];
}

function test_http_server_environment(array $environment_overrides = []): array
{
    $environment = [];
    foreach (test_http_server_environment_keys() as $environmentKey) {
        $value = getenv($environmentKey);
        if ($value !== false) {
            $environment[$environmentKey] = $value;
        }
    }

    foreach ($environment_overrides as $environmentKey => $value) {
        if (!in_array($environmentKey, test_http_server_environment_keys(), true)) {
            continue;
        }
        if ($value === null) {
            unset($environment[$environmentKey]);
        } else {
            $environment[$environmentKey] = (string)$value;
        }
    }

    return $environment;
}

function test_tcp_port_is_available(int $port): bool
{
    if ($port < 1 || $port > 65535) {
        return false;
    }

    $errorNumber = 0;
    $errorMessage = '';
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2);
    if (is_resource($socket)) {
        fclose($socket);
        return false;
    }

    return true;
}

function test_local_server_is_running($process): bool
{
    if (!is_resource($process)) {
        return false;
    }

    $status = proc_get_status($process);
    return is_array($status) && ($status['running'] ?? false) === true;
}

function test_server_diagnostics(array $paths): string
{
    $diagnosticLines = [];
    foreach ($paths as $path) {
        if (!is_string($path) || !is_file($path)) {
            continue;
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            continue;
        }

        foreach (preg_split('/\R/', $contents) as $line) {
            if (preg_match('/failed to listen|address already in use|could not bind|parse error|fatal error/i', $line) !== 1) {
                continue;
            }
            $line = preg_replace('/(?i)(password|secret|token|cookie|authorization)[^\r\n]*/', '$1=[redacted]', $line);
            $diagnosticLines[] = substr((string)$line, 0, 240);
        }
    }

    if ($diagnosticLines === []) {
        return 'no safe startup diagnostic was captured';
    }

    return substr(implode(' | ', $diagnosticLines), 0, 800);
}

function test_stop_local_server(array $server): void
{
    $process = $server[0] ?? null;
    if (is_resource($process)) {
        if (test_local_server_is_running($process)) {
            @proc_terminate($process);
            $deadline = microtime(true) + 2.0;
            while (test_local_server_is_running($process) && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (test_local_server_is_running($process)) {
                @proc_terminate($process, 9);
                $forceDeadline = microtime(true) + 1.0;
                while (test_local_server_is_running($process) && microtime(true) < $forceDeadline) {
                    usleep(10000);
                }
            }
        }
        @proc_close($process);
    }

    foreach ([2, 3] as $pathIndex) {
        $path = $server[$pathIndex] ?? null;
        if (is_string($path) && $path !== '') {
            @unlink($path);
        }
    }
}

function test_start_local_server(array $environment_overrides = [], ?int $preferred_port = null): array
{
    $firstPort = $preferred_port;
    if ($firstPort !== null && ($firstPort < 1024 || $firstPort > 65535)) {
        throw new TestFailure('The preferred temporary HTTP port is invalid.');
    }

    $environment = test_http_server_environment($environment_overrides);
    $documentRoot = dirname(__DIR__) . '/public';
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $triedPorts = [];
    $lastDiagnostic = 'no startup attempt was made';

    for ($attempt = 0; $attempt < 8; $attempt++) {
        if ($attempt === 0 && $firstPort !== null) {
            $port = $firstPort;
        } else {
            $port = null;
            $candidateStart = random_int(0, 999);
            for ($candidateOffset = 0; $candidateOffset < 1000; $candidateOffset++) {
                $candidatePort = 18000 + (($candidateStart + $candidateOffset) % 1000);
                if (!isset($triedPorts[$candidatePort])) {
                    $port = $candidatePort;
                    break;
                }
            }
            if ($port === null) {
                break;
            }
        }
        $triedPorts[$port] = true;

        if (!test_tcp_port_is_available($port)) {
            $lastDiagnostic = 'port ' . $port . ' is already occupied';
            continue;
        }

        $stdoutPath = tempnam(sys_get_temp_dir(), 'myshop_http_out_');
        $stderrPath = tempnam(sys_get_temp_dir(), 'myshop_http_err_');
        if ($stdoutPath === false || $stderrPath === false) {
            if (is_string($stdoutPath)) {
                @unlink($stdoutPath);
            }
            if (is_string($stderrPath)) {
                @unlink($stderrPath);
            }
            throw new TestFailure('Unable to create temporary HTTP server diagnostics files.');
        }

        $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' .
            escapeshellarg($documentRoot);
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $stdoutPath, 'a'],
            2 => ['file', $stderrPath, 'a'],
        ];
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $environment);
        if (!is_resource($process)) {
            @unlink($stdoutPath);
            @unlink($stderrPath);
            $lastDiagnostic = 'the PHP process could not be created on port ' . $port;
            continue;
        }

        $server = [$process, $port, $stdoutPath, $stderrPath];
        $ready = false;
        try {
            for ($readyAttempt = 0; $readyAttempt < 40; $readyAttempt++) {
                if (!test_local_server_is_running($process)) {
                    break;
                }

                try {
                    [$status, $body] = test_http_get($port, '/health.php');
                    if (
                        $status === 200
                        && strpos($body, '"status":"ok"') !== false
                        && strpos($body, '"check":"liveness"') !== false
                    ) {
                        $ready = true;
                        break;
                    }
                } catch (TestFailure $exception) {
                    // The listener may still be starting; the bounded loop below is the retry window.
                }
                usleep(100000);
            }
        } catch (Throwable $exception) {
            test_stop_local_server($server);
            throw $exception;
        }

        if ($ready) {
            return $server;
        }

        $lastDiagnostic = 'temporary server on port ' . $port . ': ' . test_server_diagnostics([$stderrPath, $stdoutPath]);
        test_stop_local_server($server);
    }

    throw new TestFailure('Temporary PHP HTTP server did not become ready after 8 bounded attempts: ' . $lastDiagnostic);
}

function test_parse_http_status(array $headers): ?int
{
    $status = null;
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
            $status = (int)$matches[1];
        }
    }

    return $status;
}

function test_http_request(int $port, string $method, string $path, array $parameters = []): array
{
    unset($http_response_header);
    $httpOptions = [
        'method' => $method,
        'ignore_errors' => true,
        'timeout' => 5,
        'follow_location' => 0,
        'max_redirects' => 0,
    ];
    if ($method === 'POST') {
        $httpOptions['header'] = "Content-Type: application/x-www-form-urlencoded\r\n";
        $httpOptions['content'] = http_build_query($parameters);
    }

    $context = stream_context_create(['http' => $httpOptions]);
    $response = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $headers = $http_response_header ?? [];
    if ($response === false && $headers === []) {
        throw new TestFailure('Temporary PHP HTTP ' . $method . ' request failed for the expected test endpoint.');
    }

    $status = test_parse_http_status($headers);
    if ($status === null) {
        throw new TestFailure('Temporary PHP HTTP ' . $method . ' response status was unavailable.');
    }

    return [$status, (string)$response, $headers];
}

function test_http_get(int $port, string $path): array
{
    return test_http_request($port, 'GET', $path);
}

function test_http_post(int $port, string $path, array $parameters): int
{
    [$status] = test_http_request($port, 'POST', $path, $parameters);
    return $status;
}
