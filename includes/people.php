<?php

declare(strict_types=1);

require_once __DIR__ . '/pagination.php';

/**
 * People read module.
 *
 * This module owns only bounded Customer and Supplier read operations.
 * Customer and Supplier writes are owned by dedicated mutation modules.
 */
function people_count_customers($conn, $search = '')
{
    $search = truncate_list_search($search);
    $stmt = null;
    try {
        if ($search === '') {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM Customer");
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM Customer WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?");
        }
        if (!$stmt) {
            error_log('Customer count prepare failed: ' . $conn->error);
            return 0;
        }
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            if (!$stmt->bind_param('sss', $pattern, $pattern, $pattern)) {
                error_log('Customer count bind failed: ' . $stmt->error);
                return 0;
            }
        }
        if (!$stmt->execute()) {
            error_log('Customer count execute failed: ' . $stmt->error);
            return 0;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Customer count result failed: ' . $stmt->error);
            return 0;
        }
        $row = $result->fetch_assoc();
        return $row ? max(0, (int)$row['total']) : 0;
    } catch (Throwable $exception) {
        error_log('Customer count failed: ' . $exception->getMessage());
        return 0;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function people_get_customers_page($conn, $search = '', $limit = 25, $offset = 0)
{
    $search = truncate_list_search($search);
    $limit = normalize_page_size($limit, 25);
    $offset = max(0, (int)$offset);
    $stmt = null;
    try {
        $sql = "SELECT * FROM Customer";
        if ($search !== '') {
            $sql .= " WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?";
        }
        $sql .= " ORDER BY name ASC, id ASC LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Customer page prepare failed: ' . $conn->error);
            return [];
        }
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            $bound = $stmt->bind_param('sssii', $pattern, $pattern, $pattern, $limit, $offset);
        } else {
            $bound = $stmt->bind_param('ii', $limit, $offset);
        }
        if (!$bound) {
            error_log('Customer page bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Customer page execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Customer page result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Customer page failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function people_get_customers_for_selector($conn, $limit = 100)
{
    $limit = normalize_page_size($limit, 100, [25, 50, 100]);
    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT id, name, phone FROM Customer ORDER BY name ASC, id ASC LIMIT ?");
        if (!$stmt) {
            error_log('Customer selector prepare failed: ' . $conn->error);
            return [];
        }
        if (!$stmt->bind_param('i', $limit)) {
            error_log('Customer selector bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Customer selector execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Customer selector result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Customer selector failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function people_count_suppliers($conn, $search = '')
{
    $search = truncate_list_search($search);
    $stmt = null;
    try {
        if ($search === '') {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM Supplier");
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM Supplier WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?");
        }
        if (!$stmt) {
            error_log('Supplier count prepare failed: ' . $conn->error);
            return 0;
        }
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            if (!$stmt->bind_param('sss', $pattern, $pattern, $pattern)) {
                error_log('Supplier count bind failed: ' . $stmt->error);
                return 0;
            }
        }
        if (!$stmt->execute()) {
            error_log('Supplier count execute failed: ' . $stmt->error);
            return 0;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Supplier count result failed: ' . $stmt->error);
            return 0;
        }
        $row = $result->fetch_assoc();
        return $row ? max(0, (int)$row['total']) : 0;
    } catch (Throwable $exception) {
        error_log('Supplier count failed: ' . $exception->getMessage());
        return 0;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function people_get_suppliers_page($conn, $search = '', $limit = 25, $offset = 0)
{
    $search = truncate_list_search($search);
    $limit = normalize_page_size($limit, 25);
    $offset = max(0, (int)$offset);
    $stmt = null;
    try {
        $sql = "SELECT * FROM Supplier";
        if ($search !== '') {
            $sql .= " WHERE name LIKE ? OR phone LIKE ? OR email LIKE ?";
        }
        $sql .= " ORDER BY name ASC, id ASC LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Supplier page prepare failed: ' . $conn->error);
            return [];
        }
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            $bound = $stmt->bind_param('sssii', $pattern, $pattern, $pattern, $limit, $offset);
        } else {
            $bound = $stmt->bind_param('ii', $limit, $offset);
        }
        if (!$bound) {
            error_log('Supplier page bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Supplier page execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Supplier page result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Supplier page failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function people_get_suppliers_for_selector($conn, $limit = 100)
{
    $limit = normalize_page_size($limit, 100, [25, 50, 100]);
    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT id, name, phone FROM Supplier ORDER BY name ASC, id ASC LIMIT ?");
        if (!$stmt) {
            error_log('Supplier selector prepare failed: ' . $conn->error);
            return [];
        }
        if (!$stmt->bind_param('i', $limit)) {
            error_log('Supplier selector bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Supplier selector execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Supplier selector result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Supplier selector failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
