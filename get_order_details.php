<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $order_id = intval($_GET['id']);
    $details = get_order_details($conn, $order_id);
    echo json_encode($details);
} else {
    echo json_encode([]);
}
?>