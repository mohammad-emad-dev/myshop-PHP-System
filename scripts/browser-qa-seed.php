<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

$required_environment = [
    'BROWSER_QA_CASHIER_USERNAME',
    'BROWSER_QA_CASHIER_FULL_NAME',
    'BROWSER_QA_CASHIER_PASSWORD',
    'BROWSER_QA_DATA_PREFIX',
];

$values = [];
foreach ($required_environment as $key) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Browser QA seed configuration is incomplete.\n");
        exit(1);
    }
    $values[$key] = $value;
}

$prefix = $values['BROWSER_QA_DATA_PREFIX'];
if (!preg_match('/^[A-Za-z0-9_]{4,40}$/', $prefix)) {
    fwrite(STDERR, "Browser QA data prefix is invalid.\n");
    exit(1);
}

if (!password_meets_policy($values['BROWSER_QA_CASHIER_PASSWORD'])) {
    fwrite(STDERR, "Browser QA cashier password does not meet the application policy.\n");
    exit(1);
}

$cashier_username = $values['BROWSER_QA_CASHIER_USERNAME'];
$cashier_full_name = $values['BROWSER_QA_CASHIER_FULL_NAME'];
$cashier_password = $values['BROWSER_QA_CASHIER_PASSWORD'];
$category_name = $prefix . '_CATEGORY';
$category_description = 'Disposable browser QA category';
$customer_name = $prefix . '_CUSTOMER';
$supplier_name = $prefix . '_SUPPLIER';

$statements = [];
$transaction_started = false;

$close_statements = static function () use (&$statements): void {
    foreach ($statements as $statement) {
        if ($statement instanceof mysqli_stmt) {
            $statement->close();
        }
    }
    $statements = [];
};

$fail = static function (string $message) use (&$transaction_started, $conn, $close_statements): never {
    if ($transaction_started) {
        $conn->rollback();
    }
    $close_statements();
    error_log('Browser QA fixture seed failed: ' . $message);
    fwrite(STDERR, "Unable to prepare browser QA fixtures.\n");
    exit(1);
};

try {
    if (!$conn->begin_transaction()) {
        $fail('Unable to start transaction.');
    }
    $transaction_started = true;

    $staff_lookup = $conn->prepare('SELECT id FROM Staff WHERE username = ? LIMIT 1');
    if (!$staff_lookup || !$staff_lookup->bind_param('s', $cashier_username) || !$staff_lookup->execute()) {
        $fail('Unable to inspect cashier account.');
    }
    $staff_result = $staff_lookup->get_result();
    $existing_cashier = $staff_result ? $staff_result->fetch_assoc() : null;
    $staff_result?->free();
    $staff_lookup->close();

    if ($existing_cashier) {
        $fail('The disposable cashier username already exists.');
    }

    if (!create_staff_member($conn, $cashier_username, $cashier_password, $cashier_full_name, 'cashier')) {
        $fail('Unable to create cashier account.');
    }

    $cashier_lookup = $conn->prepare('SELECT id FROM Staff WHERE username = ? LIMIT 1');
    if (!$cashier_lookup || !$cashier_lookup->bind_param('s', $cashier_username) || !$cashier_lookup->execute()) {
        $fail('Unable to read cashier account.');
    }
    $cashier_result = $cashier_lookup->get_result();
    $cashier_row = $cashier_result ? $cashier_result->fetch_assoc() : null;
    $cashier_result?->free();
    $cashier_lookup->close();
    $cashier_id = (int)($cashier_row['id'] ?? 0);
    if ($cashier_id <= 0) {
        $fail('Cashier account ID was not created.');
    }

    $category_insert = $conn->prepare('INSERT INTO Category (name, description) VALUES (?, ?)');
    if (!$category_insert || !$category_insert->bind_param('ss', $category_name, $category_description) || !$category_insert->execute()) {
        $fail('Unable to create category fixture.');
    }
    $category_id = (int)$conn->insert_id;
    $category_insert->close();

    $customer_phone = '555' . substr((string)$cashier_id, -7);
    $customer_email = strtolower($prefix) . '@example.test';
    $customer_address = 'Disposable browser QA address';
    $customer_insert = $conn->prepare('INSERT INTO Customer (name, phone, email, address) VALUES (?, ?, ?, ?)');
    if (!$customer_insert || !$customer_insert->bind_param('ssss', $customer_name, $customer_phone, $customer_email, $customer_address) || !$customer_insert->execute()) {
        $fail('Unable to create customer fixture.');
    }
    $customer_insert->close();

    $supplier_phone = '556' . substr((string)$cashier_id, -7);
    $supplier_email = strtolower($prefix) . '-supplier@example.test';
    $supplier_address = 'Disposable browser QA supplier address';
    $supplier_insert = $conn->prepare('INSERT INTO Supplier (name, phone, email, address) VALUES (?, ?, ?, ?)');
    if (!$supplier_insert || !$supplier_insert->bind_param('ssss', $supplier_name, $supplier_phone, $supplier_email, $supplier_address) || !$supplier_insert->execute()) {
        $fail('Unable to create supplier fixture.');
    }
    $supplier_insert->close();

    $product_insert = $conn->prepare(
        'INSERT INTO Product (name, description, price, stock, alert_threshold, category_id, barcode)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $movement_insert = $conn->prepare(
        "INSERT INTO StockMovement (product_id, staff_id, quantity, movement_type, reason)
         VALUES (?, ?, ?, 'purchase', ?)"
    );
    if (!$product_insert || !$movement_insert) {
        $fail('Unable to prepare product fixtures.');
    }

    $description = 'Disposable browser QA product';
    $reason = 'Disposable browser QA seed';
    for ($index = 1; $index <= 12; $index++) {
        $product_name = sprintf('%s_PRODUCT_%02d', $prefix, $index);
        $price = 10.00 + $index;
        $stock = 20 + $index;
        $alert_threshold = 5;
        $barcode = sprintf('%s_BARCODE_%02d', $prefix, $index);

        if (!$product_insert->bind_param('ssdiiis', $product_name, $description, $price, $stock, $alert_threshold, $category_id, $barcode) || !$product_insert->execute()) {
            $fail('Unable to create product fixture.');
        }
        $product_id = (int)$conn->insert_id;
        if (!$movement_insert->bind_param('iiis', $product_id, $cashier_id, $stock, $reason) || !$movement_insert->execute()) {
            $fail('Unable to create stock fixture.');
        }
    }
    $product_insert->close();
    $movement_insert->close();

    if (!$conn->commit()) {
        $fail('Unable to commit browser QA fixtures.');
    }
    $transaction_started = false;
    $close_statements();
    fwrite(STDOUT, "Browser QA fixtures created.\n");
} catch (Throwable $exception) {
    $fail($exception->getMessage());
}
