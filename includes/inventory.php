<?php

declare(strict_types=1);

require_once __DIR__ . '/pagination.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';

/**
 * Count stock movements, optionally scoped to one product.
 *
 * This bounded read remains independent from the compatibility facade. Stock
 * quantity mutation and the legacy unbounded movement loader remain elsewhere;
 * the history writer below preserves its existing insert seam without owning
 * transaction boundaries.
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
 * Return products at or below their configured stock alert threshold.
 */
function inventory_get_low_stock_products($conn, $limit = 100)
{
    $limit = normalize_page_size($limit, 100, [25, 50, 100]);
    $stmt = null;
    try {
        $stmt = $conn->prepare(
            "SELECT p.*, c.name as category_name
             FROM Product p
             LEFT JOIN Category c ON p.category_id = c.id
             WHERE p.stock <= p.alert_threshold
             ORDER BY p.stock ASC, p.name ASC, p.id ASC
             LIMIT ?"
        );
        if (!$stmt) {
            error_log('Low-stock product prepare failed: ' . $conn->error);
            return [];
        }
        if (!$stmt->bind_param('i', $limit)) {
            error_log('Low-stock product bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Low-stock product execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Low-stock product result retrieval failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Low-stock product query failed: ' . $exception->getMessage());
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

/**
 * Return a safe rollback diagnostic without allowing an invalid mysqli
 * connection to mask the original transaction failure.
 */
function inventory_rollback_error($conn): string
{
    try {
        if (!($conn instanceof mysqli) || !$conn->thread_id) {
            return 'connection unavailable';
        }

        return (string)$conn->error;
    } catch (Throwable $exception) {
        return 'connection unavailable';
    }
}

/**
 * Atomically adjust one product's stock and record its movement and audit
 * events. The caller owns request validation and authorization; this service
 * owns the database transaction and receives the actor explicitly.
 */
function inventory_adjust_stock($conn, $product_id, $staff_id, $quantity, $reason): bool
{
    $product_id = filter_var($product_id, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647]
    ]);
    $staff_id = filter_var($staff_id, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647]
    ]);
    $quantity = filter_var($quantity, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => -2147483647, 'max_range' => 2147483647]
    ]);

    if ($product_id === false || $staff_id === false || $quantity === false || $quantity === 0 || !is_string($reason)) {
        error_log('Stock adjustment failed: invalid adjustment arguments.');
        return false;
    }

    $product_id = (int)$product_id;
    $staff_id = (int)$staff_id;
    $quantity = (int)$quantity;

    $transaction_started = false;
    try {
        $transaction_started = $conn->begin_transaction();
    } catch (Throwable $exception) {
        $transaction_started = false;
    }

    if (!$transaction_started) {
        error_log('Stock adjustment failed: unable to start transaction.');
        audit_log($conn, $staff_id, 'stock_adjustment', 'Product', $product_id, false, ['reason' => 'transaction_start_failed']);
        return false;
    }

    $product_stmt = null;
    $update_stmt = null;
    try {
        $product_stmt = $conn->prepare("SELECT stock FROM Product WHERE id = ? FOR UPDATE");
        if (!$product_stmt) {
            throw new Exception("Failed to prepare product lock statement.");
        }
        if (!$product_stmt->bind_param("i", $product_id)) {
            throw new Exception("Failed to bind product lock statement.");
        }
        if (!$product_stmt->execute()) {
            throw new Exception("Failed to lock product.");
        }

        $product_result = $product_stmt->get_result();
        if (!$product_result) {
            throw new Exception("Failed to read locked product.");
        }
        $product = $product_result->fetch_assoc();
        $product_stmt->close();
        $product_stmt = null;

        if (!$product) {
            throw new Exception("Product not found.");
        }

        $current_stock = filter_var($product['stock'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 2147483647]
        ]);
        if ($current_stock === false) {
            throw new Exception("Product has invalid stock.");
        }

        $new_stock = $current_stock + $quantity;
        if ($new_stock < 0 || $new_stock > 2147483647) {
            throw new Exception("Stock adjustment would exceed the supported range.");
        }

        $update_stmt = $conn->prepare(
            "UPDATE Product SET stock = ? WHERE id = ? AND stock = ?"
        );
        if (!$update_stmt) {
            throw new Exception("Failed to prepare stock update.");
        }
        if (!$update_stmt->bind_param("iii", $new_stock, $product_id, $current_stock)) {
            throw new Exception("Failed to bind stock update.");
        }
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update stock.");
        }
        if ($update_stmt->affected_rows !== 1) {
            throw new Exception("Stock update affected an unexpected number of rows.");
        }
        $update_stmt->close();
        $update_stmt = null;

        if (!inventory_log_stock_movement($conn, $product_id, $staff_id, $quantity, 'manual_adjustment', $reason)) {
            throw new Exception("Failed to log stock movement.");
        }

        if (!audit_log($conn, $staff_id, 'stock_adjustment', 'Product', $product_id, true, [
            'quantity' => $quantity,
            'new_stock' => (int)$new_stock,
        ])) {
            throw new Exception('Failed to log stock adjustment audit event.');
        }

        if (!$conn->commit()) {
            throw new Exception("Failed to commit stock adjustment.");
        }
        return true;
    } catch (Throwable $exception) {
        if ($product_stmt instanceof mysqli_stmt) {
            $product_stmt->close();
        }
        if ($update_stmt instanceof mysqli_stmt) {
            $update_stmt->close();
        }

        $rollback_succeeded = false;
        try {
            $rollback_succeeded = $conn->rollback();
        } catch (Throwable $rollback_exception) {
            $rollback_succeeded = false;
        }
        if (!$rollback_succeeded) {
            error_log('Stock adjustment rollback failed: ' . inventory_rollback_error($conn));
        }
        error_log('Stock adjustment failed: ' . $exception->getMessage());
        audit_log($conn, $staff_id, 'stock_adjustment', 'Product', $product_id, false, [
            'reason' => 'database_operation_failed',
        ]);
        return false;
    }
}
