<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

header('Content-Type: application/json');

// All authenticated staff can currently view order details. Per-order
// authorization is intentionally deferred to a later batch.
if (!verify_login(false)) {
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
    
    // JSON safely transports the values. The browser renderer uses textContent
    // for every database-derived value instead of treating these strings as HTML.
    $response_order = [
        'id' => intval($order['id']),
        'order_date' => date('Y-m-d h:i A', strtotime($order['order_date'])),
        'total_amount' => floatval($order['total_amount']),
        'order_type' => (string)($order['order_type'] ?? 'sale'),
        'staff_name' => (string)($order['staff_name'] ?? ''),
        'customer_name' => (string)($order['customer_name'] ?? ''),
        'customer_phone' => (string)($order['customer_phone'] ?? ''),
        'customer_email' => (string)($order['customer_email'] ?? ''),
        'customer_address' => (string)($order['customer_address'] ?? ''),
        'supplier_name' => (string)($order['supplier_name'] ?? ''),
        'supplier_phone' => (string)($order['supplier_phone'] ?? ''),
        'supplier_email' => (string)($order['supplier_email'] ?? ''),
        'supplier_address' => (string)($order['supplier_address'] ?? '')
    ];
    
    $details = get_order_details($conn, $order_id);
    $response_items = [];
    foreach ($details as $row) {
        $row['product_name'] = (string)($row['product_name'] ?? '');
        $row['unit_price'] = floatval($row['unit_price']);
        $row['subtotal'] = floatval($row['subtotal']);
        $row['quantity'] = intval($row['quantity']);
        $response_items[] = $row;
    }
    
    echo json_encode([
        'order' => $response_order,
        'items' => $response_items
    ]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ID parameter.']);
}
?>
