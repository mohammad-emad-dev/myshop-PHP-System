<?php

declare(strict_types=1);

/**
 * Return the only tables that are part of an application backup.
 *
 * LoginRateLimit is deliberately excluded because it is ephemeral security
 * state. Restoring it would reintroduce stale blocks and failure counters;
 * the table is recreated by the canonical schema and migration instead.
 */
function get_backup_table_allowlist(): array
{
    return [
        'Staff',
        'Category',
        'Customer',
        'Supplier',
        'Product',
        'Order',
        'OrderDetail',
        'StockMovement',
        'AuditLog',
    ];
}

/**
 * Quote one of the explicitly supported project table identifiers.
 */
function quote_backup_table(string $table): ?string
{
    if (!in_array($table, get_backup_table_allowlist(), true)) {
        return null;
    }

    return '`' . str_replace('`', '``', $table) . '`';
}

/**
 * Write all bytes in a chunk to the output stream.
 *
 * This intentionally writes one bounded chunk at a time. It does not build
 * the complete dump in memory.
 */
function write_backup_chunk($output, string $chunk): void
{
    if (!is_resource($output)) {
        throw new RuntimeException('Backup output stream is unavailable.');
    }

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
 * Stream a consistent SQL backup for the canonical application tables.
 *
 * The caller owns the output resource and response headers. This function
 * owns the read-only snapshot transaction and always attempts rollback on a
 * failure. It throws a generic RuntimeException; callers must log technical
 * details server-side and must not expose the exception to users.
 */
function stream_database_backup(mysqli $conn, $output, ?array $backup_tables = null): void
{
    if (!is_resource($output)) {
        throw new RuntimeException('Backup output stream is unavailable.');
    }

    $backup_tables = $backup_tables ?? get_backup_table_allowlist();
    foreach ($backup_tables as $table) {
        if (!is_string($table) || quote_backup_table($table) === null) {
            throw new RuntimeException('Backup table configuration is invalid.');
        }
    }

    $drop_tables = array_reverse($backup_tables);
    $transaction_started = false;
    $active_result = null;

    try {
        if (!$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
            error_log('Database backup transaction start failed: ' . $conn->error);
            throw new RuntimeException('Backup transaction could not start.');
        }
        $transaction_started = true;

        write_backup_chunk($output, "-- MyShop Inventory, POS, and Order Management System\n");
        write_backup_chunk($output, "-- Database Backup\n");
        write_backup_chunk($output, '-- Generated on: ' . gmdate('Y-m-d H:i:s') . " UTC\n");
        write_backup_chunk($output, "-- Staff.password contains one-way password hashes for restore integrity.\n");
        write_backup_chunk($output, "-- This file is highly sensitive and must be protected as a credential-bearing backup.\n");
        write_backup_chunk($output, "-- AuditLog is included. LoginRateLimit is excluded as ephemeral security state.\n\n");
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

        // This marker is emitted only after the snapshot commits. Consumers
        // must reject any backup that does not contain it.
        write_backup_chunk($output, "-- MYSHOP_BACKUP_COMPLETE\n");
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

        throw $exception;
    }
}
