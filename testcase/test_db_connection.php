<?php
// test_db_connection.php - Database Connection Diagnostic Tool
// Run this file in your browser: http://localhost/bloodknight/test_db_connection.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>BloodKnight Database Connection Test</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
    .success { color: green; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
    .error { color: red; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
    .info { color: blue; background: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #4CAF50; color: white; }
</style>";

// Test 1: Check if MySQL is running
echo "<h2>Test 1: MySQL Connection</h2>";
$servername = "localhost";
$username = "root";
$password = "";

$conn = @new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    echo "<div class='error'><strong>ERROR:</strong> Could not connect to MySQL: " . $conn->connect_error . "</div>";
    echo "<p>Make sure XAMPP MySQL is running. Check the XAMPP Control Panel.</p>";
    exit;
} else {
    echo "<div class='success'><strong>SUCCESS:</strong> Connected to MySQL server</div>";
}

// Test 2: Check if database exists
echo "<h2>Test 2: Database Existence</h2>";
$dbname = "bloodknight_db";
$db_exists = $conn->query("SHOW DATABASES LIKE '$dbname'")->num_rows > 0;

if ($db_exists) {
    echo "<div class='success'><strong>SUCCESS:</strong> Database '$dbname' exists</div>";
    $conn->select_db($dbname);
} else {
    echo "<div class='error'><strong>ERROR:</strong> Database '$dbname' does not exist!</div>";
    echo "<p><strong>Solution:</strong> Run the bloodknight_db.sql file in phpMyAdmin to create the database.</p>";
    $conn->close();
    exit;
}

// Test 3: Check required tables
echo "<h2>Test 3: Required Tables</h2>";
$required_tables = ['hospital', 'donor_user', 'blood_drive', 'appointment', 'notification', 'bk_admin'];
$missing_tables = [];

foreach ($required_tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "<div class='success'>✓ Table '$table' exists</div>";
    } else {
        echo "<div class='error'>✗ Table '$table' is MISSING</div>";
        $missing_tables[] = $table;
    }
}

if (!empty($missing_tables)) {
    echo "<div class='error'><strong>ERROR:</strong> Missing tables: " . implode(', ', $missing_tables) . "</div>";
    echo "<p><strong>Solution:</strong> Run the bloodknight_db.sql file in phpMyAdmin to create the tables.</p>";
}

// Test 4: Check Agent Clinic hospital data
echo "<h2>Test 4: Agent Clinic Hospital Data</h2>";
$agent_query = "SELECT hospital_id, hospital_name, admin_email, status FROM hospital WHERE admin_email = 'agentali14@gmail.com'";
$result = $conn->query($agent_query);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "<div class='success'><strong>SUCCESS:</strong> Agent Clinic found</div>";
    echo "<table>";
    echo "<tr><th>Hospital ID</th><th>Hospital Name</th><th>Email</th><th>Status</th></tr>";
    echo "<tr><td>{$row['hospital_id']}</td><td>{$row['hospital_name']}</td><td>{$row['admin_email']}</td><td>{$row['status']}</td></tr>";
    echo "</table>";
    $agent_hospital_id = $row['hospital_id'];
} else {
    echo "<div class='error'><strong>ERROR:</strong> Agent Clinic hospital not found!</div>";
    echo "<p><strong>Solution:</strong> Run the bloodknight_db_data.sql file in phpMyAdmin to insert the hospital data.</p>";
    $agent_hospital_id = null;
}

// Test 5: Check Agent Clinic blood drives
if ($agent_hospital_id) {
    echo "<h2>Test 5: Agent Clinic Blood Drives</h2>";
    $drives_query = "SELECT COUNT(*) as count FROM blood_drive WHERE hospital_id = $agent_hospital_id";
    $result = $conn->query($drives_query);
    $drives_count = $result->fetch_assoc()['count'];
    
    if ($drives_count > 0) {
        echo "<div class='success'><strong>SUCCESS:</strong> Found $drives_count blood drive(s) for Agent Clinic</div>";
    } else {
        echo "<div class='error'><strong>WARNING:</strong> No blood drives found for Agent Clinic</div>";
        echo "<p><strong>Solution:</strong> Run the bloodknight_db_data.sql file in phpMyAdmin to insert blood drive data.</p>";
    }
}

// Test 6: Check Agent Clinic appointments
if ($agent_hospital_id) {
    echo "<h2>Test 6: Agent Clinic Appointments</h2>";
    $appt_query = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'Completed' AND volume_ml > 0 THEN 1 ELSE 0 END) as completed,
                        SUM(CASE WHEN status = 'Confirmed' THEN 1 ELSE 0 END) as confirmed,
                        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
                        COALESCE(SUM(CASE WHEN status = 'Completed' AND volume_ml > 0 THEN volume_ml ELSE 0 END), 0) as total_volume
                    FROM appointment a
                    JOIN blood_drive bd ON a.drive_id = bd.drive_id
                    WHERE bd.hospital_id = $agent_hospital_id";
    $result = $conn->query($appt_query);
    
    if ($result) {
        $row = $result->fetch_assoc();
        echo "<table>";
        echo "<tr><th>Total Appointments</th><th>Completed</th><th>Confirmed</th><th>Pending</th><th>Total Volume (ml)</th></tr>";
        echo "<tr><td>{$row['total']}</td><td>{$row['completed']}</td><td>{$row['confirmed']}</td><td>{$row['pending']}</td><td>{$row['total_volume']}</td></tr>";
        echo "</table>";
        
        if ($row['completed'] > 0 && $row['total_volume'] > 0) {
            echo "<div class='success'><strong>SUCCESS:</strong> Agent Clinic has completed appointments with volume data</div>";
        } else {
            echo "<div class='error'><strong>WARNING:</strong> Agent Clinic has no completed appointments with volume data</div>";
            echo "<p><strong>Solution:</strong> Run the bloodknight_db_data.sql file in phpMyAdmin to insert appointment data.</p>";
        }
    } else {
        echo "<div class='error'><strong>ERROR:</strong> Failed to query appointments: " . $conn->error . "</div>";
    }
}

// Test 7: Check leaderboard query
echo "<h2>Test 7: Leaderboard Query Test</h2>";
$leaderboard_query = "SELECT 
                        h.hospital_id,
                        h.hospital_name,
                        COALESCE(SUM(CASE 
                            WHEN a.status = 'Completed' 
                            AND a.volume_ml IS NOT NULL 
                            AND a.volume_ml > 0 
                            THEN a.volume_ml 
                            ELSE 0 
                        END), 0) / 1000.0 as total_volume_l,
                        COUNT(DISTINCT CASE 
                            WHEN a.status = 'Completed' 
                            AND a.volume_ml IS NOT NULL 
                            AND a.volume_ml > 0 
                            THEN a.appt_id 
                        END) as total_donations
                    FROM hospital h
                    LEFT JOIN blood_drive bd ON h.hospital_id = bd.hospital_id
                    LEFT JOIN appointment a ON bd.drive_id = a.drive_id
                        AND a.status = 'Completed'
                        AND a.volume_ml IS NOT NULL
                        AND a.volume_ml > 0
                    WHERE h.status = 'Active'
                    GROUP BY h.hospital_id, h.hospital_name
                    ORDER BY total_volume_l DESC
                    LIMIT 10";
$result = $conn->query($leaderboard_query);

if ($result) {
    $hospitals = [];
    while ($row = $result->fetch_assoc()) {
        $hospitals[] = $row;
    }
    
    if (count($hospitals) > 0) {
        echo "<div class='success'><strong>SUCCESS:</strong> Leaderboard query works! Found " . count($hospitals) . " hospitals</div>";
        echo "<table>";
        echo "<tr><th>Rank</th><th>Hospital Name</th><th>Total Volume (L)</th><th>Total Donations</th></tr>";
        $rank = 1;
        foreach ($hospitals as $h) {
            echo "<tr><td>$rank</td><td>{$h['hospital_name']}</td><td>" . round($h['total_volume_l'], 2) . "</td><td>{$h['total_donations']}</td></tr>";
            $rank++;
        }
        echo "</table>";
    } else {
        echo "<div class='error'><strong>WARNING:</strong> Leaderboard query works but returned no hospitals with data</div>";
    }
} else {
    echo "<div class='error'><strong>ERROR:</strong> Leaderboard query failed: " . $conn->error . "</div>";
}

// Test 8: Check PHP syntax errors
echo "<h2>Test 8: PHP Syntax Check</h2>";
$php_file = __DIR__ . '/admin_controller.php';
if (file_exists($php_file)) {
    $output = [];
    $return_var = 0;
    exec("php -l \"$php_file\" 2>&1", $output, $return_var);
    
    if ($return_var === 0) {
        echo "<div class='success'><strong>SUCCESS:</strong> admin_controller.php has no syntax errors</div>";
    } else {
        echo "<div class='error'><strong>ERROR:</strong> admin_controller.php has syntax errors:</div>";
        echo "<pre style='background: #f8d7da; padding: 10px; border-radius: 5px;'>";
        echo implode("\n", $output);
        echo "</pre>";
    }
} else {
    echo "<div class='error'><strong>ERROR:</strong> admin_controller.php file not found</div>";
}

$conn->close();

echo "<hr>";
echo "<h2>Summary</h2>";
echo "<p>If you see any errors above, follow the solutions provided for each test.</p>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>If database doesn't exist: Run bloodknight_db.sql in phpMyAdmin</li>";
echo "<li>If tables are missing: Run bloodknight_db.sql in phpMyAdmin</li>";
echo "<li>If data is missing: Run bloodknight_db_data.sql in phpMyAdmin</li>";
echo "<li>If PHP syntax errors: Fix the errors shown in Test 8</li>";
echo "</ol>";
?>
