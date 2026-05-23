<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

// Verify user authentication and administrator privileges
verify_login();
require_admin();

$entity = isset($_GET['entity']) ? sanitize_input($_GET['entity']) : 'orders';

$filename = "myshop_export_" . $entity . "_" . date('Y-m-d_H-i') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
if ($output === false) {
    http_response_code(500);
    die("Failed to generate report.");
}

// Write UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

switch ($entity) {
    case 'products':
        fputcsv($output, ['ID', 'Product Name', 'Category', 'Price ($)', 'Current Stock', 'Alert Threshold', 'Valuation ($)']);
        $products = get_products($conn);
        foreach ($products as $p) {
            $valuation = $p['price'] * $p['stock'];
            fputcsv($output, [
                $p['id'],
                $p['name'],
                $p['category_name'] ?? 'Uncategorized',
                number_format($p['price'], 2, '.', ''),
                $p['stock'],
                $p['alert_threshold'],
                number_format($valuation, 2, '.', '')
            ]);
        }
        break;

    case 'stock':
        fputcsv($output, ['Date & Time', 'Product Name', 'Staff Member', 'Movement Type', 'Quantity', 'Reason']);
        $movements = get_stock_movements($conn);
        foreach ($movements as $m) {
            fputcsv($output, [
                date('Y-m-d H:i:s', strtotime($m['created_at'])),
                $m['product_name'],
                $m['staff_name'],
                ucfirst(str_replace('_', ' ', $m['movement_type'])),
                $m['quantity'],
                $m['reason'] ?? ''
            ]);
        }
        break;

    case 'customers':
        fputcsv($output, ['ID', 'Customer Name', 'Phone Number', 'Email Address', 'Physical Address', 'Added On']);
        $customers = get_customers($conn);
        foreach ($customers as $c) {
            fputcsv($output, [
                $c['id'],
                $c['name'],
                $c['phone'],
                $c['email'],
                $c['address'],
                date('Y-m-d', strtotime($c['created_at']))
            ]);
        }
        break;

    case 'suppliers':
        fputcsv($output, ['ID', 'Supplier Name', 'Phone Number', 'Email Address', 'Physical Address', 'Added On']);
        $suppliers = get_suppliers($conn);
        foreach ($suppliers as $s) {
            fputcsv($output, [
                $s['id'],
                $s['name'],
                $s['phone'],
                $s['email'],
                $s['address'],
                date('Y-m-d', strtotime($s['created_at']))
            ]);
        }
        break;

    case 'orders':
    default:
        $start_date = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : date('Y-m-d');
        $type = isset($_GET['type']) ? sanitize_input($_GET['type']) : 'all';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            die("Invalid date format.");
        }
        if (!in_array($type, ['all', 'sale', 'purchase'], true)) {
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
        if ($type !== 'all') {
            $stmt->bind_param("sss", $start_date, $end_date, $type);
        } else {
            $stmt->bind_param("ss", $start_date, $end_date);
        }
        
        $stmt->execute();
        $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        fputcsv($output, ['Order ID', 'Date & Time', 'Cashier Name', 'Transaction Type', 'Total Amount ($)']);
        foreach ($orders as $order) {
            fputcsv($output, [
                '#' . $order['id'],
                date('Y-m-d H:i:s', strtotime($order['order_date'])),
                $order['staff_name'],
                ucfirst($order['order_type']),
                number_format($order['total_amount'], 2, '.', '')
            ]);
        }
        break;
}

fclose($output);
exit();
