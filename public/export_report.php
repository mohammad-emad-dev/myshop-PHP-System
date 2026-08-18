<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

// Verify user authentication and administrator privileges.
verify_login();
require_admin();

function export_csv_text($value)
{
    $text = (string)($value ?? '');

    // Excel and similar spreadsheet applications can execute these values as formulas.
    if ($text !== '' && preg_match('/^[=+\-@\t\r\n]/', $text) === 1) {
        return "'" . $text;
    }

    return $text;
}

function export_csv_write_row($output, array $row)
{
    if (fputcsv($output, $row) === false) {
        error_log('CSV export failed while writing a row.');
        if (!headers_sent()) {
            http_response_code(500);
        }
        exit('Export is temporarily unavailable.');
    }
}

function export_csv_fail($log_message, $status_code = 500)
{
    error_log('CSV export failed: ' . $log_message);
    if (!headers_sent()) {
        http_response_code($status_code);
    }
    exit('Export is temporarily unavailable.');
}

$export_definitions = [
    'products' => ['filename' => 'myshop-products.csv'],
    'stock' => ['filename' => 'myshop-stock-movements.csv'],
    'customers' => ['filename' => 'myshop-customers.csv'],
    'suppliers' => ['filename' => 'myshop-suppliers.csv'],
    'orders' => ['filename' => 'myshop-orders.csv']
];

$requested_entity = $_GET['entity'] ?? 'orders';
if (!is_string($requested_entity) || !array_key_exists($requested_entity, $export_definitions)) {
    http_response_code(400);
    exit('Invalid export request.');
}

$entity = $requested_entity;
$filename = $export_definitions[$entity]['filename'];

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

switch ($entity) {
    case 'products':
        export_csv_write_row($output, ['ID', 'Product Name', 'Category', 'Price ($)', 'Current Stock', 'Alert Threshold', 'Valuation ($)']);
        $products = get_products($conn);
        foreach ($products as $product) {
            $price = (float)$product['price'];
            $stock = (int)$product['stock'];
            export_csv_write_row($output, [
                (int)$product['id'],
                export_csv_text($product['name']),
                export_csv_text($product['category_name'] ?? 'Uncategorized'),
                round($price, 2),
                $stock,
                (int)$product['alert_threshold'],
                round($price * $stock, 2)
            ]);
        }
        break;

    case 'stock':
        export_csv_write_row($output, ['Date & Time', 'Product Name', 'Staff Member', 'Movement Type', 'Quantity', 'Reason']);
        $movements = get_stock_movements($conn);
        foreach ($movements as $movement) {
            export_csv_write_row($output, [
                export_csv_text(date('Y-m-d H:i:s', strtotime($movement['created_at']))),
                export_csv_text($movement['product_name']),
                export_csv_text($movement['staff_name']),
                export_csv_text(ucfirst(str_replace('_', ' ', (string)$movement['movement_type']))),
                (int)$movement['quantity'],
                export_csv_text($movement['reason'] ?? '')
            ]);
        }
        break;

    case 'customers':
        export_csv_write_row($output, ['ID', 'Customer Name', 'Phone Number', 'Email Address', 'Physical Address', 'Added On']);
        $customers = get_customers($conn);
        foreach ($customers as $customer) {
            export_csv_write_row($output, [
                (int)$customer['id'],
                export_csv_text($customer['name']),
                export_csv_text($customer['phone']),
                export_csv_text($customer['email']),
                export_csv_text($customer['address']),
                export_csv_text(date('Y-m-d', strtotime($customer['created_at'])))
            ]);
        }
        break;

    case 'suppliers':
        export_csv_write_row($output, ['ID', 'Supplier Name', 'Phone Number', 'Email Address', 'Physical Address', 'Added On']);
        $suppliers = get_suppliers($conn);
        foreach ($suppliers as $supplier) {
            export_csv_write_row($output, [
                (int)$supplier['id'],
                export_csv_text($supplier['name']),
                export_csv_text($supplier['phone']),
                export_csv_text($supplier['email']),
                export_csv_text($supplier['address']),
                export_csv_text(date('Y-m-d', strtotime($supplier['created_at'])))
            ]);
        }
        break;

    case 'orders':
        $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $end_date = $_GET['end_date'] ?? date('Y-m-d');
        $type = $_GET['type'] ?? 'all';

        if (!is_string($start_date) || !is_string($end_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            http_response_code(400);
            exit('Invalid export request.');
        }
        if (!is_string($type) || !in_array($type, ['all', 'sale', 'purchase'], true)) {
            $type = 'all';
        }

        $sql = "SELECT o.*, s.full_name as staff_name
                FROM `Order` o
                JOIN Staff s ON o.staff_id = s.id
                WHERE DATE(o.order_date) BETWEEN ? AND ?";

        if ($type !== 'all') {
            $sql .= " AND o.order_type = ?";
        }
        $sql .= " ORDER BY o.order_date DESC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            export_csv_fail('Unable to prepare the order export query: ' . $conn->error);
        }

        $bound = $type !== 'all'
            ? $stmt->bind_param('sss', $start_date, $end_date, $type)
            : $stmt->bind_param('ss', $start_date, $end_date);
        if (!$bound) {
            $stmt->close();
            export_csv_fail('Unable to bind the order export query.');
        }

        if (!$stmt->execute()) {
            $technical_error = $stmt->error;
            $stmt->close();
            export_csv_fail('Unable to execute the order export query: ' . $technical_error);
        }

        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            export_csv_fail('Unable to read the order export result.');
        }
        $orders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        export_csv_write_row($output, ['Order ID', 'Date & Time', 'Cashier Name', 'Transaction Type', 'Total Amount ($)']);
        foreach ($orders as $order) {
            export_csv_write_row($output, [
                (int)$order['id'],
                export_csv_text(date('Y-m-d H:i:s', strtotime($order['order_date']))),
                export_csv_text($order['staff_name']),
                export_csv_text(ucfirst((string)$order['order_type'])),
                round((float)$order['total_amount'], 2)
            ]);
        }
        break;
}

if (!fclose($output)) {
    error_log('CSV export failed while closing the output stream.');
}
exit();
