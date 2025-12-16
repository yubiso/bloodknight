<?php
// add_volume_to_blood_reports.php
// Script to add volume_ml column to blood_report table and populate it from appointments
// Run this once to add volume support to blood reports

require_once 'db_connect.php';

echo "<h2>Adding Volume to Blood Reports</h2>";

// Check if column already exists
$columnCheck = $conn->query("SHOW COLUMNS FROM blood_report LIKE 'volume_ml'");
if ($columnCheck->num_rows > 0) {
    echo "<p style='color: orange;'>✓ Volume column already exists in blood_report table.</p>";
} else {
    // Add volume_ml column
    $sql = "ALTER TABLE blood_report ADD COLUMN volume_ml INT NULL AFTER temperature";
    if ($conn->query($sql) === TRUE) {
        echo "<p style='color: green;'>✓ Successfully added volume_ml column to blood_report table.</p>";
    } else {
        echo "<p style='color: red;'>✗ Error adding column: " . $conn->error . "</p>";
        exit;
    }
}

// Populate volume from appointments where blood reports are linked
echo "<p>Updating existing blood reports with volume from appointments...</p>";

$updateSql = "UPDATE blood_report br
              JOIN appointment a ON br.appt_id = a.appt_id
              SET br.volume_ml = a.volume_ml
              WHERE br.appt_id IS NOT NULL 
              AND a.volume_ml IS NOT NULL 
              AND br.volume_ml IS NULL";

if ($conn->query($updateSql) === TRUE) {
    $affected = $conn->affected_rows;
    echo "<p style='color: green;'>✓ Updated $affected blood report(s) with volume from appointments.</p>";
} else {
    echo "<p style='color: orange;'>⚠ Note: " . $conn->error . "</p>";
}

// For reports without appointments, set a default random volume (450ml is standard donation)
$randomVolumeSql = "UPDATE blood_report 
                    SET volume_ml = 450 + FLOOR(RAND() * 50)
                    WHERE appt_id IS NULL AND volume_ml IS NULL";

if ($conn->query($randomVolumeSql) === TRUE) {
    $affected = $conn->affected_rows;
    if ($affected > 0) {
        echo "<p style='color: blue;'>ℹ Set random volume for $affected report(s) without appointments (450-500ml).</p>";
    }
}

echo "<br><strong>Update complete!</strong><br>";
echo "<a href='create_blood_reports.php'>Go to Create Blood Reports</a> | ";
echo "<a href='admin_dashboard.html'>Go to Admin Dashboard</a>";

?>

