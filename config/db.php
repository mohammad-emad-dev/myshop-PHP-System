<?php
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'ioms_db';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Self-healing database migration for Phase 4: Low-stock alert threshold
$column_check = $conn->query("SHOW COLUMNS FROM `Product` LIKE 'alert_threshold'");
if ($column_check && $column_check->num_rows === 0) {
    // Column doesn't exist, execute alter table statement securely
    $alter_sql = "ALTER TABLE `Product` ADD COLUMN alert_threshold INT NOT NULL DEFAULT 10";
    if (!$conn->query($alter_sql)) {
        error_log("Database Migration Failed (alert_threshold): " . $conn->error);
    }
}

// Self-healing database migration for Phase 5: Staff Roles
$role_check = $conn->query("SHOW COLUMNS FROM `Staff` LIKE 'role'");
if ($role_check && $role_check->num_rows === 0) {
    $alter_role_sql = "ALTER TABLE `Staff` ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'cashier'";
    if ($conn->query($alter_role_sql)) {
        $conn->query("UPDATE `Staff` SET role = 'admin' WHERE username = 'admin'");
    } else {
        error_log("Database Migration Failed (role): " . $conn->error);
    }
}

// Self-healing database migration for Phase 6: StockMovement Ledger
$sm_table_check = $conn->query("SHOW TABLES LIKE 'StockMovement'");
if ($sm_table_check && $sm_table_check->num_rows === 0) {
    $create_sm_sql = "CREATE TABLE `StockMovement` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `product_id` INT NOT NULL,
        `staff_id` INT NOT NULL,
        `quantity` INT NOT NULL,
        `movement_type` VARCHAR(20) NOT NULL,
        `reason` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`product_id`) REFERENCES `Product`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`staff_id`) REFERENCES `Staff`(`id`) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    
    if ($conn->query($create_sm_sql)) {
        // Retroactively backfill movements from Orders and calculate baselines
        $orders_sql = "SELECT od.product_id, o.staff_id, od.quantity, o.order_type, o.order_date, o.id as order_id 
                       FROM OrderDetail od 
                       JOIN `Order` o ON od.order_id = o.id";
        $past_orders = $conn->query($orders_sql);
        if ($past_orders) {
            $insert_stmt = $conn->prepare("INSERT INTO `StockMovement` (product_id, staff_id, quantity, movement_type, reason, created_at) VALUES (?, ?, ?, ?, ?, ?)");
            while ($row = $past_orders->fetch_assoc()) {
                $prod_id = $row['product_id'];
                $stf_id = $row['staff_id'];
                $qty = ($row['order_type'] === 'sale') ? -$row['quantity'] : $row['quantity'];
                $type = $row['order_type'];
                $reason = ($row['order_type'] === 'sale') ? "Order #" . $row['order_id'] . " Sale" : "Order #" . $row['order_id'] . " Purchase";
                $created = $row['order_date'];
                
                $insert_stmt->bind_param("iiisss", $prod_id, $stf_id, $qty, $type, $reason, $created);
                $insert_stmt->execute();
            }
            $insert_stmt->close();
        }

        // Add baselines for products to match current actual stock
        $products_sql = "SELECT id, stock, (SELECT COALESCE(SUM(quantity), 0) FROM `StockMovement` WHERE product_id = Product.id) as sum_qty FROM `Product`";
        $products = $conn->query($products_sql);
        if ($products) {
            $admin_res = $conn->query("SELECT id FROM `Staff` WHERE role = 'admin' LIMIT 1");
            $admin_id = ($admin_res && $admin_res->num_rows > 0) ? $admin_res->fetch_assoc()['id'] : 1;
            
            $insert_stmt = $conn->prepare("INSERT INTO `StockMovement` (product_id, staff_id, quantity, movement_type, reason) VALUES (?, ?, ?, 'manual_adjustment', ?)");
            while ($p = $products->fetch_assoc()) {
                $delta = $p['stock'] - $p['sum_qty'];
                if ($delta != 0) {
                    $reason = "Existing Stock Baseline";
                    $insert_stmt->bind_param("iiis", $p['id'], $admin_id, $delta, $reason);
                    $insert_stmt->execute();
                }
            }
            $insert_stmt->close();
        }
    } else {
        error_log("Database Migration Failed (StockMovement): " . $conn->error);
    }
}

// Self-healing database migration for Phase 7: Product Categories
$cat_table_check = $conn->query("SHOW TABLES LIKE 'Category'");
if ($cat_table_check && $cat_table_check->num_rows === 0) {
    $create_cat_sql = "CREATE TABLE `Category` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(50) NOT NULL UNIQUE,
        `description` TEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    
    if (!$conn->query($create_cat_sql)) {
        error_log("Database Migration Failed (Category table creation): " . $conn->error);
    }
}

// Ensure default "General" category exists
$default_cat_check = $conn->query("SELECT id FROM `Category` WHERE name = 'General' LIMIT 1");
if ($default_cat_check && $default_cat_check->num_rows === 0) {
    $insert_default_cat = "INSERT INTO `Category` (name, description) VALUES ('General', 'Default classification for uncategorized products')";
    if (!$conn->query($insert_default_cat)) {
        error_log("Database Migration Failed (Default category seeding): " . $conn->error);
    }
}

// Add category_id column to Product table
$cat_col_check = $conn->query("SHOW COLUMNS FROM `Product` LIKE 'category_id'");
if ($cat_col_check && $cat_col_check->num_rows === 0) {
    $alter_prod_sql = "ALTER TABLE `Product` ADD COLUMN category_id INT NULL, ADD FOREIGN KEY (category_id) REFERENCES Category(id) ON DELETE SET NULL";
    if ($conn->query($alter_prod_sql)) {
        // Retroactively link existing products to the default General category
        $gen_cat_res = $conn->query("SELECT id FROM `Category` WHERE name = 'General' LIMIT 1");
        if ($gen_cat_res && $gen_cat_res->num_rows > 0) {
            $gen_cat_id = intval($gen_cat_res->fetch_assoc()['id']);
            $conn->query("UPDATE `Product` SET category_id = $gen_cat_id WHERE category_id IS NULL");
        }
    } else {
        error_log("Database Migration Failed (Product category_id column): " . $conn->error);
    }
}



