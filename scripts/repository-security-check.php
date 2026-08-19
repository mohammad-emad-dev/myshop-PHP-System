<?php

declare(strict_types=1);

/**
 * Scan only files tracked by Git for high-confidence secrets and unsafe
 * repository configuration. Findings never include matched values.
 */
function repository_security_scan_files(array $paths, string $repositoryRoot): array
{
    $findings = [];
    $repositoryRoot = rtrim(str_replace('\\', '/', (string)(realpath($repositoryRoot) ?: $repositoryRoot)), '/');

    foreach ($paths as $path) {
        if (!is_string($path) || $path === '') {
            continue;
        }

        $fullPath = $path;
        if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $fullPath)) {
            $fullPath = $repositoryRoot . '/' . ltrim(str_replace('\\', '/', $fullPath), '/');
        }

        $relativePath = repository_security_relative_path($fullPath, $repositoryRoot);
        if (!is_file($fullPath) || !is_readable($fullPath)) {
            repository_security_add_finding($findings, $relativePath, 1, 'tracked file could not be read');
            continue;
        }

        $contents = file_get_contents($fullPath);
        if (!is_string($contents)) {
            repository_security_add_finding($findings, $relativePath, 1, 'tracked file could not be read');
            continue;
        }

        $isExample = repository_security_is_example_path($relativePath);
        repository_security_check_filename($relativePath, $isExample, $findings);

        // Avoid interpreting binary assets as text. The tracked-file list still
        // includes them, but secret/configuration rules do not apply to them.
        if (strpos($contents, "\0") !== false) {
            continue;
        }

        if (!$isExample && !repository_security_is_fixture_path($relativePath) && preg_match('/-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/i', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
            repository_security_add_finding(
                $findings,
                $relativePath,
                repository_security_line_number($contents, (int)$match[0][1]),
                'committed private key material'
            );
        }

        $lines = preg_split('/\R/', $contents);
        if (!is_array($lines)) {
            $lines = [$contents];
        }

        foreach ($lines as $index => $line) {
            if (!is_string($line) || $line === '' || $isExample || repository_security_is_fixture_path($relativePath)) {
                continue;
            }

            // Shell/YAML parameter expansion is an environment indirection;
            // do not match the variable name inside ${VARIABLE:?message}.
            if (strpos($line, '${') !== false) {
                continue;
            }

            if (preg_match('/\b(?:ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk_(?:live|test)_[A-Za-z0-9]{16,}|xox[baprs]-[A-Za-z0-9-]{20,}|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_-]{20,}|npm_[A-Za-z0-9]{20,})\b/', $line) === 1) {
                repository_security_add_finding($findings, $relativePath, $index + 1, 'high-confidence secret token');
                continue;
            }

            if (preg_match(
                '/\b(?:[A-Za-z_][A-Za-z0-9_-]*_)?(?:password|passwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret)\b\s*[:=]\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s#;,]+))/i',
                $line,
                $matches
            ) !== 1) {
                continue;
            }

            $value = '';
            foreach ([1, 2, 3] as $matchIndex) {
                if (isset($matches[$matchIndex]) && $matches[$matchIndex] !== '') {
                    $value = trim((string)$matches[$matchIndex]);
                    break;
                }
            }

            if (repository_security_is_likely_placeholder($value)) {
                continue;
            }

            if (strlen($value) >= 20) {
                repository_security_add_finding($findings, $relativePath, $index + 1, 'likely secret assignment');
            }
        }

        repository_security_check_configuration($relativePath, $contents, $findings);
    }

    usort($findings, static function (array $left, array $right): int {
        return [$left['path'], $left['line'], $left['reason']] <=> [$right['path'], $right['line'], $right['reason']];
    });

    return $findings;
}

function repository_security_scan_repository(string $repositoryRoot): array
{
    $repositoryRoot = realpath($repositoryRoot) ?: $repositoryRoot;
    $trackedFiles = repository_security_tracked_files($repositoryRoot);
    $paths = array_map(
        static fn(string $path): string => $repositoryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path),
        $trackedFiles
    );

    return repository_security_scan_files($paths, $repositoryRoot);
}

function repository_security_tracked_files(string $repositoryRoot): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(['git', '-C', $repositoryRoot, 'ls-files', '-z'], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Git tracked-file enumeration could not start.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_string($output)) {
        throw new RuntimeException('Git tracked-file enumeration failed.');
    }

    $files = array_values(array_filter(explode("\0", $output), static fn(string $file): bool => $file !== ''));
    sort($files, SORT_STRING);
    return $files;
}

function repository_security_relative_path(string $path, string $repositoryRoot): string
{
    $normalizedPath = str_replace('\\', '/', (string)(realpath($path) ?: $path));
    $normalizedRoot = rtrim(str_replace('\\', '/', (string)(realpath($repositoryRoot) ?: $repositoryRoot)), '/');
    if (str_starts_with(strtolower($normalizedPath), strtolower($normalizedRoot . '/'))) {
        return ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');
    }

    return basename($normalizedPath);
}

function repository_security_is_example_path(string $path): bool
{
    $normalizedPath = strtolower(str_replace('\\', '/', $path));
    $baseName = strtolower(basename($normalizedPath));

    return $baseName === '.env.example'
        || in_array($baseName, ['readme', 'readme.md', 'readme.txt', 'readme.rst'], true)
        || preg_match('/(^|\/)(docs?|documentation|examples?)(\/|$)/', $normalizedPath) === 1;
}

function repository_security_is_fixture_path(string $path): bool
{
    $normalizedPath = strtolower(str_replace('\\', '/', $path));
    return preg_match('/(^|\/)(tests?\/)?fixtures?(\/|$)/', $normalizedPath) === 1;
}

function repository_security_check_filename(string $path, bool $isExample, array &$findings): void
{
    $baseName = strtolower(basename(str_replace('\\', '/', $path)));
    if (!$isExample && !repository_security_is_fixture_path($path) && ($baseName === '.env' || (str_starts_with($baseName, '.env.') && $baseName !== '.env.example'))) {
        repository_security_add_finding($findings, $path, 1, 'committed environment file');
    }
}

function repository_security_check_configuration(string $path, string $contents, array &$findings): void
{
    $normalizedPath = strtolower(str_replace('\\', '/', $path));

    if ($normalizedPath === 'docker-compose.production.yml') {
        $parts = preg_split('/^  db:\s*$/m', $contents, 2);
        $appSection = is_array($parts) && isset($parts[0]) ? $parts[0] : '';
        $dbSection = is_array($parts) && isset($parts[1]) ? $parts[1] : '';

        if (preg_match('/^\s+MYSQL_ROOT_PASSWORD\s*:/m', $appSection) === 1) {
            repository_security_add_finding($findings, $path, 1, 'root database credential exposed to the application service');
        }
        if (preg_match('/^\s+ports\s*:/m', $dbSection) === 1) {
            repository_security_add_finding($findings, $path, 1, 'production database publishes a host port');
        }
    }

    if (str_starts_with($normalizedPath, '.github/workflows/')) {
        if (preg_match('/(?:curl|wget)[^\r\n]*\|\s*(?:bash|sh|zsh)\b/i', $contents) === 1) {
            repository_security_add_finding($findings, $path, 1, 'workflow executes an unpinned downloaded script');
        }
    }
}

function repository_security_is_likely_placeholder(string $value): bool
{
    $normalizedValue = strtolower(trim($value, " \t\r\n\"'"));
    if ($normalizedValue === '' || $normalizedValue === 'null' || $normalizedValue === 'false' || $normalizedValue === 'true') {
        return true;
    }

    if (str_starts_with($normalizedValue, '${') || str_starts_with($normalizedValue, '$${') || str_starts_with($normalizedValue, '$(')) {
        return true;
    }

    if (preg_match('/^(?:\$\(|\$\{?[A-Za-z_][A-Za-z0-9_]*.*\}?\}|\$[A-Za-z_][A-Za-z0-9_]*|getenv\(|env\(|process\.env\.|os\.environ|bin2hex\(|random_|openssl\s+rand|password_hash\(|hash\(|sprintf\()/', $normalizedValue) === 1) {
        return true;
    }

    return preg_match('/(?:replace[_-]?with|change[_-]?me|changeit|placeholder|redact|example|dummy|fake|fixture|test[_-]?only|ci[_-]?(?:only|placeholder)|your[_-]?|<[^>]+>|\*{3,})/', $normalizedValue) === 1;
}

function repository_security_line_number(string $contents, int $offset): int
{
    return substr_count(substr($contents, 0, $offset), "\n") + 1;
}

function repository_security_add_finding(array &$findings, string $path, int $line, string $reason): void
{
    $findings[] = [
        'path' => str_replace('\\', '/', $path),
        'line' => $line,
        'reason' => $reason,
    ];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    $repositoryRoot = $argv[1] ?? getcwd();

    try {
        $findings = repository_security_scan_repository($repositoryRoot);
    } catch (Throwable $exception) {
        fwrite(STDERR, "FAIL: repository security scan could not inspect tracked files.\n");
        exit(1);
    }

    if ($findings === []) {
        echo "PASS: repository security scan inspected tracked files without findings.\n";
        exit(0);
    }

    fwrite(STDERR, 'FAIL: repository security scan found ' . count($findings) . " issue(s).\n");
    foreach ($findings as $finding) {
        fwrite(STDERR, '- ' . $finding['path'] . ':' . $finding['line'] . ': ' . $finding['reason'] . "\n");
    }
    exit(1);
}
