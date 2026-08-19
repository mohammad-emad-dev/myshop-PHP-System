<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$database = new DisposableDatabase();
$failure = false;

try {
    $database->setup();
    $rows = test_fetch_all(
        $database->schema(),
        "SELECT table_name AS table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    );
    $actualTables = array_map(static fn(array $row): string => (string)$row['table_name'], $rows);
    sort($actualTables);
    $expectedTables = [
        'AuditLog', 'Category', 'Customer', 'LoginRateLimit', 'Order',
        'OrderDetail', 'Product', 'Staff', 'StockMovement', 'Supplier',
    ];
    sort($expectedTables);
    if ($actualTables !== $expectedTables) {
        throw new RuntimeException('Schema/migration table set is incomplete.');
    }

    echo "PASS: canonical schema and migrations applied to a disposable database.\n";
} catch (Throwable $exception) {
    $failure = true;
    fwrite(STDERR, "FAIL: canonical schema or migration validation failed.\n");
} finally {
    try {
        $database->cleanup();
    } catch (Throwable $exception) {
        $failure = true;
        fwrite(STDERR, "FAIL: disposable schema-validation cleanup failed.\n");
    }
}

exit($failure ? 1 : 0);
