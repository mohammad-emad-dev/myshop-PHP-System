<?php
/**
 * Basic sanitization for general text inputs.
 */
function sanitize_input($data)
{
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Strict sanitization for email addresses.
 */
function sanitize_email($email)
{
    $email = trim($email);
    return filter_var($email, FILTER_SANITIZE_EMAIL);
}

/**
 * Strict sanitization for phone numbers (keeps only digits, +, and spaces).
 */
function sanitize_phone($phone)
{
    return preg_replace('/[^0-9+\s-]/', '', trim($phone));
}

/**
 * Strict sanitization for numeric IDs.
 */
function sanitize_id($id)
{
    return filter_var($id, FILTER_VALIDATE_INT) !== false ? (int)$id : 0;
}

/**
 * Applies the single password policy used by every password-changing flow.
 */
function password_meets_policy($password)
{
    if (!is_string($password)) {
        return false;
    }

    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    return $length >= 12;
}

function verify_login($redirect_on_failure = true)
{
    global $conn;

    start_secure_session();

    $fail_authentication = static function ($reason) use ($redirect_on_failure) {
        if ($reason !== null) {
            error_log('Authentication session invalidated: ' . $reason);
        }

        destroy_current_session();

        if ($redirect_on_failure) {
            redirect('login.php');
        }

        return false;
    };

    if (!isset($_SESSION['staff_id'])) {
        return $fail_authentication(null);
    }

    $staff_id = filter_var($_SESSION['staff_id'], FILTER_VALIDATE_INT);
    if ($staff_id === false || $staff_id <= 0) {
        return $fail_authentication('The session contains an invalid staff identifier.');
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        return $fail_authentication('The database connection is unavailable.');
    }

    $stmt = $conn->prepare(
        "SELECT id, full_name, role, is_active FROM Staff WHERE id = ? LIMIT 1"
    );

    if (!$stmt) {
        error_log('Authentication staff lookup prepare failed: ' . $conn->error);
        return $fail_authentication('The staff lookup could not be prepared.');
    }

    $stmt->bind_param('i', $staff_id);
    if (!$stmt->execute()) {
        error_log('Authentication staff lookup failed: ' . $stmt->error);
        $stmt->close();
        return $fail_authentication('The staff lookup failed.');
    }

    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (
        !$staff
        || (int)$staff['is_active'] !== 1
        || !in_array($staff['role'], ['admin', 'cashier'], true)
    ) {
        return $fail_authentication('The staff account is missing, disabled, or has an invalid role.');
    }

    $_SESSION['staff_id'] = (int)$staff['id'];
    $_SESSION['full_name'] = $staff['full_name'];
    $_SESSION['role'] = $staff['role'];
    $_SESSION['last_activity'] = time();
    $GLOBALS['current_staff_record'] = $staff;

    return true;
}

function redirect($url)
{
    header("Location: $url");
    exit();
}

function build_product_filter_sql($search, $filter, &$search_pattern)
{
    $conditions = [];
    $search_pattern = '';
    $search = trim((string)$search);

    if ($search !== '') {
        $search_pattern = '%' . $search . '%';
        $conditions[] = '(p.name LIKE ? OR c.name LIKE ? OR p.barcode LIKE ?)';
    }

    if ($filter === 'low_stock') {
        $conditions[] = 'p.stock <= p.alert_threshold';
    }

    return empty($conditions) ? '' : ' WHERE ' . implode(' AND ', $conditions);
}

function get_all_products($conn)
{
    $sql = "SELECT p.*, c.name as category_name
            FROM Product p
            LEFT JOIN Category c ON p.category_id = c.id
            ORDER BY p.created_at DESC, p.id DESC";
    $result = $conn->query($sql);

    if (!$result) {
        error_log('Product list query failed: ' . $conn->error);
        return [];
    }

    return $result->fetch_all(MYSQLI_ASSOC);
}

function count_products($conn, $search = '', $filter = '')
{
    $search_pattern = '';
    $where_sql = build_product_filter_sql($search, $filter, $search_pattern);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM Product p
         LEFT JOIN Category c ON p.category_id = c.id" . $where_sql
    );

    if (!$stmt) {
        error_log('Product count prepare failed: ' . $conn->error);
        return 0;
    }

    if ($search_pattern !== '') {
        if (!$stmt->bind_param('sss', $search_pattern, $search_pattern, $search_pattern)) {
            error_log('Product count bind failed: ' . $stmt->error);
            $stmt->close();
            return 0;
        }
    }

    if (!$stmt->execute()) {
        error_log('Product count execute failed: ' . $stmt->error);
        $stmt->close();
        return 0;
    }

    $result = $stmt->get_result();
    if (!$result) {
        error_log('Product count result retrieval failed: ' . $stmt->error);
        $stmt->close();
        return 0;
    }

    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? max(0, (int)$row['total']) : 0;
}

function get_products_page($conn, $search = '', $filter = '', $limit = 25, $offset = 0)
{
    $allowed_page_sizes = [10, 25, 50];
    $limit = (int)$limit;
    $offset = max(0, (int)$offset);
    if (!in_array($limit, $allowed_page_sizes, true)) {
        $limit = 25;
    }

    $search_pattern = '';
    $where_sql = build_product_filter_sql($search, $filter, $search_pattern);
    $stmt = $conn->prepare(
        "SELECT p.*, c.name as category_name
         FROM Product p
         LEFT JOIN Category c ON p.category_id = c.id" . $where_sql . "
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ? OFFSET ?"
    );

    if (!$stmt) {
        error_log('Product page prepare failed: ' . $conn->error);
        return [];
    }

    if ($search_pattern !== '') {
        if (!$stmt->bind_param('sssii', $search_pattern, $search_pattern, $search_pattern, $limit, $offset)) {
            error_log('Product page bind failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
    } elseif (!$stmt->bind_param('ii', $limit, $offset)) {
        error_log('Product page bind failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    if (!$stmt->execute()) {
        error_log('Product page execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    if (!$result) {
        error_log('Product page result retrieval failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }

    $products = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $products;
}

function get_product_by_id($conn, $id)
{
    $stmt = $conn->prepare("SELECT p.*, c.name as category_name 
                            FROM Product p 
                            LEFT JOIN Category c ON p.category_id = c.id 
                            WHERE p.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function get_low_stock_products($conn)
{
    $sql = "SELECT p.*, c.name as category_name 
            FROM Product p 
            LEFT JOIN Category c ON p.category_id = c.id 
            WHERE p.stock <= p.alert_threshold 
            ORDER BY p.stock ASC, p.name ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function log_stock_movement($conn, $product_id, $staff_id, $quantity, $movement_type, $reason = null)
{
    $stmt = $conn->prepare("INSERT INTO `StockMovement` (product_id, staff_id, quantity, movement_type, reason) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        error_log('Stock movement prepare failed: ' . $conn->error);
        return false;
    }

    if (!$stmt->bind_param("iiiss", $product_id, $staff_id, $quantity, $movement_type, $reason)) {
        error_log('Stock movement bind failed: ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $result = $stmt->execute();
    if (!$result) {
        error_log('Stock movement insert failed: ' . $stmt->error);
    } elseif ($stmt->affected_rows !== 1) {
        error_log('Stock movement insert affected an unexpected number of rows.');
        $result = false;
    }
    $stmt->close();
    return $result;
}

function get_stock_movements($conn, $product_id = null)
{
    if ($product_id !== null) {
        $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, s.full_name as staff_name 
                               FROM `StockMovement` sm
                               JOIN Product p ON sm.product_id = p.id
                               JOIN Staff s ON sm.staff_id = s.id
                               WHERE sm.product_id = ?
                               ORDER BY sm.created_at DESC, sm.id DESC");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    } else {
        $sql = "SELECT sm.*, p.name as product_name, s.full_name as staff_name 
                FROM `StockMovement` sm
                JOIN Product p ON sm.product_id = p.id
                JOIN Staff s ON sm.staff_id = s.id
                ORDER BY sm.created_at DESC, sm.id DESC";
        $result = $conn->query($sql);
        if (!$result) {
            return [];
        }
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}

function create_product($conn, $staff_id, $name, $description, $price, $stock, $image_path = null, $alert_threshold = 10, $category_id = null, $barcode = null)
{
    $conn->begin_transaction();
    try {
        if ($category_id === null) {
            $gen_stmt = $conn->query("SELECT id FROM Category WHERE name = 'General' LIMIT 1");
            if ($gen_stmt && $gen_stmt->num_rows > 0) {
                $category_id = intval($gen_stmt->fetch_assoc()['id']);
            }
        } else {
            $category_id = intval($category_id);
        }

        // Clean empty barcode strings to NULL to avoid unique constraint violations
        if (empty(trim($barcode))) {
            $barcode = null;
        } else {
            $barcode = trim($barcode);
        }

        $stmt = $conn->prepare("INSERT INTO Product (name, description, price, stock, image_path, alert_threshold, category_id, barcode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement");
        }
        $stmt->bind_param("ssdisiis", $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $barcode);
        if (!$stmt->execute()) {
            throw new Exception("Product insertion failed");
        }
        $product_id = $conn->insert_id;
        $stmt->close();

        if ($stock != 0) {
            if (!log_stock_movement($conn, $product_id, $staff_id, $stock, 'manual_adjustment', 'Initial stock allocation')) {
                throw new Exception("Stock movement logging failed");
            }
        }

        if (!$conn->commit()) {
            throw new Exception("Product creation commit failed");
        }
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("create_product failed: " . $e->getMessage());
        return false;
    }
}

function update_product($conn, $staff_id, $id, $name, $description, $price, $stock, $image_path = null, $alert_threshold = 10, $category_id = null, $barcode = null)
{
    $id = (int)$id;
    $stock = filter_var($stock, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => 2147483647]
    ]);

    if ($id <= 0 || $stock === false) {
        return false;
    }

    if (!$conn->begin_transaction()) {
        error_log('update_product failed: unable to start transaction.');
        return false;
    }

    try {
        $product_stmt = $conn->prepare("SELECT stock FROM Product WHERE id = ? FOR UPDATE");
        if (!$product_stmt) {
            throw new Exception("Failed to prepare product lock statement");
        }
        if (!$product_stmt->bind_param("i", $id)) {
            throw new Exception("Failed to bind product lock statement");
        }
        if (!$product_stmt->execute()) {
            throw new Exception("Failed to lock product");
        }

        $product_result = $product_stmt->get_result();
        if (!$product_result) {
            throw new Exception("Failed to read locked product");
        }
        $product = $product_result->fetch_assoc();
        $product_stmt->close();

        if (!$product) {
            throw new Exception("Product not found");
        }

        $old_stock = filter_var($product['stock'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 2147483647]
        ]);
        if ($old_stock === false) {
            throw new Exception("Product has invalid stock");
        }

        $delta = $stock - $old_stock;

        if ($category_id === null) {
            $gen_stmt = $conn->query("SELECT id FROM Category WHERE name = 'General' LIMIT 1");
            if (!$gen_stmt) {
                throw new Exception("Failed to load default category");
            }
            if ($gen_stmt->num_rows > 0) {
                $category_id = intval($gen_stmt->fetch_assoc()['id']);
            }
        } else {
            $category_id = intval($category_id);
        }

        // Clean empty barcode strings to NULL to avoid unique constraint violations
        if (empty(trim($barcode))) {
            $barcode = null;
        } else {
            $barcode = trim($barcode);
        }

        if ($image_path) {
            $stmt = $conn->prepare("UPDATE Product SET name = ?, description = ?, price = ?, stock = ?, image_path = ?, alert_threshold = ?, category_id = ?, barcode = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare statement");
            }
            if (!$stmt->bind_param("ssdisiisi", $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $barcode, $id)) {
                throw new Exception("Failed to bind statement");
            }
        } else {
            $stmt = $conn->prepare("UPDATE Product SET name = ?, description = ?, price = ?, stock = ?, alert_threshold = ?, category_id = ?, barcode = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare statement");
            }
            if (!$stmt->bind_param("ssdiiisi", $name, $description, $price, $stock, $alert_threshold, $category_id, $barcode, $id)) {
                throw new Exception("Failed to bind statement");
            }
        }

        if (!$stmt->execute()) {
            throw new Exception("Product update failed");
        }
        if ($stmt->affected_rows < 0 || $stmt->affected_rows > 1) {
            throw new Exception("Product update affected an unexpected number of rows");
        }
        $stmt->close();

        if ($delta != 0) {
            $reason = "Manual stock adjustment (from " . $old_stock . " to " . $stock . ")";
            if (!log_stock_movement($conn, $id, $staff_id, $delta, 'manual_adjustment', $reason)) {
                throw new Exception("Stock movement logging failed");
            }
        }

        if (!$conn->commit()) {
            throw new Exception("Failed to commit product update");
        }
        return true;
    } catch (Throwable $e) {
        if (!$conn->rollback()) {
            error_log('update_product rollback failed: ' . $conn->error);
        }
        error_log("update_product failed: " . $e->getMessage());
        return false;
    }
}

function delete_product($conn, $id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return false;
    }

    if (!$conn->begin_transaction()) {
        error_log('delete_product failed: unable to start transaction.');
        return false;
    }

    try {
        $product_stmt = $conn->prepare("SELECT id FROM Product WHERE id = ? FOR UPDATE");
        if (!$product_stmt) {
            throw new Exception("Failed to prepare product lock statement");
        }
        if (!$product_stmt->bind_param("i", $id)) {
            throw new Exception("Failed to bind product lock statement");
        }
        if (!$product_stmt->execute()) {
            throw new Exception("Failed to lock product");
        }

        $product_result = $product_stmt->get_result();
        if (!$product_result) {
            throw new Exception("Failed to read product");
        }
        if ($product_result->num_rows !== 1) {
            throw new Exception("Product not found");
        }
        $product_stmt->close();

        $order_detail_stmt = $conn->prepare("SELECT id FROM OrderDetail WHERE product_id = ? LIMIT 1");
        if (!$order_detail_stmt) {
            throw new Exception("Failed to prepare order history check");
        }
        if (!$order_detail_stmt->bind_param("i", $id)) {
            throw new Exception("Failed to bind order history check");
        }
        if (!$order_detail_stmt->execute()) {
            throw new Exception("Failed to check order history");
        }
        $order_detail_result = $order_detail_stmt->get_result();
        if (!$order_detail_result) {
            throw new Exception("Failed to read order history");
        }
        $has_order_history = $order_detail_result->num_rows > 0;
        $order_detail_stmt->close();
        if ($has_order_history) {
            throw new Exception("Product has historical order details");
        }

        $movement_stmt = $conn->prepare("SELECT id FROM StockMovement WHERE product_id = ? LIMIT 1");
        if (!$movement_stmt) {
            throw new Exception("Failed to prepare stock history check");
        }
        if (!$movement_stmt->bind_param("i", $id)) {
            throw new Exception("Failed to bind stock history check");
        }
        if (!$movement_stmt->execute()) {
            throw new Exception("Failed to check stock history");
        }
        $movement_result = $movement_stmt->get_result();
        if (!$movement_result) {
            throw new Exception("Failed to read stock history");
        }
        $has_stock_history = $movement_result->num_rows > 0;
        $movement_stmt->close();
        if ($has_stock_history) {
            throw new Exception("Product has historical stock movements");
        }

        $delete_stmt = $conn->prepare("DELETE FROM Product WHERE id = ?");
        if (!$delete_stmt) {
            throw new Exception("Failed to prepare product deletion");
        }
        if (!$delete_stmt->bind_param("i", $id)) {
            throw new Exception("Failed to bind product deletion");
        }
        if (!$delete_stmt->execute()) {
            throw new Exception("Failed to delete product");
        }
        if ($delete_stmt->affected_rows !== 1) {
            throw new Exception("Product deletion affected an unexpected number of rows");
        }
        $delete_stmt->close();

        if (!$conn->commit()) {
            throw new Exception("Failed to commit product deletion");
        }
        return true;
    } catch (Throwable $e) {
        if (!$conn->rollback()) {
            error_log('delete_product rollback failed: ' . $conn->error);
        }
        error_log("delete_product failed: " . $e->getMessage());
        return false;
    }
}

function create_order($conn, $staff_id, $items, $order_type = 'sale', $customer_id = null, $supplier_id = null)
{
    $staff_id = (int)$staff_id;
    $max_stock = 2147483647;
    $max_money_cents = 9999999999;

    if ($staff_id <= 0 || !is_array($items) || empty($items)) {
        return false;
    }
    if (!in_array($order_type, ['sale', 'purchase'], true)) {
        return false;
    }

    $normalized_items = [];
    try {
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new Exception('Invalid order item.');
            }

            $product_id = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $max_stock]
            ]);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $max_stock]
            ]);

            if ($product_id === false || $quantity === false) {
                throw new Exception('Invalid product or quantity.');
            }

            $existing_quantity = $normalized_items[$product_id] ?? 0;
            if ($quantity > ($max_stock - $existing_quantity)) {
                throw new Exception('Order quantity exceeds the supported limit.');
            }
            $normalized_items[$product_id] = $existing_quantity + $quantity;
        }

        if (empty($normalized_items)) {
            throw new Exception('Order cart is empty.');
        }

        if ($order_type === 'sale') {
            $customer_id = filter_var($customer_id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $max_stock]
            ]);
            if ($customer_id === false) {
                throw new Exception('Invalid customer.');
            }
            $supplier_id = null;
        } else {
            $supplier_id = filter_var($supplier_id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $max_stock]
            ]);
            if ($supplier_id === false) {
                throw new Exception('Invalid supplier.');
            }
            $customer_id = null;
        }

        ksort($normalized_items, SORT_NUMERIC);
    } catch (Throwable $e) {
        error_log('create_order validation failed: ' . $e->getMessage());
        return false;
    }

    if (!$conn->begin_transaction()) {
        error_log('create_order failed: unable to start transaction.');
        return false;
    }

    try {
        $staff_stmt = $conn->prepare(
            "SELECT role, is_active FROM Staff WHERE id = ? LIMIT 1"
        );
        if (!$staff_stmt) {
            throw new Exception('Failed to prepare order staff authorization statement.');
        }
        if (!$staff_stmt->bind_param('i', $staff_id)) {
            throw new Exception('Failed to bind order staff authorization statement.');
        }
        if (!$staff_stmt->execute()) {
            throw new Exception('Failed to verify order staff authorization.');
        }
        $staff_result = $staff_stmt->get_result();
        if (!$staff_result) {
            throw new Exception('Failed to read order staff authorization result.');
        }
        $staff_record = $staff_result->fetch_assoc();
        $staff_stmt->close();

        if (
            !$staff_record
            || (int)$staff_record['is_active'] !== 1
            || !in_array($staff_record['role'], ['admin', 'cashier'], true)
            || ($order_type === 'purchase' && $staff_record['role'] !== 'admin')
        ) {
            throw new Exception('The staff account is not authorized for this order type.');
        }

        $party_table = $order_type === 'sale' ? 'Customer' : 'Supplier';
        $party_id = $order_type === 'sale' ? $customer_id : $supplier_id;
        $party_stmt = $conn->prepare("SELECT id FROM {$party_table} WHERE id = ? LIMIT 1");
        if (!$party_stmt) {
            throw new Exception('Failed to prepare party validation statement.');
        }
        if (!$party_stmt->bind_param("i", $party_id)) {
            throw new Exception('Failed to bind party validation statement.');
        }
        if (!$party_stmt->execute()) {
            throw new Exception('Failed to validate party.');
        }
        $party_result = $party_stmt->get_result();
        if (!$party_result) {
            throw new Exception('Failed to read party validation result.');
        }
        if ($party_result->num_rows !== 1) {
            throw new Exception('Selected party does not exist.');
        }
        $party_stmt->close();

        $product_stmt = $conn->prepare(
            "SELECT id, price, stock FROM Product WHERE id = ? FOR UPDATE"
        );
        if (!$product_stmt) {
            throw new Exception('Failed to prepare product lock statement.');
        }

        $locked_items = [];
        $total_cents = 0;
        foreach ($normalized_items as $product_id => $quantity) {
            if (!$product_stmt->bind_param("i", $product_id)) {
                throw new Exception('Failed to bind product lock statement.');
            }
            if (!$product_stmt->execute()) {
                throw new Exception('Failed to lock product.');
            }

            $product_result = $product_stmt->get_result();
            if (!$product_result) {
                throw new Exception('Failed to read locked product.');
            }
            $product = $product_result->fetch_assoc();
            if (!$product) {
                throw new Exception('Selected product does not exist.');
            }

            $current_stock = filter_var($product['stock'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => $max_stock]
            ]);
            if ($current_stock === false) {
                throw new Exception('Product has invalid stock.');
            }

            if ($order_type === 'sale' && $quantity > $current_stock) {
                throw new Exception('Insufficient stock.');
            }

            if (!is_numeric($product['price']) || !is_finite((float)$product['price'])) {
                throw new Exception('Product has invalid price.');
            }
            $price = (float)$product['price'];
            $price_cents = (int)round($price * 100);
            if ($price < 0 || $price_cents < 0 || $price_cents > $max_money_cents) {
                throw new Exception('Product has invalid price.');
            }
            if ($price_cents > 0 && $quantity > intdiv($max_money_cents, $price_cents)) {
                throw new Exception('Order subtotal exceeds the supported limit.');
            }

            $subtotal_cents = $price_cents * $quantity;
            if ($subtotal_cents > ($max_money_cents - $total_cents)) {
                throw new Exception('Order total exceeds the supported limit.');
            }
            $total_cents += $subtotal_cents;

            $locked_items[] = [
                'product_id' => (int)$product_id,
                'quantity' => $quantity,
                'current_stock' => $current_stock,
                'new_stock' => $order_type === 'sale'
                    ? $current_stock - $quantity
                    : $current_stock + $quantity,
                'unit_price' => $price_cents / 100,
                'subtotal' => $subtotal_cents / 100
            ];
        }
        $product_stmt->close();

        foreach ($locked_items as $locked_item) {
            if ($locked_item['new_stock'] < 0 || $locked_item['new_stock'] > $max_stock) {
                throw new Exception('Stock update exceeds the supported range.');
            }
        }

        $total = $total_cents / 100;
        if ($order_type === 'sale') {
            $order_stmt = $conn->prepare(
                "INSERT INTO `Order` (total_amount, staff_id, order_type, customer_id) VALUES (?, ?, ?, ?)"
            );
            if (!$order_stmt) {
                throw new Exception('Failed to prepare order statement.');
            }
            if (!$order_stmt->bind_param("disi", $total, $staff_id, $order_type, $customer_id)) {
                throw new Exception('Failed to bind order statement.');
            }
        } else {
            $order_stmt = $conn->prepare(
                "INSERT INTO `Order` (total_amount, staff_id, order_type, supplier_id) VALUES (?, ?, ?, ?)"
            );
            if (!$order_stmt) {
                throw new Exception('Failed to prepare order statement.');
            }
            if (!$order_stmt->bind_param("disi", $total, $staff_id, $order_type, $supplier_id)) {
                throw new Exception('Failed to bind order statement.');
            }
        }

        if (!$order_stmt->execute()) {
            throw new Exception('Failed to insert order.');
        }
        if ($order_stmt->affected_rows !== 1) {
            throw new Exception('Order insert affected an unexpected number of rows.');
        }
        $order_id = (int)$conn->insert_id;
        if ($order_id <= 0) {
            throw new Exception('Order ID was not created.');
        }
        $order_stmt->close();

        $detail_stmt = $conn->prepare(
            "INSERT INTO OrderDetail (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)"
        );
        if (!$detail_stmt) {
            throw new Exception('Failed to prepare order detail statement.');
        }

        $stock_stmt = $conn->prepare(
            "UPDATE Product SET stock = ? WHERE id = ? AND stock = ?"
        );
        if (!$stock_stmt) {
            throw new Exception('Failed to prepare stock update statement.');
        }

        foreach ($locked_items as $locked_item) {
            $detail_product_id = $locked_item['product_id'];
            $detail_quantity = $locked_item['quantity'];
            $detail_unit_price = $locked_item['unit_price'];
            $detail_subtotal = $locked_item['subtotal'];

            if (!$detail_stmt->bind_param(
                "iiidd",
                $order_id,
                $detail_product_id,
                $detail_quantity,
                $detail_unit_price,
                $detail_subtotal
            )) {
                throw new Exception('Failed to bind order detail statement.');
            }
            if (!$detail_stmt->execute()) {
                throw new Exception('Failed to insert order detail.');
            }
            if ($detail_stmt->affected_rows !== 1) {
                throw new Exception('Order detail insert affected an unexpected number of rows.');
            }

            $new_stock = $locked_item['new_stock'];
            $old_stock = $locked_item['current_stock'];
            if (!$stock_stmt->bind_param("iii", $new_stock, $detail_product_id, $old_stock)) {
                throw new Exception('Failed to bind stock update statement.');
            }
            if (!$stock_stmt->execute()) {
                throw new Exception('Failed to update product stock.');
            }
            if ($stock_stmt->affected_rows !== 1) {
                throw new Exception('Stock update affected an unexpected number of rows.');
            }

            $movement_quantity = $order_type === 'sale'
                ? -$detail_quantity
                : $detail_quantity;
            $reason = $order_type === 'sale'
                ? "Order #{$order_id} Sale"
                : "Order #{$order_id} Purchase";
            if (!log_stock_movement(
                $conn,
                $detail_product_id,
                $staff_id,
                $movement_quantity,
                $order_type,
                $reason
            )) {
                throw new Exception('Stock movement logging failed.');
            }
        }

        $detail_stmt->close();
        $stock_stmt->close();

        if (!$conn->commit()) {
            throw new Exception('Failed to commit order transaction.');
        }
        return $order_id;
    } catch (Throwable $e) {
        if (!$conn->rollback()) {
            error_log('create_order rollback failed: ' . $conn->error);
        }
        error_log("CRITICAL ORDER ERROR: " . $e->getMessage());
        return false;
    }
}

function get_orders($conn)
{
    $sql = "SELECT o.*, s.full_name as staff_name, c.name as customer_name, sup.name as supplier_name 
            FROM `Order` o 
            JOIN Staff s ON o.staff_id = s.id 
            LEFT JOIN Customer c ON o.customer_id = c.id
            LEFT JOIN Supplier sup ON o.supplier_id = sup.id
            ORDER BY o.order_date DESC";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function get_orders_for_staff($conn, $staff_id)
{
    $staff_id = (int)$staff_id;
    if ($staff_id <= 0) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT o.*, s.full_name as staff_name, c.name as customer_name, sup.name as supplier_name
         FROM `Order` o
         JOIN Staff s ON o.staff_id = s.id
         LEFT JOIN Customer c ON o.customer_id = c.id
         LEFT JOIN Supplier sup ON o.supplier_id = sup.id
         WHERE o.staff_id = ?
         ORDER BY o.order_date DESC"
    );
    if (!$stmt) {
        error_log('Scoped order list prepare failed: ' . $conn->error);
        return [];
    }
    if (!$stmt->bind_param('i', $staff_id)) {
        error_log('Scoped order list bind failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    if (!$stmt->execute()) {
        error_log('Scoped order list execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    if (!$result) {
        error_log('Scoped order list result failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $orders;
}

function get_order_by_id($conn, $order_id, $staff_id = null)
{
    $order_id = (int)$order_id;
    if ($order_id <= 0) {
        return null;
    }

    $sql = "SELECT o.*, s.full_name as staff_name,
                                   c.name as customer_name, c.phone as customer_phone, c.email as customer_email, c.address as customer_address,
                                   sup.name as supplier_name, sup.phone as supplier_phone, sup.email as supplier_email, sup.address as supplier_address
                            FROM `Order` o 
                            JOIN Staff s ON o.staff_id = s.id 
                            LEFT JOIN Customer c ON o.customer_id = c.id
                            LEFT JOIN Supplier sup ON o.supplier_id = sup.id
                            WHERE o.id = ?";
    if ($staff_id !== null) {
        $staff_id = (int)$staff_id;
        if ($staff_id <= 0) {
            return null;
        }
        $sql .= " AND o.staff_id = ?";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Order lookup prepare failed: ' . $conn->error);
        return null;
    }
    if ($staff_id === null) {
        if (!$stmt->bind_param('i', $order_id)) {
            error_log('Order lookup bind failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
    } elseif (!$stmt->bind_param('ii', $order_id, $staff_id)) {
        error_log('Scoped order lookup bind failed: ' . $stmt->error);
        $stmt->close();
        return null;
    }
    if (!$stmt->execute()) {
        error_log('Order lookup execute failed: ' . $stmt->error);
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    if (!$result) {
        error_log('Order lookup result failed: ' . $stmt->error);
        $stmt->close();
        return null;
    }
    $res = $result->fetch_assoc();
    $stmt->close();
    return $res;
}

function get_order_details($conn, $order_id, $staff_id = null)
{
    $order_id = (int)$order_id;
    if ($order_id <= 0) {
        return [];
    }

    $sql = "SELECT od.*, p.name as product_name
                           FROM OrderDetail od 
                           JOIN Product p ON od.product_id = p.id 
                           WHERE od.order_id = ?";
    if ($staff_id !== null) {
        $staff_id = (int)$staff_id;
        if ($staff_id <= 0) {
            return [];
        }
        $sql = "SELECT od.*, p.name as product_name
                FROM OrderDetail od
                JOIN Product p ON od.product_id = p.id
                JOIN `Order` o ON o.id = od.order_id
                WHERE od.order_id = ? AND o.staff_id = ?";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Order detail lookup prepare failed: ' . $conn->error);
        return [];
    }
    if ($staff_id === null) {
        if (!$stmt->bind_param('i', $order_id)) {
            error_log('Order detail lookup bind failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }
    } elseif (!$stmt->bind_param('ii', $order_id, $staff_id)) {
        error_log('Scoped order detail lookup bind failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    if (!$stmt->execute()) {
        error_log('Order detail lookup execute failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    if (!$result) {
        error_log('Order detail lookup result failed: ' . $stmt->error);
        $stmt->close();
        return [];
    }
    $res = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $res;
}

function get_dashboard_stats($conn)
{
    $stats = [
        'total_products' => 0,
        'total_orders'   => 0,
        'total_sales'    => 0.0,
        'total_stock'    => 0
    ];

    $result = $conn->query("SELECT COUNT(*) as count FROM Product");
    if ($result) {
        $stats['total_products'] = (int) $result->fetch_assoc()['count'];
    }

    $result = $conn->query("SELECT COUNT(*) as count FROM `Order`");
    if ($result) {
        $stats['total_orders'] = (int) $result->fetch_assoc()['count'];
    }

    // Only count revenue from SALES, not purchases
    $result = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM `Order` WHERE order_type = 'sale'");
    if ($result) {
        $stats['total_sales'] = (float) $result->fetch_assoc()['total'];
    }

    $result = $conn->query("SELECT COALESCE(SUM(stock), 0) as total FROM Product");
    if ($result) {
        $stats['total_stock'] = (int) $result->fetch_assoc()['total'];
    }

    return $stats;
}

function handle_image_upload($file)
{
    $max_file_size = 5 * 1024 * 1024;
    $max_image_width = 4096;
    $max_image_height = 4096;
    $max_image_pixels = 16 * 1024 * 1024;
    $target_file = null;

    if (!is_array($file)) {
        return false;
    }

    $upload_error = $file['error'] ?? null;
    $temporary_file = $file['tmp_name'] ?? null;
    if (!is_int($upload_error) && !(is_string($upload_error) && ctype_digit($upload_error))) {
        return false;
    }
    $upload_error = (int)$upload_error;

    // Validate the server-reported upload result and reject malformed/non-uploaded files.
    if ($upload_error !== UPLOAD_ERR_OK || !is_string($temporary_file) || $temporary_file === '' || !is_uploaded_file($temporary_file)) {
        return false;
    }

    $actual_file_size = @filesize($temporary_file);
    if ($actual_file_size === false || $actual_file_size <= 0 || $actual_file_size > $max_file_size) {
        return false;
    }

    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif'
    ];

    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $temporary_file);
    } catch (Throwable $exception) {
        error_log('Image upload MIME inspection failed.');
        return false;
    }

    if (!is_string($mime_type) || !array_key_exists($mime_type, $allowed_mimes)) {
        return false;
    }

    // Validate image structure and impose dimensions/pixel-count limits before storing it.
    $image_info = @getimagesize($temporary_file);
    if (!is_array($image_info)) {
        return false;
    }

    $image_width = isset($image_info[0]) ? (int)$image_info[0] : 0;
    $image_height = isset($image_info[1]) ? (int)$image_info[1] : 0;
    $image_mime = isset($image_info['mime']) ? (string)$image_info['mime'] : '';
    if ($image_width <= 0 || $image_height <= 0 || $image_width > $max_image_width || $image_height > $max_image_height || ($image_width * $image_height) > $max_image_pixels || !array_key_exists($image_mime, $allowed_mimes)) {
        return false;
    }

    $public_directory = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public');
    if ($public_directory === false) {
        error_log('Image upload failed: public directory could not be resolved.');
        return false;
    }

    $upload_directory = $public_directory . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($upload_directory) && !mkdir($upload_directory, 0755, true) && !is_dir($upload_directory)) {
        error_log('Image upload failed: upload directory could not be created.');
        return false;
    }

    $resolved_upload_directory = realpath($upload_directory);
    if ($resolved_upload_directory === false || strtolower($resolved_upload_directory) !== strtolower($upload_directory)) {
        error_log('Image upload failed: upload directory path validation failed.');
        return false;
    }

    try {
        $extension = $allowed_mimes[$mime_type];
        do {
            $new_filename = bin2hex(random_bytes(16)) . '.' . $extension;
            $target_file = $resolved_upload_directory . DIRECTORY_SEPARATOR . $new_filename;
        } while (file_exists($target_file));
    } catch (Throwable $exception) {
        error_log('Image upload failed: secure filename generation failed.');
        return false;
    }

    if (!move_uploaded_file($temporary_file, $target_file)) {
        if (is_file($target_file)) {
            @unlink($target_file);
        }
        return false;
    }

    // Store the document-root-relative URL, never the absolute filesystem path.
    return 'uploads/' . $new_filename;
}

/**
 * Delete only an image created by handle_image_upload() during the current request.
 * Invalid paths and paths outside the canonical public/uploads directory are ignored.
 */
function delete_newly_uploaded_image($relative_path)
{
    if (!is_string($relative_path) || preg_match('#\Auploads/[a-f0-9]{32}\.(?:jpe?g|png|gif)\z#D', $relative_path) !== 1) {
        return false;
    }

    $public_directory = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public');
    if ($public_directory === false) {
        return false;
    }

    $upload_directory = $public_directory . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($upload_directory)) {
        return true;
    }

    $resolved_upload_directory = realpath($upload_directory);
    if ($resolved_upload_directory === false || strtolower($resolved_upload_directory) !== strtolower($upload_directory)) {
        return false;
    }

    $filename = substr($relative_path, strlen('uploads/'));
    $resolved_target = realpath($resolved_upload_directory . DIRECTORY_SEPARATOR . $filename);
    if ($resolved_target === false) {
        return true;
    }

    if (strtolower(dirname($resolved_target)) !== strtolower($resolved_upload_directory) || !is_file($resolved_target)) {
        return false;
    }

    // The path has been validated and resolved inside public/uploads.
    return @unlink($resolved_target) || !file_exists($resolved_target);
}

if (!defined('SESSION_IDLE_TIMEOUT')) {
    define('SESSION_IDLE_TIMEOUT', 1800);
}

/**
 * Destroy the current session and expire its browser cookie.
 */
function destroy_current_session()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        unset($GLOBALS['current_staff_record']);
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }

    session_destroy();
    unset($GLOBALS['current_staff_record']);
}

/**
 * Starts a secure PHP session and expires idle sessions after 30 minutes.
 */
function start_secure_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');

        $is_secure = isset($_SERVER['HTTPS'])
            && strtolower((string)$_SERVER['HTTPS']) !== 'off'
            && $_SERVER['HTTPS'] !== '';
        ini_set('session.cookie_secure', $is_secure ? '1' : '0');

        session_start([
            'cookie_lifetime' => 0,
            'cookie_path' => '/',
            'cookie_secure' => $is_secure,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax'
        ]);

        $now = time();
        $last_activity = $_SESSION['last_activity'] ?? null;
        if (
            $last_activity !== null
            && (!is_numeric($last_activity) || ($now - (int)$last_activity) > SESSION_IDLE_TIMEOUT)
        ) {
            destroy_current_session();
            return;
        }

        $_SESSION['last_activity'] = $now;
    }
}

/**
 * Generates a CSRF token and stores it in the session if not already set.
 */
function generate_csrf_token()
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifies a given token against the one stored in the session.
 */
function verify_csrf_token($token)
{
    start_secure_session();
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Returns the per-response nonce used by the remaining PHP-rendered inline
 * script and style blocks.
 */
function get_csp_nonce()
{
    if (!isset($GLOBALS['csp_nonce'])) {
        $GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));
    }

    return $GLOBALS['csp_nonce'];
}

/**
 * Sends the enforced browser policy for HTML responses.
 *
 * Inline scripts and the remaining print stylesheet require the per-response
 * nonce. Inline style attributes are not permitted; unsafe-eval is never
 * permitted.
 */
function send_security_headers()
{
    $nonce = get_csp_nonce();

    if (!headers_sent()) {
        $policy = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "script-src-attr 'none'",
            "style-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com",
            "style-src-attr 'none'",
            "img-src 'self' data:",
            "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "connect-src 'self'"
        ]);

        header('Content-Security-Policy: ' . $policy);
    }

    return $nonce;
}

/**
 * Returns verified SRI metadata for pinned external assets.
 * Null means the asset is local or intentionally documented as unsupported.
 */
function get_asset_integrity($asset_url)
{
    $integrity = [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css'
            => 'sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN',
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js'
            => 'sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css'
            => 'sha384-blOohCVdhjmtROpu8+CfTnUWham9nkX7P7OZQMst+RUnhtoY/9qemFAkIKOYxDI3',
        'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.min.css'
            => 'sha384-QeY5AQZQxuccpv3R7xMnhIyrxSmzwsqI9A8hFrcDhljKd7rfQHZgnTh8gpCM5kWu',
        'https://cdn.jsdelivr.net/npm/sweetalert2@11.10.0/dist/sweetalert2.all.min.js'
            => 'sha384-5VBAkNWNEnA0Y+L5aWNg6fHumW6MdNSl4unYF6X6pHsXjltAvKa6VxLur8ZAQlzu',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'
            => 'sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4',
        'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js'
            => 'sha384-Yv5O+t3uE3hunW8uyrbpPW3iw6/5/Y7HitWJBLgqfMoA36NogMmy+8wWZMpn3HWc'
    ];

    return $integrity[$asset_url] ?? null;
}

/**
 * Fetches sales and purchase chart data for the last N days.
 * Pads missing dates with 0.0 values.
 */
function get_chart_data($conn, $days = 7)
{
    $data = [];
    
    // Generate empty values for the last N days to ensure complete timeline
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $data[$date] = [
            'label' => date('M d', strtotime($date)),
            'sales' => 0.0,
            'purchases' => 0.0
        ];
    }
    
    // Fetch aggregated daily sales and purchases
    $query = "SELECT DATE(order_date) as order_day, order_type, SUM(total_amount) as total 
              FROM `Order` 
              WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              GROUP BY DATE(order_date), order_type
              ORDER BY DATE(order_date) ASC";
              
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $days);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $day = $row['order_day'];
            if (isset($data[$day])) {
                if ($row['order_type'] === 'sale') {
                    $data[$day]['sales'] = floatval($row['total']);
                } else if ($row['order_type'] === 'purchase') {
                    $data[$day]['purchases'] = floatval($row['total']);
                }
            }
        }
    }
    
    return array_values($data);
}

/**
 * Checks if the current session belongs to an administrator.
 */
function is_admin()
{
    if (!isset($GLOBALS['current_staff_record']) || !is_array($GLOBALS['current_staff_record'])) {
        if (!verify_login(false)) {
            return false;
        }
    }

    return isset($GLOBALS['current_staff_record']['role'])
        && $GLOBALS['current_staff_record']['role'] === 'admin';
}

/**
 * Enforces administrator privileges with a non-leaking forbidden response.
 */
function require_admin()
{
    verify_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

/**
 * Retrieves all registered staff accounts.
 */
function get_staff_members($conn)
{
    $sql = "SELECT id, username, full_name, role, is_active, created_at FROM Staff ORDER BY created_at DESC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Inserts a new staff account into the database.
 */
function create_staff_member($conn, $username, $password, $full_name, $role)
{
    $username = trim($username);
    $full_name = trim($full_name);
    $role = trim($role);

    if (
        empty($username)
        || !password_meets_policy($password)
        || empty($full_name)
        || !in_array($role, ['admin', 'cashier'], true)
    ) {
        return false;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    if ($hashed_password === false) {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO Staff (username, password, full_name, role) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }

    if (!$stmt->bind_param("ssss", $username, $hashed_password, $full_name, $role)) {
        $stmt->close();
        return false;
    }
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Modifies an existing staff account's credentials and role.
 */
function update_staff_member($conn, $id, $username, $full_name, $role, $password = null)
{
    $id = (int)$id;
    $username = trim($username);
    $full_name = trim($full_name);
    $role = trim($role);

    if ($id <= 0 || empty($username) || empty($full_name) || !in_array($role, ['admin', 'cashier'], true)) {
        return false;
    }

    if ($password !== null && $password !== '' && !password_meets_policy($password)) {
        return false;
    }

    if (!$conn->begin_transaction()) {
        error_log('update_staff_member failed: unable to start transaction.');
        return false;
    }

    try {
        $target_stmt = $conn->prepare("SELECT role, is_active FROM Staff WHERE id = ? FOR UPDATE");
        if (!$target_stmt) {
            throw new Exception('Failed to prepare target staff query.');
        }
        if (!$target_stmt->bind_param("i", $id)) {
            throw new Exception('Failed to bind target staff query.');
        }
        if (!$target_stmt->execute()) {
            throw new Exception('Failed to load target staff account.');
        }

        $target_result = $target_stmt->get_result();
        if (!$target_result) {
            throw new Exception('Failed to read target staff account.');
        }
        $target = $target_result->fetch_assoc();
        $target_stmt->close();

        if (!$target) {
            throw new Exception('Target staff account does not exist.');
        }

        if ($role === 'cashier' && $target['role'] === 'admin' && (int)$target['is_active'] === 1) {
            $admins_stmt = $conn->prepare(
                "SELECT id FROM Staff WHERE role = 'admin' AND is_active = 1 FOR UPDATE"
            );
            if (!$admins_stmt) {
                throw new Exception('Failed to prepare active administrator query.');
            }
            if (!$admins_stmt->execute()) {
                throw new Exception('Failed to lock active administrators.');
            }

            $admins_result = $admins_stmt->get_result();
            if (!$admins_result) {
                throw new Exception('Failed to read active administrators.');
            }
            $active_admin_count = $admins_result->num_rows;
            $admins_stmt->close();

            if ($active_admin_count <= 1) {
                throw new Exception('The last active administrator cannot be demoted.');
            }
        }

        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            if ($hashed_password === false) {
                throw new Exception('Failed to hash staff password.');
            }

            $update_stmt = $conn->prepare("UPDATE Staff SET username = ?, full_name = ?, role = ?, password = ? WHERE id = ?");
            if (!$update_stmt) {
                throw new Exception('Failed to prepare staff update.');
            }
            if (!$update_stmt->bind_param("ssssi", $username, $full_name, $role, $hashed_password, $id)) {
                throw new Exception('Failed to bind staff update.');
            }
        } else {
            $update_stmt = $conn->prepare("UPDATE Staff SET username = ?, full_name = ?, role = ? WHERE id = ?");
            if (!$update_stmt) {
                throw new Exception('Failed to prepare staff update.');
            }
            if (!$update_stmt->bind_param("sssi", $username, $full_name, $role, $id)) {
                throw new Exception('Failed to bind staff update.');
            }
        }

        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update staff account.');
        }
        $update_stmt->close();

        if (!$conn->commit()) {
            throw new Exception('Failed to commit staff update.');
        }

        return true;
    } catch (Throwable $e) {
        if (!$conn->rollback()) {
            error_log('update_staff_member rollback failed: ' . $conn->error);
        }
        error_log('update_staff_member failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Deactivates a staff account without removing historical references.
 */
function delete_staff_member($conn, $id, $current_admin_id)
{
    $id = (int)$id;
    $current_admin_id = (int)$current_admin_id;

    if ($id <= 0 || $id === $current_admin_id) {
        return false;
    }

    if (!$conn->begin_transaction()) {
        error_log('delete_staff_member failed: unable to start transaction.');
        return false;
    }

    try {
        $target_stmt = $conn->prepare("SELECT role, is_active FROM Staff WHERE id = ? FOR UPDATE");
        if (!$target_stmt) {
            throw new Exception('Failed to prepare target staff query.');
        }
        if (!$target_stmt->bind_param("i", $id)) {
            throw new Exception('Failed to bind target staff query.');
        }
        if (!$target_stmt->execute()) {
            throw new Exception('Failed to load target staff account.');
        }

        $target_result = $target_stmt->get_result();
        if (!$target_result) {
            throw new Exception('Failed to read target staff account.');
        }
        $target = $target_result->fetch_assoc();
        $target_stmt->close();

        if (!$target) {
            throw new Exception('Target staff account does not exist.');
        }

        if ($target['role'] === 'admin' && (int)$target['is_active'] === 1) {
            $admins_stmt = $conn->prepare(
                "SELECT id FROM Staff WHERE role = 'admin' AND is_active = 1 FOR UPDATE"
            );
            if (!$admins_stmt) {
                throw new Exception('Failed to prepare active administrator query.');
            }
            if (!$admins_stmt->execute()) {
                throw new Exception('Failed to lock active administrators.');
            }

            $admins_result = $admins_stmt->get_result();
            if (!$admins_result) {
                throw new Exception('Failed to read active administrators.');
            }
            $active_admin_count = $admins_result->num_rows;
            $admins_stmt->close();

            if ($active_admin_count <= 1) {
                throw new Exception('The last active administrator cannot be deactivated.');
            }
        }

        $deactivate_stmt = $conn->prepare("UPDATE Staff SET is_active = 0 WHERE id = ?");
        if (!$deactivate_stmt) {
            throw new Exception('Failed to prepare staff deactivation.');
        }
        if (!$deactivate_stmt->bind_param("i", $id)) {
            throw new Exception('Failed to bind staff deactivation.');
        }
        if (!$deactivate_stmt->execute()) {
            throw new Exception('Failed to deactivate staff account.');
        }
        $deactivate_stmt->close();

        if (!$conn->commit()) {
            throw new Exception('Failed to commit staff deactivation.');
        }

        return true;
    } catch (Throwable $e) {
        if (!$conn->rollback()) {
            error_log('delete_staff_member rollback failed: ' . $conn->error);
        }
        error_log('delete_staff_member failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Enables or disables a staff account with administrator safety checks.
 *
 * The transaction locks the target account and active administrator rows so
 * two concurrent requests cannot both disable the last active administrator.
 */
function set_staff_active($conn, $id, $is_active, $current_admin_id)
{
    $id = (int)$id;
    $is_active = (int)$is_active;
    $current_admin_id = (int)$current_admin_id;

    if ($id <= 0 || !in_array($is_active, [0, 1], true) || $id === $current_admin_id) {
        return false;
    }

    if (!$conn->begin_transaction()) {
        error_log('set_staff_active failed: unable to start transaction.');
        return false;
    }

    try {
        $target_stmt = $conn->prepare("SELECT role, is_active FROM Staff WHERE id = ? FOR UPDATE");
        if (!$target_stmt) {
            throw new Exception('Failed to prepare staff status query.');
        }

        $target_stmt->bind_param("i", $id);
        if (!$target_stmt->execute()) {
            throw new Exception('Failed to load staff status.');
        }

        $target = $target_stmt->get_result()->fetch_assoc();
        $target_stmt->close();

        if (!$target) {
            throw new Exception('Staff account does not exist.');
        }

        if ((int)$target['is_active'] === $is_active) {
            if (!$conn->commit()) {
                throw new Exception('Failed to commit staff status.');
            }
            return true;
        }

        if ($is_active === 0 && $target['role'] === 'admin') {
            $admins_stmt = $conn->prepare(
                "SELECT id FROM Staff WHERE role = 'admin' AND is_active = 1 FOR UPDATE"
            );
            if (!$admins_stmt || !$admins_stmt->execute()) {
                throw new Exception('Failed to verify active administrators.');
            }

            $active_admin_count = $admins_stmt->get_result()->num_rows;
            $admins_stmt->close();

            if ($active_admin_count <= 1) {
                throw new Exception('The last active administrator cannot be disabled.');
            }
        }

        $update_stmt = $conn->prepare("UPDATE Staff SET is_active = ? WHERE id = ?");
        if (!$update_stmt) {
            throw new Exception('Failed to prepare staff status update.');
        }

        $update_stmt->bind_param("ii", $is_active, $id);
        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update staff status.');
        }
        $update_stmt->close();

        if (!$conn->commit()) {
            throw new Exception('Failed to commit staff status.');
        }
        return true;
    } catch (Exception $e) {
        if (!$conn->rollback()) {
            error_log('set_staff_active rollback failed: ' . $conn->error);
        }
        error_log('set_staff_active failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Retrieves all product categories.
 */
function get_categories($conn)
{
    $sql = "SELECT c.*, COUNT(p.id) as product_count 
            FROM Category c 
            LEFT JOIN Product p ON c.id = p.category_id 
            GROUP BY c.id 
            ORDER BY c.name ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Retrieves a specific category by its ID.
 */
function get_category_by_id($conn, $id)
{
    $stmt = $conn->prepare("SELECT * FROM Category WHERE id = ?");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res;
}

/**
 * Creates a new product category.
 */
function create_category($conn, $name, $description)
{
    $name = trim($name);
    $description = trim($description);

    if (empty($name)) {
        return false;
    }

    // Verify uniqueness
    $stmt = $conn->prepare("SELECT id FROM Category WHERE name = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $name);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        return false;
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO Category (name, description) VALUES (?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ss", $name, $description);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Updates an existing product category.
 */
function update_category($conn, $id, $name, $description)
{
    $id = (int)$id;
    $name = trim($name);
    $description = trim($description);

    if ($id <= 0 || empty($name)) {
        return false;
    }

    // Check if another category has the same name
    $stmt = $conn->prepare("SELECT id FROM Category WHERE name = ? AND id != ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("si", $name, $id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        return false;
    }
    $stmt->close();

    // Check if category is 'General' and we are trying to rename it
    $stmt = $conn->prepare("SELECT name FROM Category WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $old_name_res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($old_name_res && $old_name_res['name'] === 'General' && $name !== 'General') {
        // Prevent renaming the default category
        return false;
    }

    $stmt = $conn->prepare("UPDATE Category SET name = ?, description = ? WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssi", $name, $description, $id);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Deletes a category and handles reassigning of products.
 */
function delete_category($conn, $id)
{
    $id = (int)$id;
    
    // Prevent deleting the default category
    $stmt = $conn->prepare("SELECT name FROM Category WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res || $res['name'] === 'General') {
        return false;
    }

    $conn->begin_transaction();
    try {
        $gen_stmt = $conn->query("SELECT id FROM Category WHERE name = 'General' LIMIT 1");
        $gen_cat_id = null;
        if ($gen_stmt && $gen_stmt->num_rows > 0) {
            $gen_cat_id = intval($gen_stmt->fetch_assoc()['id']);
        }

        $del_stmt = $conn->prepare("DELETE FROM Category WHERE id = ?");
        if (!$del_stmt) {
            throw new Exception("Failed to prepare delete statement");
        }
        $del_stmt->bind_param("i", $id);
        if (!$del_stmt->execute()) {
            throw new Exception("Failed to delete category");
        }
        $del_stmt->close();

        if ($gen_cat_id !== null) {
            $conn->query("UPDATE Product SET category_id = $gen_cat_id WHERE category_id IS NULL");
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("delete_category failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetch all customers sorted alphabetically by name.
 */
function get_customers($conn)
{
    $sql = "SELECT * FROM Customer ORDER BY name ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Fetch customer details by ID.
 */
function get_customer_by_id($conn, $id)
{
    $id = (int)$id;
    $stmt = $conn->prepare("SELECT * FROM Customer WHERE id = ?");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res;
}

/**
 * Creates a new customer record securely.
 */
function create_customer($conn, $name, $phone, $email, $address)
{
    $name = sanitize_input($name);
    $phone = sanitize_phone($phone);
    $email = sanitize_email($email);
    $address = sanitize_input($address);

    if (empty($name)) {
        return false;
    }

    $stmt = $conn->prepare("INSERT INTO Customer (name, phone, email, address) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssss", $name, $phone, $email, $address);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Updates customer details, preventing changes to default Walk-in Customer (ID = 1).
 */
function update_customer($conn, $id, $name, $phone, $email, $address)
{
    $id = sanitize_id($id);
    $name = sanitize_input($name);
    $phone = sanitize_phone($phone);
    $email = sanitize_email($email);
    $address = sanitize_input($address);

    if ($id <= 1 || empty($name)) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE Customer SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssssi", $name, $phone, $email, $address, $id);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Deletes a customer, preventing deletion of default Walk-in Customer (ID = 1).
 */
function delete_customer($conn, $id)
{
    $id = (int)$id;
    if ($id <= 1) {
        return false;
    }

    $stmt = $conn->prepare("DELETE FROM Customer WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $id);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Fetch all suppliers sorted alphabetically by name.
 */
function get_suppliers($conn)
{
    $sql = "SELECT * FROM Supplier ORDER BY name ASC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Fetch supplier details by ID.
 */
function get_supplier_by_id($conn, $id)
{
    $id = (int)$id;
    $stmt = $conn->prepare("SELECT * FROM Supplier WHERE id = ?");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res;
}

/**
 * Creates a new supplier record securely.
 */
function create_supplier($conn, $name, $phone, $email, $address)
{
    $name = sanitize_input($name);
    $phone = sanitize_phone($phone);
    $email = sanitize_email($email);
    $address = sanitize_input($address);

    if (empty($name)) {
        return false;
    }

    $stmt = $conn->prepare("INSERT INTO Supplier (name, phone, email, address) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssss", $name, $phone, $email, $address);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Updates supplier details, preventing changes to default General Supplier (ID = 1).
 */
function update_supplier($conn, $id, $name, $phone, $email, $address)
{
    $id = sanitize_id($id);
    $name = sanitize_input($name);
    $phone = sanitize_phone($phone);
    $email = sanitize_email($email);
    $address = sanitize_input($address);

    if ($id <= 1 || empty($name)) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE Supplier SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssssi", $name, $phone, $email, $address, $id);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Deletes a supplier, preventing deletion of default General Supplier (ID = 1).
 */
function delete_supplier($conn, $id)
{
    $id = (int)$id;
    if ($id <= 1) {
        return false;
    }

    $stmt = $conn->prepare("DELETE FROM Supplier WHERE id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $id);
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}

/**
 * Compute inventory valuation based on current stock levels and unit prices.
 */
function get_inventory_valuation($conn)
{
    $sql = "SELECT SUM(stock * price) as valuation FROM Product";
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        return (float)($row['valuation'] ?? 0.0);
    }
    return 0.0;
}

/**
 * Fetch top selling products sorted by total quantity sold.
 */
function get_top_selling_products($conn, $limit = 5)
{
    $limit = max(1, min((int)$limit, 50));
    $sql = "SELECT p.name, SUM(od.quantity) as total_qty, SUM(od.subtotal) as total_sales 
            FROM OrderDetail od 
            JOIN `Order` o ON od.order_id = o.id 
            JOIN Product p ON od.product_id = p.id 
            WHERE o.order_type = 'sale' 
            GROUP BY od.product_id, p.name 
            ORDER BY total_qty DESC 
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Group sales volume distributions based on product categories.
 */
function get_category_sales_distribution($conn)
{
    $sql = "SELECT COALESCE(c.name, 'Uncategorized') as category_name, SUM(od.subtotal) as total_sales 
            FROM OrderDetail od 
            JOIN `Order` o ON od.order_id = o.id 
            JOIN Product p ON od.product_id = p.id 
            LEFT JOIN Category c ON p.category_id = c.id 
            WHERE o.order_type = 'sale' 
            GROUP BY p.category_id, c.name 
            ORDER BY total_sales DESC";
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}
