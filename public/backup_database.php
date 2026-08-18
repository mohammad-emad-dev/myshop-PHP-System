<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

// Query failures are handled explicitly below; do not let mysqli warnings become response data.
mysqli_report(MYSQLI_REPORT_OFF);

/**
 * Record a backup attempt without recording credentials or dump contents.
 */
function log_backup_attempt($staff_id, $success, $reason)
{
    $staff_label = ($staff_id === null) ? 'none' : (string)(int)$staff_id;
    $result_label = $success ? 'success' : 'failure';
    error_log(sprintf(
        'Database backup attempt staff_id=%s timestamp=%s result=%s reason=%s',
        $staff_label,
        gmdate('c'),
        $result_label,
        $reason
    ));
}

/**
 * Send a generic error and stop before a backup response has started.
 */
function abort_backup_request($staff_id, $reason, $status_code)
{
    log_backup_attempt($staff_id, false, $reason);
    http_response_code($status_code);
    exit('Backup is temporarily unavailable.');
}

/**
 * Return the complete canonical project table allow-list.
 */
function get_backup_table_allowlist()
{
    return [
        'Staff',
        'Category',
        'Customer',
        'Supplier',
        'Product',
        'Order',
        'OrderDetail',
        'StockMovement'
    ];
}

/**
 * Quote one of the explicitly supported project table identifiers.
 */
function quote_backup_table($table)
{
    if (!in_array($table, get_backup_table_allowlist(), true)) {
        return false;
    }

    return '`' . str_replace('`', '``', $table) . '`';
}

/**
 * Write all bytes in a chunk to the output stream.
 */
function write_backup_chunk($output, $chunk)
{
    $length = strlen($chunk);
    $offset = 0;

    while ($offset < $length) {
        $written = fwrite($output, substr($chunk, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Backup output failed.');
        }
        $offset += $written;
    }
}

/**
 * Close a failed streamed response without exposing technical details.
 */
function finish_failed_backup_response($output)
{
    if (is_resource($output)) {
        $message = "\n-- BACKUP FAILED: The generated file is incomplete and must not be restored.\n";
        $written = @fwrite($output, $message);
        if ($written === false || $written !== strlen($message)) {
            error_log('Database backup failure marker could not be written.');
        }
        @fclose($output);
        return;
    }

    http_response_code(500);
    exit('Backup is temporarily unavailable.');
}

$session_staff_id = null;
if (isset($_SESSION['staff_id']) && filter_var($_SESSION['staff_id'], FILTER_VALIDATE_INT) !== false) {
    $session_staff_id = (int)$_SESSION['staff_id'];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    abort_backup_request($session_staff_id, 'method_not_allowed', 405);
}

// Use the current database-backed account and active role, not stale session role data.
try {
    $authenticated = verify_login(false);
} catch (Throwable $exception) {
    error_log('Backup authentication failed: ' . $exception->getMessage());
    abort_backup_request($session_staff_id, 'authentication_error', 500);
}

if (!$authenticated) {
    abort_backup_request($session_staff_id, 'authentication_failed', 401);
}

$staff_id = isset($_SESSION['staff_id']) ? (int)$_SESSION['staff_id'] : null;
try {
    $authorized = is_admin();
} catch (Throwable $exception) {
    error_log('Backup authorization failed: ' . $exception->getMessage());
    abort_backup_request($staff_id, 'authorization_error', 500);
}

if (!$authorized) {
    abort_backup_request($staff_id, 'active_admin_authorization_failed', 403);
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!is_string($csrf_token) || !verify_csrf_token($csrf_token)) {
    abort_backup_request($staff_id, 'csrf_validation_failed', 403);
}

$current_password = $_POST['current_password'] ?? '';
if (!is_string($current_password) || $current_password === '') {
    abort_backup_request($staff_id, 'password_reauthentication_missing', 403);
}

// Re-authenticate against the current active administrator record for every request.
$password_stmt = null;
try {
    $password_stmt = $conn->prepare(
        "SELECT password FROM Staff WHERE id = ? AND role = 'admin' AND is_active = 1 LIMIT 1"
    );
    if (!$password_stmt) {
        error_log('Backup password lookup prepare failed: ' . $conn->error);
        abort_backup_request($staff_id, 'password_lookup_prepare_failed', 500);
    }

    if (!$password_stmt->bind_param('i', $staff_id)) {
        error_log('Backup password lookup bind failed: ' . $password_stmt->error);
        $password_stmt->close();
        abort_backup_request($staff_id, 'password_lookup_bind_failed', 500);
    }

    if (!$password_stmt->execute()) {
        error_log('Backup password lookup execute failed: ' . $password_stmt->error);
        $password_stmt->close();
        abort_backup_request($staff_id, 'password_lookup_execute_failed', 500);
    }

    $password_result = $password_stmt->get_result();
    if (!$password_result) {
        error_log('Backup password lookup result failed: ' . $password_stmt->error);
        $password_stmt->close();
        abort_backup_request($staff_id, 'password_lookup_result_failed', 500);
    }

    $password_row = $password_result->fetch_assoc();
    $password_result->free();
    $password_stmt->close();
} catch (Throwable $exception) {
    if ($password_stmt instanceof mysqli_stmt) {
        $password_stmt->close();
    }
    error_log('Backup password lookup failed: ' . $exception->getMessage());
    abort_backup_request($staff_id, 'password_lookup_error', 500);
}

if (!$password_row || !isset($password_row['password']) || !is_string($password_row['password'])) {
    abort_backup_request($staff_id, 'password_reauthentication_failed', 403);
}

try {
    $password_valid = password_verify($current_password, $password_row['password']);
} catch (Throwable $exception) {
    error_log('Backup password verification failed: ' . $exception->getMessage());
    abort_backup_request($staff_id, 'password_reauthentication_error', 500);
}

if (!$password_valid) {
    abort_backup_request($staff_id, 'password_reauthentication_failed', 403);
}

// This is the complete canonical table allow-list. No table discovery is used.
$backup_tables = get_backup_table_allowlist();
$drop_tables = array_reverse($backup_tables);

foreach (array_merge($backup_tables, $drop_tables) as $table) {
    if (quote_backup_table($table) === false) {
        abort_backup_request($staff_id, 'invalid_backup_table_configuration', 500);
    }
}

set_time_limit(0);

$transaction_started = false;
$output = null;
$active_result = null;

try {
    // The canonical schema uses InnoDB. This snapshot remains consistent while rows stream.
    if (!$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
        error_log('Database backup transaction start failed: ' . $conn->error);
        throw new RuntimeException('Backup transaction could not start.');
    }
    $transaction_started = true;

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Backup output stream could not be opened.');
    }

    $filename = 'myshop_database_backup.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    write_backup_chunk($output, "-- MyShop Inventory, POS, and Order Management System\n");
    write_backup_chunk($output, "-- Database Backup\n");
    write_backup_chunk($output, '-- Generated on: ' . gmdate('Y-m-d H:i:s') . " UTC\n");
    write_backup_chunk($output, "-- Staff.password contains one-way password hashes for restore integrity.\n");
    write_backup_chunk($output, "-- This file is highly sensitive and must be protected as a credential-bearing backup.\n\n");
    write_backup_chunk($output, "SET NAMES utf8mb4;\n");
    write_backup_chunk($output, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    // Drop dependants first, then recreate and populate parents before dependants.
    foreach ($drop_tables as $table) {
        $quoted_table = quote_backup_table($table);
        write_backup_chunk($output, 'DROP TABLE IF EXISTS ' . $quoted_table . ";\n");
    }
    write_backup_chunk($output, "\n");

    foreach ($backup_tables as $table) {
        $quoted_table = quote_backup_table($table);

        $create_result = $conn->query('SHOW CREATE TABLE ' . $quoted_table);
        if (!$create_result) {
            error_log('Backup table definition query failed for ' . $table . ': ' . $conn->error);
            throw new RuntimeException('Backup table definition query failed.');
        }

        $create_row = $create_result->fetch_row();
        $create_result->free();
        if (!is_array($create_row) || !isset($create_row[1]) || !is_string($create_row[1])) {
            error_log('Backup table definition was empty for ' . $table . '.');
            throw new RuntimeException('Backup table definition was empty.');
        }

        write_backup_chunk($output, "--\n-- Table structure for table " . $quoted_table . "\n--\n\n");
        write_backup_chunk($output, $create_row[1] . ";\n\n");

        $active_result = $conn->query('SELECT * FROM ' . $quoted_table, MYSQLI_USE_RESULT);
        if (!$active_result) {
            error_log('Backup table data query failed for ' . $table . ': ' . $conn->error);
            throw new RuntimeException('Backup table data query failed.');
        }

        $has_rows = false;
        while ($row = $active_result->fetch_assoc()) {
            if (!$has_rows) {
                write_backup_chunk($output, "--\n-- Dumping data for table " . $quoted_table . "\n--\n\n");
                $has_rows = true;
            }

            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = "'" . $conn->real_escape_string((string)$value) . "'";
                }
            }

            write_backup_chunk(
                $output,
                'INSERT INTO ' . $quoted_table . ' VALUES (' . implode(',', $values) . ");\n"
            );
        }

        if ($conn->errno !== 0) {
            error_log('Backup table data stream failed for ' . $table . ': ' . $conn->error);
            throw new RuntimeException('Backup table data stream failed.');
        }

        $active_result->free();
        $active_result = null;
        if ($has_rows) {
            write_backup_chunk($output, "\n");
        }
    }

    write_backup_chunk($output, "SET FOREIGN_KEY_CHECKS=1;\n");

    if (!$conn->commit()) {
        error_log('Database backup transaction commit failed: ' . $conn->error);
        throw new RuntimeException('Backup transaction commit failed.');
    }
    $transaction_started = false;

    if (!fclose($output)) {
        error_log('Database backup output stream close failed.');
        $output = null;
        throw new RuntimeException('Backup output stream close failed.');
    }
    $output = null;

    log_backup_attempt($staff_id, true, 'completed');
    exit();
} catch (Throwable $exception) {
    if ($active_result instanceof mysqli_result) {
        $active_result->free();
        $active_result = null;
    }

    if ($transaction_started) {
        if (!$conn->rollback()) {
            error_log('Database backup rollback failed: ' . $conn->error);
        }
        $transaction_started = false;
    }

    error_log('Database backup generation failed: ' . $exception->getMessage());
    log_backup_attempt($staff_id, false, 'generation_failed');
    finish_failed_backup_response($output);
    exit();
}
