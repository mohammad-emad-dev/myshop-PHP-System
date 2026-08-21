<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protects the Phase 4G removal gate. The first assertion is intentionally RED
 * until the manifest-approved generated artifact is deleted.
 */
function run_dead_code_retirement_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $manifestPath = $repository . '/docs/architecture/PHASE-4G-DEAD-CODE-RETIREMENT-TDD.md';
    $manifest = file_get_contents($manifestPath);
    $facadePath = $repository . '/includes/functions.php';
    $facade = file_get_contents($facadePath);

    $tests->assertTrue(is_string($manifest), 'Phase 4G removal manifest could not be read.');
    $tests->assertTrue(is_string($facade), 'Compatibility facade could not be read.');
    foreach ([
        'Safe to remove now',
        'Requires internal caller migration first',
        'Must remain as compatibility wrappers',
        'Security/auth boundary and must remain',
        'Uncertain external compatibility risk',
        'Not actually dead',
        '`docs/preview.png`',
        'No JavaScript or CSS deletion is authorized',
    ] as $manifestContract) {
        $tests->assertContains($manifestContract, $manifest, 'Phase 4G manifest contract is incomplete: ' . $manifestContract);
    }

    $previewPath = $repository . '/docs/preview.png';
    $tests->assertFalse(
        is_file($previewPath),
        'Manifest-approved generated artifact still exists: docs/preview.png.'
    );

    $sourceRoots = [
        $repository . '/includes',
        $repository . '/public',
        $repository . '/scripts',
        $repository . '/config',
        $repository . '/database',
        $repository . '/docker',
        $repository . '/.github',
    ];
    $sourceReferenceCount = 0;
    foreach ($sourceRoots as $sourceRoot) {
        if (!is_dir($sourceRoot)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $sourceFile) {
            if (!$sourceFile->isFile()) {
                continue;
            }
            $content = @file_get_contents($sourceFile->getPathname());
            if ($content !== false && preg_match('/(?:docs[\\\\\/]preview\.png|preview\.png)/i', $content) === 1) {
                $sourceReferenceCount++;
            }
        }
    }
    $tests->assertSame(0, $sourceReferenceCount, 'Retirement target still has a source, runtime, CI, or configuration reference.');

    foreach ([
        'function login_rate_limit_check',
        'function login_rate_limit_record_failure',
        'function login_rate_limit_reset',
        'function get_staff_members',
        'function create_staff_member',
        'function update_staff_member',
        'function delete_staff_member',
        'function set_staff_active',
        'function create_product',
        'function create_order',
        'function create_category',
        'function create_customer',
        'function create_supplier',
    ] as $protectedFacadeFunction) {
        $tests->assertContains($protectedFacadeFunction, $facade, 'Protected facade function was removed unexpectedly: ' . $protectedFacadeFunction);
    }
    foreach ([
        'includes/functions.php',
        'includes/security.php',
        'includes/auth.php',
        'includes/backup.php',
        'includes/export.php',
        'public/health.php',
        'public/ready.php',
        'public/assets/js/script.js',
        'public/assets/js/sweetalert-csp.js',
        'public/assets/css/style.css',
        'screenshots/Dashboard.png',
        'screenshots/login.png',
        'screenshots/orders.png',
        'screenshots/Products.png',
    ] as $protectedPath) {
        $tests->assertTrue(is_file($repository . '/' . $protectedPath), 'Protected source or asset disappeared: ' . $protectedPath);
    }

    return $tests->assertions();
}
