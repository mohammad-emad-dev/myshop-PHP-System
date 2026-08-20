<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

auth_verify_login($conn);

$order_id = isset($_GET['id']) ? sanitize_id($_GET['id']) : 0;

if ($order_id <= 0) {
    die("Invalid Order ID.");
}

$is_admin_user = auth_is_admin($conn);
$staff_scope = $is_admin_user ? null : (int)$_SESSION['staff_id'];
$order = orders_get_by_id($conn, $order_id, $staff_scope);
if (!$order) {
    http_response_code(404);
    audit_log_current_actor($conn, 'invoice_view', 'Order', $order_id, false, ['reason' => 'not_found_or_not_authorized']);
    exit("Invoice not found.");
}

$items = orders_get_details($conn, $order_id, $staff_scope);

$is_sale = ($order['order_type'] === 'sale');
$party_title = $is_sale ? "Customer:" : "Supplier:";
$party_name = $is_sale ? ($order['customer_name'] ?? 'Walk-in Customer') : ($order['supplier_name'] ?? 'General Supplier');
$csp_nonce = send_security_headers();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?php echo str_pad($order_id, 6, '0', STR_PAD_LEFT); ?></title>
    <!-- Use basic styling optimized for print, no external bloated CSS -->
    <style nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
        body {
            font-family: 'Courier New', Courier, monospace;
            background-color: #fff;
            color: #000;
            margin: 0;
            padding: 20px;
            font-size: 14px;
        }
        .invoice-box {
            max-width: 80mm; /* Standard thermal printer width */
            margin: auto;
            padding: 10px;
        }
        h1, h2, h3, h4, p {
            margin: 0;
            padding: 0;
            text-align: center;
        }
        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        
        .divider {
            border-bottom: 1px dashed #000;
            margin: 10px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            padding: 4px 0;
            vertical-align: top;
        }
        th {
            border-bottom: 1px dashed #000;
            text-align: left;
        }
        .qty-col { width: 15%; text-align: center; }
        .price-col { width: 25%; text-align: right; }
        .total-col { width: 25%; text-align: right; }
        
        .totals-table {
            width: 100%;
            margin-top: 10px;
        }
        .totals-table td {
            padding: 2px 0;
        }
        .grand-total {
            font-size: 16px;
            font-weight: bold;
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
            padding: 5px 0;
        }
        
        .footer-note {
            text-align: center;
            font-size: 12px;
            margin-top: 20px;
        }

        /* Adjustments for normal A4 printing (optional, it gracefully degrades) */
        @media print {
            body { margin: 0; padding: 0; }
            .invoice-box { width: 100%; max-width: 100%; border: none; padding: 0; }
        }
    </style>
</head>
<body>

<div class="invoice-box">
    <h2>myShop</h2>
    <p>Inventory & POS System</p>
    <p>123 Business Avenue, Tech City</p>
    <p>Tel: +1 234 567 890</p>
    
    <div class="divider"></div>
    
    <p class="text-left fw-bold">Invoice #<?php echo str_pad($order['id'], 6, '0', STR_PAD_LEFT); ?></p>
    <p class="text-left">Date: <?php echo date('Y-m-d H:i', strtotime($order['order_date'])); ?></p>
    <p class="text-left">Cashier: <?php echo htmlspecialchars($order['staff_name']); ?></p>
    <p class="text-left">Type: <?php echo $is_sale ? 'Sale (Receipt)' : 'Purchase (Bill)'; ?></p>
    
    <div class="divider"></div>
    
    <p class="text-left"><strong><?php echo $party_title; ?></strong> <?php echo htmlspecialchars($party_name); ?></p>
    
    <div class="divider"></div>
    
    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="qty-col">Qty</th>
                <th class="price-col">Price</th>
                <th class="total-col">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                <td class="qty-col"><?php echo $item['quantity']; ?></td>
                <td class="price-col"><?php echo number_format($item['unit_price'], 2); ?></td>
                <td class="total-col"><?php echo number_format($item['subtotal'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <table class="totals-table">
        <tr>
            <td class="text-right">Subtotal:</td>
            <td class="total-col">$<?php echo number_format($order['total_amount'], 2); ?></td>
        </tr>
        <tr>
            <td class="text-right">Tax (0%):</td>
            <td class="total-col">$0.00</td>
        </tr>
        <tr class="grand-total">
            <td class="text-right">TOTAL:</td>
            <td class="total-col">$<?php echo number_format($order['total_amount'], 2); ?></td>
        </tr>
    </table>
    
    <div class="divider"></div>
    
    <div class="footer-note">
        <p>Thank you for your business!</p>
        <p>Please keep this receipt for your records.</p>
        <p>*** <?php echo date('Y-m-d H:i:s'); ?> ***</p>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars($csp_nonce, ENT_QUOTES, 'UTF-8'); ?>">
    window.addEventListener('load', function() {
        window.print();
    });
</script>
</body>
</html>
