<?php

declare(strict_types=1);

require_once __DIR__ . '/repository-security-check.php';

/**
 * Inspect one tracked file using a virtual repository-relative path. The
 * function intentionally does not inspect documentation, fixtures, or local
 * development Docker files for production image policy.
 */
function ci_supply_chain_scan_content(string $relativePath, string $contents): array
{
    $normalizedPath = strtolower(str_replace('\\', '/', ltrim($relativePath, '/')));
    $isWorkflow = str_starts_with($normalizedPath, '.github/workflows/');
    $isProductionCompose = $normalizedPath === 'docker-compose.production.yml';
    $findings = [];
    $lines = preg_split('/\R/', $contents) ?: [$contents];

    foreach ($lines as $index => $line) {
        if (!is_string($line)) {
            continue;
        }

        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if ($isWorkflow && preg_match('/^\s*(?:-\s*)?uses\s*:\s*([^\s#]+)(?:\s+#(.*))?\s*$/i', $line, $matches) === 1) {
            $actionReference = trim((string)$matches[1], " \t\r\n'\"");
            $comment = trim((string)($matches[2] ?? ''));

            if (str_starts_with($actionReference, './')) {
                continue;
            }

            if (preg_match('/^[^\/\s]+\/[^@\s]+@[0-9a-f]{40}$/', $actionReference) !== 1) {
                ci_supply_chain_add_finding($findings, $relativePath, $index + 1, 'third-party GitHub Action is not pinned to a full commit SHA');
            } elseif (preg_match('/\b(?:v)?\d+(?:\.\d+){0,2}\b/i', $comment) !== 1) {
                ci_supply_chain_add_finding($findings, $relativePath, $index + 1, 'pinned GitHub Action is missing an inline release comment');
            }
        }

        if ($isProductionCompose && preg_match('/^\s*image\s*:\s*(.+?)\s*$/i', $line, $matches) === 1) {
            $imageReference = trim((string)$matches[1], " \t\r\n'\"");
            $imageReference = preg_replace('/\s+#.*$/', '', $imageReference) ?? $imageReference;

            // Compose resolves these deployment-supplied values. The
            // production preflight validates their resolved digest values.
            if (str_contains($imageReference, '${')) {
                continue;
            }

            if (!ci_supply_chain_is_immutable_image_reference($imageReference)) {
                ci_supply_chain_add_finding($findings, $relativePath, $index + 1, 'production container image is not pinned to an immutable digest');
            }
        }

        if ($isWorkflow && preg_match('/\b(?:PRODUCTION_APP_IMAGE|PRODUCTION_MYSQL_IMAGE)\s*[:=]\s*([^\s#]+)/i', $line, $matches) === 1) {
            if (str_contains($line, 'app_digest') && str_contains($line, 'PRODUCTION_APP_IMAGE')) {
                // The CI job replaces its temporary tag with the locally
                // inspected image digest before invoking the release checks.
                continue;
            }

            $imageReference = trim((string)$matches[1], " \t\r\n'\"");
            if ((str_contains($line, 'GITHUB_SHA') && preg_match('/(^|:)ci[-_]/i', $imageReference) === 1)
                || str_contains($line, 'MYSQL_CI_IMAGE')
                || $imageReference === '%s'
                || (str_contains($line, 'app_digest') && str_contains($imageReference, '$'))) {
                // A CI-only build tag is allowed for the disposable image
                // build. It is converted to a digest before release use.
                continue;
            }

            if (!ci_supply_chain_is_immutable_image_reference($imageReference)) {
                ci_supply_chain_add_finding($findings, $relativePath, $index + 1, 'workflow production image reference is mutable outside the temporary CI exception');
            }
        }
    }

    usort($findings, static function (array $left, array $right): int {
        return [$left['path'], $left['line'], $left['reason']] <=> [$right['path'], $right['line'], $right['reason']];
    });

    return $findings;
}

function ci_supply_chain_is_immutable_image_reference(string $reference): bool
{
    return preg_match('/^[A-Za-z0-9._:\/-]+@sha256:[0-9a-f]{64}$/', $reference) === 1;
}

function ci_supply_chain_scan_repository(string $repositoryRoot): array
{
    $repositoryRoot = realpath($repositoryRoot) ?: $repositoryRoot;
    $trackedFiles = repository_security_tracked_files($repositoryRoot);
    $findings = [];

    foreach ($trackedFiles as $relativePath) {
        $normalizedPath = strtolower(str_replace('\\', '/', $relativePath));
        if (!str_starts_with($normalizedPath, '.github/workflows/') && $normalizedPath !== 'docker-compose.production.yml') {
            continue;
        }

        $fullPath = $repositoryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $contents = @file_get_contents($fullPath);
        if (!is_string($contents)) {
            throw new RuntimeException('CI supply-chain policy could not read a tracked policy file.');
        }

        $findings = array_merge($findings, ci_supply_chain_scan_content($relativePath, $contents));
    }

    usort($findings, static function (array $left, array $right): int {
        return [$left['path'], $left['line'], $left['reason']] <=> [$right['path'], $right['line'], $right['reason']];
    });

    return $findings;
}

function ci_supply_chain_add_finding(array &$findings, string $path, int $line, string $reason): void
{
    $findings[] = [
        'path' => str_replace('\\', '/', $path),
        'line' => $line,
        'reason' => $reason,
    ];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    try {
        $findings = ci_supply_chain_scan_repository(getcwd());
    } catch (Throwable $exception) {
        fwrite(STDERR, "FAIL: CI supply-chain policy could not inspect tracked workflow and production files.\n");
        exit(1);
    }

    if ($findings === []) {
        echo "PASS: CI supply-chain policy found only immutable workflow and production image references.\n";
        exit(0);
    }

    fwrite(STDERR, 'FAIL: CI supply-chain policy found ' . count($findings) . " issue(s).\n");
    foreach ($findings as $finding) {
        fwrite(STDERR, '- ' . $finding['path'] . ':' . $finding['line'] . ': ' . $finding['reason'] . "\n");
    }
    exit(1);
}
