<?php
require_once '../includes/functions.php';
require_once '../includes/export.php';
start_secure_session();
require_once '../config/db.php';

// Verify user authentication and administrator privileges.
auth_verify_login($conn);
auth_require_admin($conn);

$requested_entity = $_GET['entity'] ?? 'orders';
try {
    $entity = export_validate_entity($requested_entity);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    exit('Invalid export request.');
}

$filename = export_report_definitions()[$entity]['filename'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
if ($output === false) {
    export_csv_fail('Unable to open the CSV output stream.');
}

// Write UTF-8 BOM for Excel compatibility.
if (fwrite($output, "\xEF\xBB\xBF") === false) {
    export_csv_fail('Unable to write the CSV header.');
}

try {
    switch ($entity) {
        case 'products':
            export_csv_write_row($output, ['ID', 'Product Name', 'Category', 'Price ($)', 'Current Stock', 'Alert Threshold', 'Valuation ($)']);
            export_stream_entity($conn, 'products', static function (array $product) use ($output): void {
                $price = (float)$product['price'];
                $stock = (int)$product['stock'];
                export_csv_write_row($output, [
                    (int)$product['id'],
                    export_csv_text($product['name']),
                    export_csv_text($product['category_name'] ?? 'Uncategorized'),
                    round($price, 2),
                    $stock,
                    (int)$product['alert_threshold'],
                    round($price * $stock, 2),
                ]);
            });
            break;

        case 'stock':
            export_csv_write_row($output, ['Date & Time', 'Product Name', 'Staff Member', 'Movement Type', 'Quantity', 'Reason']);
            export_stream_entity($conn, 'stock', static function (array $movement) use ($output): void {
                export_csv_write_row($output, [
                    export_csv_text(date('Y-m-d H:i:s', strtotime($movement['created_at']))),
                    export_csv_text($movement['product_name']),
                    export_csv_text($movement['staff_name']),
                    export_csv_text(ucfirst(str_replace('_', ' ', (string)$movement['movement_type']))),
                    (int)$movement['quantity'],
                    export_csv_text($movement['reason'] ?? ''),
                ]);
            });
            break;

        case 'customers':
            export_csv_write_row($output, ['ID', 'Customer Name', 'Phone Number', 'Email Address', 'Physical Address', 'Added On']);
            export_stream_entity($conn, 'customers', static function (array $customer) use ($output): void {
                export_csv_write_row($output, [
                    (int)$customer['id'],
                    export_csv_text($customer['name']),
                    export_csv_text($customer['phone']),
                    export_csv_text($customer['email']),
                    export_csv_text($customer['address']),
                    export_csv_text(date('Y-m-d', strtotime($customer['created_at']))),
                ]);
            });
            break;

        case 'suppliers':
            export_csv_write_row($output, ['ID', 'Supplier Name', 'Phone Number', 'Email Address', 'Physical Address', 'Added On']);
            export_stream_entity($conn, 'suppliers', static function (array $supplier) use ($output): void {
                export_csv_write_row($output, [
                    (int)$supplier['id'],
                    export_csv_text($supplier['name']),
                    export_csv_text($supplier['phone']),
                    export_csv_text($supplier['email']),
                    export_csv_text($supplier['address']),
                    export_csv_text(date('Y-m-d', strtotime($supplier['created_at']))),
                ]);
            });
            break;

        case 'orders':
            $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $end_date = $_GET['end_date'] ?? date('Y-m-d');
            $type = $_GET['type'] ?? 'all';

            try {
                $order_filters = export_validate_order_filters($start_date, $end_date, $type);
            } catch (InvalidArgumentException $exception) {
                http_response_code(400);
                exit('Invalid export request.');
            }
            export_csv_write_row($output, ['Order ID', 'Date & Time', 'Cashier Name', 'Transaction Type', 'Total Amount ($)']);
            export_stream_entity($conn, 'orders', static function (array $order) use ($output): void {
                export_csv_write_row($output, [
                    (int)$order['id'],
                    export_csv_text(date('Y-m-d H:i:s', strtotime($order['order_date']))),
                    export_csv_text($order['staff_name']),
                    export_csv_text(ucfirst((string)$order['order_type'])),
                    round((float)$order['total_amount'], 2),
                ]);
            }, $order_filters);
            break;
    }
} catch (Throwable $exception) {
    export_csv_fail($exception->getMessage());
}

if (!fclose($output)) {
    error_log('CSV export failed while closing the output stream.');
}
exit();
