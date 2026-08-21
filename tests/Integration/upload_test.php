<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function upload_test_start_server(string $modulePath): array
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myshop_upload_probe_' . bin2hex(random_bytes(6));
    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new TestFailure('Unable to create the temporary upload probe directory.');
    }

    $scriptPath = $root . DIRECTORY_SEPARATOR . 'upload_probe.php';
    $script = "<?php\n"
        . 'require_once ' . var_export($modulePath, true) . ";\n"
        . "header('Content-Type: application/json');\n"
        . "\$result = uploads_handle_image(\$_FILES['image'] ?? null);\n"
        . "echo json_encode(['result' => \$result]);\n";
    if (file_put_contents($scriptPath, $script) === false) {
        @rmdir($root);
        throw new TestFailure('Unable to create the temporary upload probe script.');
    }

    $stdoutPath = tempnam(sys_get_temp_dir(), 'myshop_upload_out_');
    $stderrPath = tempnam(sys_get_temp_dir(), 'myshop_upload_err_');
    if ($stdoutPath === false || $stderrPath === false) {
        if (is_string($stdoutPath)) {
            @unlink($stdoutPath);
        }
        if (is_string($stderrPath)) {
            @unlink($stderrPath);
        }
        @unlink($scriptPath);
        @rmdir($root);
        throw new TestFailure('Unable to create upload probe diagnostics files.');
    }

    $port = null;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = random_int(19000, 19999);
        if (test_tcp_port_is_available($candidate)) {
            $port = $candidate;
            break;
        }
    }
    if ($port === null) {
        @unlink($stdoutPath);
        @unlink($stderrPath);
        @unlink($scriptPath);
        @rmdir($root);
        throw new TestFailure('Unable to allocate a temporary upload probe port.');
    }

    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root);
    $descriptors = [
        0 => ['file', $nullDevice, 'r'],
        1 => ['file', $stdoutPath, 'a'],
        2 => ['file', $stderrPath, 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), test_http_server_environment());
    if (!is_resource($process)) {
        @unlink($stdoutPath);
        @unlink($stderrPath);
        @unlink($scriptPath);
        @rmdir($root);
        throw new TestFailure('Unable to start the temporary upload probe server.');
    }

    $server = [$process, $port, $root, $scriptPath, $stdoutPath, $stderrPath];
    for ($attempt = 0; $attempt < 40; $attempt++) {
        if (!test_local_server_is_running($process)) {
            break;
        }
        if (!test_tcp_port_is_available($port)) {
            return $server;
        }
        usleep(100000);
    }

    upload_test_stop_server($server);
    throw new TestFailure('Temporary upload probe server did not become ready.');
}

function upload_test_stop_server(array $server): void
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
            }
        }
        @proc_close($process);
    }

    foreach ([$server[3] ?? null, $server[4] ?? null, $server[5] ?? null] as $path) {
        if (is_string($path)) {
            @unlink($path);
        }
    }
    if (is_string($server[2] ?? null)) {
        @rmdir($server[2]);
    }
}

function upload_test_post(int $port, string $path, string $mime): array
{
    $handle = curl_init('http://127.0.0.1:' . $port . '/upload_probe.php');
    if ($handle === false) {
        throw new TestFailure('Unable to initialize the upload probe request.');
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['image' => curl_file_create($path, $mime, basename($path))],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    curl_close($handle);
    if ($body === false) {
        throw new TestFailure('Upload probe request failed: ' . $error);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        throw new TestFailure('Upload probe response was not valid JSON.');
    }

    return $payload;
}

function upload_test_create_png(string $path, int $width, int $height): void
{
    $image = imagecreatetruecolor($width, $height);
    if ($image === false || imagepng($image, $path) === false) {
        if ($image !== false) {
            imagedestroy($image);
        }
        throw new TestFailure('Unable to create a disposable PNG fixture.');
    }
    imagedestroy($image);
}

function run_upload_integration_tests(): int
{
    $tests = new TestContext();
    $repository = dirname(__DIR__, 2);
    $modulePath = $repository . '/includes/uploads.php';
    $server = null;
    $temporaryPaths = [];
    $existingRelative = 'uploads/' . str_repeat('a', 32) . '.png';
    $existingAbsolute = $repository . '/public/' . $existingRelative;
    $outsidePath = tempnam(sys_get_temp_dir(), 'myshop_upload_outside_');

    try {
        $server = upload_test_start_server($modulePath);

        $validPath = tempnam(sys_get_temp_dir(), 'myshop_upload_valid_');
        $invalidPath = tempnam(sys_get_temp_dir(), 'myshop_upload_invalid_');
        $oversizedPath = tempnam(sys_get_temp_dir(), 'myshop_upload_large_');
        $widePath = tempnam(sys_get_temp_dir(), 'myshop_upload_wide_');
        foreach ([$validPath, $invalidPath, $oversizedPath, $widePath] as $path) {
            if ($path === false) {
                throw new TestFailure('Unable to allocate disposable upload fixture.');
            }
            $temporaryPaths[] = $path;
        }

        upload_test_create_png($validPath, 2, 2);
        $validPayload = upload_test_post($server[1], $validPath, 'image/png');
        $uploadedRelative = $validPayload['result'] ?? false;
        $tests->assertTrue(
            is_string($uploadedRelative) && preg_match('#\\Auploads/[a-f0-9]{32}\\.png\\z#D', $uploadedRelative) === 1,
            'Valid image upload must return the canonical relative PNG path.'
        );
        if (is_string($uploadedRelative)) {
            $uploadedAbsolute = $repository . '/public/' . $uploadedRelative;
            $tests->assertTrue(is_file($uploadedAbsolute), 'Valid upload must be stored inside public/uploads.');
            $tests->assertTrue(uploads_delete_newly_uploaded_image($uploadedRelative), 'Newly uploaded image cleanup must succeed.');
            $tests->assertFalse(is_file($uploadedAbsolute), 'Newly uploaded image cleanup must remove the generated file.');
        }

        file_put_contents($invalidPath, 'not an image');
        $tests->assertSame(false, upload_test_post($server[1], $invalidPath, 'image/png')['result'] ?? null, 'Invalid MIME/content must be rejected.');

        $largeHandle = fopen($oversizedPath, 'wb');
        if ($largeHandle === false || ftruncate($largeHandle, 5 * 1024 * 1024 + 1) === false) {
            throw new TestFailure('Unable to create oversized upload fixture.');
        }
        fclose($largeHandle);
        $tests->assertSame(false, upload_test_post($server[1], $oversizedPath, 'image/png')['result'] ?? null, 'Oversized uploads must be rejected.');

        upload_test_create_png($widePath, 4097, 1);
        $tests->assertSame(false, upload_test_post($server[1], $widePath, 'image/png')['result'] ?? null, 'Images over the width limit must be rejected.');
        $tests->assertSame(false, uploads_handle_image(['error' => UPLOAD_ERR_NO_FILE]), 'Upload error values must return false.');

        file_put_contents($existingAbsolute, 'existing image placeholder');
        $temporaryPaths[] = $existingAbsolute;
        $missingRelative = 'uploads/' . str_repeat('b', 32) . '.png';
        $tests->assertTrue(uploads_delete_newly_uploaded_image($missingRelative), 'Missing generated upload cleanup must remain idempotent.');
        $tests->assertSame('existing image placeholder', file_get_contents($existingAbsolute), 'Unrelated existing uploads must be preserved.');
        $tests->assertFalse(uploads_delete_newly_uploaded_image('../outside.png'), 'Traversal upload paths must be rejected.');
        $tests->assertFalse(uploads_delete_newly_uploaded_image('/tmp/outside.png'), 'Absolute upload paths must be rejected.');
        $tests->assertFalse(uploads_delete_newly_uploaded_image('uploads/../outside.png'), 'Normalized traversal upload paths must be rejected.');

        if (is_string($outsidePath)) {
            file_put_contents($outsidePath, 'outside root');
            $symlinkRelative = 'uploads/' . str_repeat('c', 32) . '.png';
            $symlinkAbsolute = $repository . '/public/' . $symlinkRelative;
            if (function_exists('symlink') && @symlink($outsidePath, $symlinkAbsolute)) {
                $tests->assertFalse(uploads_delete_newly_uploaded_image($symlinkRelative), 'Symlinked outside-root uploads must be rejected.');
                $tests->assertSame('outside root', file_get_contents($outsidePath), 'Outside-root files must never be deleted.');
                @unlink($symlinkAbsolute);
            }
        }
    } finally {
        if (is_array($server)) {
            upload_test_stop_server($server);
        }
        foreach ($temporaryPaths as $path) {
            if (is_string($path)) {
                @unlink($path);
            }
        }
        if (is_string($outsidePath)) {
            @unlink($outsidePath);
        }
    }

    return $tests->assertions();
}
