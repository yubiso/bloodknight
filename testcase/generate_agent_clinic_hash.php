<?php
// Generate password hash for Agent Clinic
// Password: Password123
// Run this in browser: http://localhost/bloodknight/generate_agent_clinic_hash.php

$password = 'Password123';
$hash = password_hash($password, PASSWORD_DEFAULT);

// Also verify the existing hash in the SQL file
$existingHash = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';

echo "<h2>Agent Clinic Password Hash Generator</h2>";
echo "<p><strong>Password:</strong> $password</p>";
echo "<p><strong>New Generated Hash:</strong> $hash</p>";
echo "<hr>";
echo "<h3>SQL Update Statement (for bloodknight_db_data.sql):</h3>";
echo "<pre>";
echo "-- Replace the password_hash value in the Agent Clinic INSERT statement with:\n";
echo "'$hash'\n\n";
echo "Or run this SQL command:\n";
echo "UPDATE hospital SET password_hash = '$hash' WHERE admin_email = 'agentali14@gmail.com';";
echo "</pre>";
echo "<hr>";
echo "<h3>Verification:</h3>";
if (password_verify($password, $hash)) {
    echo "<p style='color: green;'>✓ New hash is valid for Password123</p>";
} else {
    echo "<p style='color: red;'>✗ New hash verification failed</p>";
}

echo "<hr>";
echo "<h3>Existing Hash Verification:</h3>";
if (password_verify($password, $existingHash)) {
    echo "<p style='color: green;'>✓ Existing hash in SQL file is valid for Password123</p>";
} else {
    echo "<p style='color: orange;'>⚠ Existing hash in SQL file is NOT valid - needs update</p>";
    echo "<p>Use the new hash above to update bloodknight_db_data.sql</p>";
}
?>
