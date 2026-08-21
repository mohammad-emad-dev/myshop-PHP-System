<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * Protects the focused stylesheet cleanup. These assertions intentionally run
 * RED while the confirmed-unused CSS targets still exist.
 */
function run_css_cleanup_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $stylesheetPath = $repository . '/public/assets/css/style.css';
    $stylesheet = file_get_contents($stylesheetPath);

    $tests->assertTrue(is_string($stylesheet), 'The shared stylesheet could not be read.');

    $legacyVariables = [
        '--main' . '-bg-color',
        '--main' . '-text-color',
        '--second' . '-text-color',
        '--second' . '-bg-color',
    ];
    foreach ($legacyVariables as $legacyVariable) {
        $tests->assertFalse(
            preg_match('/^\s*' . preg_quote($legacyVariable, '/') . '\s*:/m', $stylesheet) === 1,
            'Legacy root variable remains in style.css: ' . $legacyVariable
        );
    }

    $unusedSelectors = [
        '.badge-' . 'sale',
        '.badge-' . 'purchase',
        '.cart-' . 'row-hidden',
    ];
    foreach ($unusedSelectors as $unusedSelector) {
        $tests->assertFalse(
            preg_match('/^\s*' . preg_quote($unusedSelector, '/') . '\s*\{/m', $stylesheet) === 1,
            'Confirmed-unused selector remains in style.css: ' . $unusedSelector
        );
    }

    foreach ([
        ':root',
        'body',
        '.modal',
        '.modal-body',
        '.table-row-hidden',
        '.cart-clear-visible',
        '.cart-count-badge',
        '--primary:',
        '--primary-bg-subtle:',
        '--success-bg-subtle:',
    ] as $requiredSelector) {
        $tests->assertContains($requiredSelector, $stylesheet, 'Required shared CSS contract disappeared: ' . $requiredSelector);
    }

    $consumerRoots = [
        $repository . '/includes',
        $repository . '/public',
        $repository . '/scripts',
        $repository . '/config',
        $repository . '/database',
        $repository . '/docker',
        $repository . '/.github',
        $repository . '/e2e',
        $repository . '/README.md',
        $repository . '/Dockerfile',
        $repository . '/docker-compose.yml',
        $repository . '/docker-compose.production.yml',
    ];
    $targetNames = array_merge($legacyVariables, $unusedSelectors);
    $consumerReferences = [];
    foreach ($consumerRoots as $consumerRoot) {
        if (is_file($consumerRoot)) {
            $files = [$consumerRoot];
        } elseif (is_dir($consumerRoot)) {
            $files = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumerRoot, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $sourceFile) {
                if ($sourceFile->isFile()) {
                    $files[] = $sourceFile->getPathname();
                }
            }
        } else {
            continue;
        }
        foreach ($files as $sourceFile) {
            if (str_replace('\\', '/', $sourceFile) === str_replace('\\', '/', $stylesheetPath)) {
                continue;
            }
            $content = @file_get_contents($sourceFile);
            if ($content === false) {
                continue;
            }
            foreach ($targetNames as $targetName) {
                if (strpos($content, $targetName) !== false) {
                    $consumerReferences[] = $sourceFile . ' -> ' . $targetName;
                }
            }
        }
    }
    $tests->assertSame([], $consumerReferences, 'Removed CSS targets still have consumer references: ' . implode(', ', $consumerReferences));

    $testReferences = [];
    $testRoot = $repository . '/tests';
    $testIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($testIterator as $testFile) {
        if (!$testFile->isFile() || str_replace('\\', '/', $testFile->getPathname()) === str_replace('\\', '/', __FILE__)) {
            continue;
        }
        $content = @file_get_contents($testFile->getPathname());
        if ($content === false) {
            continue;
        }
        foreach ($targetNames as $targetName) {
            if (strpos($content, $targetName) !== false) {
                $testReferences[] = $testFile->getPathname() . ' -> ' . $targetName;
            }
        }
    }
    $tests->assertSame([], $testReferences, 'Removed CSS targets still have unrelated test references: ' . implode(', ', $testReferences));

    return $tests->assertions();
}
