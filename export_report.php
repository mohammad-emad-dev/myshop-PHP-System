<?php
require_once 'includes/functions.php';
start_secure_session();
require_once 'includes/db.php';

// Verify user authentication
verify_login();

// Protect against cross-site request forgery and general unauthorized inputs
$start_date = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : '';
$type = isset($_GET['type']) ? sanitize_input($_GET['type']) : 'all';

// Defensive default ranges
if (empty($start_date)) {
    $start_date = date('Y-m-d', strtotime('-30 days'));
}
if (empty($end_date)) {
    $end_date = date('Y-m-d');
}

// Ensure valid date inputs to prevent anomalies/injection
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    http_response_code(400);
    die("Invalid date format. Expected YYYY-MM-DD.");
}

// Validate type
if (!in_array($type, ['all', 'sale', 'purchase'], true)) {
    $type = 'all';
}

/**
 * Retrieves filtered transactions based on date and type parameters.
 * Uses prepared statements defensively.
 */
function get_filtered_orders($conn, $start_date, $end_date, $type = 'all')
{
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
        return [];
    }
    
    if ($type !== 'all') {
        $stmt->bind_param("sss", $start_date, $end_date, $type);
    } else {
        $stmt->bind_param("ss", $start_date, $end_date);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $orders;
}

$orders = get_filtered_orders($conn, $start_date, $end_date, $type);

// Set HTTP headers for file download
$filename = "myshop_report_" . $type . "_" . $start_date . "_to_" . $end_date . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Open PHP output stream
$output = fopen('php://output', 'w');
if ($output === false) {
    http_response_code(500);
    die("Failed to generate report.");
}

// Write UTF-8 BOM for Excel compatibility (essential for supporting Arabic and international characters)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header columns
fputcsv($output, [
    'Order ID',
    'Date & Time',
    'Cashier Name',
    'Transaction Type',
    'Total Amount ($)'
]);

foreach ($orders as $order) {
    fputcsv($output, [
        '#' . $order['id'],
        date('Y-m-d H:i:s', strtotime($order['order_date'])),
        $order['staff_name'],
        ucfirst($order['order_type']),
        number_format($order['total_amount'], 2, '.', '')
    ]);
}

fclose($output);
exit();
