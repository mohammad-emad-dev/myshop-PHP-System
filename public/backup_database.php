<?php
require_once '../includes/functions.php';
start_secure_session();
require_once '../config/db.php';

// Verify authentication and enforce strict admin authorization
verify_login();
require_admin();

// Disable execution time limit for large databases
set_time_limit(0);

// Get all tables in the database
$tables = [];
$result = $conn->query("SHOW TABLES");
if (!$result) {
    die("Database error: Could not retrieve tables.");
}
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

$sql_dump = "-- MyShop Enterprise Inventory & POS System\n";
$sql_dump .= "-- Database Backup\n";
$sql_dump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
$sql_dump .= "-- Server Version: " . $conn->server_info . "\n\n";
$sql_dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    // Get table creation syntax
    $result = $conn->query("SHOW CREATE TABLE `$table`");
    $row = $result->fetch_row();
    $sql_dump .= "--\n-- Table structure for table `$table`\n--\n\n";
    $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
    $sql_dump .= $row[1] . ";\n\n";

    // Get table data
    $result = $conn->query("SELECT * FROM `$table`");
    $num_rows = $result->num_rows;

    if ($num_rows > 0) {
        $sql_dump .= "--\n-- Dumping data for table `$table`\n--\n\n";
        
        while ($row = $result->fetch_assoc()) {
            $sql_dump .= "INSERT INTO `$table` VALUES(";
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = "NULL";
                } else {
                    // Escape special characters to prevent SQL syntax errors on import
                    $escaped_value = $conn->real_escape_string($value);
                    $values[] = "'" . $escaped_value . "'";
                }
            }
            $sql_dump .= implode(",", $values) . ");\n";
        }
        $sql_dump .= "\n";
    }
}

$sql_dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

// Set headers to force download as .sql file
$filename = "myshop_backup_" . date("Y-m-d_H-i-s") . ".sql";

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate'); // HTTP 1.1
header('Pragma: no-cache'); // HTTP 1.0
header('Expires: 0'); // Proxies
header('Content-Length: ' . strlen($sql_dump));

// Output the dump directly
echo $sql_dump;
exit();
