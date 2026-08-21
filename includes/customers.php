<?php

declare(strict_types=1);

require_once __DIR__ . '/validation.php';

/**
 * Creates a new customer record securely.
 */
function customers_create($conn, $name, $phone, $email, $address): bool
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
        $stmt = $conn->prepare("INSERT INTO Customer (name, phone, email, address) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            error_log('Customer insert prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('ssss', $name, $phone, $email, $address)) {
            error_log('Customer insert bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Customer insert execute failed: ' . $stmt->error);
            return false;
        }
        if ($stmt->affected_rows !== 1) {
            error_log('Customer insert affected an unexpected number of rows.');
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Customer creation failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Updates customer details, preventing changes to default Walk-in Customer (ID = 1).
 */
function customers_update($conn, $id, $name, $phone, $email, $address): bool
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
        $stmt = $conn->prepare("UPDATE Customer SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?");
        if (!$stmt) {
            error_log('Customer update prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('ssssi', $name, $phone, $email, $address, $id)) {
            error_log('Customer update bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Customer update execute failed: ' . $stmt->error);
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Customer update failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Deletes a customer, preventing deletion of default Walk-in Customer (ID = 1).
 */
function customers_delete($conn, $id): bool
{
    $id = (int)$id;
    if ($id <= 1) {
        return false;
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare("DELETE FROM Customer WHERE id = ?");
        if (!$stmt) {
            error_log('Customer deletion prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('i', $id)) {
            error_log('Customer deletion bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Customer deletion execute failed: ' . $stmt->error);
            return false;
        }
        if ($stmt->affected_rows !== 1) {
            error_log('Customer deletion affected an unexpected number of rows.');
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Customer deletion failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
