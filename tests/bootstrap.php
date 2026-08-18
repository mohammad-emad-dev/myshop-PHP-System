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

    public function cleanup(): void
    {
        $errors = [];
        if (isset($this->runtime) && $this->runtime instanceof mysqli) {
            $this->runtime->close();
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

            $this->schemaConnection->close();
        }

        if ($errors !== []) {
            throw new TestFailure('Cleanup failed for: ' . implode(', ', $errors));
        }
    }
}

function test_start_local_server(): array
{
    $port = random_int(18000, 18999);
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $command = PHP_BINARY . ' -S 127.0.0.1:' . $port . ' -t ' .
        escapeshellarg(dirname(__DIR__) . '/public');
    $descriptors = [
        0 => ['file', $nullDevice, 'r'],
        1 => ['file', $nullDevice, 'a'],
        2 => ['file', $nullDevice, 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), getenv());
    if (!is_resource($process)) {
        throw new TestFailure('Unable to start the temporary PHP HTTP server.');
    }

    $url = 'http://127.0.0.1:' . $port . '/login.php';
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        @file_get_contents($url, false, $context);
        if (isset($http_response_header[0])) {
            return [$process, $port];
        }
        usleep(100000);
    }

    proc_terminate($process);
    proc_close($process);
    throw new TestFailure('Temporary PHP HTTP server did not become ready.');
}

function test_http_post(int $port, string $path, array $parameters): int
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($parameters),
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);
    $response = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $headers = $http_response_header ?? [];
    if ($response === false && $headers === []) {
        throw new TestFailure('Temporary PHP HTTP request failed.');
    }

    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            return (int)$matches[1];
        }
    }

    throw new TestFailure('Temporary PHP HTTP response status was unavailable.');
}
