<?php

declare(strict_types=1);

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/audit.php';

/**
 * Create a product and its optional initial stock history atomically.
 *
 * The caller owns request validation, authorization, and upload handling.
 * This service receives the actor explicitly and owns only the database
 * transaction and its associated product, movement, and audit writes.
 */
function products_create($conn, $staff_id, $name, $description, $price, $stock, $image_path = null, $alert_threshold = 10, $category_id = null, $barcode = null): bool
{
    $transaction_started = false;
    $stmt = null;

    try {
        if (!$conn->begin_transaction()) {
            error_log('create_product failed: unable to start transaction.');
            return false;
        }
        $transaction_started = true;

        if ($category_id === null) {
            $general_result = $conn->query("SELECT id FROM Category WHERE name = 'General' LIMIT 1");
            if (!$general_result) {
                throw new Exception('Failed to load default category.');
            }
            if ($general_result->num_rows > 0) {
                $category_id = (int)$general_result->fetch_assoc()['id'];
            }
            $general_result->free();
        } else {
            $category_id = (int)$category_id;
        }

        // Clean empty barcode strings to NULL to avoid unique constraint violations
        if (empty(trim((string)$barcode))) {
            $barcode = null;
        } else {
            $barcode = trim((string)$barcode);
        }

        $stmt = $conn->prepare(
            "INSERT INTO Product
                (name, description, price, stock, image_path, alert_threshold, category_id, barcode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new Exception('Failed to prepare product insertion.');
        }
        if (!$stmt->bind_param("ssdisiis", $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $barcode)) {
            throw new Exception('Failed to bind product insertion.');
        }
        if (!$stmt->execute()) {
            throw new Exception('Product insertion failed.');
        }
        if ($stmt->affected_rows !== 1) {
            throw new Exception('Product insertion affected an unexpected number of rows.');
        }
        $product_id = $conn->insert_id;
        if ($product_id <= 0) {
            throw new Exception('Product ID was not created.');
        }
        $stmt->close();
        $stmt = null;

        if ($stock != 0) {
            if (!inventory_log_stock_movement($conn, $product_id, $staff_id, $stock, 'manual_adjustment', 'Initial stock allocation')) {
                throw new Exception('Stock movement logging failed.');
            }
        }

        if (!audit_log($conn, $staff_id, 'product_create', 'Product', $product_id, true, [
            'stock' => (int)$stock,
            'has_image' => is_string($image_path) && $image_path !== '',
        ])) {
            throw new Exception('Product audit logging failed.');
        }

        if (!$conn->commit()) {
            throw new Exception('Product creation commit failed.');
        }
        $transaction_started = false;
        return true;
    } catch (Throwable $e) {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        if ($transaction_started) {
            try {
                if (!$conn->rollback()) {
                    error_log('create_product rollback failed: ' . inventory_rollback_error($conn));
                }
            } catch (Throwable $rollback_exception) {
                error_log('create_product rollback failed: ' . $rollback_exception->getMessage());
            }
        }
        error_log('create_product failed: ' . $e->getMessage());
        audit_log($conn, $staff_id, 'product_create', 'Product', null, false, [
            'reason' => 'database_operation_failed',
        ]);
        return false;
    }
}

/**
 * Update a product and its optional stock movement atomically.
 *
 * The caller owns request validation, authorization, and upload handling.
 * This service receives the actor explicitly and preserves the existing
 * no-op update behavior while owning the database transaction.
 */
function products_update($conn, $staff_id, $id, $name, $description, $price, $stock, $image_path = null, $alert_threshold = 10, $category_id = null, $barcode = null): bool
{
    $id = (int)$id;
    $stock = filter_var($stock, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 2147483647]
    ]);

    if ($id <= 0 || $stock === false) {
        return false;
    }

    try {
        if (!$conn->begin_transaction()) {
            error_log('update_product failed: unable to start transaction.');
            return false;
        }
    } catch (Throwable $exception) {
        error_log('update_product transaction start failed: ' . $exception->getMessage());
        return false;
    }

    $transaction_started = true;
    $product_stmt = null;
    $stmt = null;
    try {
        $product_stmt = $conn->prepare("SELECT stock FROM Product WHERE id = ? FOR UPDATE");
        if (!$product_stmt) {
            throw new Exception("Failed to prepare product lock statement");
        }
        if (!$product_stmt->bind_param("i", $id)) {
            throw new Exception("Failed to bind product lock statement");
        }
        if (!$product_stmt->execute()) {
            throw new Exception("Failed to lock product");
        }

        $product_result = $product_stmt->get_result();
        if (!$product_result) {
            throw new Exception("Failed to read locked product");
        }
        $product = $product_result->fetch_assoc();
        $product_stmt->close();
        $product_stmt = null;

        if (!$product) {
            throw new Exception("Product not found");
        }

        $old_stock = filter_var($product['stock'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 2147483647]
        ]);
        if ($old_stock === false) {
            throw new Exception("Product has invalid stock");
        }

        $delta = $stock - $old_stock;

        if ($category_id === null) {
            $gen_stmt = $conn->query("SELECT id FROM Category WHERE name = 'General' LIMIT 1");
            if (!$gen_stmt) {
                throw new Exception("Failed to load default category");
            }
            if ($gen_stmt->num_rows > 0) {
                $category_id = intval($gen_stmt->fetch_assoc()['id']);
            }
            $gen_stmt->free();
        } else {
            $category_id = intval($category_id);
        }

        // Clean empty barcode strings to NULL to avoid unique constraint violations
        if (empty(trim((string)$barcode))) {
            $barcode = null;
        } else {
            $barcode = trim((string)$barcode);
        }

        if ($image_path) {
            $stmt = $conn->prepare("UPDATE Product SET name = ?, description = ?, price = ?, stock = ?, image_path = ?, alert_threshold = ?, category_id = ?, barcode = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare statement");
            }
            if (!$stmt->bind_param("ssdisiisi", $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $barcode, $id)) {
                throw new Exception("Failed to bind statement");
            }
        } else {
            $stmt = $conn->prepare("UPDATE Product SET name = ?, description = ?, price = ?, stock = ?, alert_threshold = ?, category_id = ?, barcode = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare statement");
            }
            if (!$stmt->bind_param("ssdiiisi", $name, $description, $price, $stock, $alert_threshold, $category_id, $barcode, $id)) {
                throw new Exception("Failed to bind statement");
            }
        }

        if (!$stmt->execute()) {
            throw new Exception("Product update failed");
        }
        if ($stmt->affected_rows < 0 || $stmt->affected_rows > 1) {
            throw new Exception("Product update affected an unexpected number of rows");
        }
        $stmt->close();
        $stmt = null;

        if ($delta != 0) {
            $reason = "Manual stock adjustment (from " . $old_stock . " to " . $stock . ")";
            if (!inventory_log_stock_movement($conn, $id, $staff_id, $delta, 'manual_adjustment', $reason)) {
                throw new Exception("Stock movement logging failed");
            }
        }

        if (!audit_log($conn, $staff_id, 'product_update', 'Product', $id, true, [
            'stock_delta' => (int)$delta,
            'has_image' => is_string($image_path) && $image_path !== '',
        ])) {
            throw new Exception('Product audit logging failed');
        }

        if (!$conn->commit()) {
            throw new Exception("Failed to commit product update");
        }
        $transaction_started = false;
        return true;
    } catch (Throwable $e) {
        if ($product_stmt instanceof mysqli_stmt) {
            $product_stmt->close();
        }
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        if ($transaction_started) {
            try {
                if (!$conn->rollback()) {
                    error_log('update_product rollback failed: ' . inventory_rollback_error($conn));
                }
            } catch (Throwable $rollback_exception) {
                error_log('update_product rollback failed: ' . $rollback_exception->getMessage());
            }
        }
        error_log("update_product failed: " . $e->getMessage());
        audit_log($conn, $staff_id, 'product_update', 'Product', $id, false, [
            'reason' => 'database_operation_failed',
        ]);
        return false;
    }
}

/**
 * Delete a product that has no historical order or stock records atomically.
 *
 * The caller owns authorization and request validation. This service receives
 * the optional actor explicitly and owns only the deletion transaction and
 * its associated audit write.
 */
function products_delete($conn, $id, $actor_staff_id = null): bool
{
    $id = (int)$id;
    if ($id <= 0) {
        return false;
    }

    try {
        if (!$conn->begin_transaction()) {
            error_log('delete_product failed: unable to start transaction.');
            return false;
        }
    } catch (Throwable $exception) {
        error_log('delete_product transaction start failed: ' . $exception->getMessage());
        return false;
    }

    $transaction_started = true;
    $product_stmt = null;
    $order_detail_stmt = null;
    $movement_stmt = null;
    $delete_stmt = null;
    try {
        $product_stmt = $conn->prepare("SELECT id FROM Product WHERE id = ? FOR UPDATE");
        if (!$product_stmt) {
            throw new Exception("Failed to prepare product lock statement");
        }
        if (!$product_stmt->bind_param("i", $id)) {
            throw new Exception("Failed to bind product lock statement");
        }
        if (!$product_stmt->execute()) {
            throw new Exception("Failed to lock product");
        }

        $product_result = $product_stmt->get_result();
        if (!$product_result) {
            throw new Exception("Failed to read product");
        }
        if ($product_result->num_rows !== 1) {
            throw new Exception("Product not found");
        }
        $product_stmt->close();
        $product_stmt = null;

        $order_detail_stmt = $conn->prepare("SELECT id FROM OrderDetail WHERE product_id = ? LIMIT 1");
        if (!$order_detail_stmt) {
            throw new Exception("Failed to prepare order history check");
        }
        if (!$order_detail_stmt->bind_param("i", $id)) {
            throw new Exception("Failed to bind order history check");
        }
        if (!$order_detail_stmt->execute()) {
            throw new Exception("Failed to check order history");
        }
        $order_detail_result = $order_detail_stmt->get_result();
        if (!$order_detail_result) {
            throw new Exception("Failed to read order history");
        }
        $has_order_history = $order_detail_result->num_rows > 0;
        $order_detail_stmt->close();
        $order_detail_stmt = null;
        if ($has_order_history) {
            throw new Exception("Product has historical order details");
        }

        $movement_stmt = $conn->prepare("SELECT id FROM StockMovement WHERE product_id = ? LIMIT 1");
        if (!$movement_stmt) {
            throw new Exception("Failed to prepare stock history check");
        }
        if (!$movement_stmt->bind_param("i", $id)) {
            throw new Exception("Failed to bind stock history check");
        }
        if (!$movement_stmt->execute()) {
            throw new Exception("Failed to check stock history");
        }
        $movement_result = $movement_stmt->get_result();
        if (!$movement_result) {
            throw new Exception("Failed to read stock history");
        }
        $has_stock_history = $movement_result->num_rows > 0;
        $movement_stmt->close();
        $movement_stmt = null;
        if ($has_stock_history) {
            throw new Exception("Product has historical stock movements");
        }

        $delete_stmt = $conn->prepare("DELETE FROM Product WHERE id = ?");
        if (!$delete_stmt) {
            throw new Exception("Failed to prepare product deletion");
        }
        if (!$delete_stmt->bind_param("i", $id)) {
            throw new Exception("Failed to bind product deletion");
        }
        if (!$delete_stmt->execute()) {
            throw new Exception("Failed to delete product");
        }
        if ($delete_stmt->affected_rows !== 1) {
            throw new Exception("Product deletion affected an unexpected number of rows");
        }
        $delete_stmt->close();
        $delete_stmt = null;

        if (!audit_log($conn, $actor_staff_id, 'product_delete', 'Product', $id, true, null)) {
            throw new Exception("Product audit logging failed");
        }

        if (!$conn->commit()) {
            throw new Exception("Failed to commit product deletion");
        }
        $transaction_started = false;
        return true;
    } catch (Throwable $e) {
        foreach ([$product_stmt, $order_detail_stmt, $movement_stmt, $delete_stmt] as $open_stmt) {
            if ($open_stmt instanceof mysqli_stmt) {
                $open_stmt->close();
            }
        }
        if ($transaction_started) {
            try {
                if (!$conn->rollback()) {
                    error_log('delete_product rollback failed: ' . inventory_rollback_error($conn));
                }
            } catch (Throwable $rollback_exception) {
                error_log('delete_product rollback failed: ' . $rollback_exception->getMessage());
            }
        }
        error_log("delete_product failed: " . $e->getMessage());
        audit_log($conn, $actor_staff_id, 'product_delete', 'Product', $id, false, [
            'reason' => 'database_operation_failed',
        ]);
        return false;
    }
}
