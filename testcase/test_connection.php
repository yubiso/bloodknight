<?php
// Simple test to see what's being output
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

require_once 'db_connect.php';

if (isset($db_error)) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => $db_error]);
} else if (isset($conn) && $conn && !$conn->connect_error) {
    ob_clean();
    echo json_encode(['status' => 'success', 'message' => 'Database connected successfully']);
} else {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
}
?>
