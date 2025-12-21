<?php
// Simple script to fix Agent Clinic password
// Run this in browser: http://localhost/bloodknight/fix_agent_password_simple.php
// This will update the password hash in the database to work with "Password123"

require_once 'db_connect.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Fixing Agent Clinic Password</h2>";

// Check database connection
if (!isset($conn) || $conn === null || $conn->connect_error) {
    echo "<p style='color: red;'>✗ Database connection failed</p>";
    exit;
}

// Ensure we're using the correct database
$current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
if ($current_db !== 'bloodknight_db') {
    if (!$conn->select_db('bloodknight_db')) {
        echo "<p style='color: red;'>✗ Failed to select bloodknight_db database</p>";
        exit;
    }
}

$email = 'agentali14@gmail.com';
$password = 'Password123';

// Generate the correct password hash
$password_hash = password_hash($password, PASSWORD_DEFAULT);

echo "<p><strong>Email:</strong> $email</p>";
echo "<p><strong>Password:</strong> $password</p>";
echo "<p><strong>Generated Hash:</strong> <code style='word-break: break-all;'>$password_hash</code></p>";

// Verify it works
if (password_verify($password, $password_hash)) {
    echo "<p style='color: green;'>✓ Hash verified - This will work!</p>";
} else {
    echo "<p style='color: red;'>✗ Hash verification failed</p>";
    exit;
}

// Update the database
$stmt = $conn->prepare("UPDATE hospital SET password_hash = ? WHERE admin_email = ?");
$stmt->bind_param("ss", $password_hash, $email);

if ($stmt->execute()) {
    $affected = $conn->affected_rows;
    if ($affected > 0) {
        echo "<p style='color: green; font-size: 18px; font-weight: bold;'>✓ Password updated successfully!</p>";
        echo "<p>You can now login with:</p>";
        echo "<ul>";
        echo "<li><strong>Email:</strong> $email</li>";
        echo "<li><strong>Password:</strong> $password</li>";
        echo "</ul>";
        echo "<p><a href='index.html' style='background: #dc2626; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 20px;'>Go to Login</a></p>";
    } else {
        echo "<p style='color: orange;'>⚠ No rows updated. Hospital might not exist. Make sure you've imported bloodknight_db_data.sql</p>";
    }
} else {
    echo "<p style='color: red;'>✗ Update failed: " . $conn->error . "</p>";
}
$stmt->close();

echo "<hr>";
echo "<h3>To update bloodknight_db_data.sql file:</h3>";
echo "<p>Replace the password_hash value in the Agent Clinic INSERT statement (around line 79) with:</p>";
echo "<pre style='background: #f0f0f0; padding: 15px; border-radius: 5px; word-break: break-all;'>";
echo "'$password_hash'";
echo "</pre>";
?>
