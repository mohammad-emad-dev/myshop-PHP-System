<?php

declare(strict_types=1);

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/pagination.php';

/**
 * Create one sale or purchase order atomically.
 *
 * The existing public contract returns the created order ID on success and
 * false on failure. The explicit staff ID keeps authorization independent of
 * request/session state while preserving the legacy caller behavior.
 */
function orders_create($conn, $staff_id, $items, $order_type = 'sale', $customer_id = null, $supplier_id = null): int|false
{
    $staff_id = (int)$staff_id;
    $max_stock = 2147483647;
    $max_money_cents = 9999999999;

    if ($staff_id <= 0 || !is_array($items) || empty($items)) {
        if ($conn instanceof mysqli) {
            audit_log($conn, $staff_id, 'order_create', 'Order', null, false, ['reason' => 'validation_failed']);
        }
        return false;
    }
    if (!in_array($order_type, ['sale', 'purchase'], true)) {
        if ($conn instanceof mysqli) {
            audit_log($conn, $staff_id, 'order_create', 'Order', null, false, ['reason' => 'invalid_order_type']);
        }
        return false;
    }

    $normalized_items = [];
    try {
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new Exception('Invalid order item.');
            }

            $product_id = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $max_stock]
            ]);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $max_stock]
            ]);

            if ($product_id === false || $quantity === false) {
                throw new Exception('Invalid product or quantity.');
            }

            $existing_quantity = $normalized_items[$product_id] ?? 0;
            if ($quantity > ($max_stock - $existing_quantity)) {
                throw new Exception('Order quantity exceeds the supported limit.');
            }
            $normalized_items[$product_id] = $existing_quantity + $quantity;
        }

        if (empty($normalized_items)) {
            throw new Exception('Order cart is empty.');
        }

        if ($order_type === 'sale') {
            $customer_id = filter_var($customer_id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $max_stock]
            ]);
            if ($customer_id === false) {
                throw new Exception('Invalid customer.');
            }
            $supplier_id = null;
        } else {
            $supplier_id = filter_var($supplier_id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $max_stock]
            ]);
            if ($supplier_id === false) {
                throw new Exception('Invalid supplier.');
            }
            $customer_id = null;
        }

        ksort($normalized_items, SORT_NUMERIC);
    } catch (Throwable $e) {
        error_log('create_order validation failed: ' . $e->getMessage());
        $audit_action = $order_type === 'purchase' ? 'purchase_order_create' : 'sale_order_create';
        if ($conn instanceof mysqli) {
            audit_log($conn, $staff_id, $audit_action, 'Order', null, false, ['reason' => 'validation_failed']);
        }
        return false;
    }

    try {
        if (!$conn->begin_transaction()) {
            error_log('create_order failed: unable to start transaction.');
            return false;
        }
    } catch (Throwable $exception) {
        error_log('create_order transaction start failed: ' . $exception->getMessage());
        return false;
    }

    $transaction_started = true;
    $staff_stmt = null;
    $party_stmt = null;
    $product_stmt = null;
    $order_stmt = null;
    $detail_stmt = null;
    $stock_stmt = null;

    try {
        $staff_stmt = $conn->prepare(
            "SELECT role, is_active FROM Staff WHERE id = ? LIMIT 1"
        );
        if (!$staff_stmt) {
            throw new Exception('Failed to prepare order staff authorization statement.');
        }
        if (!$staff_stmt->bind_param('i', $staff_id)) {
            throw new Exception('Failed to bind order staff authorization statement.');
        }
        if (!$staff_stmt->execute()) {
            throw new Exception('Failed to verify order staff authorization.');
        }
        $staff_result = $staff_stmt->get_result();
        if (!$staff_result) {
            throw new Exception('Failed to read order staff authorization result.');
        }
        $staff_record = $staff_result->fetch_assoc();
        $staff_stmt->close();
        $staff_stmt = null;

        if (
            !$staff_record
            || (int)$staff_record['is_active'] !== 1
            || !in_array($staff_record['role'], ['admin', 'cashier'], true)
            || ($order_type === 'purchase' && $staff_record['role'] !== 'admin')
        ) {
            throw new Exception('The staff account is not authorized for this order type.');
        }

        $party_table = $order_type === 'sale' ? 'Customer' : 'Supplier';
        $party_id = $order_type === 'sale' ? $customer_id : $supplier_id;
        $party_stmt = $conn->prepare("SELECT id FROM {$party_table} WHERE id = ? LIMIT 1");
        if (!$party_stmt) {
            throw new Exception('Failed to prepare party validation statement.');
        }
        if (!$party_stmt->bind_param("i", $party_id)) {
            throw new Exception('Failed to bind party validation statement.');
        }
        if (!$party_stmt->execute()) {
            throw new Exception('Failed to validate party.');
        }
        $party_result = $party_stmt->get_result();
        if (!$party_result) {
            throw new Exception('Failed to read party validation result.');
        }
        if ($party_result->num_rows !== 1) {
            throw new Exception('Selected party does not exist.');
        }
        $party_stmt->close();
        $party_stmt = null;

        $product_stmt = $conn->prepare(
            "SELECT id, price, stock FROM Product WHERE id = ? FOR UPDATE"
        );
        if (!$product_stmt) {
            throw new Exception('Failed to prepare product lock statement.');
        }

        $locked_items = [];
        $total_cents = 0;
        foreach ($normalized_items as $product_id => $quantity) {
            if (!$product_stmt->bind_param("i", $product_id)) {
                throw new Exception('Failed to bind product lock statement.');
            }
            if (!$product_stmt->execute()) {
                throw new Exception('Failed to lock product.');
            }

            $product_result = $product_stmt->get_result();
            if (!$product_result) {
                throw new Exception('Failed to read locked product.');
            }
            $product = $product_result->fetch_assoc();
            if (!$product) {
                throw new Exception('Selected product does not exist.');
            }

            $current_stock = filter_var($product['stock'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => $max_stock]
            ]);
            if ($current_stock === false) {
                throw new Exception('Product has invalid stock.');
            }

            if ($order_type === 'sale' && $quantity > $current_stock) {
                throw new Exception('Insufficient stock.');
            }

            if (!is_numeric($product['price']) || !is_finite((float)$product['price'])) {
                throw new Exception('Product has invalid price.');
            }
            $price = (float)$product['price'];
            $price_cents = (int)round($price * 100);
            if ($price < 0 || $price_cents < 0 || $price_cents > $max_money_cents) {
                throw new Exception('Product has invalid price.');
            }
            if ($price_cents > 0 && $quantity > intdiv($max_money_cents, $price_cents)) {
                throw new Exception('Order subtotal exceeds the supported limit.');
            }

            $subtotal_cents = $price_cents * $quantity;
            if ($subtotal_cents > ($max_money_cents - $total_cents)) {
                throw new Exception('Order total exceeds the supported limit.');
            }
            $total_cents += $subtotal_cents;

            $locked_items[] = [
                'product_id' => (int)$product_id,
                'quantity' => $quantity,
                'current_stock' => $current_stock,
                'new_stock' => $order_type === 'sale'
                    ? $current_stock - $quantity
                    : $current_stock + $quantity,
                'unit_price' => $price_cents / 100,
                'subtotal' => $subtotal_cents / 100
            ];
        }
        $product_stmt->close();
        $product_stmt = null;

        foreach ($locked_items as $locked_item) {
            if ($locked_item['new_stock'] < 0 || $locked_item['new_stock'] > $max_stock) {
                throw new Exception('Stock update exceeds the supported range.');
            }
        }

        $total = $total_cents / 100;
        if ($order_type === 'sale') {
            $order_stmt = $conn->prepare(
                "INSERT INTO `Order` (total_amount, staff_id, order_type, customer_id) VALUES (?, ?, ?, ?)"
            );
            if (!$order_stmt) {
                throw new Exception('Failed to prepare order statement.');
            }
            if (!$order_stmt->bind_param("disi", $total, $staff_id, $order_type, $customer_id)) {
                throw new Exception('Failed to bind order statement.');
            }
        } else {
            $order_stmt = $conn->prepare(
                "INSERT INTO `Order` (total_amount, staff_id, order_type, supplier_id) VALUES (?, ?, ?, ?)"
            );
            if (!$order_stmt) {
                throw new Exception('Failed to prepare order statement.');
            }
            if (!$order_stmt->bind_param("disi", $total, $staff_id, $order_type, $supplier_id)) {
                throw new Exception('Failed to bind order statement.');
            }
        }

        if (!$order_stmt->execute()) {
            throw new Exception('Failed to insert order.');
        }
        if ($order_stmt->affected_rows !== 1) {
            throw new Exception('Order insert affected an unexpected number of rows.');
        }
        $order_id = (int)$conn->insert_id;
        if ($order_id <= 0) {
            throw new Exception('Order ID was not created.');
        }
        $order_stmt->close();
        $order_stmt = null;

        $detail_stmt = $conn->prepare(
            "INSERT INTO OrderDetail (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)"
        );
        if (!$detail_stmt) {
            throw new Exception('Failed to prepare order detail statement.');
        }

        $stock_stmt = $conn->prepare(
            "UPDATE Product SET stock = ? WHERE id = ? AND stock = ?"
        );
        if (!$stock_stmt) {
            throw new Exception('Failed to prepare stock update statement.');
        }

        foreach ($locked_items as $locked_item) {
            $detail_product_id = $locked_item['product_id'];
            $detail_quantity = $locked_item['quantity'];
            $detail_unit_price = $locked_item['unit_price'];
            $detail_subtotal = $locked_item['subtotal'];

            if (!$detail_stmt->bind_param(
                "iiidd",
                $order_id,
                $detail_product_id,
                $detail_quantity,
                $detail_unit_price,
                $detail_subtotal
            )) {
                throw new Exception('Failed to bind order detail statement.');
            }
            if (!$detail_stmt->execute()) {
                throw new Exception('Failed to insert order detail.');
            }
            if ($detail_stmt->affected_rows !== 1) {
                throw new Exception('Order detail insert affected an unexpected number of rows.');
            }

            $new_stock = $locked_item['new_stock'];
            $old_stock = $locked_item['current_stock'];
            if (!$stock_stmt->bind_param("iii", $new_stock, $detail_product_id, $old_stock)) {
                throw new Exception('Failed to bind stock update statement.');
            }
            if (!$stock_stmt->execute()) {
                throw new Exception('Failed to update product stock.');
            }
            if ($stock_stmt->affected_rows !== 1) {
                throw new Exception('Stock update affected an unexpected number of rows.');
            }

            $movement_quantity = $order_type === 'sale'
                ? -$detail_quantity
                : $detail_quantity;
            $reason = $order_type === 'sale'
                ? "Order #{$order_id} Sale"
                : "Order #{$order_id} Purchase";
            if (!inventory_log_stock_movement(
                $conn,
                $detail_product_id,
                $staff_id,
                $movement_quantity,
                $order_type,
                $reason
            )) {
                throw new Exception('Stock movement logging failed.');
            }
        }

        $detail_stmt->close();
        $detail_stmt = null;
        $stock_stmt->close();
        $stock_stmt = null;

        $audit_action = $order_type === 'sale' ? 'sale_order_create' : 'purchase_order_create';
        if (!audit_log($conn, $staff_id, $audit_action, 'Order', $order_id, true, [
            'order_type' => $order_type,
            'item_count' => count($locked_items),
            'total_amount' => $total,
        ])) {
            throw new Exception('Order audit logging failed.');
        }

        if (!$conn->commit()) {
            throw new Exception('Failed to commit order transaction.');
        }
        $transaction_started = false;
        return $order_id;
    } catch (Throwable $e) {
        foreach ([$staff_stmt, $party_stmt, $product_stmt, $order_stmt, $detail_stmt, $stock_stmt] as $open_stmt) {
            if ($open_stmt instanceof mysqli_stmt) {
                $open_stmt->close();
            }
        }
        if ($transaction_started) {
            try {
                if (!$conn->rollback()) {
                    error_log('create_order rollback failed: ' . inventory_rollback_error($conn));
                }
            } catch (Throwable $rollback_exception) {
                error_log('create_order rollback failed: ' . $rollback_exception->getMessage());
            }
        }
        error_log('create_order failed: ' . $e->getMessage());
        $audit_action = $order_type === 'purchase' ? 'purchase_order_create' : 'sale_order_create';
        audit_log($conn, $staff_id, $audit_action, 'Order', null, false, [
            'reason' => 'database_operation_failed',
        ]);
        return false;
    }
}

function orders_count($conn, $staff_id = null, $filter_type = 'all')
{
    $filter_type = in_array($filter_type, ['all', 'sale', 'purchase'], true) ? $filter_type : 'all';
    $staff_id = $staff_id === null ? null : (int)$staff_id;
    if ($staff_id !== null && $staff_id <= 0) {
        return 0;
    }

    $stmt = null;
    try {
        if ($staff_id === null && $filter_type === 'all') {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM `Order`");
        } elseif ($staff_id === null) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM `Order` WHERE order_type = ?");
        } elseif ($filter_type === 'all') {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM `Order` WHERE staff_id = ?");
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM `Order` WHERE staff_id = ? AND order_type = ?");
        }
        if (!$stmt) {
            error_log('Order count prepare failed: ' . $conn->error);
            return 0;
        }

        if ($staff_id === null && $filter_type !== 'all') {
            if (!$stmt->bind_param('s', $filter_type)) {
                error_log('Order count bind failed: ' . $stmt->error);
                return 0;
            }
        } elseif ($staff_id !== null && $filter_type === 'all') {
            if (!$stmt->bind_param('i', $staff_id)) {
                error_log('Order count bind failed: ' . $stmt->error);
                return 0;
            }
        } elseif ($staff_id !== null) {
            if (!$stmt->bind_param('is', $staff_id, $filter_type)) {
                error_log('Order count bind failed: ' . $stmt->error);
                return 0;
            }
        }

        if (!$stmt->execute()) {
            error_log('Order count execute failed: ' . $stmt->error);
            return 0;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Order count result failed: ' . $stmt->error);
            return 0;
        }
        $row = $result->fetch_assoc();
        return $row ? max(0, (int)$row['total']) : 0;
    } catch (Throwable $exception) {
        error_log('Order count failed: ' . $exception->getMessage());
        return 0;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function orders_get_summary($conn, $staff_id = null, $filter_type = 'all')
{
    $empty_summary = [
        'total_orders' => 0,
        'total_sales_amount' => 0.0,
        'total_purchases_amount' => 0.0,
        'sales_count' => 0,
        'purchases_count' => 0,
    ];
    $filter_type = in_array($filter_type, ['all', 'sale', 'purchase'], true) ? $filter_type : 'all';
    $staff_id = $staff_id === null ? null : (int)$staff_id;
    if ($staff_id !== null && $staff_id <= 0) {
        return $empty_summary;
    }

    $stmt = null;
    try {
        $sql = "SELECT COUNT(*) AS total_orders,
                       COALESCE(SUM(CASE WHEN order_type = 'sale' THEN total_amount ELSE 0 END), 0) AS total_sales_amount,
                       COALESCE(SUM(CASE WHEN order_type = 'purchase' THEN total_amount ELSE 0 END), 0) AS total_purchases_amount,
                       COALESCE(SUM(CASE WHEN order_type = 'sale' THEN 1 ELSE 0 END), 0) AS sales_count,
                       COALESCE(SUM(CASE WHEN order_type = 'purchase' THEN 1 ELSE 0 END), 0) AS purchases_count
                FROM `Order`";
        if ($staff_id === null && $filter_type !== 'all') {
            $sql .= " WHERE order_type = ?";
        } elseif ($staff_id !== null && $filter_type === 'all') {
            $sql .= " WHERE staff_id = ?";
        } elseif ($staff_id !== null) {
            $sql .= " WHERE staff_id = ? AND order_type = ?";
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Order summary prepare failed: ' . $conn->error);
            return $empty_summary;
        }
        if ($staff_id === null && $filter_type !== 'all') {
            if (!$stmt->bind_param('s', $filter_type)) {
                error_log('Order summary bind failed: ' . $stmt->error);
                return $empty_summary;
            }
        } elseif ($staff_id !== null && $filter_type === 'all') {
            if (!$stmt->bind_param('i', $staff_id)) {
                error_log('Order summary bind failed: ' . $stmt->error);
                return $empty_summary;
            }
        } elseif ($staff_id !== null) {
            if (!$stmt->bind_param('is', $staff_id, $filter_type)) {
                error_log('Order summary bind failed: ' . $stmt->error);
                return $empty_summary;
            }
        }
        if (!$stmt->execute()) {
            error_log('Order summary execute failed: ' . $stmt->error);
            return $empty_summary;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Order summary result failed: ' . $stmt->error);
            return $empty_summary;
        }
        $row = $result->fetch_assoc();
        if (!$row) {
            return $empty_summary;
        }
        return [
            'total_orders' => max(0, (int)$row['total_orders']),
            'total_sales_amount' => (float)$row['total_sales_amount'],
            'total_purchases_amount' => (float)$row['total_purchases_amount'],
            'sales_count' => max(0, (int)$row['sales_count']),
            'purchases_count' => max(0, (int)$row['purchases_count']),
        ];
    } catch (Throwable $exception) {
        error_log('Order summary failed: ' . $exception->getMessage());
        return $empty_summary;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function orders_get_page($conn, $staff_id = null, $filter_type = 'all', $limit = 25, $offset = 0)
{
    $filter_type = in_array($filter_type, ['all', 'sale', 'purchase'], true) ? $filter_type : 'all';
    $staff_id = $staff_id === null ? null : (int)$staff_id;
    if ($staff_id !== null && $staff_id <= 0) {
        return [];
    }
    $limit = normalize_page_size($limit, 25);
    $offset = max(0, (int)$offset);
    $stmt = null;

    try {
        $sql = "SELECT o.*, s.full_name as staff_name, c.name as customer_name, sup.name as supplier_name
                FROM `Order` o
                JOIN Staff s ON o.staff_id = s.id
                LEFT JOIN Customer c ON o.customer_id = c.id
                LEFT JOIN Supplier sup ON o.supplier_id = sup.id";
        if ($staff_id === null && $filter_type !== 'all') {
            $sql .= " WHERE o.order_type = ?";
        } elseif ($staff_id !== null && $filter_type === 'all') {
            $sql .= " WHERE o.staff_id = ?";
        } elseif ($staff_id !== null) {
            $sql .= " WHERE o.staff_id = ? AND o.order_type = ?";
        }
        $sql .= " ORDER BY o.order_date DESC, o.id DESC LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Order page prepare failed: ' . $conn->error);
            return [];
        }

        if ($staff_id === null && $filter_type !== 'all') {
            $bound = $stmt->bind_param('sii', $filter_type, $limit, $offset);
        } elseif ($staff_id !== null && $filter_type === 'all') {
            $bound = $stmt->bind_param('iii', $staff_id, $limit, $offset);
        } elseif ($staff_id !== null) {
            $bound = $stmt->bind_param('isii', $staff_id, $filter_type, $limit, $offset);
        } else {
            $bound = $stmt->bind_param('ii', $limit, $offset);
        }
        if (!$bound) {
            error_log('Order page bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Order page execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Order page result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Order page failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function orders_get_by_id($conn, $order_id, $staff_id = null)
{
    $order_id = (int)$order_id;
    if ($order_id <= 0) {
        return null;
    }

    $sql = "SELECT o.*, s.full_name as staff_name,
                                   c.name as customer_name, c.phone as customer_phone, c.email as customer_email, c.address as customer_address,
                                   sup.name as supplier_name, sup.phone as supplier_phone, sup.email as supplier_email, sup.address as supplier_address
                            FROM `Order` o
                            JOIN Staff s ON o.staff_id = s.id
                            LEFT JOIN Customer c ON o.customer_id = c.id
                            LEFT JOIN Supplier sup ON o.supplier_id = sup.id
                            WHERE o.id = ?";
    if ($staff_id !== null) {
        $staff_id = (int)$staff_id;
        if ($staff_id <= 0) {
            return null;
        }
        $sql .= " AND o.staff_id = ?";
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Order lookup prepare failed: ' . $conn->error);
            return null;
        }
        if ($staff_id === null) {
            if (!$stmt->bind_param('i', $order_id)) {
                error_log('Order lookup bind failed: ' . $stmt->error);
                return null;
            }
        } elseif (!$stmt->bind_param('ii', $order_id, $staff_id)) {
            error_log('Scoped order lookup bind failed: ' . $stmt->error);
            return null;
        }
        if (!$stmt->execute()) {
            error_log('Order lookup execute failed: ' . $stmt->error);
            return null;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Order lookup result failed: ' . $stmt->error);
            return null;
        }
        return $result->fetch_assoc() ?: null;
    } catch (Throwable $exception) {
        error_log('Order lookup failed: ' . $exception->getMessage());
        return null;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function orders_get_details($conn, $order_id, $staff_id = null)
{
    $order_id = (int)$order_id;
    if ($order_id <= 0) {
        return [];
    }

    $sql = "SELECT od.*, p.name as product_name
                           FROM OrderDetail od
                           JOIN Product p ON od.product_id = p.id
                           WHERE od.order_id = ?";
    if ($staff_id !== null) {
        $staff_id = (int)$staff_id;
        if ($staff_id <= 0) {
            return [];
        }
        $sql = "SELECT od.*, p.name as product_name
                FROM OrderDetail od
                JOIN Product p ON od.product_id = p.id
                JOIN `Order` o ON o.id = od.order_id
                WHERE od.order_id = ? AND o.staff_id = ?";
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('Order detail lookup prepare failed: ' . $conn->error);
            return [];
        }
        if ($staff_id === null) {
            if (!$stmt->bind_param('i', $order_id)) {
                error_log('Order detail lookup bind failed: ' . $stmt->error);
                return [];
            }
        } elseif (!$stmt->bind_param('ii', $order_id, $staff_id)) {
            error_log('Scoped order detail lookup bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Order detail lookup execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Order detail lookup result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Order detail lookup failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
