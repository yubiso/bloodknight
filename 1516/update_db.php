<?php
// update_db.php - ADDS PROFILE PIC COLUMN
require_once 'db_connect.php';

$sql = "ALTER TABLE donor_user ADD COLUMN profile_pic VARCHAR(255) DEFAULT NULL";

if ($conn->query($sql) === TRUE) {
    echo "<h3>Success!</h3> Profile picture column added to database.";
} else {
    // If it fails, it might already exist, which is fine.
    echo "<h3>Notice:</h3> " . $conn->error;
}
echo "<br><a href='profile.html'>Go to Profile</a>";
?>