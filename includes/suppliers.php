<?php

declare(strict_types=1);

require_once __DIR__ . '/validation.php';

/**
 * Creates a new supplier record securely.
 */
function suppliers_create($conn, $name, $phone, $email, $address): bool
{
    $name = sanitize_input($name);
    $phone = sanitize_phone($phone);
    $email = sanitize_email($email);
    $address = sanitize_input($address);

    if (empty($name)) {
        return false;
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare("INSERT INTO Supplier (name, phone, email, address) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            error_log('Supplier insert prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('ssss', $name, $phone, $email, $address)) {
            error_log('Supplier insert bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Supplier insert execute failed: ' . $stmt->error);
            return false;
        }
        if ($stmt->affected_rows !== 1) {
            error_log('Supplier insert affected an unexpected number of rows.');
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Supplier creation failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Updates supplier details, preventing changes to default General Supplier (ID = 1).
 */
function suppliers_update($conn, $id, $name, $phone, $email, $address): bool
{
    $id = sanitize_id($id);
    $name = sanitize_input($name);
    $phone = sanitize_phone($phone);
    $email = sanitize_email($email);
    $address = sanitize_input($address);

    if ($id <= 1 || empty($name)) {
        return false;
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare("UPDATE Supplier SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?");
        if (!$stmt) {
            error_log('Supplier update prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('ssssi', $name, $phone, $email, $address, $id)) {
            error_log('Supplier update bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Supplier update execute failed: ' . $stmt->error);
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Supplier update failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Deletes a supplier, preventing deletion of default General Supplier (ID = 1).
 */
function suppliers_delete($conn, $id): bool
{
    $id = (int)$id;
    if ($id <= 1) {
        return false;
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare("DELETE FROM Supplier WHERE id = ?");
        if (!$stmt) {
            error_log('Supplier deletion prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('i', $id)) {
            error_log('Supplier deletion bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Supplier deletion execute failed: ' . $stmt->error);
            return false;
        }
        if ($stmt->affected_rows !== 1) {
            error_log('Supplier deletion affected an unexpected number of rows.');
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Supplier deletion failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
