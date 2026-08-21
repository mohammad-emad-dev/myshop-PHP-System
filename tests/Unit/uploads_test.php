<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function run_upload_unit_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $module = is_file($repository . '/includes/uploads.php') ? file_get_contents($repository . '/includes/uploads.php') : null;
    $facade = file_get_contents($repository . '/includes/functions.php');
    $page = file_get_contents($repository . '/public/products.php');

    foreach ([$module, $facade, $page] as $fixture) {
        $tests->assertTrue(is_string($fixture), 'Upload source fixture could not be read.');
    }

    $tests->assertContains('declare(strict_types=1);', $module, 'Uploads module must use strict typing.');
    $tests->assertFalse(
        strpos($module, "require_once __DIR__ . '/functions.php'") !== false,
        'Uploads module must not require the compatibility facade.'
    );
    $tests->assertFalse(strpos($module, '$_SESSION') !== false, 'Uploads module must not read session state.');
    $tests->assertFalse(strpos($module, '$GLOBALS') !== false, 'Uploads module must not read global state.');

    foreach ([
        'function uploads_handle_image($file)',
        'function uploads_delete_newly_uploaded_image($relative_path)',
        'is_uploaded_file($temporary_file)',
        'new finfo(FILEINFO_MIME_TYPE)',
        'finfo_file($finfo, $temporary_file)',
        'getimagesize($temporary_file)',
        '5 * 1024 * 1024',
        '4096',
        '16 * 1024 * 1024',
        "realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public')",
        "return 'uploads/' . $new_filename;",
        "preg_match('#\\Auploads/[a-f0-9]{32}\\.(?:jpe?g|png|gif)\\z#D'",
        'move_uploaded_file($temporary_file, $target_file)',
        'return @unlink($resolved_target) || !file_exists($resolved_target);',
    ] as $contract) {
        $tests->assertContains($contract, $module, 'Uploads security contract is missing: ' . $contract);
    }

    foreach ([
        'handle_image_upload' => 'uploads_handle_image',
        'delete_newly_uploaded_image' => 'uploads_delete_newly_uploaded_image',
    ] as $legacyName => $focusedName) {
        $pattern = '/function ' . preg_quote($legacyName, '/') . '\\s*\\([^)]*\\)\\s*\\{(?<body>.*?)\\n\\}/s';
        $matched = preg_match($pattern, $facade, $matches) === 1;
        $tests->assertTrue($matched, 'Upload compatibility wrapper is missing: ' . $legacyName);
        if ($matched) {
            $tests->assertContains($focusedName . '(', $matches['body'], 'Upload wrapper does not delegate: ' . $legacyName);
            $tests->assertSame(1, substr_count($matches['body'], $focusedName . '('), 'Upload wrapper must delegate exactly once: ' . $legacyName);
            foreach (['finfo', 'getimagesize', 'move_uploaded_file', 'realpath', 'unlink'] as $implementationDetail) {
                $tests->assertFalse(
                    strpos($matches['body'], $implementationDetail) !== false,
                    'Upload wrapper still contains implementation detail: ' . $legacyName
                );
            }
        }
    }

    $tests->assertSame(2, substr_count($page, 'uploads_handle_image('), 'Products page must call the focused upload helper for create and update.');
    $tests->assertSame(2, substr_count($page, 'uploads_delete_newly_uploaded_image('), 'Products page must call focused cleanup for create and update failures.');
    foreach (['handle_image_upload', 'delete_newly_uploaded_image'] as $legacyName) {
        $tests->assertSame(
            0,
            preg_match('/\\b' . preg_quote($legacyName, '/') . '\\s*\\(/', $page),
            'Products page must not call the legacy upload helper: ' . $legacyName
        );
    }

    $csrfPosition = strpos($page, 'verify_csrf_token($csrf_token)');
    $authorizationPosition = strpos($page, 'auth_is_admin($conn)');
    $firstUploadPosition = strpos($page, 'uploads_handle_image(');
    $tests->assertTrue(
        $csrfPosition !== false && $firstUploadPosition !== false && $csrfPosition < $firstUploadPosition,
        'Products page must validate CSRF before upload handling.'
    );
    $tests->assertTrue(
        $authorizationPosition !== false && $firstUploadPosition !== false && $authorizationPosition < $firstUploadPosition,
        'Products page must authorize administrators before upload handling.'
    );
    $tests->assertContains('if (is_string($image_path))', $page, 'Products page must retain failed-mutation upload cleanup guards.');

    return $tests->assertions();
}
