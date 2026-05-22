<?php
function sanitize_input($data)
{
    return htmlspecialchars(strip_tags(trim($data)));
}

function verify_login()
{
    if (!isset($_SESSION['staff_id'])) {
        header('Location: login.php');
        exit();
    }
}

function redirect($url)
{
    header("Location: $url");
    exit();
}

function get_products($conn)
{
    $sql = "SELECT p.*, c.name as category_name 
            FROM Product p 
            LEFT JOIN Category c ON p.category_id = c.id 
            ORDER BY p.created_at DESC";
    $result = $conn->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
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
    return $result->fetch_all(MYSQLI_ASSOC);
}

function log_stock_movement($conn, $product_id, $staff_id, $quantity, $movement_type, $reason = null)
{
    $stmt = $conn->prepare("INSERT INTO `StockMovement` (product_id, staff_id, quantity, movement_type, reason) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("iiiss", $product_id, $staff_id, $quantity, $movement_type, $reason);
    $result = $stmt->execute();
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

function create_product($conn, $staff_id, $name, $description, $price, $stock, $image_path = null, $alert_threshold = 10, $category_id = null)
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

        $stmt = $conn->prepare("INSERT INTO Product (name, description, price, stock, image_path, alert_threshold, category_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Failed to prepare statement");
        }
        $stmt->bind_param("ssdisii", $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id);
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

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("create_product failed: " . $e->getMessage());
        return false;
    }
}

function update_product($conn, $staff_id, $id, $name, $description, $price, $stock, $image_path = null, $alert_threshold = 10, $category_id = null)
{
    $conn->begin_transaction();
    try {
        $product = get_product_by_id($conn, $id);
        if (!$product) {
            throw new Exception("Product not found");
        }
        $old_stock = intval($product['stock']);
        $delta = $stock - $old_stock;

        if ($category_id === null) {
            $gen_stmt = $conn->query("SELECT id FROM Category WHERE name = 'General' LIMIT 1");
            if ($gen_stmt && $gen_stmt->num_rows > 0) {
                $category_id = intval($gen_stmt->fetch_assoc()['id']);
            }
        } else {
            $category_id = intval($category_id);
        }

        if ($image_path) {
            $stmt = $conn->prepare("UPDATE Product SET name = ?, description = ?, price = ?, stock = ?, image_path = ?, alert_threshold = ?, category_id = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare statement");
            }
            $stmt->bind_param("ssdisiii", $name, $description, $price, $stock, $image_path, $alert_threshold, $category_id, $id);
        } else {
            $stmt = $conn->prepare("UPDATE Product SET name = ?, description = ?, price = ?, stock = ?, alert_threshold = ?, category_id = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare statement");
            }
            $stmt->bind_param("ssdiiii", $name, $description, $price, $stock, $alert_threshold, $category_id, $id);
        }

        if (!$stmt->execute()) {
            throw new Exception("Product update failed");
        }
        $stmt->close();

        if ($delta != 0) {
            $reason = "Manual stock adjustment (from " . $old_stock . " to " . $stock . ")";
            if (!log_stock_movement($conn, $id, $staff_id, $delta, 'manual_adjustment', $reason)) {
                throw new Exception("Stock movement logging failed");
            }
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("update_product failed: " . $e->getMessage());
        return false;
    }
}

function delete_product($conn, $id)
{
    $conn->begin_transaction();
    try {
        // Delete dependencies in OrderDetail first (Cascade Delete)
        $stmt = $conn->prepare("DELETE FROM OrderDetail WHERE product_id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare delete detail statement");
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        // Delete the product
        $stmt = $conn->prepare("DELETE FROM Product WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Failed to prepare delete product statement");
        }
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $stmt->close();
            $conn->commit();
            return true;
        } else {
            $stmt->close();
            throw new Exception("Failed to delete product");
        }
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

function create_order($conn, $staff_id, $items, $order_type = 'sale')
{
    $conn->begin_transaction();
    try {
        $total = 0;
        foreach ($items as $item) {
            $total += $item['subtotal'];
        }

        $stmt = $conn->prepare("INSERT INTO `Order` (total_amount, staff_id, order_type) VALUES (?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Failed to prepare order statement");
        }
        $stmt->bind_param("dis", $total, $staff_id, $order_type);
        $stmt->execute();
        $order_id = $conn->insert_id;
        $stmt->close();

        $detail_stmt = $conn->prepare("INSERT INTO OrderDetail (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
        if (!$detail_stmt) {
            throw new Exception("Failed to prepare order details statement");
        }

        foreach ($items as $item) {
            $detail_stmt->bind_param("iiidd", $order_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']);
            $detail_stmt->execute();

            // Adjust stock based on order type
            if ($order_type === 'sale') {
                $update_stmt = $conn->prepare("UPDATE Product SET stock = stock - ? WHERE id = ?");
            } else { // purchase
                $update_stmt = $conn->prepare("UPDATE Product SET stock = stock + ? WHERE id = ?");
            }
            if (!$update_stmt) {
                throw new Exception("Failed to prepare update stock statement");
            }
            $update_stmt->bind_param("ii", $item['quantity'], $item['product_id']);
            $update_stmt->execute();
            $update_stmt->close();

            // Log stock movement
            $movement_qty = ($order_type === 'sale') ? -$item['quantity'] : $item['quantity'];
            $reason = ($order_type === 'sale') ? "Order #{$order_id} Sale" : "Order #{$order_id} Purchase";
            if (!log_stock_movement($conn, $item['product_id'], $staff_id, $movement_qty, $order_type, $reason)) {
                throw new Exception("Stock movement logging failed");
            }
        }
        $detail_stmt->close();

        $conn->commit();
        return $order_id;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("create_order failed: " . $e->getMessage());
        return false;
    }
}

function get_orders($conn)
{
    $sql = "SELECT o.*, s.full_name as staff_name 
            FROM `Order` o 
            JOIN Staff s ON o.staff_id = s.id 
            ORDER BY o.order_date DESC";
    return $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

function get_order_details($conn, $order_id)
{
    $stmt = $conn->prepare("SELECT od.*, p.name as product_name 
                           FROM OrderDetail od 
                           JOIN Product p ON od.product_id = p.id 
                           WHERE od.order_id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function get_dashboard_stats($conn)
{
    $stats = [];

    $result = $conn->query("SELECT COUNT(*) as count FROM Product");
    $stats['total_products'] = $result->fetch_assoc()['count'];

    $result = $conn->query("SELECT COUNT(*) as count FROM `Order`");
    $stats['total_orders'] = $result->fetch_assoc()['count'];

    $result = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM `Order`");
    $stats['total_sales'] = $result->fetch_assoc()['total'];

    $result = $conn->query("SELECT SUM(stock) as total FROM Product");
    $stats['total_stock'] = $result->fetch_assoc()['total'];

    return $stats;
}

function handle_image_upload($file)
{
    $target_dir = "uploads/";

    if (!file_exists($target_dir)) {
        if (!mkdir($target_dir, 0755, true)) {
            return false;
        }
    }

    // Validate PHP upload errors
    if (!isset($file['error']) || is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    // Validate file size (max 5MB)
    if ($file['size'] > 5000000) {
        return false;
    }

    // Validate MIME type securely using Fileinfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);
    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif'
    ];

    if (!array_key_exists($mime_type, $allowed_mimes)) {
        return false;
    }

    // Double check that it is a valid image using getimagesize
    $image_info = getimagesize($file['tmp_name']);
    if ($image_info === false) {
        return false;
    }

    // Generate secure random file name
    $extension = $allowed_mimes[$mime_type];
    $new_filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return $target_file;
    }

    return false;
}

/**
 * Starts a secure PHP session with HTTPOnly and SameSite cookie options.
 */
function start_secure_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        
        $is_secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        if ($is_secure) {
            ini_set('session.cookie_secure', 1);
        }
        
        session_start([
            'cookie_lifetime' => 0,
            'cookie_path' => '/',
            'cookie_secure' => $is_secure,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax'
        ]);
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
    start_secure_session();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Enforces admin privileges, redirecting cashier accounts to index.
 */
function require_admin()
{
    verify_login();
    if (!is_admin()) {
        header('Location: index.php?error=unauthorized');
        exit();
    }
}

/**
 * Retrieves all registered staff accounts.
 */
function get_staff_members($conn)
{
    $sql = "SELECT id, username, full_name, role, created_at FROM Staff ORDER BY created_at DESC";
    $result = $conn->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Inserts a new staff account into the database.
 */
function create_staff_member($conn, $username, $password, $full_name, $role)
{
    $username = trim($username);
    $full_name = trim($full_name);
    $role = trim($role);

    if (empty($username) || empty($password) || empty($full_name) || !in_array($role, ['admin', 'cashier'])) {
        return false;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO Staff (username, password, full_name, role) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ssss", $username, $hashed_password, $full_name, $role);
    return $stmt->execute();
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

    if ($id <= 0 || empty($username) || empty($full_name) || !in_array($role, ['admin', 'cashier'])) {
        return false;
    }

    if ($role === 'cashier') {
        $stmt = $conn->prepare("SELECT role FROM Staff WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if ($res && $res['role'] === 'admin') {
            $count_res = $conn->query("SELECT COUNT(*) as count FROM Staff WHERE role = 'admin'");
            $total_admins = $count_res->fetch_assoc()['count'];
            if ($total_admins <= 1) {
                return false;
            }
        }
    }

    if (!empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE Staff SET username = ?, full_name = ?, role = ?, password = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $username, $full_name, $role, $hashed_password, $id);
    } else {
        $stmt = $conn->prepare("UPDATE Staff SET username = ?, full_name = ?, role = ? WHERE id = ?");
        $stmt->bind_param("sssi", $username, $full_name, $role, $id);
    }

    return $stmt->execute();
}

/**
 * Removes a staff account from the system.
 */
function delete_staff_member($conn, $id, $current_admin_id)
{
    $id = (int)$id;
    $current_admin_id = (int)$current_admin_id;

    if ($id <= 0 || $id === $current_admin_id) {
        return false;
    }

    $stmt = $conn->prepare("SELECT role FROM Staff WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res && $res['role'] === 'admin') {
        $count_res = $conn->query("SELECT COUNT(*) as count FROM Staff WHERE role = 'admin'");
        $total_admins = $count_res->fetch_assoc()['count'];
        if ($total_admins <= 1) {
            return false;
        }
    }

    $stmt = $conn->prepare("DELETE FROM Staff WHERE id = ?");
    $stmt->bind_param("i", $id);
    return $stmt->execute();
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
