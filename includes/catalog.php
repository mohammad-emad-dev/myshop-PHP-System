<?php

declare(strict_types=1);

require_once __DIR__ . '/pagination.php';

/**
 * Build the allow-listed product filter fragment and its bound search value.
 *
 * This helper only returns fixed SQL fragments. Search values remain bound by
 * each caller and the filter value is never interpolated into SQL.
 */
function catalog_build_product_filter_sql($search, $filter, &$search_pattern)
{
    $conditions = [];
    $search_pattern = '';
    $search = trim((string)$search);

    if ($search !== '') {
        $search_pattern = '%' . $search . '%';
        $conditions[] = '(p.name LIKE ? OR c.name LIKE ? OR p.barcode LIKE ?)';
    }

    if ($filter === 'low_stock') {
        $conditions[] = 'p.stock <= p.alert_threshold';
    }

    return empty($conditions) ? '' : ' WHERE ' . implode(' AND ', $conditions);
}

/**
 * Returns a bounded product set for the POS. Search is optional so the POS
 * remains fast for normal selection while barcode/name lookups never require
 * loading the full catalog into memory.
 */
function catalog_get_pos_products($conn, $search = '', $limit = 100)
{
    $search = truncate_list_search($search);
    $limit = normalize_page_size($limit, 100, [25, 50, 100]);
    $stmt = null;

    try {
        if ($search !== '') {
            $search_pattern = '%' . $search . '%';
            $stmt = $conn->prepare(
                "SELECT p.*, c.name as category_name
                 FROM Product p
                 LEFT JOIN Category c ON p.category_id = c.id
                 WHERE p.name LIKE ? OR p.barcode LIKE ?
                 ORDER BY p.created_at DESC, p.id DESC
                 LIMIT ?"
            );
            if (!$stmt) {
                error_log('POS product search prepare failed: ' . $conn->error);
                return [];
            }
            if (!$stmt->bind_param('ssi', $search_pattern, $search_pattern, $limit)) {
                error_log('POS product search bind failed: ' . $stmt->error);
                return [];
            }
        } else {
            $stmt = $conn->prepare(
                "SELECT p.*, c.name as category_name
                 FROM Product p
                 LEFT JOIN Category c ON p.category_id = c.id
                 ORDER BY p.created_at DESC, p.id DESC
                 LIMIT ?"
            );
            if (!$stmt) {
                error_log('POS product list prepare failed: ' . $conn->error);
                return [];
            }
            if (!$stmt->bind_param('i', $limit)) {
                error_log('POS product list bind failed: ' . $stmt->error);
                return [];
            }
        }

        if (!$stmt->execute()) {
            error_log('POS product query execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('POS product result retrieval failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('POS product query failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function catalog_get_pos_product_by_barcode($conn, $barcode)
{
    $barcode = truncate_list_search($barcode);
    if ($barcode === '') {
        return null;
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare(
            "SELECT p.*, c.name as category_name
             FROM Product p
             LEFT JOIN Category c ON p.category_id = c.id
             WHERE p.barcode = ?
             LIMIT 1"
        );
        if (!$stmt) {
            error_log('POS barcode lookup prepare failed: ' . $conn->error);
            return null;
        }
        if (!$stmt->bind_param('s', $barcode)) {
            error_log('POS barcode lookup bind failed: ' . $stmt->error);
            return null;
        }
        if (!$stmt->execute()) {
            error_log('POS barcode lookup execute failed: ' . $stmt->error);
            return null;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('POS barcode lookup result failed: ' . $stmt->error);
            return null;
        }
        return $result->fetch_assoc() ?: null;
    } catch (Throwable $exception) {
        error_log('POS barcode lookup failed: ' . $exception->getMessage());
        return null;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function catalog_count_products($conn, $search = '', $filter = '')
{
    $stmt = null;
    try {
        $search_pattern = '';
        $where_sql = catalog_build_product_filter_sql($search, $filter, $search_pattern);
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM Product p
             LEFT JOIN Category c ON p.category_id = c.id" . $where_sql
        );

        if (!$stmt) {
            error_log('Product count prepare failed: ' . $conn->error);
            return 0;
        }

        if ($search_pattern !== '' && !$stmt->bind_param('sss', $search_pattern, $search_pattern, $search_pattern)) {
            error_log('Product count bind failed: ' . $stmt->error);
            return 0;
        }

        if (!$stmt->execute()) {
            error_log('Product count execute failed: ' . $stmt->error);
            return 0;
        }

        $result = $stmt->get_result();
        if (!$result) {
            error_log('Product count result retrieval failed: ' . $stmt->error);
            return 0;
        }

        $row = $result->fetch_assoc();
        return $row ? max(0, (int)$row['total']) : 0;
    } catch (Throwable $exception) {
        error_log('Product count failed: ' . $exception->getMessage());
        return 0;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function catalog_get_products_page($conn, $search = '', $filter = '', $limit = 25, $offset = 0)
{
    $allowed_page_sizes = [10, 25, 50];
    $limit = (int)$limit;
    $offset = max(0, (int)$offset);
    if (!in_array($limit, $allowed_page_sizes, true)) {
        $limit = 25;
    }

    $stmt = null;
    try {
        $search_pattern = '';
        $where_sql = catalog_build_product_filter_sql($search, $filter, $search_pattern);
        $stmt = $conn->prepare(
            "SELECT p.*, c.name as category_name
             FROM Product p
             LEFT JOIN Category c ON p.category_id = c.id" . $where_sql . "
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ? OFFSET ?"
        );

        if (!$stmt) {
            error_log('Product page prepare failed: ' . $conn->error);
            return [];
        }

        if ($search_pattern !== '') {
            if (!$stmt->bind_param('sssii', $search_pattern, $search_pattern, $search_pattern, $limit, $offset)) {
                error_log('Product page bind failed: ' . $stmt->error);
                return [];
            }
        } elseif (!$stmt->bind_param('ii', $limit, $offset)) {
            error_log('Product page bind failed: ' . $stmt->error);
            return [];
        }

        if (!$stmt->execute()) {
            error_log('Product page execute failed: ' . $stmt->error);
            return [];
        }

        $result = $stmt->get_result();
        if (!$result) {
            error_log('Product page result retrieval failed: ' . $stmt->error);
            return [];
        }

        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Product page query failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function catalog_get_product_by_id($conn, $id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return null;
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare(
            "SELECT p.*, c.name as category_name
             FROM Product p
             LEFT JOIN Category c ON p.category_id = c.id
             WHERE p.id = ?"
        );
        if (!$stmt) {
            error_log('Product lookup prepare failed: ' . $conn->error);
            return null;
        }
        if (!$stmt->bind_param('i', $id)) {
            error_log('Product lookup bind failed: ' . $stmt->error);
            return null;
        }
        if (!$stmt->execute()) {
            error_log('Product lookup execute failed: ' . $stmt->error);
            return null;
        }

        $result = $stmt->get_result();
        if (!$result) {
            error_log('Product lookup result failed: ' . $stmt->error);
            return null;
        }

        return $result->fetch_assoc() ?: null;
    } catch (Throwable $exception) {
        error_log('Product lookup failed: ' . $exception->getMessage());
        return null;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function catalog_get_categories_for_selector($conn, $limit = 100)
{
    $limit = normalize_page_size($limit, 100, [25, 50, 100]);
    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT id, name FROM Category ORDER BY name ASC, id ASC LIMIT ?");
        if (!$stmt) {
            error_log('Category selector prepare failed: ' . $conn->error);
            return [];
        }
        if (!$stmt->bind_param('i', $limit)) {
            error_log('Category selector bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Category selector execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Category selector result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Category selector failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function catalog_count_categories($conn, $search = '')
{
    $search = truncate_list_search($search);
    $stmt = null;
    try {
        if ($search === '') {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM Category");
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM Category WHERE name LIKE ? OR description LIKE ?");
        }
        if (!$stmt) {
            error_log('Category count prepare failed: ' . $conn->error);
            return 0;
        }
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            if (!$stmt->bind_param('ss', $pattern, $pattern)) {
                error_log('Category count bind failed: ' . $stmt->error);
                return 0;
            }
        }
        if (!$stmt->execute()) {
            error_log('Category count execute failed: ' . $stmt->error);
            return 0;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Category count result failed: ' . $stmt->error);
            return 0;
        }
        $row = $result->fetch_assoc();
        return $row ? max(0, (int)$row['total']) : 0;
    } catch (Throwable $exception) {
        error_log('Category count failed: ' . $exception->getMessage());
        return 0;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function catalog_get_categories_page($conn, $search = '', $limit = 25, $offset = 0)
{
    $search = truncate_list_search($search);
    $limit = normalize_page_size($limit, 25);
    $offset = max(0, (int)$offset);
    $stmt = null;
    try {
        $sql = "SELECT c.*, COUNT(p.id) AS product_count
                FROM Category c
                LEFT JOIN Product p ON c.id = p.category_id";
        if ($search !== '') {
            $sql .= " WHERE c.name LIKE ? OR c.description LIKE ?";
        }
        $sql .= " GROUP BY c.id ORDER BY c.name ASC, c.id ASC LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Category page prepare failed: ' . $conn->error);
            return [];
        }
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            $bound = $stmt->bind_param('ssii', $pattern, $pattern, $limit, $offset);
        } else {
            $bound = $stmt->bind_param('ii', $limit, $offset);
        }
        if (!$bound) {
            error_log('Category page bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Category page execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Category page result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Category page failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
