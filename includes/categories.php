<?php

declare(strict_types=1);

/**
 * Creates a new product category.
 */
function categories_create($conn, $name, $description): bool
{
    $name = trim($name);
    $description = trim($description);

    if (empty($name)) {
        return false;
    }

    // Verify uniqueness
    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT id FROM Category WHERE name = ? LIMIT 1");
        if (!$stmt) {
            error_log('Category uniqueness prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('s', $name)) {
            error_log('Category uniqueness bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category uniqueness execute failed: ' . $stmt->error);
            return false;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Category uniqueness result failed: ' . $stmt->error);
            return false;
        }
        if ($result->num_rows > 0) {
            return false;
        }
        $stmt->close();
        $stmt = null;

        $stmt = $conn->prepare("INSERT INTO Category (name, description) VALUES (?, ?)");
        if (!$stmt) {
            error_log('Category insert prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('ss', $name, $description)) {
            error_log('Category insert bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category insert execute failed: ' . $stmt->error);
            return false;
        }
        if ($stmt->affected_rows !== 1) {
            error_log('Category insert affected an unexpected number of rows.');
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Category creation failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Updates an existing product category.
 */
function categories_update($conn, $id, $name, $description): bool
{
    $id = (int)$id;
    $name = trim($name);
    $description = trim($description);

    if ($id <= 0 || empty($name)) {
        return false;
    }

    // Check if another category has the same name
    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT id FROM Category WHERE name = ? AND id != ? LIMIT 1");
        if (!$stmt) {
            error_log('Category update uniqueness prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('si', $name, $id)) {
            error_log('Category update uniqueness bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category update uniqueness execute failed: ' . $stmt->error);
            return false;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Category update uniqueness result failed: ' . $stmt->error);
            return false;
        }
        if ($result->num_rows > 0) {
            return false;
        }
        $stmt->close();
        $stmt = null;

        // Check if category is 'General' and we are trying to rename it.
        $stmt = $conn->prepare("SELECT name FROM Category WHERE id = ?");
        if (!$stmt) {
            error_log('Category update lookup prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('i', $id)) {
            error_log('Category update lookup bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category update lookup execute failed: ' . $stmt->error);
            return false;
        }
        $old_name_result = $stmt->get_result();
        if (!$old_name_result) {
            error_log('Category update lookup result failed: ' . $stmt->error);
            return false;
        }
        $old_name_res = $old_name_result->fetch_assoc();
        $stmt->close();
        $stmt = null;

        if ($old_name_res && $old_name_res['name'] === 'General' && $name !== 'General') {
            return false;
        }

        $stmt = $conn->prepare("UPDATE Category SET name = ?, description = ? WHERE id = ?");
        if (!$stmt) {
            error_log('Category update prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('ssi', $name, $description, $id)) {
            error_log('Category update bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category update execute failed: ' . $stmt->error);
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Category update failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
