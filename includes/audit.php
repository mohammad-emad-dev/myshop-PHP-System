<?php

function audit_metadata_key_is_safe($key)
{
    if (!is_string($key)) {
        return false;
    }

    return preg_match(
        '/(?:pass(?:word)?|csrf|token|session|secret|credential|cookie|authorization|request|body|dump)/i',
        $key
    ) !== 1;
}

function audit_sanitize_metadata_value($value, $depth = 0)
{
    if ($depth > 2) {
        return '[truncated]';
    }

    if (is_string($value)) {
        return function_exists('mb_substr') ? mb_substr($value, 0, 255, 'UTF-8') : substr($value, 0, 255);
    }

    if (is_int($value) || is_bool($value) || $value === null) {
        return $value;
    }

    if (is_float($value)) {
        return is_finite($value) ? $value : '[invalid-number]';
    }

    if (!is_array($value)) {
        return '[unsupported]';
    }

    $sanitized = [];
    $count = 0;
    foreach ($value as $key => $child) {
        if ($count >= 20) {
            $sanitized['_truncated'] = true;
            break;
        }
        if (!audit_metadata_key_is_safe((string)$key)) {
            continue;
        }

        $safe_key = function_exists('mb_substr')
            ? mb_substr((string)$key, 0, 64, 'UTF-8')
            : substr((string)$key, 0, 64);
        $sanitized[$safe_key] = audit_sanitize_metadata_value($child, $depth + 1);
        $count++;
    }

    return $sanitized;
}

/**
 * Insert one bounded audit event. This helper never accepts request bodies,
 * credentials, CSRF values, or session identifiers as metadata.
 */
function audit_log($conn, $actor_staff_id, $action, $entity_type, $entity_id, $success, $metadata = null)
{
    if (!($conn instanceof mysqli) || !is_string($action) || !is_string($entity_type)) {
        error_log('Audit log rejected invalid connection or event fields.');
        return false;
    }

    $action = trim($action);
    $entity_type = trim($entity_type);
    if (
        $action === '' || strlen($action) > 80 ||
        $entity_type === '' || strlen($entity_type) > 50 ||
        preg_match('/^[A-Za-z0-9_.:-]+$/', $action) !== 1 ||
        preg_match('/^[A-Za-z0-9_.:-]+$/', $entity_type) !== 1
    ) {
        error_log('Audit log rejected invalid event identifiers.');
        return false;
    }

    $actor_staff_id = filter_var($actor_staff_id, FILTER_VALIDATE_INT);
    $actor_staff_id = $actor_staff_id !== false && $actor_staff_id > 0 ? (int)$actor_staff_id : null;
    $entity_id = filter_var($entity_id, FILTER_VALIDATE_INT);
    $entity_id = $entity_id !== false && $entity_id > 0 ? (int)$entity_id : null;
    $outcome = $success ? 'success' : 'failure';
    $source_ip = get_login_source_ip();
    $source_ip = is_string($source_ip) ? $source_ip : null;
    $metadata_json = null;

    if ($metadata !== null) {
        $safe_metadata = audit_sanitize_metadata_value($metadata);
        $metadata_json = json_encode(
            $safe_metadata,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($metadata_json)) {
            error_log('Audit metadata JSON encoding failed.');
            return false;
        }
        if (strlen($metadata_json) > 4096) {
            $metadata_json = '{"truncated":true}';
        }
    }

    $stmt = null;
    try {
        if ($actor_staff_id === null && $entity_id === null) {
            $sql = "INSERT INTO AuditLog
                        (actor_staff_id, action, entity_type, entity_id, outcome, source_ip, metadata)
                    VALUES (NULL, ?, ?, NULL, ?, ?, ?)";
            $types = 'sssss';
            $parameters = [$action, $entity_type, $outcome, $source_ip, $metadata_json];
        } elseif ($actor_staff_id === null) {
            $sql = "INSERT INTO AuditLog
                        (actor_staff_id, action, entity_type, entity_id, outcome, source_ip, metadata)
                    VALUES (NULL, ?, ?, ?, ?, ?, ?)";
            $types = 'ssisss';
            $parameters = [$action, $entity_type, $entity_id, $outcome, $source_ip, $metadata_json];
        } elseif ($entity_id === null) {
            $sql = "INSERT INTO AuditLog
                        (actor_staff_id, action, entity_type, entity_id, outcome, source_ip, metadata)
                    VALUES (?, ?, ?, NULL, ?, ?, ?)";
            $types = 'isssss';
            $parameters = [$actor_staff_id, $action, $entity_type, $outcome, $source_ip, $metadata_json];
        } else {
            $sql = "INSERT INTO AuditLog
                        (actor_staff_id, action, entity_type, entity_id, outcome, source_ip, metadata)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $types = 'ississs';
            $parameters = [$actor_staff_id, $action, $entity_type, $entity_id, $outcome, $source_ip, $metadata_json];
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Audit insert prepare failed: ' . $conn->error);
        }
        $references = [$types];
        foreach ($parameters as &$parameter) {
            $references[] = &$parameter;
        }
        unset($parameter);
        if (!call_user_func_array([$stmt, 'bind_param'], $references)) {
            throw new RuntimeException('Audit insert bind failed: ' . $stmt->error);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Audit insert execute failed: ' . $stmt->error);
        }
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('Audit insert affected an unexpected number of rows.');
        }
        return true;
    } catch (Throwable $exception) {
        error_log('Audit log insert failed: ' . $exception->getMessage());
        return false;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function audit_log_current_actor($conn, $action, $entity_type, $entity_id, $success, $metadata = null)
{
    return audit_log($conn, get_authenticated_staff_id(), $action, $entity_type, $entity_id, $success, $metadata);
}

function audit_log_denied($conn, $action, $entity_type = 'Route', $entity_id = null, $metadata = [])
{
    $metadata = is_array($metadata) ? $metadata : [];
    $metadata['reason'] = 'authorization_denied';
    return audit_log_current_actor($conn, $action, $entity_type, $entity_id, false, $metadata);
}

function build_audit_log_filter_sql($filters, &$types, &$parameters)
{
    $types = '';
    $parameters = [];
    $conditions = ['1 = 1'];
    $filters = is_array($filters) ? $filters : [];

    $string_filters = [
        'action' => ['a.action = ?', 80],
        'entity_type' => ['a.entity_type = ?', 50],
        'outcome' => ['a.outcome = ?', 10],
    ];
    foreach ($string_filters as $key => [$condition, $max_length]) {
        $value = $filters[$key] ?? '';
        if (is_string($value) && $value !== '') {
            $value = substr($value, 0, $max_length);
            $conditions[] = $condition;
            $types .= 's';
            $parameters[] = $value;
        }
    }

    $actor_id = filter_var($filters['actor_staff_id'] ?? null, FILTER_VALIDATE_INT);
    if ($actor_id !== false && $actor_id !== null && $actor_id > 0) {
        $conditions[] = 'a.actor_staff_id = ?';
        $types .= 'i';
        $parameters[] = (int)$actor_id;
    }

    foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
        $date = $filters[$key] ?? '';
        if (
            is_string($date)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            && DateTime::createFromFormat('!Y-m-d', $date) instanceof DateTime
        ) {
            $conditions[] = "a.created_at {$operator} ?";
            $types .= 's';
            $parameters[] = $date . ($key === 'date_from' ? ' 00:00:00' : ' 23:59:59');
        }
    }

    return implode(' AND ', $conditions);
}

function bind_audit_log_parameters($stmt, $types, &$parameters)
{
    if ($types === '') {
        return true;
    }

    $references = [$types];
    foreach ($parameters as &$parameter) {
        $references[] = &$parameter;
    }
    unset($parameter);
    return call_user_func_array([$stmt, 'bind_param'], $references);
}

function count_audit_logs($conn, $filters = [])
{
    $stmt = null;
    try {
        $types = '';
        $parameters = [];
        $where_sql = build_audit_log_filter_sql($filters, $types, $parameters);
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM AuditLog a WHERE {$where_sql}");
        if (!$stmt || !bind_audit_log_parameters($stmt, $types, $parameters) || !$stmt->execute()) {
            error_log('Audit log count query failed: ' . ($stmt ? $stmt->error : $conn->error));
            return 0;
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Audit log count result failed: ' . $stmt->error);
            return 0;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return isset($row['total']) ? (int)$row['total'] : 0;
    } catch (Throwable $exception) {
        error_log('Audit log count failed: ' . $exception->getMessage());
        return 0;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

function get_audit_logs_page($conn, $filters = [], $limit = 25, $offset = 0)
{
    $limit = normalize_page_size($limit, 25, [10, 25, 50, 100]);
    $offset = filter_var($offset, FILTER_VALIDATE_INT);
    $offset = $offset !== false && $offset >= 0 ? $offset : 0;
    $stmt = null;

    try {
        $types = '';
        $parameters = [];
        $where_sql = build_audit_log_filter_sql($filters, $types, $parameters);
        $sql = "SELECT a.id, a.created_at, a.actor_staff_id, a.action,
                       a.entity_type, a.entity_id, a.outcome, a.source_ip,
                       a.metadata, s.username AS actor_username,
                       s.full_name AS actor_full_name
                FROM AuditLog a
                LEFT JOIN Staff s ON s.id = a.actor_staff_id
                WHERE {$where_sql}
                ORDER BY a.created_at DESC, a.id DESC
                LIMIT ? OFFSET ?";
        $types .= 'ii';
        $parameters[] = $limit;
        $parameters[] = $offset;
        $stmt = $conn->prepare($sql);
        if (!$stmt || !bind_audit_log_parameters($stmt, $types, $parameters) || !$stmt->execute()) {
            error_log('Audit log page query failed: ' . ($stmt ? $stmt->error : $conn->error));
            return [];
        }
        $result = $stmt->get_result();
        if (!$result) {
            error_log('Audit log page result failed: ' . $stmt->error);
            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    } catch (Throwable $exception) {
        error_log('Audit log page query failed: ' . $exception->getMessage());
        return [];
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}
