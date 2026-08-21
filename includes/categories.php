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

/**
 * Deletes a category and handles reassigning of products.
 */
function categories_delete($conn, $id): bool
{
    $id = (int)$id;
    if ($id <= 0) {
        return false;
    }

    // Prevent deleting the default category
    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT name FROM Category WHERE id = ?");
        if (!$stmt) {
            error_log('Category deletion lookup prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('i', $id)) {
            error_log('Category deletion lookup bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category deletion lookup execute failed: ' . $stmt->error);
            return false;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Category deletion lookup result failed: ' . $stmt->error);
            return false;
        }
        $res = $result->fetch_assoc();
    } catch (Throwable $exception) {
        error_log('Category deletion lookup failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }

    if (!$res || $res['name'] === 'General') {
        return false;
    }

    $transaction_started = false;
    $delete_stmt = null;
    $general_stmt = null;
    $reassign_stmt = null;
    try {
        if (!$conn->begin_transaction()) {
            error_log('delete_category failed: unable to start transaction.');
            return false;
        }
        $transaction_started = true;

        $general_stmt = $conn->prepare("SELECT id FROM Category WHERE name = 'General' LIMIT 1");
        if (!$general_stmt) {
            throw new Exception('Failed to prepare default category lookup.');
        }
        if (!$general_stmt->execute()) {
            throw new Exception('Failed to load default category.');
        }
        $general_result = $general_stmt->get_result();
        if (!$general_result) {
            throw new Exception('Failed to read default category.');
        }
        $general_row = $general_result->fetch_assoc();
        if (!$general_row) {
            throw new Exception('Default category is missing.');
        }
        $gen_cat_id = (int)$general_row['id'];
        $general_stmt->close();
        $general_stmt = null;

        $delete_stmt = $conn->prepare("DELETE FROM Category WHERE id = ?");
        if (!$delete_stmt) {
            throw new Exception('Failed to prepare category deletion.');
        }
        if (!$delete_stmt->bind_param('i', $id)) {
            throw new Exception('Failed to bind category deletion.');
        }
        if (!$delete_stmt->execute()) {
            throw new Exception('Failed to delete category.');
        }
        if ($delete_stmt->affected_rows !== 1) {
            throw new Exception('Category deletion affected an unexpected number of rows.');
        }
        $delete_stmt->close();
        $delete_stmt = null;

        $reassign_stmt = $conn->prepare(
            "UPDATE Product SET category_id = ? WHERE category_id IS NULL"
        );
        if (!$reassign_stmt) {
            throw new Exception('Failed to prepare product reassignment.');
        }
        if (!$reassign_stmt->bind_param('i', $gen_cat_id)) {
            throw new Exception('Failed to bind product reassignment.');
        }
        if (!$reassign_stmt->execute()) {
            throw new Exception('Failed to reassign products.');
        }
        if ($reassign_stmt->affected_rows < 0) {
            throw new Exception('Product reassignment affected-row check failed.');
        }
        $reassign_stmt->close();
        $reassign_stmt = null;

        if (!$conn->commit()) {
            throw new Exception('Failed to commit category deletion.');
        }
        $transaction_started = false;
        return true;
    } catch (Throwable $e) {
        foreach ([$general_stmt, $delete_stmt, $reassign_stmt] as $open_stmt) {
            if ($open_stmt instanceof mysqli_stmt) {
                $open_stmt->close();
            }
        }
        if ($transaction_started) {
            try {
                if (!$conn->rollback()) {
                    error_log('delete_category rollback failed: ' . $conn->error);
                }
            } catch (Throwable $rollback_exception) {
                error_log('delete_category rollback failed: ' . $rollback_exception->getMessage());
            }
        }
        error_log('delete_category failed: ' . $e->getMessage());
        return false;
    }
}
