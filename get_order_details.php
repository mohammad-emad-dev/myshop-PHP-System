<?php
require_once 'includes/functions.php';
start_secure_session();
require_once 'includes/db.php';

header('Content-Type: application/json');

// Session Authentication check to prevent unauthorised data exposure
if (!isset($_SESSION['staff_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please log in.']);
    exit();
}

if (isset($_GET['id'])) {
    $order_id = intval($_GET['id']);
    $details = get_order_details($conn, $order_id);
    
    // XSS sanitisation on outputs returned to client
    $sanitized_details = [];
    foreach ($details as $row) {
        $row['product_name'] = htmlspecialchars($row['product_name'] ?? '');
        $sanitized_details[] = $row;
    }
    
    echo json_encode($sanitized_details);
} else {
    echo json_encode([]);
}
?>