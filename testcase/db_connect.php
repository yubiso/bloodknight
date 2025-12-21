<?php
// db_connect.php - UNIVERSAL DATABASE CONNECTION
// -------------------------------------------------------------------------
// INSTRUCTIONS FOR ONLINE DEPLOYMENT:
// 1. Upload this file to your server (public_html folder).
// 2. Edit the $username, $password, and $dbname below to match your HOSTING details.
// 3. Keep $servername as "localhost" (usually) for shared hosting.
// -------------------------------------------------------------------------

// Suppress all errors to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);

$servername = "localhost"; 

// --- LOCALHOST SETTINGS (XAMPP) ---
$username = "root";
$password = "";
$dbname = "bloodknight_db";

// --- ONLINE SETTINGS (Example - UNCOMMENT AND EDIT WHEN UPLOADING) ---
// $username = "id2025_blood_admin";
// $password = "StrongPassword123!";
// $dbname = "id2025_bloodknight_db";

// Create connection - suppress warnings
@$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    // Don't output anything - let bloodknight.php handle the error
    // Just set the connection to null so bloodknight.php knows it failed
    $conn = null;
}

// Set Charset to UTF-8 for compatibility (only if connection succeeded)
if ($conn && !$conn->connect_error) {
    @$conn->set_charset("utf8mb4");
    
    // Explicitly select the database to ensure we're using bloodknight_db
    @$conn->select_db($dbname);
    
    // Verify we're connected to the correct database
    $current_db = @$conn->query("SELECT DATABASE()")->fetch_row()[0];
    if ($current_db !== $dbname) {
        error_log("WARNING: Connected to wrong database. Expected: $dbname, Got: $current_db");
    } else {
        error_log("Database connection verified: Connected to $dbname");
    }
}
?>
