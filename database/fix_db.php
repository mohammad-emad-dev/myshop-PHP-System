<?php
require_once 'includes/db.php';

echo "Checking database structure...\n";

// Check if 'order_type' column exists
$check_query = "SHOW COLUMNS FROM `Order` LIKE 'order_type'";
$result = $conn->query($check_query);

if ($result && $result->num_rows > 0) {
    echo "Column 'order_type' already exists.\n";
} else {
    echo "Column 'order_type' is missing. Adding it now...\n";
    $alter_query = "ALTER TABLE `Order` ADD COLUMN order_type ENUM('sale', 'purchase') NOT NULL DEFAULT 'sale'";
    if ($conn->query($alter_query) === TRUE) {
        echo "Successfully added 'order_type' column.\n";
    } else {
        echo "Error checking/updating table: " . $conn->error . "\n";
    }
}

echo "Database check complete.\n";
?>