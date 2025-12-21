<?php
// Quick script to generate password hash for Password123
// Run this once: http://localhost/bloodknight/generate_password_hash.php

$password = 'Password123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Password Hash Generator</h2>";
echo "<p><strong>Password:</strong> " . htmlspecialchars($password) . "</p>";
echo "<p><strong>Hash:</strong> " . htmlspecialchars($hash) . "</p>";
echo "<hr>";
echo "<h3>SQL Update Command:</h3>";
echo "<code>UPDATE hospital SET password_hash = '" . htmlspecialchars($hash) . "' WHERE admin_email = 'agentali14@gmail.com';</code>";
echo "<hr>";
echo "<h3>Verification Test:</h3>";
$testPassword = 'Password123';
if (password_verify($testPassword, $hash)) {
    echo "<p style='color: green;'>✅ Password verification successful!</p>";
} else {
    echo "<p style='color: red;'>❌ Password verification failed!</p>";
}
?>
