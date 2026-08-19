<?php

declare(strict_types=1);

require_once __DIR__ . '/production-preflight.php';

/**
 * Build safe release evidence. This manifest deliberately contains metadata
 * only; credentials, environment files, database contents, and backup data
 * never enter the returned structure.
 */
function release_integrity_build_manifest(array $metadata, string $schemaMigrationVersion, array $migrations): array
{
    $errors = [];
    $requiredMetadata = ['commit_sha', 'workflow', 'ref', 'image_reference', 'verification_status'];
    foreach ($requiredMetadata as $key) {
        if (!isset($metadata[$key]) || !is_string($metadata[$key]) || trim($metadata[$key]) === '') {
            $errors[] = $key . ' is required';
        }
    }

    $commitSha = strtolower(trim((string)($metadata['commit_sha'] ?? '')));
    if (preg_match('/^[0-9a-f]{40}$/', $commitSha) !== 1) {
        $errors[] = 'commit_sha must be a 40-character commit identifier';
    }

    $workflow = trim((string)($metadata['workflow'] ?? ''));
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._:-]{0,119}$/', $workflow) !== 1) {
        $errors[] = 'workflow contains unsupported characters';
    }

    $ref = trim((string)($metadata['ref'] ?? ''));
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,199}$/', $ref) !== 1) {
        $errors[] = 'ref contains unsupported characters';
    }

    $imageReference = trim((string)($metadata['image_reference'] ?? ''));
    if (!production_preflight_is_immutable_image_reference($imageReference)) {
        $errors[] = 'image_reference must use an immutable sha256 digest';
    }

    $verificationStatus = trim((string)($metadata['verification_status'] ?? ''));
    if ($verificationStatus !== 'verified') {
        $errors[] = 'verification_status must be verified';
    }

    if (preg_match('/^[A-Za-z0-9._-]{1,120}$/', $schemaMigrationVersion) !== 1) {
        $errors[] = 'schema_migration_version contains unsupported characters';
    }

    $safeMigrations = [];
    foreach ($migrations as $migration) {
        if (!is_string($migration) || preg_match('/^batch[0-9]+_[A-Za-z0-9_-]+\.sql$/', $migration) !== 1) {
            $errors[] = 'migrations contain an unsupported filename';
            continue;
        }
        $safeMigrations[] = $migration;
    }
    release_integrity_sort_migrations($safeMigrations);

    if ($errors !== []) {
        throw new InvalidArgumentException(implode('; ', $errors));
    }

    return [
        'commit_sha' => $commitSha,
        'workflow' => $workflow,
        'ref' => $ref,
        'image_reference' => $imageReference,
        'schema_migration_version' => $schemaMigrationVersion,
        'migrations' => $safeMigrations,
        'verification_status' => $verificationStatus,
    ];
}

function release_integrity_sort_migrations(array &$migrations): void
{
    usort($migrations, static function (string $left, string $right): int {
        preg_match('/^batch([0-9]+)/', $left, $leftMatch);
        preg_match('/^batch([0-9]+)/', $right, $rightMatch);
        $numberComparison = ((int)($leftMatch[1] ?? 0)) <=> ((int)($rightMatch[1] ?? 0));
        return $numberComparison !== 0 ? $numberComparison : strcmp($left, $right);
    });
}

function release_integrity_collect_migrations(string $repositoryRoot): array
{
    $migrationDirectory = rtrim($repositoryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'database';
    $paths = glob($migrationDirectory . DIRECTORY_SEPARATOR . 'batch*.sql') ?: [];
    $migrations = array_map(static fn(string $path): string => basename($path), $paths);
    release_integrity_sort_migrations($migrations);
    return $migrations;
}

function release_integrity_commit_from_environment(string $repositoryRoot): string
{
    $commitSha = trim((string)(getenv('RELEASE_COMMIT_SHA') ?: getenv('GITHUB_SHA') ?: ''));
    if ($commitSha !== '') {
        return $commitSha;
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['git', '-C', $repositoryRoot, 'rev-parse', 'HEAD'], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('A release commit identifier is required.');
    }
    $output = trim((string)stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || $output === '') {
        throw new RuntimeException('A release commit identifier is required.');
    }
    return $output;
}

function release_integrity_main(string $repositoryRoot): int
{
    $migrations = release_integrity_collect_migrations($repositoryRoot);
    $schemaMigrationVersion = $migrations === [] ? 'schema' : pathinfo($migrations[count($migrations) - 1], PATHINFO_FILENAME);
    $manifest = release_integrity_build_manifest([
        'commit_sha' => release_integrity_commit_from_environment($repositoryRoot),
        'workflow' => (string)(getenv('GITHUB_WORKFLOW') ?: getenv('RELEASE_WORKFLOW') ?: 'local-verification'),
        'ref' => (string)(getenv('GITHUB_REF') ?: getenv('RELEASE_REF') ?: 'local'),
        'image_reference' => (string)(getenv('RELEASE_IMAGE_REFERENCE') ?: ''),
        'verification_status' => (string)(getenv('RELEASE_VERIFICATION_STATUS') ?: 'unverified'),
    ], $schemaMigrationVersion, $migrations);

    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    echo $encoded . PHP_EOL;
    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    try {
        exit(release_integrity_main(realpath(dirname(__DIR__)) ?: dirname(__DIR__)));
    } catch (Throwable $exception) {
        fwrite(STDERR, "FAIL: safe release integrity evidence could not be generated.\n");
        exit(1);
    }
}
