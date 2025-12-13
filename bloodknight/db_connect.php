<?php
// db_connect.php - UNIVERSAL DATABASE CONNECTION
// -------------------------------------------------------------------------
// INSTRUCTIONS FOR ONLINE DEPLOYMENT:
// 1. Upload this file to your server (public_html folder).
// 2. Edit the $username, $password, and $dbname below to match your HOSTING details.
// 3. Keep $servername as "localhost" (usually) for shared hosting.
// -------------------------------------------------------------------------

$servername = "localhost"; 

// --- LOCALHOST SETTINGS (XAMPP) ---
$username = "root";
$password = "";
$dbname = "bloodknight_db";

// --- ONLINE SETTINGS (Example - UNCOMMENT AND EDIT WHEN UPLOADING) ---
// $username = "id2025_blood_admin";
// $password = "StrongPassword123!";
// $dbname = "id2025_bloodknight_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    // Return JSON error so the frontend knows what happened
    die(json_encode(["status" => "error", "message" => "Database Connection Failed: " . $conn->connect_error]));
}

// Set Charset to UTF-8 for compatibility
$conn->set_charset("utf8mb4");
?>
