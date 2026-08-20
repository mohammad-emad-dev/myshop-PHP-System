<?php

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/http.php';

/**
 * Validate the active-state and role fields returned by the Staff lookup.
 */
function auth_staff_record_is_active_with_supported_role($staff)
{
    return is_array($staff)
        && (int)($staff['is_active'] ?? 0) === 1
        && in_array($staff['role'] ?? null, ['admin', 'cashier'], true);
}

/**
 * Revalidate the authenticated staff session against the active Staff record.
 */
function auth_verify_login($conn, $redirect_on_failure = true)
{
    start_secure_session();

    $fail_authentication = static function ($reason) use ($redirect_on_failure) {
        if ($reason !== null) {
            error_log('Authentication session invalidated: ' . $reason);
        }

        destroy_current_session();

        if ($redirect_on_failure) {
            http_redirect('login.php');
        }

        return false;
    };

    if (!isset($_SESSION['staff_id'])) {
        return $fail_authentication(null);
    }

    $staff_id = filter_var($_SESSION['staff_id'], FILTER_VALIDATE_INT);
    if ($staff_id === false || $staff_id <= 0) {
        return $fail_authentication('The session contains an invalid staff identifier.');
    }

    if (!($conn instanceof mysqli)) {
        return $fail_authentication('The database connection is unavailable.');
    }

    $stmt = null;
    try {
        $stmt = $conn->prepare(
            "SELECT id, full_name, role, is_active FROM Staff WHERE id = ? LIMIT 1"
        );

        if (!$stmt) {
            error_log('Authentication staff lookup prepare failed: ' . $conn->error);
            return $fail_authentication('The staff lookup could not be prepared.');
        }

        if (!$stmt->bind_param('i', $staff_id)) {
            error_log('Authentication staff lookup bind failed: ' . $stmt->error);
            return $fail_authentication('The staff lookup could not be bound.');
        }
        if (!$stmt->execute()) {
            error_log('Authentication staff lookup failed: ' . $stmt->error);
            return $fail_authentication('The staff lookup failed.');
        }

        $result = $stmt->get_result();
        if (!$result) {
            error_log('Authentication staff lookup result failed: ' . $stmt->error);
            return $fail_authentication('The staff lookup result failed.');
        }
        $staff = $result->fetch_assoc();
    } catch (Throwable $exception) {
        error_log('Authentication staff lookup failed: ' . $exception->getMessage());
        return $fail_authentication('The staff lookup failed.');
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }

    if (!auth_staff_record_is_active_with_supported_role($staff)) {
        return $fail_authentication('The staff account is missing, disabled, or has an invalid role.');
    }

    $_SESSION['staff_id'] = (int)$staff['id'];
    $_SESSION['full_name'] = $staff['full_name'];
    $_SESSION['role'] = $staff['role'];
    $_SESSION['last_activity'] = time();
    $GLOBALS['current_staff_record'] = $staff;

    return true;
}

/**
 * Check whether the current authenticated staff record is an administrator.
 */
function auth_is_admin($conn)
{
    if (!isset($GLOBALS['current_staff_record']) || !is_array($GLOBALS['current_staff_record'])) {
        if (!auth_verify_login($conn, false)) {
            return false;
        }
    }

    return isset($GLOBALS['current_staff_record']['role'])
        && $GLOBALS['current_staff_record']['role'] === 'admin';
}

/**
 * Enforce administrator privileges with the existing forbidden response.
 */
function auth_require_admin($conn)
{
    auth_verify_login($conn);
    if (!auth_is_admin($conn)) {
        $route = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'admin_route'));
        audit_log_denied($conn, 'admin_route_access', 'Route', null, ['route' => $route]);
        http_response_code(403);
        exit('Access denied.');
    }
}
