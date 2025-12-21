<?php
// Quick script to generate correct password hash for Agent Clinic
// Run this in browser: http://localhost/bloodknight/fix_agent_clinic_password.php

$password = 'Password123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Agent Clinic Password Fix</h2>";
echo "<p><strong>Password:</strong> $password</p>";
echo "<p><strong>Generated Hash:</strong> $hash</p>";

// Verify it works
if (password_verify($password, $hash)) {
    echo "<p style='color: green; font-weight: bold;'>✓ Hash verified - This hash will work for Password123</p>";
    } else {
    echo "<p style='color: red;'>✗ Hash verification failed</p>";
    }
    
    echo "<hr>";
echo "<h3>SQL UPDATE Command (run this in phpMyAdmin):</h3>";
echo "<pre style='background: #f0f0f0; padding: 15px; border-radius: 5px;'>";
echo "UPDATE hospital SET password_hash = '$hash' WHERE admin_email = 'agentali14@gmail.com';";
echo "</pre>";

echo "<hr>";
echo "<h3>Or update bloodknight_db_data.sql file:</h3>";
echo "<p>Replace the password_hash value in the Agent Clinic INSERT statement with:</p>";
echo "<pre style='background: #f0f0f0; padding: 15px; border-radius: 5px;'>";
echo "'$hash'";
echo "</pre>";
?>
