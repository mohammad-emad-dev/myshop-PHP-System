<?php

declare(strict_types=1);

/**
 * Validate and store one uploaded product image under public/uploads.
 */
function uploads_handle_image($file)
{
    $max_file_size = 5 * 1024 * 1024;
    $max_image_width = 4096;
    $max_image_height = 4096;
    $max_image_pixels = 16 * 1024 * 1024;
    $target_file = null;

    if (!is_array($file)) {
        return false;
    }

    $upload_error = $file['error'] ?? null;
    $temporary_file = $file['tmp_name'] ?? null;
    if (!is_int($upload_error) && !(is_string($upload_error) && ctype_digit($upload_error))) {
        return false;
    }
    $upload_error = (int)$upload_error;

    // Validate the server-reported upload result and reject malformed/non-uploaded files.
    if ($upload_error !== UPLOAD_ERR_OK || !is_string($temporary_file) || $temporary_file === '' || !is_uploaded_file($temporary_file)) {
        return false;
    }

    $actual_file_size = @filesize($temporary_file);
    if ($actual_file_size === false || $actual_file_size <= 0 || $actual_file_size > $max_file_size) {
        return false;
    }

    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif'
    ];

    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $temporary_file);
    } catch (Throwable $exception) {
        error_log('Image upload MIME inspection failed.');
        return false;
    }

    if (!is_string($mime_type) || !array_key_exists($mime_type, $allowed_mimes)) {
        return false;
    }

    // Validate image structure and impose dimensions/pixel-count limits before storing it.
    $image_info = @getimagesize($temporary_file);
    if (!is_array($image_info)) {
        return false;
    }

    $image_width = isset($image_info[0]) ? (int)$image_info[0] : 0;
    $image_height = isset($image_info[1]) ? (int)$image_info[1] : 0;
    $image_mime = isset($image_info['mime']) ? (string)$image_info['mime'] : '';
    if ($image_width <= 0 || $image_height <= 0 || $image_width > $max_image_width || $image_height > $max_image_height || ($image_width * $image_height) > $max_image_pixels || !array_key_exists($image_mime, $allowed_mimes)) {
        return false;
    }

    $public_directory = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public');
    if ($public_directory === false) {
        error_log('Image upload failed: public directory could not be resolved.');
        return false;
    }

    $upload_directory = $public_directory . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($upload_directory) && !mkdir($upload_directory, 0755, true) && !is_dir($upload_directory)) {
        error_log('Image upload failed: upload directory could not be created.');
        return false;
    }

    $resolved_upload_directory = realpath($upload_directory);
    if ($resolved_upload_directory === false || strtolower($resolved_upload_directory) !== strtolower($upload_directory)) {
        error_log('Image upload failed: upload directory path validation failed.');
        return false;
    }

    try {
        $extension = $allowed_mimes[$mime_type];
        do {
            $new_filename = bin2hex(random_bytes(16)) . '.' . $extension;
            $target_file = $resolved_upload_directory . DIRECTORY_SEPARATOR . $new_filename;
        } while (file_exists($target_file));
    } catch (Throwable $exception) {
        error_log('Image upload failed: secure filename generation failed.');
        return false;
    }

    if (!move_uploaded_file($temporary_file, $target_file)) {
        if (is_file($target_file)) {
            @unlink($target_file);
        }
        return false;
    }

    // Store the document-root-relative URL, never the absolute filesystem path.
    return 'uploads/' . $new_filename;
}

/**
 * Delete only an image created by uploads_handle_image() during the current request.
 * Invalid paths and paths outside the canonical public/uploads directory are ignored.
 */
function uploads_delete_newly_uploaded_image($relative_path)
{
    if (!is_string($relative_path) || preg_match('#\Auploads/[a-f0-9]{32}\.(?:jpe?g|png|gif)\z#D', $relative_path) !== 1) {
        return false;
    }

    $public_directory = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public');
    if ($public_directory === false) {
        return false;
    }

    $upload_directory = $public_directory . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($upload_directory)) {
        return true;
    }

    $resolved_upload_directory = realpath($upload_directory);
    if ($resolved_upload_directory === false || strtolower($resolved_upload_directory) !== strtolower($upload_directory)) {
        return false;
    }

    $filename = substr($relative_path, strlen('uploads/'));
    $resolved_target = realpath($resolved_upload_directory . DIRECTORY_SEPARATOR . $filename);
    if ($resolved_target === false) {
        return true;
    }

    if (strtolower(dirname($resolved_target)) !== strtolower($resolved_upload_directory) || !is_file($resolved_target)) {
        return false;
    }

    // The path has been validated and resolved inside public/uploads.
    return @unlink($resolved_target) || !file_exists($resolved_target);
}
