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

