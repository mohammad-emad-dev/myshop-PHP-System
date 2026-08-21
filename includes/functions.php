<?php

// Compatibility facade: legacy pages continue to require this file while
// cohesive shared services live in dedicated modules.
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/pagination.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/categories.php';
require_once __DIR__ . '/customers.php';
require_once __DIR__ . '/suppliers.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/uploads.php';

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
    return inventory_get_low_stock_products($conn, $limit);
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
    return orders_create($conn, $staff_id, $items, $order_type, $customer_id, $supplier_id);
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
    return orders_count($conn, $staff_id, $filter_type);
}

function get_order_summary($conn, $staff_id = null, $filter_type = 'all')
{
    return orders_get_summary($conn, $staff_id, $filter_type);
}

function get_orders_page($conn, $staff_id = null, $filter_type = 'all', $limit = 25, $offset = 0)
{
    return orders_get_page($conn, $staff_id, $filter_type, $limit, $offset);
}

function get_order_by_id($conn, $order_id, $staff_id = null)
{
    return orders_get_by_id($conn, $order_id, $staff_id);
}

function get_order_details($conn, $order_id, $staff_id = null)
{
    return orders_get_details($conn, $order_id, $staff_id);
}

function get_dashboard_stats($conn, $staff_id = null)
{
    return dashboard_get_stats($conn, $staff_id);
}

function handle_image_upload($file)
{
    return uploads_handle_image($file);
}

/**
 * Delete only an image created by handle_image_upload() during the current request.
 * Invalid paths and paths outside the canonical public/uploads directory are ignored.
 */
function delete_newly_uploaded_image($relative_path)
{
    return uploads_delete_newly_uploaded_image($relative_path);
}

/**
 * Fetches sales and purchase chart data for the last N days.
 * Pads missing dates with 0.0 values.
 */
function get_chart_data($conn, $days = 7, $staff_id = null)
{
    return dashboard_get_chart_data($conn, $days, $staff_id);
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

function create_category($conn, $name, $description)
{
    return categories_create($conn, $name, $description);
}

function update_category($conn, $id, $name, $description)
{
    return categories_update($conn, $id, $name, $description);
}

/**
 * Backward-compatible category deletion wrapper.
 */
function delete_category($conn, $id)
{
    return categories_delete($conn, $id);
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
 * Backward-compatible customer creation wrapper.
 */
function create_customer($conn, $name, $phone, $email, $address)
{
    return customers_create($conn, $name, $phone, $email, $address);
}

/**
 * Backward-compatible customer update wrapper.
 */
function update_customer($conn, $id, $name, $phone, $email, $address)
{
    return customers_update($conn, $id, $name, $phone, $email, $address);
}

/**
 * Backward-compatible customer deletion wrapper.
 */
function delete_customer($conn, $id)
{
    return customers_delete($conn, $id);
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
    return suppliers_create($conn, $name, $phone, $email, $address);
}

/**
 * Updates supplier details, preventing changes to default General Supplier (ID = 1).
 */
function update_supplier($conn, $id, $name, $phone, $email, $address)
{
    return suppliers_update($conn, $id, $name, $phone, $email, $address);
}

/**
 * Deletes a supplier, preventing deletion of default General Supplier (ID = 1).
 */
function delete_supplier($conn, $id)
{
    return suppliers_delete($conn, $id);
}

/**
 * Compatibility wrapper for the focused Dashboard inventory valuation service.
 */
function get_inventory_valuation($conn)
{
    return dashboard_get_inventory_valuation($conn);
}

/**
 * Compatibility wrapper for the focused Dashboard top-selling service.
 */
function get_top_selling_products($conn, $limit = 5, $staff_id = null)
{
    return dashboard_get_top_selling_products($conn, $limit, $staff_id);
}

/**
 * Compatibility wrapper for the focused Dashboard category-sales service.
 */
function get_category_sales_distribution($conn, $staff_id = null, $limit = 100)
{
    return dashboard_get_category_sales_distribution($conn, $staff_id, $limit);
}
