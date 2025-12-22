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
    $sql = "SELECT * FROM Product ORDER BY created_at DESC";
    $result = $conn->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

function get_product_by_id($conn, $id)
{
    $stmt = $conn->prepare("SELECT * FROM Product WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function create_product($conn, $name, $description, $price, $stock, $image_path = null)
{
    $stmt = $conn->prepare("INSERT INTO Product (name, description, price, stock, image_path) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdis", $name, $description, $price, $stock, $image_path);
    return $stmt->execute();
}

function update_product($conn, $id, $name, $description, $price, $stock, $image_path = null)
{
    if ($image_path) {
        $stmt = $conn->prepare("UPDATE Product SET name = ?, description = ?, price = ?, stock = ?, image_path = ? WHERE id = ?");
        $stmt->bind_param("ssdisi", $name, $description, $price, $stock, $image_path, $id);
    } else {
        $stmt = $conn->prepare("UPDATE Product SET name = ?, description = ?, price = ?, stock = ? WHERE id = ?");
        $stmt->bind_param("ssdii", $name, $description, $price, $stock, $id);
    }
    return $stmt->execute();
}

function delete_product($conn, $id)
{
    $conn->begin_transaction();
    try {
        // Delete dependencies in OrderDetail first (Cascade Delete)
        $stmt = $conn->prepare("DELETE FROM OrderDetail WHERE product_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        // Delete the product
        $stmt = $conn->prepare("DELETE FROM Product WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $conn->commit();
            return true;
        } else {
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
        $stmt->bind_param("dis", $total, $staff_id, $order_type);
        $stmt->execute();
        $order_id = $conn->insert_id;

        $stmt = $conn->prepare("INSERT INTO OrderDetail (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");

        foreach ($items as $item) {
            $stmt->bind_param("iiidd", $order_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']);
            $stmt->execute();

            // Adjust stock based on order type
            if ($order_type === 'sale') {
                $update_stmt = $conn->prepare("UPDATE Product SET stock = stock - ? WHERE id = ?");
            } else { // purchase
                $update_stmt = $conn->prepare("UPDATE Product SET stock = stock + ? WHERE id = ?");
            }
            $update_stmt->bind_param("ii", $item['quantity'], $item['product_id']);
            $update_stmt->execute();
        }

        $conn->commit();
        return $order_id;
    } catch (Exception $e) {
        $conn->rollback();
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
        mkdir($target_dir, 0777, true);
    }

    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];

    if (!in_array($file_extension, $allowed)) {
        return false;
    }

    $new_filename = uniqid() . '.' . $file_extension;
    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return $target_file;
    }

    return false;
}
