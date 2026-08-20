<?php

// Compatibility facade: legacy pages continue to require this file while
// cohesive shared services live in dedicated modules.
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/pagination.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/products.php';

/**
 * Basic sanitization for general text inputs.
 */
function sanitize_input($data)
{
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Strict sanitization for email addresses.
 */
function sanitize_email($email)
{
    $email = trim($email);
    return filter_var($email, FILTER_SANITIZE_EMAIL);
}

/**
 * Strict sanitization for phone numbers (keeps only digits, +, and spaces).
 */
function sanitize_phone($phone)
{
    return preg_replace('/[^0-9+\s-]/', '', trim($phone));
}

/**
 * Strict sanitization for numeric IDs.
 */
function sanitize_id($id)
{
    return filter_var($id, FILTER_VALIDATE_INT) !== false ? (int)$id : 0;
}

/**
 * Applies the single password policy used by every password-changing flow.
 */
function password_meets_policy($password)
{
    if (!is_string($password)) {
        return false;
    }

    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    return $length >= 12;
}

/**
 * Normalize the login identifier used by authentication and rate limiting.
 * This is deliberately not sanitize_input(), which is an output-encoding
 * helper and must not change the value used for an authentication lookup.
 */
function normalize_login_identifier($username)
{
    if (!is_string($username)) {
        return '';
    }

    $username = trim($username);
    return function_exists('mb_strtolower')
        ? mb_strtolower($username, 'UTF-8')
        : strtolower($username);
}

function build_login_rate_limit_key($username, $source_ip)
{
    if (!is_string($source_ip) || filter_var($source_ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    return [
        'username_hash' => hash('sha256', normalize_login_identifier($username)),
        'ip_address' => $source_ip,
    ];
}

function login_rate_limit_log_failure($operation, $database_error = '')
{
    $message = 'Login rate limiter ' . $operation . ' failed.';
    if ($database_error !== '') {
        $message .= ' ' . $database_error;
    }

    error_log($message);
}

function login_rate_limit_begin_transaction($conn, $operation)
{
    try {
        if (!$conn->begin_transaction()) {
            login_rate_limit_log_failure($operation . ' transaction start', $conn->error);
            return false;
        }
    } catch (Throwable $exception) {
        login_rate_limit_log_failure($operation . ' transaction start', $exception->getMessage());
        return false;
    }

    return true;
}

function login_rate_limit_rollback($conn, $operation)
{
    try {
        if (!$conn->rollback()) {
            login_rate_limit_log_failure($operation . ' rollback', $conn->error);
        }
    } catch (Throwable $exception) {
        login_rate_limit_log_failure($operation . ' rollback', $exception->getMessage());
    }
}

function login_rate_limit_cleanup_expired($conn)
{
    $cleanup_queries = [
        "DELETE FROM LoginRateLimit
         WHERE blocked_until IS NOT NULL
           AND blocked_until <= UTC_TIMESTAMP()
         LIMIT 100",
        "DELETE FROM LoginRateLimit
         WHERE blocked_until IS NULL
           AND last_attempt_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
         LIMIT 100",
    ];

    foreach ($cleanup_queries as $cleanup_query) {
        $stmt = $conn->prepare($cleanup_query);
        if (!$stmt) {
            login_rate_limit_log_failure('cleanup prepare', $conn->error);
            return false;
        }

        if (!$stmt->execute()) {
            login_rate_limit_log_failure('cleanup execute', $stmt->error);
            $stmt->close();
            return false;
        }

        if ($stmt->affected_rows < 0) {
            login_rate_limit_log_failure('cleanup affected-row check', $stmt->error);
            $stmt->close();
            return false;
        }

        $stmt->close();
    }

    return true;
}

function login_rate_limit_check($conn, $rate_limit_key)
{
    if (!is_array($rate_limit_key)) {
        return ['status' => 'error', 'retry_after' => 0];
    }

    if (!login_rate_limit_begin_transaction($conn, 'check')) {
        return ['status' => 'error', 'retry_after' => 0];
    }

    try {
        if (!login_rate_limit_cleanup_expired($conn)) {
            login_rate_limit_rollback($conn, 'check cleanup');
            return ['status' => 'error', 'retry_after' => 0];
        }

        $stmt = $conn->prepare(
            "SELECT failure_count,
                    CASE
                        WHEN blocked_until IS NOT NULL AND blocked_until > UTC_TIMESTAMP()
                        THEN TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), blocked_until)
                        ELSE 0
                    END AS blocked_seconds
             FROM LoginRateLimit
             WHERE username_hash = ? AND ip_address = ?
             LIMIT 1
             FOR UPDATE"
        );
        if (!$stmt) {
            login_rate_limit_log_failure('check prepare', $conn->error);
            login_rate_limit_rollback($conn, 'check prepare');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if (!$stmt->bind_param('ss', $rate_limit_key['username_hash'], $rate_limit_key['ip_address'])) {
            login_rate_limit_log_failure('check bind', $stmt->error);
            $stmt->close();
            login_rate_limit_rollback($conn, 'check bind');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if (!$stmt->execute()) {
            login_rate_limit_log_failure('check execute', $stmt->error);
            $stmt->close();
            login_rate_limit_rollback($conn, 'check execute');
            return ['status' => 'error', 'retry_after' => 0];
        }

        $result = $stmt->get_result();
        if (!$result) {
            login_rate_limit_log_failure('check result', $stmt->error);
            $stmt->close();
            login_rate_limit_rollback($conn, 'check result');
            return ['status' => 'error', 'retry_after' => 0];
        }

        $rate_limit_row = $result->fetch_assoc();
        $result->free();
        $stmt->close();

        if (!$conn->commit()) {
            login_rate_limit_log_failure('check commit', $conn->error);
            login_rate_limit_rollback($conn, 'check commit');
            return ['status' => 'error', 'retry_after' => 0];
        }

        $blocked_seconds = $rate_limit_row ? max(0, (int)$rate_limit_row['blocked_seconds']) : 0;
        $failure_count = $rate_limit_row ? (int)$rate_limit_row['failure_count'] : 0;

        if ($failure_count >= 5 && $blocked_seconds > 0) {
            return ['status' => 'blocked', 'retry_after' => max(1, $blocked_seconds)];
        }

        return ['status' => 'allowed', 'retry_after' => 0];
    } catch (Throwable $exception) {
        login_rate_limit_log_failure('check unexpected failure', $exception->getMessage());
        login_rate_limit_rollback($conn, 'check unexpected failure');
        return ['status' => 'error', 'retry_after' => 0];
    }
}

function login_rate_limit_record_failure($conn, $rate_limit_key)
{
    if (!is_array($rate_limit_key)) {
        return ['status' => 'error', 'retry_after' => 0];
    }

    if (!login_rate_limit_begin_transaction($conn, 'failure')) {
        return ['status' => 'error', 'retry_after' => 0];
    }

    try {
        if (!login_rate_limit_cleanup_expired($conn)) {
            login_rate_limit_rollback($conn, 'failure cleanup');
            return ['status' => 'error', 'retry_after' => 0];
        }

        $upsert = $conn->prepare(
            "INSERT INTO LoginRateLimit
                (username_hash, ip_address, failure_count, first_attempt_at, last_attempt_at)
             VALUES (?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id = id"
        );
        if (!$upsert) {
            login_rate_limit_log_failure('failure upsert prepare', $conn->error);
            login_rate_limit_rollback($conn, 'failure upsert prepare');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if (!$upsert->bind_param('ss', $rate_limit_key['username_hash'], $rate_limit_key['ip_address'])) {
            login_rate_limit_log_failure('failure upsert bind', $upsert->error);
            $upsert->close();
            login_rate_limit_rollback($conn, 'failure upsert bind');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if (!$upsert->execute()) {
            login_rate_limit_log_failure('failure upsert execute', $upsert->error);
            $upsert->close();
            login_rate_limit_rollback($conn, 'failure upsert execute');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if ($upsert->affected_rows < 0) {
            login_rate_limit_log_failure('failure upsert affected-row check', $upsert->error);
            $upsert->close();
            login_rate_limit_rollback($conn, 'failure upsert affected-row check');
            return ['status' => 'error', 'retry_after' => 0];
        }
        $upsert->close();

        $select = $conn->prepare(
            "SELECT failure_count,
                    TIMESTAMPDIFF(SECOND, first_attempt_at, UTC_TIMESTAMP()) AS window_age_seconds,
                    CASE
                        WHEN blocked_until IS NOT NULL AND blocked_until > UTC_TIMESTAMP()
                        THEN TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), blocked_until)
                        ELSE 0
                    END AS blocked_seconds
             FROM LoginRateLimit
             WHERE username_hash = ? AND ip_address = ?
             LIMIT 1
             FOR UPDATE"
        );
        if (!$select) {
            login_rate_limit_log_failure('failure select prepare', $conn->error);
            login_rate_limit_rollback($conn, 'failure select prepare');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if (!$select->bind_param('ss', $rate_limit_key['username_hash'], $rate_limit_key['ip_address'])) {
            login_rate_limit_log_failure('failure select bind', $select->error);
            $select->close();
            login_rate_limit_rollback($conn, 'failure select bind');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if (!$select->execute()) {
            login_rate_limit_log_failure('failure select execute', $select->error);
            $select->close();
            login_rate_limit_rollback($conn, 'failure select execute');
            return ['status' => 'error', 'retry_after' => 0];
        }

        $result = $select->get_result();
        if (!$result) {
            login_rate_limit_log_failure('failure select result', $select->error);
            $select->close();
            login_rate_limit_rollback($conn, 'failure select result');
            return ['status' => 'error', 'retry_after' => 0];
        }

        $rate_limit_row = $result->fetch_assoc();
        $result->free();
        $select->close();

        if (!$rate_limit_row) {
            login_rate_limit_log_failure('failure select returned no row');
            login_rate_limit_rollback($conn, 'failure missing row');
            return ['status' => 'error', 'retry_after' => 0];
        }

        $blocked_seconds = max(0, (int)$rate_limit_row['blocked_seconds']);
        if ((int)$rate_limit_row['failure_count'] >= 5 && $blocked_seconds > 0) {
            if (!$conn->commit()) {
                login_rate_limit_log_failure('blocked failure commit', $conn->error);
                login_rate_limit_rollback($conn, 'blocked failure commit');
                return ['status' => 'error', 'retry_after' => 0];
            }

            return ['status' => 'blocked', 'retry_after' => max(1, $blocked_seconds)];
        }

        $window_age = (int)$rate_limit_row['window_age_seconds'];
        $window_expired = $window_age < 0 || $window_age >= 900;
        $failure_count = $window_expired
            ? 1
            : min(5, (int)$rate_limit_row['failure_count'] + 1);

        if ($window_expired) {
            $update_sql =
                "UPDATE LoginRateLimit
                 SET failure_count = ?,
                     first_attempt_at = UTC_TIMESTAMP(),
                     last_attempt_at = UTC_TIMESTAMP(),
                     blocked_until = NULL
                 WHERE username_hash = ? AND ip_address = ?";
        } elseif ($failure_count >= 5) {
            $update_sql =
                "UPDATE LoginRateLimit
                 SET failure_count = ?,
                     last_attempt_at = UTC_TIMESTAMP(),
                     blocked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
                 WHERE username_hash = ? AND ip_address = ?";
        } else {
            $update_sql =
                "UPDATE LoginRateLimit
                 SET failure_count = ?,
                     last_attempt_at = UTC_TIMESTAMP(),
                     blocked_until = NULL
                 WHERE username_hash = ? AND ip_address = ?";
        }

        $update = $conn->prepare($update_sql);
        if (!$update) {
            login_rate_limit_log_failure('failure update prepare', $conn->error);
            login_rate_limit_rollback($conn, 'failure update prepare');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if (!$update->bind_param('iss', $failure_count, $rate_limit_key['username_hash'], $rate_limit_key['ip_address'])) {
            login_rate_limit_log_failure('failure update bind', $update->error);
            $update->close();
            login_rate_limit_rollback($conn, 'failure update bind');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if (!$update->execute()) {
            login_rate_limit_log_failure('failure update execute', $update->error);
            $update->close();
            login_rate_limit_rollback($conn, 'failure update execute');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if ($update->affected_rows !== 1) {
            login_rate_limit_log_failure('failure update affected-row check', $update->error);
            $update->close();
            login_rate_limit_rollback($conn, 'failure update affected-row check');
            return ['status' => 'error', 'retry_after' => 0];
        }
        $update->close();

        if (!$conn->commit()) {
            login_rate_limit_log_failure('failure commit', $conn->error);
            login_rate_limit_rollback($conn, 'failure commit');
            return ['status' => 'error', 'retry_after' => 0];
        }

        if ($failure_count >= 5) {
            return ['status' => 'blocked', 'retry_after' => 900];
        }

        return ['status' => 'recorded', 'retry_after' => 0];
    } catch (Throwable $exception) {
        login_rate_limit_log_failure('failure unexpected failure', $exception->getMessage());
        login_rate_limit_rollback($conn, 'failure unexpected failure');
        return ['status' => 'error', 'retry_after' => 0];
    }
}

function login_rate_limit_reset($conn, $rate_limit_key)
{
    if (!is_array($rate_limit_key)) {
        return false;
    }

    if (!login_rate_limit_begin_transaction($conn, 'reset')) {
        return false;
    }

    try {
        $stmt = $conn->prepare(
            "DELETE FROM LoginRateLimit WHERE username_hash = ? AND ip_address = ?"
        );
        if (!$stmt) {
            login_rate_limit_log_failure('reset prepare', $conn->error);
            login_rate_limit_rollback($conn, 'reset prepare');
            return false;
        }

        if (!$stmt->bind_param('ss', $rate_limit_key['username_hash'], $rate_limit_key['ip_address'])) {
            login_rate_limit_log_failure('reset bind', $stmt->error);
            $stmt->close();
            login_rate_limit_rollback($conn, 'reset bind');
            return false;
        }

        if (!$stmt->execute()) {
            login_rate_limit_log_failure('reset execute', $stmt->error);
            $stmt->close();
            login_rate_limit_rollback($conn, 'reset execute');
            return false;
        }

        if ($stmt->affected_rows < 0) {
            login_rate_limit_log_failure('reset affected-row check', $stmt->error);
            $stmt->close();
            login_rate_limit_rollback($conn, 'reset affected-row check');
            return false;
        }
        $stmt->close();

        if (!$conn->commit()) {
            login_rate_limit_log_failure('reset commit', $conn->error);
            login_rate_limit_rollback($conn, 'reset commit');
            return false;
        }

        return true;
    } catch (Throwable $exception) {
        login_rate_limit_log_failure('reset unexpected failure', $exception->getMessage());
        login_rate_limit_rollback($conn, 'reset unexpected failure');
        return false;
    }
}

function verify_login($redirect_on_failure = true)
{
    global $conn;
    $database = $conn ?? null;
    return auth_verify_login($database, $redirect_on_failure);
}

function redirect($url)
{
    http_redirect($url);
}

function build_product_filter_sql($search, $filter, &$search_pattern)
{
    return catalog_build_product_filter_sql($search, $filter, $search_pattern);
}

function get_all_products($conn)
{
    $sql = "SELECT p.*, c.name as category_name
            FROM Product p
            LEFT JOIN Category c ON p.category_id = c.id
            ORDER BY p.created_at DESC, p.id DESC";
    try {
        $result = $conn->query($sql);

        if (!$result) {
            error_log('Product list query failed: ' . $conn->error);
            return [];
        }

        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Product list query failed: ' . $exception->getMessage());
        return [];
    }
}

/**
 * Returns a bounded product set for the POS. Search is optional so the POS
 * remains fast for normal selection while barcode/name lookups never require
 * loading the full catalog into memory.
 */
function get_pos_products($conn, $search = '', $limit = 100)
{
    return catalog_get_pos_products($conn, $search, $limit);
}

function get_pos_product_by_barcode($conn, $barcode)
{
    return catalog_get_pos_product_by_barcode($conn, $barcode);
}

function count_products($conn, $search = '', $filter = '')
{
    return catalog_count_products($conn, $search, $filter);
}

function get_products_page($conn, $search = '', $filter = '', $limit = 25, $offset = 0)
{
    return catalog_get_products_page($conn, $search, $filter, $limit, $offset);
}

function get_product_by_id($conn, $id)
{
    return catalog_get_product_by_id($conn, $id);
}

function get_low_stock_products($conn, $limit = 100)
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

function log_stock_movement($conn, $product_id, $staff_id, $quantity, $movement_type, $reason = null)
{
    return inventory_log_stock_movement($conn, $product_id, $staff_id, $quantity, $movement_type, $reason);
}

function get_stock_movements($conn, $product_id = null)
{
    if ($product_id !== null) {
        $stmt = null;
        try {
            $stmt = $conn->prepare(
                "SELECT sm.*, p.name as product_name, s.full_name as staff_name
                 FROM `StockMovement` sm
                 JOIN Product p ON sm.product_id = p.id
                 JOIN Staff s ON sm.staff_id = s.id
                 WHERE sm.product_id = ?
                 ORDER BY sm.created_at DESC, sm.id DESC"
            );
            if (!$stmt) {
                error_log('Scoped stock movement prepare failed: ' . $conn->error);
                return [];
            }
            if (!$stmt->bind_param('i', $product_id)) {
                error_log('Scoped stock movement bind failed: ' . $stmt->error);
                return [];
            }
            if (!$stmt->execute()) {
                error_log('Scoped stock movement execute failed: ' . $stmt->error);
                return [];
            }
            $result = $stmt->get_result();
            if (!$result) {
                error_log('Scoped stock movement result failed: ' . $stmt->error);
                return [];
            }
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $exception) {
            error_log('Scoped stock movement query failed: ' . $exception->getMessage());
            return [];
        } finally {
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
        }
    } else {
        $sql = "SELECT sm.*, p.name as product_name, s.full_name as staff_name 
                FROM `StockMovement` sm
                JOIN Product p ON sm.product_id = p.id
                JOIN Staff s ON sm.staff_id = s.id
                ORDER BY sm.created_at DESC, sm.id DESC";
        try {
            $result = $conn->query($sql);
            if (!$result) {
                error_log('Stock movement query failed: ' . $conn->error);
                return [];
            }
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $exception) {
            error_log('Stock movement query failed: ' . $exception->getMessage());
            return [];
        }
    }
}

function count_stock_movements($conn, $product_id = null)
{
    return inventory_count_stock_movements($conn, $product_id);
}

function get_stock_movements_page($conn, $product_id = null, $limit = 25, $offset = 0)
{
    return inventory_get_stock_movements_page($conn, $product_id, $limit, $offset);
}

function create_product($conn, $staff_id, $name, $description, $price, $stock, $image_path = null, $alert_threshold = 10, $category_id = null, $barcode = null)
{
    return products_create($conn, $staff_id, $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $barcode);
}

function update_product($conn, $staff_id, $id, $name, $description, $price, $stock, $image_path = null, $alert_threshold = 10, $category_id = null, $barcode = null)
{
    return products_update($conn, $staff_id, $id, $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $barcode);
}

function delete_product($conn, $id, $actor_staff_id = null)
{
    return products_delete($conn, $id, $actor_staff_id);
}

function create_order($conn, $staff_id, $items, $order_type = 'sale', $customer_id = null, $supplier_id = null)
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
            if (!log_stock_movement(
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
                    error_log('create_order rollback failed: ' . $conn->error);
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

function get_orders($conn)
{
    $sql = "SELECT o.*, s.full_name as staff_name, c.name as customer_name, sup.name as supplier_name 
            FROM `Order` o 
            JOIN Staff s ON o.staff_id = s.id 
            LEFT JOIN Customer c ON o.customer_id = c.id
            LEFT JOIN Supplier sup ON o.supplier_id = sup.id
            ORDER BY o.order_date DESC";
    try {
        $result = $conn->query($sql);
        if (!$result) {
            error_log('Order list query failed: ' . $conn->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Order list query failed: ' . $exception->getMessage());
        return [];
    }
}

function get_orders_for_staff($conn, $staff_id)
{
    $staff_id = (int)$staff_id;
    if ($staff_id <= 0) {
        return [];
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare(
            "SELECT o.*, s.full_name as staff_name, c.name as customer_name, sup.name as supplier_name
             FROM `Order` o
             JOIN Staff s ON o.staff_id = s.id
             LEFT JOIN Customer c ON o.customer_id = c.id
             LEFT JOIN Supplier sup ON o.supplier_id = sup.id
             WHERE o.staff_id = ?
             ORDER BY o.order_date DESC"
        );
        if (!$stmt) {
            error_log('Scoped order list prepare failed: ' . $conn->error);
            return [];
        }
        if (!$stmt->bind_param('i', $staff_id)) {
            error_log('Scoped order list bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Scoped order list execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Scoped order list result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Scoped order list query failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function count_orders($conn, $staff_id = null, $filter_type = 'all')
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

function get_order_summary($conn, $staff_id = null, $filter_type = 'all')
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

function get_orders_page($conn, $staff_id = null, $filter_type = 'all', $limit = 25, $offset = 0)
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

function get_order_by_id($conn, $order_id, $staff_id = null)
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

function get_order_details($conn, $order_id, $staff_id = null)
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

function get_dashboard_stats($conn, $staff_id = null)
{
    $stats = [
        'total_products' => 0,
        'total_orders'   => 0,
        'total_sales'    => 0.0,
        'total_stock'    => 0
    ];

    try {
        $result = $conn->query("SELECT COUNT(*) as count FROM Product");
        if ($result) {
            $stats['total_products'] = (int) $result->fetch_assoc()['count'];
        } else {
            error_log('Dashboard product count query failed: ' . $conn->error);
        }

        if ($staff_id === null) {
            $result = $conn->query("SELECT COUNT(*) as count FROM `Order`");
            if ($result) {
                $stats['total_orders'] = (int) $result->fetch_assoc()['count'];
            } else {
                error_log('Dashboard order count query failed: ' . $conn->error);
            }

            // Only count revenue from SALES, not purchases.
            $result = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM `Order` WHERE order_type = 'sale'");
            if ($result) {
                $stats['total_sales'] = (float) $result->fetch_assoc()['total'];
            } else {
                error_log('Dashboard sales total query failed: ' . $conn->error);
            }
        } else {
            // Cashier dashboards must not aggregate another staff member's orders.
            $staff_id = (int)$staff_id;

            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM `Order` WHERE staff_id = ?");
            if ($stmt) {
                if (!$stmt->bind_param('i', $staff_id)) {
                    error_log('Dashboard scoped order count bind failed: ' . $stmt->error);
                } elseif (!$stmt->execute()) {
                    error_log('Dashboard scoped order count execute failed: ' . $stmt->error);
                } else {
                    $result = $stmt->get_result();
                    if ($result) {
                        $stats['total_orders'] = (int)$result->fetch_assoc()['count'];
                    } else {
                        error_log('Dashboard scoped order count result failed: ' . $stmt->error);
                    }
                }
                $stmt->close();
            } else {
                error_log('Dashboard scoped order count prepare failed: ' . $conn->error);
            }

            // Only count this cashier's sales, not purchases or other staff orders.
            $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM `Order` WHERE order_type = 'sale' AND staff_id = ?");
            if ($stmt) {
                if (!$stmt->bind_param('i', $staff_id)) {
                    error_log('Dashboard scoped sales total bind failed: ' . $stmt->error);
                } elseif (!$stmt->execute()) {
                    error_log('Dashboard scoped sales total execute failed: ' . $stmt->error);
                } else {
                    $result = $stmt->get_result();
                    if ($result) {
                        $stats['total_sales'] = (float)$result->fetch_assoc()['total'];
                    } else {
                        error_log('Dashboard scoped sales total result failed: ' . $stmt->error);
                    }
                }
                $stmt->close();
            } else {
                error_log('Dashboard scoped sales total prepare failed: ' . $conn->error);
            }
        }

        $result = $conn->query("SELECT COALESCE(SUM(stock), 0) as total FROM Product");
        if ($result) {
            $stats['total_stock'] = (int) $result->fetch_assoc()['total'];
        } else {
            error_log('Dashboard stock total query failed: ' . $conn->error);
        }
    } catch (Throwable $exception) {
        error_log('Dashboard stats query failed: ' . $exception->getMessage());
    }

    return $stats;
}

function handle_image_upload($file)
{
    $max_file_size = 5 * 1024 * 1024;
    $max_image_width = 4096;
    $max_image_height = 4096;
    $max_image_pixels = 16 * 1024 * 1024;
    $target_file = null;

    if (!is_array($file)) {
        return false;
    }

    $upload_error = $file['error'] ?? null;
    $temporary_file = $file['tmp_name'] ?? null;
    if (!is_int($upload_error) && !(is_string($upload_error) && ctype_digit($upload_error))) {
        return false;
    }
    $upload_error = (int)$upload_error;

    // Validate the server-reported upload result and reject malformed/non-uploaded files.
    if ($upload_error !== UPLOAD_ERR_OK || !is_string($temporary_file) || $temporary_file === '' || !is_uploaded_file($temporary_file)) {
        return false;
    }

    $actual_file_size = @filesize($temporary_file);
    if ($actual_file_size === false || $actual_file_size <= 0 || $actual_file_size > $max_file_size) {
        return false;
    }

    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif'
    ];

    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $temporary_file);
    } catch (Throwable $exception) {
        error_log('Image upload MIME inspection failed.');
        return false;
    }

    if (!is_string($mime_type) || !array_key_exists($mime_type, $allowed_mimes)) {
        return false;
    }

    // Validate image structure and impose dimensions/pixel-count limits before storing it.
    $image_info = @getimagesize($temporary_file);
    if (!is_array($image_info)) {
        return false;
    }

    $image_width = isset($image_info[0]) ? (int)$image_info[0] : 0;
    $image_height = isset($image_info[1]) ? (int)$image_info[1] : 0;
    $image_mime = isset($image_info['mime']) ? (string)$image_info['mime'] : '';
    if ($image_width <= 0 || $image_height <= 0 || $image_width > $max_image_width || $image_height > $max_image_height || ($image_width * $image_height) > $max_image_pixels || !array_key_exists($image_mime, $allowed_mimes)) {
        return false;
    }

    $public_directory = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public');
    if ($public_directory === false) {
        error_log('Image upload failed: public directory could not be resolved.');
        return false;
    }

    $upload_directory = $public_directory . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($upload_directory) && !mkdir($upload_directory, 0755, true) && !is_dir($upload_directory)) {
        error_log('Image upload failed: upload directory could not be created.');
        return false;
    }

    $resolved_upload_directory = realpath($upload_directory);
    if ($resolved_upload_directory === false || strtolower($resolved_upload_directory) !== strtolower($upload_directory)) {
        error_log('Image upload failed: upload directory path validation failed.');
        return false;
    }

    try {
        $extension = $allowed_mimes[$mime_type];
        do {
            $new_filename = bin2hex(random_bytes(16)) . '.' . $extension;
            $target_file = $resolved_upload_directory . DIRECTORY_SEPARATOR . $new_filename;
        } while (file_exists($target_file));
    } catch (Throwable $exception) {
        error_log('Image upload failed: secure filename generation failed.');
        return false;
    }

    if (!move_uploaded_file($temporary_file, $target_file)) {
        if (is_file($target_file)) {
            @unlink($target_file);
        }
        return false;
    }

    // Store the document-root-relative URL, never the absolute filesystem path.
    return 'uploads/' . $new_filename;
}

/**
 * Delete only an image created by handle_image_upload() during the current request.
 * Invalid paths and paths outside the canonical public/uploads directory are ignored.
 */
function delete_newly_uploaded_image($relative_path)
{
    if (!is_string($relative_path) || preg_match('#\Auploads/[a-f0-9]{32}\.(?:jpe?g|png|gif)\z#D', $relative_path) !== 1) {
        return false;
    }

    $public_directory = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public');
    if ($public_directory === false) {
        return false;
    }

    $upload_directory = $public_directory . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($upload_directory)) {
        return true;
    }

    $resolved_upload_directory = realpath($upload_directory);
    if ($resolved_upload_directory === false || strtolower($resolved_upload_directory) !== strtolower($upload_directory)) {
        return false;
    }

    $filename = substr($relative_path, strlen('uploads/'));
    $resolved_target = realpath($resolved_upload_directory . DIRECTORY_SEPARATOR . $filename);
    if ($resolved_target === false) {
        return true;
    }

    if (strtolower(dirname($resolved_target)) !== strtolower($resolved_upload_directory) || !is_file($resolved_target)) {
        return false;
    }

    // The path has been validated and resolved inside public/uploads.
    return @unlink($resolved_target) || !file_exists($resolved_target);
}

/**
 * Fetches sales and purchase chart data for the last N days.
 * Pads missing dates with 0.0 values.
 */
function get_chart_data($conn, $days = 7, $staff_id = null)
{
    $days = max(1, min((int)$days, 31));
    $data = [];
    
    // Generate empty values for the last N days to ensure complete timeline
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $data[$date] = [
            'label' => date('M d', strtotime($date)),
            'sales' => 0.0,
            'purchases' => 0.0
        ];
    }
    
    // Fetch aggregated daily sales and purchases
    $query = "SELECT DATE(order_date) as order_day, order_type, SUM(total_amount) as total 
              FROM `Order` 
              WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    if ($staff_id !== null) {
        $query .= " AND staff_id = ?";
    }
    $query .= " GROUP BY DATE(order_date), order_type
                ORDER BY DATE(order_date) ASC";
              
    $stmt = null;
    try {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log('Chart data prepare failed: ' . $conn->error);
            return array_values($data);
        }
        if ($staff_id === null) {
            if (!$stmt->bind_param('i', $days)) {
                error_log('Chart data bind failed: ' . $stmt->error);
                return array_values($data);
            }
        } else {
            $staff_id = (int)$staff_id;
            if (!$stmt->bind_param('ii', $days, $staff_id)) {
                error_log('Scoped chart data bind failed: ' . $stmt->error);
                return array_values($data);
            }
        }
        if (!$stmt->execute()) {
            error_log('Chart data execute failed: ' . $stmt->error);
            return array_values($data);
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Chart data result failed: ' . $stmt->error);
            return array_values($data);
        }
        while ($row = $result->fetch_assoc()) {
            $day = $row['order_day'];
            if (isset($data[$day])) {
                if ($row['order_type'] === 'sale') {
                    $data[$day]['sales'] = floatval($row['total']);
                } else if ($row['order_type'] === 'purchase') {
                    $data[$day]['purchases'] = floatval($row['total']);
                }
            }
        }
    } catch (Throwable $exception) {
        error_log('Chart data query failed: ' . $exception->getMessage());
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
    
    return array_values($data);
}

/**
 * Checks if the current session belongs to an administrator.
 */
function is_admin()
{
    global $conn;
    $database = $conn ?? null;
    return auth_is_admin($database);
}

/**
 * Enforces administrator privileges with a non-leaking forbidden response.
 */
function require_admin()
{
    global $conn;
    $database = $conn ?? null;
    auth_require_admin($database);
}

/**
 * Retrieves a bounded staff account list for the settings view.
 */
function get_staff_members($conn, $limit = 100, $offset = 0)
{
    $limit = normalize_page_size($limit, 100, [25, 50, 100]);
    $offset = max(0, (int)$offset);
    $stmt = null;
    try {
        $stmt = $conn->prepare(
            "SELECT id, username, full_name, role, is_active, created_at
             FROM Staff
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?"
        );
        if (!$stmt) {
            error_log('Staff list prepare failed: ' . $conn->error);
            return [];
        }
        if (!$stmt->bind_param('ii', $limit, $offset)) {
            error_log('Staff list bind failed: ' . $stmt->error);
            return [];
        }
        if (!$stmt->execute()) {
            error_log('Staff list execute failed: ' . $stmt->error);
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Staff list result failed: ' . $stmt->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Staff list query failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Inserts a new staff account into the database.
 */
function create_staff_member($conn, $username, $password, $full_name, $role)
{
    $username = trim($username);
    $full_name = trim($full_name);
    $role = trim($role);

    if (
        empty($username)
        || !password_meets_policy($password)
        || empty($full_name)
        || !in_array($role, ['admin', 'cashier'], true)
    ) {
        return false;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    if ($hashed_password === false) {
        return false;
    }
    $stmt = null;
    try {
        $stmt = $conn->prepare("INSERT INTO Staff (username, password, full_name, role) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            error_log('Staff insert prepare failed: ' . $conn->error);
            return false;
        }

        if (!$stmt->bind_param('ssss', $username, $hashed_password, $full_name, $role)) {
            error_log('Staff insert bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Staff insert execute failed: ' . $stmt->error);
            return false;
        }
        if ($stmt->affected_rows !== 1) {
            error_log('Staff insert affected an unexpected number of rows.');
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Staff creation failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Modifies an existing staff account's credentials and role.
 */
function update_staff_member($conn, $id, $username, $full_name, $role, $password = null)
{
    $id = (int)$id;
    $username = trim($username);
    $full_name = trim($full_name);
    $role = trim($role);

    if ($id <= 0 || empty($username) || empty($full_name) || !in_array($role, ['admin', 'cashier'], true)) {
        return false;
    }

    if ($password !== null && $password !== '' && !password_meets_policy($password)) {
        return false;
    }

    try {
        if (!$conn->begin_transaction()) {
            error_log('update_staff_member failed: unable to start transaction.');
            return false;
        }
    } catch (Throwable $exception) {
        error_log('update_staff_member transaction start failed: ' . $exception->getMessage());
        return false;
    }

    $transaction_started = true;
    $target_stmt = null;
    $admins_stmt = null;
    $update_stmt = null;
    try {
        $target_stmt = $conn->prepare("SELECT role, is_active FROM Staff WHERE id = ? FOR UPDATE");
        if (!$target_stmt) {
            throw new Exception('Failed to prepare target staff query.');
        }
        if (!$target_stmt->bind_param("i", $id)) {
            throw new Exception('Failed to bind target staff query.');
        }
        if (!$target_stmt->execute()) {
            throw new Exception('Failed to load target staff account.');
        }

        $target_result = $target_stmt->get_result();
        if (!$target_result) {
            throw new Exception('Failed to read target staff account.');
        }
        $target = $target_result->fetch_assoc();
        $target_stmt->close();
        $target_stmt = null;

        if (!$target) {
            throw new Exception('Target staff account does not exist.');
        }

        if ($role === 'cashier' && $target['role'] === 'admin' && (int)$target['is_active'] === 1) {
            $admins_stmt = $conn->prepare(
                "SELECT id FROM Staff WHERE role = 'admin' AND is_active = 1 FOR UPDATE"
            );
            if (!$admins_stmt) {
                throw new Exception('Failed to prepare active administrator query.');
            }
            if (!$admins_stmt->execute()) {
                throw new Exception('Failed to lock active administrators.');
            }

            $admins_result = $admins_stmt->get_result();
            if (!$admins_result) {
                throw new Exception('Failed to read active administrators.');
            }
            $active_admin_count = $admins_result->num_rows;
            $admins_stmt->close();
            $admins_stmt = null;

            if ($active_admin_count <= 1) {
                throw new Exception('The last active administrator cannot be demoted.');
            }
        }

        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            if ($hashed_password === false) {
                throw new Exception('Failed to hash staff password.');
            }

            $update_stmt = $conn->prepare("UPDATE Staff SET username = ?, full_name = ?, role = ?, password = ? WHERE id = ?");
            if (!$update_stmt) {
                throw new Exception('Failed to prepare staff update.');
            }
            if (!$update_stmt->bind_param("ssssi", $username, $full_name, $role, $hashed_password, $id)) {
                throw new Exception('Failed to bind staff update.');
            }
        } else {
            $update_stmt = $conn->prepare("UPDATE Staff SET username = ?, full_name = ?, role = ? WHERE id = ?");
            if (!$update_stmt) {
                throw new Exception('Failed to prepare staff update.');
            }
            if (!$update_stmt->bind_param("sssi", $username, $full_name, $role, $id)) {
                throw new Exception('Failed to bind staff update.');
            }
        }

        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update staff account.');
        }
        $update_stmt->close();
        $update_stmt = null;

        if (!$conn->commit()) {
            throw new Exception('Failed to commit staff update.');
        }
        $transaction_started = false;

        return true;
    } catch (Throwable $e) {
        foreach ([$target_stmt, $admins_stmt, $update_stmt] as $open_stmt) {
            if ($open_stmt instanceof mysqli_stmt) {
                $open_stmt->close();
            }
        }
        if ($transaction_started) {
            try {
                if (!$conn->rollback()) {
                    error_log('update_staff_member rollback failed: ' . $conn->error);
                }
            } catch (Throwable $rollback_exception) {
                error_log('update_staff_member rollback failed: ' . $rollback_exception->getMessage());
            }
        }
        error_log('update_staff_member failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Deactivates a staff account without removing historical references.
 */
function delete_staff_member($conn, $id, $current_admin_id)
{
    $id = (int)$id;
    $current_admin_id = (int)$current_admin_id;

    if ($id <= 0 || $id === $current_admin_id) {
        return false;
    }

    try {
        if (!$conn->begin_transaction()) {
            error_log('delete_staff_member failed: unable to start transaction.');
            return false;
        }
    } catch (Throwable $exception) {
        error_log('delete_staff_member transaction start failed: ' . $exception->getMessage());
        return false;
    }

    $transaction_started = true;
    $target_stmt = null;
    $admins_stmt = null;
    $deactivate_stmt = null;
    try {
        $target_stmt = $conn->prepare("SELECT role, is_active FROM Staff WHERE id = ? FOR UPDATE");
        if (!$target_stmt) {
            throw new Exception('Failed to prepare target staff query.');
        }
        if (!$target_stmt->bind_param("i", $id)) {
            throw new Exception('Failed to bind target staff query.');
        }
        if (!$target_stmt->execute()) {
            throw new Exception('Failed to load target staff account.');
        }

        $target_result = $target_stmt->get_result();
        if (!$target_result) {
            throw new Exception('Failed to read target staff account.');
        }
        $target = $target_result->fetch_assoc();
        $target_stmt->close();
        $target_stmt = null;

        if (!$target) {
            throw new Exception('Target staff account does not exist.');
        }

        if ($target['role'] === 'admin' && (int)$target['is_active'] === 1) {
            $admins_stmt = $conn->prepare(
                "SELECT id FROM Staff WHERE role = 'admin' AND is_active = 1 FOR UPDATE"
            );
            if (!$admins_stmt) {
                throw new Exception('Failed to prepare active administrator query.');
            }
            if (!$admins_stmt->execute()) {
                throw new Exception('Failed to lock active administrators.');
            }

            $admins_result = $admins_stmt->get_result();
            if (!$admins_result) {
                throw new Exception('Failed to read active administrators.');
            }
            $active_admin_count = $admins_result->num_rows;
            $admins_stmt->close();
            $admins_stmt = null;

            if ($active_admin_count <= 1) {
                throw new Exception('The last active administrator cannot be deactivated.');
            }
        }

        $deactivate_stmt = $conn->prepare("UPDATE Staff SET is_active = 0 WHERE id = ?");
        if (!$deactivate_stmt) {
            throw new Exception('Failed to prepare staff deactivation.');
        }
        if (!$deactivate_stmt->bind_param("i", $id)) {
            throw new Exception('Failed to bind staff deactivation.');
        }
        if (!$deactivate_stmt->execute()) {
            throw new Exception('Failed to deactivate staff account.');
        }
        $deactivate_stmt->close();
        $deactivate_stmt = null;

        if (!$conn->commit()) {
            throw new Exception('Failed to commit staff deactivation.');
        }
        $transaction_started = false;

        return true;
    } catch (Throwable $e) {
        foreach ([$target_stmt, $admins_stmt, $deactivate_stmt] as $open_stmt) {
            if ($open_stmt instanceof mysqli_stmt) {
                $open_stmt->close();
            }
        }
        if ($transaction_started) {
            try {
                if (!$conn->rollback()) {
                    error_log('delete_staff_member rollback failed: ' . $conn->error);
                }
            } catch (Throwable $rollback_exception) {
                error_log('delete_staff_member rollback failed: ' . $rollback_exception->getMessage());
            }
        }
        error_log('delete_staff_member failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Enables or disables a staff account with administrator safety checks.
 *
 * The transaction locks the target account and active administrator rows so
 * two concurrent requests cannot both disable the last active administrator.
 */
function set_staff_active($conn, $id, $is_active, $current_admin_id)
{
    $id = (int)$id;
    $is_active = (int)$is_active;
    $current_admin_id = (int)$current_admin_id;

    if ($id <= 0 || !in_array($is_active, [0, 1], true) || $id === $current_admin_id) {
        return false;
    }

    try {
        if (!$conn->begin_transaction()) {
            error_log('set_staff_active failed: unable to start transaction.');
            return false;
        }
    } catch (Throwable $exception) {
        error_log('set_staff_active transaction start failed: ' . $exception->getMessage());
        return false;
    }

    $transaction_started = true;
    $target_stmt = null;
    $admins_stmt = null;
    $update_stmt = null;
    try {
        $target_stmt = $conn->prepare("SELECT role, is_active FROM Staff WHERE id = ? FOR UPDATE");
        if (!$target_stmt) {
            throw new Exception('Failed to prepare staff status query.');
        }

        if (!$target_stmt->bind_param('i', $id)) {
            throw new Exception('Failed to bind staff status query.');
        }
        if (!$target_stmt->execute()) {
            throw new Exception('Failed to load staff status.');
        }

        $target_result = $target_stmt->get_result();
        if (!$target_result) {
            throw new Exception('Failed to read staff status.');
        }
        $target = $target_result->fetch_assoc();
        $target_stmt->close();
        $target_stmt = null;

        if (!$target) {
            throw new Exception('Staff account does not exist.');
        }

        if ((int)$target['is_active'] === $is_active) {
            if (!$conn->commit()) {
                throw new Exception('Failed to commit staff status.');
            }
            $transaction_started = false;
            return true;
        }

        if ($is_active === 0 && $target['role'] === 'admin') {
            $admins_stmt = $conn->prepare(
                "SELECT id FROM Staff WHERE role = 'admin' AND is_active = 1 FOR UPDATE"
            );
            if (!$admins_stmt) {
                throw new Exception('Failed to prepare active administrator query.');
            }
            if (!$admins_stmt->execute()) {
                throw new Exception('Failed to verify active administrators.');
            }

            $admins_result = $admins_stmt->get_result();
            if (!$admins_result) {
                throw new Exception('Failed to read active administrators.');
            }
            $active_admin_count = $admins_result->num_rows;
            $admins_stmt->close();
            $admins_stmt = null;

            if ($active_admin_count <= 1) {
                throw new Exception('The last active administrator cannot be disabled.');
            }
        }

        $update_stmt = $conn->prepare("UPDATE Staff SET is_active = ? WHERE id = ?");
        if (!$update_stmt) {
            throw new Exception('Failed to prepare staff status update.');
        }

        if (!$update_stmt->bind_param('ii', $is_active, $id)) {
            throw new Exception('Failed to bind staff status update.');
        }
        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update staff status.');
        }
        $update_stmt->close();
        $update_stmt = null;

        if (!$conn->commit()) {
            throw new Exception('Failed to commit staff status.');
        }
        $transaction_started = false;
        return true;
    } catch (Throwable $e) {
        foreach ([$target_stmt, $admins_stmt, $update_stmt] as $open_stmt) {
            if ($open_stmt instanceof mysqli_stmt) {
                $open_stmt->close();
            }
        }
        if ($transaction_started) {
            try {
                if (!$conn->rollback()) {
                    error_log('set_staff_active rollback failed: ' . $conn->error);
                }
            } catch (Throwable $rollback_exception) {
                error_log('set_staff_active rollback failed: ' . $rollback_exception->getMessage());
            }
        }
        error_log('set_staff_active failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves all product categories.
 */
function get_categories($conn)
{
    $sql = "SELECT c.*, COUNT(p.id) as product_count 
            FROM Category c 
            LEFT JOIN Product p ON c.id = p.category_id 
            GROUP BY c.id 
            ORDER BY c.name ASC";
    try {
        $result = $conn->query($sql);
        if (!$result) {
            error_log('Category list query failed: ' . $conn->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Category list query failed: ' . $exception->getMessage());
        return [];
    }
}

function count_categories($conn, $search = '')
{
    return catalog_count_categories($conn, $search);
}

function get_categories_page($conn, $search = '', $limit = 25, $offset = 0)
{
    return catalog_get_categories_page($conn, $search, $limit, $offset);
}

function get_categories_for_selector($conn, $limit = 100)
{
    return catalog_get_categories_for_selector($conn, $limit);
}

/**
 * Retrieves a specific category by its ID.
 */
function get_category_by_id($conn, $id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return null;
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT * FROM Category WHERE id = ?");
        if (!$stmt) {
            error_log('Category lookup prepare failed: ' . $conn->error);
            return null;
        }
        if (!$stmt->bind_param('i', $id)) {
            error_log('Category lookup bind failed: ' . $stmt->error);
            return null;
        }
        if (!$stmt->execute()) {
            error_log('Category lookup execute failed: ' . $stmt->error);
            return null;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Category lookup result failed: ' . $stmt->error);
            return null;
        }
        return $result->fetch_assoc() ?: null;
    } catch (Throwable $exception) {
        error_log('Category lookup failed: ' . $exception->getMessage());
        return null;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Creates a new product category.
 */
function create_category($conn, $name, $description)
{
    $name = trim($name);
    $description = trim($description);

    if (empty($name)) {
        return false;
    }

    // Verify uniqueness
    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT id FROM Category WHERE name = ? LIMIT 1");
        if (!$stmt) {
            error_log('Category uniqueness prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('s', $name)) {
            error_log('Category uniqueness bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category uniqueness execute failed: ' . $stmt->error);
            return false;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Category uniqueness result failed: ' . $stmt->error);
            return false;
        }
        if ($result->num_rows > 0) {
            return false;
        }
        $stmt->close();
        $stmt = null;

        $stmt = $conn->prepare("INSERT INTO Category (name, description) VALUES (?, ?)");
        if (!$stmt) {
            error_log('Category insert prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('ss', $name, $description)) {
            error_log('Category insert bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category insert execute failed: ' . $stmt->error);
            return false;
        }
        if ($stmt->affected_rows !== 1) {
            error_log('Category insert affected an unexpected number of rows.');
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Category creation failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Updates an existing product category.
 */
function update_category($conn, $id, $name, $description)
{
    $id = (int)$id;
    $name = trim($name);
    $description = trim($description);

    if ($id <= 0 || empty($name)) {
        return false;
    }

    // Check if another category has the same name
    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT id FROM Category WHERE name = ? AND id != ? LIMIT 1");
        if (!$stmt) {
            error_log('Category update uniqueness prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('si', $name, $id)) {
            error_log('Category update uniqueness bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category update uniqueness execute failed: ' . $stmt->error);
            return false;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Category update uniqueness result failed: ' . $stmt->error);
            return false;
        }
        if ($result->num_rows > 0) {
            return false;
        }
        $stmt->close();
        $stmt = null;

        // Check if category is 'General' and we are trying to rename it.
        $stmt = $conn->prepare("SELECT name FROM Category WHERE id = ?");
        if (!$stmt) {
            error_log('Category update lookup prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('i', $id)) {
            error_log('Category update lookup bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category update lookup execute failed: ' . $stmt->error);
            return false;
        }
        $old_name_result = $stmt->get_result();
        if (!$old_name_result) {
            error_log('Category update lookup result failed: ' . $stmt->error);
            return false;
        }
        $old_name_res = $old_name_result->fetch_assoc();
        $stmt->close();
        $stmt = null;

        if ($old_name_res && $old_name_res['name'] === 'General' && $name !== 'General') {
            return false;
        }

        $stmt = $conn->prepare("UPDATE Category SET name = ?, description = ? WHERE id = ?");
        if (!$stmt) {
            error_log('Category update prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('ssi', $name, $description, $id)) {
            error_log('Category update bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category update execute failed: ' . $stmt->error);
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Category update failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Deletes a category and handles reassigning of products.
 */
function delete_category($conn, $id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return false;
    }

    // Prevent deleting the default category
    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT name FROM Category WHERE id = ?");
        if (!$stmt) {
            error_log('Category deletion lookup prepare failed: ' . $conn->error);
            return false;
        }
        if (!$stmt->bind_param('i', $id)) {
            error_log('Category deletion lookup bind failed: ' . $stmt->error);
            return false;
        }
        if (!$stmt->execute()) {
            error_log('Category deletion lookup execute failed: ' . $stmt->error);
            return false;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Category deletion lookup result failed: ' . $stmt->error);
            return false;
        }
        $res = $result->fetch_assoc();
    } catch (Throwable $exception) {
        error_log('Category deletion lookup failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }

    if (!$res || $res['name'] === 'General') {
        return false;
    }

    $transaction_started = false;
    $delete_stmt = null;
    $general_stmt = null;
    $reassign_stmt = null;
    try {
        if (!$conn->begin_transaction()) {
            error_log('delete_category failed: unable to start transaction.');
            return false;
        }
        $transaction_started = true;

        $general_stmt = $conn->prepare("SELECT id FROM Category WHERE name = 'General' LIMIT 1");
        if (!$general_stmt) {
            throw new Exception('Failed to prepare default category lookup.');
        }
        if (!$general_stmt->execute()) {
            throw new Exception('Failed to load default category.');
        }
        $general_result = $general_stmt->get_result();
        if (!$general_result) {
            throw new Exception('Failed to read default category.');
        }
        $general_row = $general_result->fetch_assoc();
        if (!$general_row) {
            throw new Exception('Default category is missing.');
        }
        $gen_cat_id = (int)$general_row['id'];
        $general_stmt->close();
        $general_stmt = null;

        $delete_stmt = $conn->prepare("DELETE FROM Category WHERE id = ?");
        if (!$delete_stmt) {
            throw new Exception('Failed to prepare category deletion.');
        }
        if (!$delete_stmt->bind_param('i', $id)) {
            throw new Exception('Failed to bind category deletion.');
        }
        if (!$delete_stmt->execute()) {
            throw new Exception('Failed to delete category.');
        }
        if ($delete_stmt->affected_rows !== 1) {
            throw new Exception('Category deletion affected an unexpected number of rows.');
        }
        $delete_stmt->close();
        $delete_stmt = null;

        $reassign_stmt = $conn->prepare(
            "UPDATE Product SET category_id = ? WHERE category_id IS NULL"
        );
        if (!$reassign_stmt) {
            throw new Exception('Failed to prepare product reassignment.');
        }
        if (!$reassign_stmt->bind_param('i', $gen_cat_id)) {
            throw new Exception('Failed to bind product reassignment.');
        }
        if (!$reassign_stmt->execute()) {
            throw new Exception('Failed to reassign products.');
        }
        if ($reassign_stmt->affected_rows < 0) {
            throw new Exception('Product reassignment affected-row check failed.');
        }
        $reassign_stmt->close();
        $reassign_stmt = null;

        if (!$conn->commit()) {
            throw new Exception('Failed to commit category deletion.');
        }
        $transaction_started = false;
        return true;
    } catch (Throwable $e) {
        foreach ([$general_stmt, $delete_stmt, $reassign_stmt] as $open_stmt) {
            if ($open_stmt instanceof mysqli_stmt) {
                $open_stmt->close();
            }
        }
        if ($transaction_started) {
            try {
                if (!$conn->rollback()) {
                    error_log('delete_category rollback failed: ' . $conn->error);
                }
            } catch (Throwable $rollback_exception) {
                error_log('delete_category rollback failed: ' . $rollback_exception->getMessage());
            }
        }
        error_log('delete_category failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch all customers sorted alphabetically by name.
 */
function get_customers($conn)
{
    $sql = "SELECT * FROM Customer ORDER BY name ASC";
    try {
        $result = $conn->query($sql);
        if (!$result) {
            error_log('Customer list query failed: ' . $conn->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Customer list query failed: ' . $exception->getMessage());
        return [];
    }
}

function count_customers($conn, $search = '')
{
    return people_count_customers($conn, $search);
}

function get_customers_page($conn, $search = '', $limit = 25, $offset = 0)
{
    return people_get_customers_page($conn, $search, $limit, $offset);
}

function get_customers_for_selector($conn, $limit = 100)
{
    return people_get_customers_for_selector($conn, $limit);
}

/**
 * Fetch customer details by ID.
 */
function get_customer_by_id($conn, $id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return null;
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT * FROM Customer WHERE id = ?");
        if (!$stmt) {
            error_log('Customer lookup prepare failed: ' . $conn->error);
            return null;
        }
        if (!$stmt->bind_param('i', $id)) {
            error_log('Customer lookup bind failed: ' . $stmt->error);
            return null;
        }
        if (!$stmt->execute()) {
            error_log('Customer lookup execute failed: ' . $stmt->error);
            return null;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Customer lookup result failed: ' . $stmt->error);
            return null;
        }
        return $result->fetch_assoc() ?: null;
    } catch (Throwable $exception) {
        error_log('Customer lookup failed: ' . $exception->getMessage());
        return null;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Creates a new customer record securely.
 */
function create_customer($conn, $name, $phone, $email, $address)
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
function update_customer($conn, $id, $name, $phone, $email, $address)
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
function delete_customer($conn, $id)
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

/**
 * Fetch all suppliers sorted alphabetically by name.
 */
function get_suppliers($conn)
{
    $sql = "SELECT * FROM Supplier ORDER BY name ASC";
    try {
        $result = $conn->query($sql);
        if (!$result) {
            error_log('Supplier list query failed: ' . $conn->error);
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $exception) {
        error_log('Supplier list query failed: ' . $exception->getMessage());
        return [];
    }
}

function count_suppliers($conn, $search = '')
{
    return people_count_suppliers($conn, $search);
}

function get_suppliers_page($conn, $search = '', $limit = 25, $offset = 0)
{
    return people_get_suppliers_page($conn, $search, $limit, $offset);
}

function get_suppliers_for_selector($conn, $limit = 100)
{
    return people_get_suppliers_for_selector($conn, $limit);
}

/**
 * Fetch supplier details by ID.
 */
function get_supplier_by_id($conn, $id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return null;
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare("SELECT * FROM Supplier WHERE id = ?");
        if (!$stmt) {
            error_log('Supplier lookup prepare failed: ' . $conn->error);
            return null;
        }
        if (!$stmt->bind_param('i', $id)) {
            error_log('Supplier lookup bind failed: ' . $stmt->error);
            return null;
        }
        if (!$stmt->execute()) {
            error_log('Supplier lookup execute failed: ' . $stmt->error);
            return null;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Supplier lookup result failed: ' . $stmt->error);
            return null;
        }
        return $result->fetch_assoc() ?: null;
    } catch (Throwable $exception) {
        error_log('Supplier lookup failed: ' . $exception->getMessage());
        return null;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/**
 * Creates a new supplier record securely.
 */
function create_supplier($conn, $name, $phone, $email, $address)
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
function update_supplier($conn, $id, $name, $phone, $email, $address)
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
function delete_supplier($conn, $id)
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

/**
 * Compute inventory valuation based on current stock levels and unit prices.
 */
function get_inventory_valuation($conn)
{
    $sql = "SELECT SUM(stock * price) as valuation FROM Product";
    try {
        $result = $conn->query($sql);
        if ($result) {
            $row = $result->fetch_assoc();
            return (float)($row['valuation'] ?? 0.0);
        }
        error_log('Inventory valuation query failed: ' . $conn->error);
    } catch (Throwable $exception) {
        error_log('Inventory valuation query failed: ' . $exception->getMessage());
    }
    return 0.0;
}

/**
 * Fetch top selling products sorted by total quantity sold.
 */
function get_top_selling_products($conn, $limit = 5, $staff_id = null)
{
    $limit = max(1, min((int)$limit, 50));
    $sql = "SELECT p.name, SUM(od.quantity) as total_qty, SUM(od.subtotal) as total_sales 
            FROM OrderDetail od 
            JOIN `Order` o ON od.order_id = o.id 
            JOIN Product p ON od.product_id = p.id 
            WHERE o.order_type = 'sale'";
    if ($staff_id !== null) {
        $sql .= " AND o.staff_id = ?";
    }
    $sql .= " GROUP BY od.product_id, p.name
              ORDER BY total_qty DESC
              LIMIT ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Top-selling products prepare failed: ' . $conn->error);
        return [];
    }
    if ($staff_id === null) {
        if (!$stmt->bind_param('i', $limit)) {
            error_log('Top-selling products bind failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
    } else {
        $staff_id = (int)$staff_id;
        if (!$stmt->bind_param('ii', $staff_id, $limit)) {
            error_log('Scoped top-selling products bind failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
    }
    if (!$stmt->execute()) {
        error_log('Top-selling products execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    if (!$result) {
        error_log('Top-selling products result failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $products = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $products;
}

/**
 * Group sales volume distributions based on product categories.
 */
function get_category_sales_distribution($conn, $staff_id = null, $limit = 100)
{
    $limit = normalize_page_size($limit, 100, [25, 50, 100]);
    $sql = "SELECT COALESCE(c.name, 'Uncategorized') as category_name, SUM(od.subtotal) as total_sales 
            FROM OrderDetail od 
            JOIN `Order` o ON od.order_id = o.id 
            JOIN Product p ON od.product_id = p.id 
            LEFT JOIN Category c ON p.category_id = c.id 
            WHERE o.order_type = 'sale'";
    if ($staff_id !== null) {
        $sql .= " AND o.staff_id = ?";
    }
    $sql .= " GROUP BY p.category_id, c.name
              ORDER BY total_sales DESC
              LIMIT ?";

    if ($staff_id === null) {
        $stmt = null;
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log('Category sales distribution prepare failed: ' . $conn->error);
                return [];
            }
            if (!$stmt->bind_param('i', $limit)) {
                error_log('Category sales distribution bind failed: ' . $stmt->error);
                return [];
            }
            if (!$stmt->execute()) {
                error_log('Category sales distribution execute failed: ' . $stmt->error);
                return [];
            }
            $result = $stmt->get_result();
            if (!$result) {
                error_log('Category sales distribution result failed: ' . $stmt->error);
                return [];
            }
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $exception) {
            error_log('Category sales distribution query failed: ' . $exception->getMessage());
            return [];
        } finally {
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
        }
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Scoped category sales distribution prepare failed: ' . $conn->error);
        return [];
    }
    $staff_id = (int)$staff_id;
    if (!$stmt->bind_param('ii', $staff_id, $limit)) {
        error_log('Scoped category sales distribution bind failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    if (!$stmt->execute()) {
        error_log('Scoped category sales distribution execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    if (!$result) {
        error_log('Scoped category sales distribution result failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $categories = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $categories;
}
