<?php
// Simple script to get the hash for Password123
$password = 'Password123';
$hash = password_hash($password, PASSWORD_DEFAULT);
echo $hash;
?>
