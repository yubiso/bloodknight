<?php
// create_blood_report_table.php - Creates blood_report table if it doesn't exist
// Run this file once to ensure the blood_report table exists

require_once 'db_connect.php';

echo "<h2>Creating Blood Report Table</h2>";

// Check if table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'blood_report'");
if ($tableCheck->num_rows > 0) {
    echo "<p style='color: orange;'>✓ Blood report table already exists.</p>";
} else {
    echo "<p>Creating blood_report table...</p>";
    
    $sql = "CREATE TABLE IF NOT EXISTS blood_report (
        report_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        appt_id INT NULL,
        report_date DATE NOT NULL,
        hemoglobin DECIMAL(5,2),
        hematocrit DECIMAL(5,2),
        platelet_count INT,
        white_blood_cell_count DECIMAL(6,2),
        red_blood_cell_count DECIMAL(6,2),
        blood_pressure VARCHAR(20),
        temperature DECIMAL(4,2),
        volume_ml INT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES donor_user(user_id) ON DELETE CASCADE,
        FOREIGN KEY (appt_id) REFERENCES appointment(appt_id) ON DELETE SET NULL
    )";
    
    if ($conn->query($sql) === TRUE) {
        echo "<p style='color: green;'>✓ Blood report table created successfully!</p>";
    } else {
        echo "<p style='color: red;'>✗ Error creating table: " . $conn->error . "</p>";
    }
}

echo "<br><a href='dashboard.html'>Go to Dashboard</a> | <a href='admin_dashboard.html'>Go to Admin Dashboard</a>";
?>

