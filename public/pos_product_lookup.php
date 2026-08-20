<?php

require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

auth_verify_login($conn);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$barcode = truncate_list_search($_GET['barcode'] ?? '');
if ($barcode === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid barcode lookup.']);
    exit;
}

$product = catalog_get_pos_product_by_barcode($conn, $barcode);
if ($product === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found.']);
    exit;
}

echo json_encode([
    'product' => [
        'id' => (int)$product['id'],
        'name' => (string)$product['name'],
        'price' => (float)$product['price'],
        'stock' => (int)$product['stock'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
