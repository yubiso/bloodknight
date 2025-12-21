<?php
// This script generates the correct password hash and updates the database directly
// Run this in browser: http://localhost/bloodknight/update_agent_clinic_sql.php

require_once 'db_connect.php';

$password = 'Password123';
$email = 'agentali14@gmail.com';

// Generate the correct password hash
$hash = password_hash($password, PASSWORD_DEFAULT);

// Verify it works
$verified = password_verify($password, $hash);

echo "<h2>Agent Clinic Password Fix</h2>";
echo "<p><strong>Email:</strong> $email</p>";
echo "<p><strong>Password:</strong> $password</p>";
echo "<p><strong>Generated Hash:</strong> <code style='background: #f0f0f0; padding: 5px; word-break: break-all;'>$hash</code></p>";

if ($verified) {
    echo "<p style='color: green; font-size: 18px; font-weight: bold;'>✓ Hash verified - This will work!</p>";
} else {
    echo "<p style='color: red;'>✗ Hash verification failed</p>";
    exit;
}

echo "<hr>";

// Update the database directly
if (isset($conn) && $conn && !$conn->connect_error) {
    $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
    if ($current_db !== 'bloodknight_db') {
        $conn->select_db('bloodknight_db');
    }
    
    $stmt = $conn->prepare("UPDATE hospital SET password_hash = ? WHERE admin_email = ?");
    $stmt->bind_param("ss", $hash, $email);
    
    if ($stmt->execute()) {
        $affected = $conn->affected_rows;
        if ($affected > 0) {
            echo "<p style='color: green; font-size: 16px; font-weight: bold;'>✓ Database updated successfully! ($affected row(s) affected)</p>";
            echo "<p>You can now login with:</p>";
            echo "<ul>";
            echo "<li><strong>Email:</strong> $email</li>";
            echo "<li><strong>Password:</strong> $password</li>";
            echo "</ul>";
        } else {
            echo "<p style='color: orange;'>⚠ No rows updated. Hospital might not exist yet. Make sure you've imported bloodknight_db_data.sql</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Database update failed: " . $conn->error . "</p>";
    }
    $stmt->close();
} else {
    echo "<p style='color: red;'>✗ Database connection failed</p>";
}

echo "<hr>";
echo "<h3>To update bloodknight_db_data.sql file:</h3>";
echo "<p>In the Agent Clinic INSERT statement (around line 84), replace the password_hash value with:</p>";
echo "<pre style='background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; font-size: 14px; word-break: break-all;'>";
echo "'$hash'";
echo "</pre>";

echo "<hr>";
echo "<h3>SQL UPDATE command (if you prefer to run it manually):</h3>";
echo "<pre style='background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; font-size: 14px; word-break: break-all;'>";
echo "UPDATE hospital SET password_hash = '$hash' WHERE admin_email = 'agentali14@gmail.com';";
echo "</pre>";
?>
