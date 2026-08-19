<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/scripts/release-integrity-check.php';

function run_release_integrity_unit_tests(): int
{
    $tests = new TestContext();
    $metadata = [
        'commit_sha' => str_repeat('a', 40),
        'workflow' => 'Quality Gate',
        'ref' => 'refs/heads/main',
        'image_reference' => 'registry.example/myshop@sha256:' . str_repeat('b', 64),
        'verification_status' => 'verified',
    ];
    $migrations = ['batch22_audit_log.sql', 'batch2_staff_active.sql'];
    $assertThrows = static function (callable $callback, string $needle, string $message) use ($tests): void {
        try {
            $callback();
        } catch (Throwable $exception) {
            $tests->assertContains($needle, $exception->getMessage(), $message);
            return;
        }

        throw new TestFailure($message);
    };

    $manifest = release_integrity_build_manifest($metadata, 'batch22_audit_log', $migrations);
    $tests->assertSame(str_repeat('a', 40), $manifest['commit_sha'], 'Release evidence must record the commit identifier.');
    $tests->assertSame('registry.example/myshop@sha256:' . str_repeat('b', 64), $manifest['image_reference'], 'Release evidence must record the immutable image reference.');
    $tests->assertSame(['batch2_staff_active.sql', 'batch22_audit_log.sql'], $manifest['migrations'], 'Release evidence must retain the reviewed migration list in migration order.');
    $tests->assertSame(['commit_sha', 'workflow', 'ref', 'image_reference', 'schema_migration_version', 'migrations', 'verification_status'], array_keys($manifest), 'Release evidence must contain metadata only.');

    $mutable = $metadata;
    $mutable['image_reference'] = 'myshop-app:production';
    $assertThrows(static fn(): array => release_integrity_build_manifest($mutable, 'batch22_audit_log', $migrations), 'image_reference', 'Mutable release image references must be rejected.');

    $invalidCommit = $metadata;
    $invalidCommit['commit_sha'] = 'not-a-commit';
    $assertThrows(static fn(): array => release_integrity_build_manifest($invalidCommit, 'batch22_audit_log', $migrations), 'commit_sha', 'Invalid commit identifiers must be rejected.');

    $unverified = $metadata;
    $unverified['verification_status'] = 'unverified';
    $assertThrows(static fn(): array => release_integrity_build_manifest($unverified, 'batch22_audit_log', $migrations), 'verification_status', 'Unverified release evidence must not pass the release check.');

    $unsafeMigration = $migrations;
    $unsafeMigration[] = 'backup.sql';
    $assertThrows(static fn(): array => release_integrity_build_manifest($metadata, 'batch22_audit_log', $unsafeMigration), 'migrations', 'Unexpected migration filenames must be rejected.');

    $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);
    $tests->assertFalse(str_contains($encoded, 'password') || str_contains($encoded, 'secret') || str_contains($encoded, 'token'), 'Release evidence must not contain credential fields.');

    return $tests->assertions();
}
