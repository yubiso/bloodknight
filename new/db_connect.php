<?php
// db_connect.php
$servername = "localhost";
$username = "root";      // Default XAMPP username
$password = "";          // Default XAMPP password
$dbname = "bloodknight_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "Connection failed: " . $conn->connect_error]));
}
?>