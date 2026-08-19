<?php

declare(strict_types=1);

const EXPORT_BATCH_SIZE = 250;

final class ExportStreamFailure extends RuntimeException
{
}

function export_report_definitions(): array
{
    return [
        'products' => ['filename' => 'myshop-products.csv'],
        'stock' => ['filename' => 'myshop-stock-movements.csv'],
        'customers' => ['filename' => 'myshop-customers.csv'],
        'suppliers' => ['filename' => 'myshop-suppliers.csv'],
        'orders' => ['filename' => 'myshop-orders.csv'],
    ];
}

function export_validate_entity($requested_entity): string
{
    $definitions = export_report_definitions();
    if (!is_string($requested_entity) || !array_key_exists($requested_entity, $definitions)) {
        throw new InvalidArgumentException('Invalid export request.');
    }

    return $requested_entity;
}

function export_validate_order_filters($start_date, $end_date, $type): array
{
    if (
        !is_string($start_date)
        || !is_string($end_date)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)
    ) {
        throw new InvalidArgumentException('Invalid export request.');
    }

    if (!is_string($type) || !in_array($type, ['all', 'sale', 'purchase'], true)) {
        $type = 'all';
    }

    return [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'type' => $type,
    ];
}

function export_csv_text($value): string
{
    $text = (string)($value ?? '');

    // Excel and similar spreadsheet applications can execute these values as formulas.
    if ($text !== '' && preg_match('/^[=+\-@\t\r\n]/', $text) === 1) {
        return "'" . $text;
    }

    return $text;
}

function export_csv_write_row($output, array $row): void
{
    if (!is_resource($output) || fputcsv($output, $row) === false) {
        throw new ExportStreamFailure('CSV export failed while writing a row.');
    }
}

function export_csv_fail($log_message, $status_code = 500): void
{
    error_log('CSV export failed: ' . $log_message);
    if (!headers_sent()) {
        http_response_code($status_code);
    }
    exit('Export is temporarily unavailable.');
}

function export_bind_parameters(mysqli_stmt $statement, string $types, array &$parameters): void
{
    if ($types === '') {
        return;
    }

    $references = [$types];
    foreach ($parameters as &$parameter) {
        $references[] = &$parameter;
    }

    if (!call_user_func_array([$statement, 'bind_param'], $references)) {
        throw new ExportStreamFailure('CSV export query parameter binding failed: ' . $statement->error);
    }
}

function export_bind_result(mysqli_stmt $statement, array &$values): void
{
    $references = [];
    foreach ($values as &$value) {
        $references[] = &$value;
    }

    if (!call_user_func_array([$statement, 'bind_result'], $references)) {
        throw new ExportStreamFailure('CSV export result binding failed: ' . $statement->error);
    }
}

/**
 * Execute bounded prepared queries and deliver each fetched row immediately.
 * mysqli_stmt::fetch() is intentionally used without store_result() or get_result(),
 * so only the current row and the server-side LIMIT batch are in flight.
 */
function export_stream_batches(
    mysqli $conn,
    callable $build_query,
    callable $bind_row,
    callable $map_row,
    callable $cursor_from_values,
    callable $write_row
): int {
    $cursor = null;
    $total_rows = 0;

    while (true) {
        $query = $build_query($cursor);
        if (!is_array($query) || count($query) !== 3) {
            throw new ExportStreamFailure('CSV export query definition is invalid.');
        }

        [$sql, $types, $parameters] = $query;
        if (!is_string($sql) || !is_string($types) || !is_array($parameters)) {
            throw new ExportStreamFailure('CSV export query definition is invalid.');
        }

        $statement = $conn->prepare($sql);
        if (!$statement) {
            throw new ExportStreamFailure('CSV export query preparation failed: ' . $conn->error);
        }

        try {
            export_bind_parameters($statement, $types, $parameters);
            if (!$statement->execute()) {
                throw new ExportStreamFailure('CSV export query execution failed: ' . $statement->error);
            }

            $values = [];
            $bind_row($statement, $values);
            $batch_rows = 0;
            $last_cursor = null;

            while (true) {
                $fetch_status = $statement->fetch();
                if ($fetch_status === null) {
                    break;
                }
                if ($fetch_status === false) {
                    throw new ExportStreamFailure('CSV export result reading failed: ' . $statement->error);
                }

                $write_row($map_row($values));
                $total_rows++;
                $batch_rows++;
                $last_cursor = $cursor_from_values($values);
            }

            if ($batch_rows < EXPORT_BATCH_SIZE) {
                return $total_rows;
            }
            if ($last_cursor === null) {
                throw new ExportStreamFailure('CSV export pagination cursor was not produced.');
            }

            $cursor = $last_cursor;
        } finally {
            $statement->close();
        }
    }
}

function export_stream_entity(mysqli $conn, string $entity, callable $write_row, ?array $order_filters = null): int
{
    export_validate_entity($entity);

    switch ($entity) {
        case 'products':
            return export_stream_batches(
                $conn,
                static function (?array $cursor): array {
                    $sql = "SELECT p.id, p.name, c.name AS category_name, p.price, p.stock,
                                   p.alert_threshold, p.created_at
                            FROM Product p
                            LEFT JOIN Category c ON p.category_id = c.id";
                    if ($cursor === null) {
                        return [$sql . ' ORDER BY p.created_at DESC, p.id DESC LIMIT ?', 'i', [EXPORT_BATCH_SIZE]];
                    }

                    return [
                        $sql . ' WHERE (p.created_at < ? OR (p.created_at = ? AND p.id < ?))' .
                        ' ORDER BY p.created_at DESC, p.id DESC LIMIT ?',
                        'ssii',
                        [$cursor[0], $cursor[0], $cursor[1], EXPORT_BATCH_SIZE],
                    ];
                },
                static function (mysqli_stmt $statement, array &$values): void {
                    $values = [null, null, null, null, null, null, null];
                    export_bind_result($statement, $values);
                },
                static function (array $values): array {
                    return [
                        'id' => (int)$values[0],
                        'name' => $values[1],
                        'category_name' => $values[2],
                        'price' => $values[3],
                        'stock' => $values[4],
                        'alert_threshold' => $values[5],
                        'created_at' => $values[6],
                    ];
                },
                static fn(array $values): array => [(string)$values[6], (int)$values[0],],
                $write_row
            );

        case 'stock':
            return export_stream_batches(
                $conn,
                static function (?array $cursor): array {
                    $sql = "SELECT sm.created_at, p.name AS product_name, s.full_name AS staff_name,
                                   sm.movement_type, sm.quantity, sm.reason, sm.id
                            FROM `StockMovement` sm
                            JOIN Product p ON sm.product_id = p.id
                            JOIN Staff s ON sm.staff_id = s.id";
                    if ($cursor === null) {
                        return [$sql . ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT ?', 'i', [EXPORT_BATCH_SIZE]];
                    }

                    return [
                        $sql . ' WHERE (sm.created_at < ? OR (sm.created_at = ? AND sm.id < ?))' .
                        ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT ?',
                        'ssii',
                        [$cursor[0], $cursor[0], $cursor[1], EXPORT_BATCH_SIZE],
                    ];
                },
                static function (mysqli_stmt $statement, array &$values): void {
                    $values = [null, null, null, null, null, null, null];
                    export_bind_result($statement, $values);
                },
                static function (array $values): array {
                    return [
                        'created_at' => $values[0],
                        'product_name' => $values[1],
                        'staff_name' => $values[2],
                        'movement_type' => $values[3],
                        'quantity' => $values[4],
                        'reason' => $values[5],
                        'id' => (int)$values[6],
                    ];
                },
                static fn(array $values): array => [(string)$values[0], (int)$values[6],],
                $write_row
            );

        case 'customers':
            return export_stream_batches(
                $conn,
                static function (?array $cursor): array {
                    $sql = 'SELECT id, name, phone, email, address, created_at FROM Customer';
                    if ($cursor === null) {
                        return [$sql . ' ORDER BY name ASC, id ASC LIMIT ?', 'i', [EXPORT_BATCH_SIZE]];
                    }

                    return [
                        $sql . ' WHERE (name > ? OR (name = ? AND id > ?))' .
                        ' ORDER BY name ASC, id ASC LIMIT ?',
                        'ssii',
                        [$cursor[0], $cursor[0], $cursor[1], EXPORT_BATCH_SIZE],
                    ];
                },
                static function (mysqli_stmt $statement, array &$values): void {
                    $values = [null, null, null, null, null, null];
                    export_bind_result($statement, $values);
                },
                static function (array $values): array {
                    return [
                        'id' => (int)$values[0],
                        'name' => $values[1],
                        'phone' => $values[2],
                        'email' => $values[3],
                        'address' => $values[4],
                        'created_at' => $values[5],
                    ];
                },
                static fn(array $values): array => [(string)$values[1], (int)$values[0],],
                $write_row
            );

        case 'suppliers':
            return export_stream_batches(
                $conn,
                static function (?array $cursor): array {
                    $sql = 'SELECT id, name, phone, email, address, created_at FROM Supplier';
                    if ($cursor === null) {
                        return [$sql . ' ORDER BY name ASC, id ASC LIMIT ?', 'i', [EXPORT_BATCH_SIZE]];
                    }

                    return [
                        $sql . ' WHERE (name > ? OR (name = ? AND id > ?))' .
                        ' ORDER BY name ASC, id ASC LIMIT ?',
                        'ssii',
                        [$cursor[0], $cursor[0], $cursor[1], EXPORT_BATCH_SIZE],
                    ];
                },
                static function (mysqli_stmt $statement, array &$values): void {
                    $values = [null, null, null, null, null, null];
                    export_bind_result($statement, $values);
                },
                static function (array $values): array {
                    return [
                        'id' => (int)$values[0],
                        'name' => $values[1],
                        'phone' => $values[2],
                        'email' => $values[3],
                        'address' => $values[4],
                        'created_at' => $values[5],
                    ];
                },
                static fn(array $values): array => [(string)$values[1], (int)$values[0],],
                $write_row
            );

        case 'orders':
            if (!is_array($order_filters)) {
                throw new InvalidArgumentException('Invalid export request.');
            }

            $filters = export_validate_order_filters(
                $order_filters['start_date'] ?? null,
                $order_filters['end_date'] ?? null,
                $order_filters['type'] ?? null
            );

            return export_stream_batches(
                $conn,
                static function (?array $cursor) use ($filters): array {
                    $sql = "SELECT o.id, o.order_date, s.full_name AS staff_name,
                                   o.order_type, o.total_amount
                            FROM `Order` o
                            JOIN Staff s ON o.staff_id = s.id
                            WHERE DATE(o.order_date) BETWEEN ? AND ?";
                    $types = 'ss';
                    $parameters = [$filters['start_date'], $filters['end_date']];

                    if ($filters['type'] !== 'all') {
                        $sql .= ' AND o.order_type = ?';
                        $types .= 's';
                        $parameters[] = $filters['type'];
                    }
                    if ($cursor !== null) {
                        $sql .= ' AND (o.order_date < ? OR (o.order_date = ? AND o.id < ?))';
                        $types .= 'ssi';
                        $parameters[] = $cursor[0];
                        $parameters[] = $cursor[0];
                        $parameters[] = $cursor[1];
                    }

                    $sql .= ' ORDER BY o.order_date DESC, o.id DESC LIMIT ?';
                    $types .= 'i';
                    $parameters[] = EXPORT_BATCH_SIZE;

                    return [$sql, $types, $parameters];
                },
                static function (mysqli_stmt $statement, array &$values): void {
                    $values = [null, null, null, null, null];
                    export_bind_result($statement, $values);
                },
                static function (array $values): array {
                    return [
                        'id' => (int)$values[0],
                        'order_date' => $values[1],
                        'staff_name' => $values[2],
                        'order_type' => $values[3],
                        'total_amount' => $values[4],
                    ];
                },
                static fn(array $values): array => [(string)$values[1], (int)$values[0],],
                $write_row
            );
    }

    throw new InvalidArgumentException('Invalid export request.');
}
