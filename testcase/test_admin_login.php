<?php
// Test script to verify admin login setup
// Visit: http://localhost/bloodknight/test_admin_login.php

require_once 'db_connect.php';

echo "<h2>BloodKnight Admin Login Test</h2>";

// Test 1: Check database connection
echo "<h3>1. Database Connection:</h3>";
if ($conn->connect_error) {
    echo "❌ Connection failed: " . $conn->connect_error;
} else {
    echo "✅ Database connected successfully<br>";
}

// Test 2: Check if bk_admin table exists
echo "<h3>2. Check bk_admin table:</h3>";
$result = $conn->query("SHOW TABLES LIKE 'bk_admin'");
if ($result->num_rows > 0) {
    echo "✅ bk_admin table exists<br>";
} else {
    echo "❌ bk_admin table does NOT exist<br>";
    echo "<p>Please run the bloodknight_db.sql file first.</p>";
    exit;
}

// Test 3: Check if admin exists
echo "<h3>3. Check admin account:</h3>";
$result = $conn->query("SELECT admin_id, email, full_name FROM bk_admin WHERE email = 'bloodknight.about@gmail.com'");
if ($result->num_rows > 0) {
    $admin = $result->fetch_assoc();
    echo "✅ Admin account found:<br>";
    echo "ID: " . $admin['admin_id'] . "<br>";
    echo "Email: " . $admin['email'] . "<br>";
    echo "Name: " . $admin['full_name'] . "<br>";
} else {
    echo "❌ Admin account NOT found<br>";
    echo "<p>Please run the bloodknight_db.sql file to create the default admin.</p>";
    exit;
}

// Test 4: Test password verification
echo "<h3>4. Test password verification:</h3>";
$stmt = $conn->prepare("SELECT password_hash FROM bk_admin WHERE email = 'bloodknight.about@gmail.com'");
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$hash = $row['password_hash'];

$testPassword = 'admin123';
if (password_verify($testPassword, $hash)) {
    echo "✅ Password 'admin123' is CORRECT<br>";
} else {
    echo "❌ Password 'admin123' is INCORRECT<br>";
    echo "<p>Generating new hash for 'admin123'...</p>";
    $newHash = password_hash($testPassword, PASSWORD_DEFAULT);
    echo "<strong>New Hash:</strong> " . $newHash . "<br><br>";
    echo "<strong>SQL Command to fix:</strong><br>";
    echo "<code>UPDATE bk_admin SET password_hash = '" . $newHash . "' WHERE email = 'bloodknight.about@gmail.com';</code>";
}

// Test 5: Test controller endpoint
echo "<h3>5. Test controller endpoint:</h3>";
$testUrl = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/bk_admin_controller.php';
echo "Controller URL: <a href='$testUrl?action=check_session' target='_blank'>$testUrl</a><br>";
echo "(Should return JSON with 'Not logged in' message)<br>";
?>
