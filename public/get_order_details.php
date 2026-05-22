<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

header('Content-Type: application/json');

// Session Authentication check to prevent unauthorised data exposure
if (!isset($_SESSION['staff_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please log in.']);
    exit();
}

if (isset($_GET['id'])) {
    $order_id = intval($_GET['id']);
    $order = get_order_by_id($conn, $order_id);
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit();
    }
    
    // XSS sanitisation on outputs returned to client
    $sanitized_order = [
        'id' => intval($order['id']),
        'order_date' => date('Y-m-d h:i A', strtotime($order['order_date'])),
        'total_amount' => floatval($order['total_amount']),
        'order_type' => htmlspecialchars($order['order_type'] ?? 'sale'),
        'staff_name' => htmlspecialchars($order['staff_name'] ?? ''),
        'customer_name' => htmlspecialchars($order['customer_name'] ?? ''),
        'customer_phone' => htmlspecialchars($order['customer_phone'] ?? ''),
        'customer_email' => htmlspecialchars($order['customer_email'] ?? ''),
        'customer_address' => htmlspecialchars($order['customer_address'] ?? ''),
        'supplier_name' => htmlspecialchars($order['supplier_name'] ?? ''),
        'supplier_phone' => htmlspecialchars($order['supplier_phone'] ?? ''),
        'supplier_email' => htmlspecialchars($order['supplier_email'] ?? ''),
        'supplier_address' => htmlspecialchars($order['supplier_address'] ?? '')
    ];
    
    $details = get_order_details($conn, $order_id);
    $sanitized_items = [];
    foreach ($details as $row) {
        $row['product_name'] = htmlspecialchars($row['product_name'] ?? '');
        $row['unit_price'] = floatval($row['unit_price']);
        $row['subtotal'] = floatval($row['subtotal']);
        $row['quantity'] = intval($row['quantity']);
        $sanitized_items[] = $row;
    }
    
    echo json_encode([
        'order' => $sanitized_order,
        'items' => $sanitized_items
    ]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ID parameter.']);
}
?>