<?php
// Quick script to generate password hash for admin123
// Run this once: http://localhost/bloodknight/generate_admin_hash.php

$password = 'admin123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: " . $password . "\n";
echo "Hash: " . $hash . "\n";
echo "\nSQL Update Command:\n";
echo "UPDATE bk_admin SET password_hash = '" . $hash . "' WHERE email = 'bloodknight.about@gmail.com';\n";
?>
