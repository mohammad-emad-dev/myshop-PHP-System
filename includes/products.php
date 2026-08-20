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
