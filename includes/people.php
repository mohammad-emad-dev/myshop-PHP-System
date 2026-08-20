<?php

declare(strict_types=1);

require_once __DIR__ . '/pagination.php';

/**
 * Transitional people module.
 *
 * This module currently owns only bounded Customer read operations. Customer
 * writes remain in the legacy compatibility facade until they are separately
 * characterized and extracted.
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
