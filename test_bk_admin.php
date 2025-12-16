<?php
// Simple test file to verify database and POST handling
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => 'Test endpoint is working',
    'method' => $_SERVER['REQUEST_METHOD'],
    'post_data' => $_POST,
    'action' => $_POST['action'] ?? 'none'
]);

// Test database connection
try {
    require_once 'db_connect.php';
    if (isset($conn) && $conn && !$conn->connect_error) {
        // Check if bk_admin table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'bk_admin'");
        $tableExists = $tableCheck->num_rows > 0;
        
        // Get table structure if it exists
        $tableInfo = [];
        if ($tableExists) {
            $result = $conn->query("DESCRIBE bk_admin");
            while ($row = $result->fetch_assoc()) {
                $tableInfo[] = $row;
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'db_connected' => true,
            'db_name' => $conn->query("SELECT DATABASE()")->fetch_row()[0],
            'bk_admin_table_exists' => $tableExists,
            'table_structure' => $tableInfo
        ], JSON_PRETTY_PRINT);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Database connection failed',
            'error' => $conn->connect_error ?? 'Unknown error'
        ], JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Exception: ' . $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>
