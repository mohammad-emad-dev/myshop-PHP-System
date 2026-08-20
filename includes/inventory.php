<?php

declare(strict_types=1);

require_once __DIR__ . '/pagination.php';

/**
 * Count stock movements, optionally scoped to one product.
 *
 * This bounded read remains independent from the compatibility facade. Stock
 * mutation and the legacy unbounded movement loader remain elsewhere until
 * their callers are separately characterized.
 */
function inventory_count_stock_movements($conn, $product_id = null)
{
    $product_id = $product_id === null ? null : (int)$product_id;
    if ($product_id !== null && $product_id <= 0) {
        return 0;
    }

    $stmt = null;
    try {
        if ($product_id === null) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM `StockMovement`");
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM `StockMovement` WHERE product_id = ?");
        }
        if (!$stmt) {
            error_log('Stock movement count prepare failed: ' . $conn->error);
            return 0;
        }
        if ($product_id !== null && !$stmt->bind_param('i', $product_id)) {
            error_log('Stock movement count bind failed: ' . $stmt->error);
            return 0;
        }
        if (!$stmt->execute()) {
            error_log('Stock movement count execute failed: ' . $stmt->error);
            return 0;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Stock movement count result failed: ' . $stmt->error);
            return 0;
        }
        $row = $result->fetch_assoc();
        return $row ? max(0, (int)$row['total']) : 0;
    } catch (Throwable $exception) {
        error_log('Stock movement count failed: ' . $exception->getMessage());
        return 0;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Return one bounded, deterministically ordered stock-movement page.
 */
function inventory_get_stock_movements_page($conn, $product_id = null, $limit = 25, $offset = 0)
{
    $product_id = $product_id === null ? null : (int)$product_id;
    if ($product_id !== null && $product_id <= 0) {
        return [];
    }
    $limit = normalize_page_size($limit, 25);
    $offset = max(0, (int)$offset);
    $stmt = null;

    try {
        if ($product_id === null) {
            $stmt = $conn->prepare(
                "SELECT sm.*, p.name as product_name, s.full_name as staff_name
                 FROM `StockMovement` sm
                 JOIN Product p ON sm.product_id = p.id
                 JOIN Staff s ON sm.staff_id = s.id
                 ORDER BY sm.created_at DESC, sm.id DESC
                 LIMIT ? OFFSET ?"
            );
        } else {
            $stmt = $conn->prepare(
                "SELECT sm.*, p.name as product_name, s.full_name as staff_name
                 FROM `StockMovement` sm
                 JOIN Product p ON sm.product_id = p.id
                 JOIN Staff s ON sm.staff_id = s.id
                 WHERE sm.product_id = ?
                 ORDER BY sm.created_at DESC, sm.id DESC
                 LIMIT ? OFFSET ?"
            );
        }
        if (!$stmt) {
            error_log('Stock movement page prepare failed: ' . $conn->error);
            return [];
        }
        $bound = $product_id === null
            ? $stmt->bind_param('ii', $limit, $offset)
            : $stmt->bind_param('iii', $product_id, $limit, $offset);
        if (!$bound) {
            error_log('Stock movement page bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Stock movement page execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Stock movement page result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Stock movement page failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Insert one stock-movement history row while preserving the legacy write
 * contract. Callers remain on the compatibility wrapper during this batch.
 */
function inventory_log_stock_movement($conn, $product_id, $staff_id, $quantity, $movement_type, $reason = null)
{
    $stmt = null;
    try {
        $stmt = $conn->prepare(
            "INSERT INTO `StockMovement`
                (product_id, staff_id, quantity, movement_type, reason)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            error_log('Stock movement prepare failed: ' . $conn->error);
            return false;
        }

        if (!$stmt->bind_param('iiiss', $product_id, $staff_id, $quantity, $movement_type, $reason)) {
            error_log('Stock movement bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Stock movement insert failed: ' . $stmt->error);
            return false;
        }
        if ($stmt->affected_rows !== 1) {
            error_log('Stock movement insert affected an unexpected number of rows.');
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Stock movement insert failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
