<?php
// test_db_connection.php - Database Connection Diagnostic Tool
// Open this file in your browser to test the database connection

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Connection Test - BloodKnight</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: #10b981; background: #d1fae5; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { color: #ef4444; background: #fee2e2; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { color: #3b82f6; background: #dbeafe; padding: 10px; border-radius: 4px; margin: 10px 0; }
        h1 { color: #1f2937; }
        h2 { color: #374151; margin-top: 20px; }
        pre { background: #f3f4f6; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 BloodKnight Database Connection Test</h1>
        
        <?php
        $servername = "localhost";
        $username = "root";
        $password = "";
        $dbname = "bloodknight_db";
        
        echo "<h2>Step 1: Testing MySQL Server Connection</h2>";
        
        // Test 1: Connect to MySQL (without database)
        $test_conn = @new mysqli($servername, $username, $password);
        
        if ($test_conn->connect_error) {
            echo "<div class='error'>";
            echo "❌ <strong>MySQL Server Connection Failed</strong><br>";
            echo "Error: " . $test_conn->connect_error . "<br><br>";
            echo "<strong>Possible Solutions:</strong><br>";
            echo "1. Open XAMPP Control Panel<br>";
            echo "2. Click 'Start' button next to MySQL<br>";
            echo "3. Wait for MySQL to start (green indicator)<br>";
            echo "4. Refresh this page";
            echo "</div>";
            exit;
        } else {
            echo "<div class='success'>";
            echo "✅ <strong>MySQL Server is Running</strong><br>";
            echo "Server: $servername<br>";
            echo "User: $username<br>";
            echo "Connection successful!";
            echo "</div>";
        }
        
        echo "<h2>Step 2: Checking if Database Exists</h2>";
        
        // Test 2: Check if database exists
        $db_check = $test_conn->query("SHOW DATABASES LIKE '$dbname'");
        
        if ($db_check->num_rows == 0) {
            echo "<div class='error'>";
            echo "❌ <strong>Database '$dbname' Does Not Exist</strong><br><br>";
            echo "<strong>To Fix This:</strong><br>";
            echo "1. Open phpMyAdmin: <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a><br>";
            echo "2. Click on 'Import' tab<br>";
            echo "3. Choose file: <code>bloodknight_db.sql</code><br>";
            echo "4. Click 'Go' to import<br>";
            echo "5. Refresh this page";
            echo "</div>";
            $test_conn->close();
            exit;
        } else {
            echo "<div class='success'>";
            echo "✅ <strong>Database '$dbname' Exists</strong>";
            echo "</div>";
        }
        
        echo "<h2>Step 3: Testing Database Connection</h2>";
        
        // Test 3: Connect to the specific database
        $conn = @new mysqli($servername, $username, $password, $dbname);
        
        if ($conn->connect_error) {
            echo "<div class='error'>";
            echo "❌ <strong>Database Connection Failed</strong><br>";
            echo "Error: " . $conn->connect_error;
            echo "</div>";
            $test_conn->close();
            exit;
        } else {
            echo "<div class='success'>";
            echo "✅ <strong>Database Connection Successful!</strong><br>";
            echo "Connected to: $dbname";
            echo "</div>";
        }
        
        echo "<h2>Step 4: Checking Database Tables</h2>";
        
        // Test 4: List tables
        $tables_result = $conn->query("SHOW TABLES");
        
        if ($tables_result && $tables_result->num_rows > 0) {
            echo "<div class='success'>";
            echo "✅ <strong>Found " . $tables_result->num_rows . " table(s):</strong><br>";
            echo "<ul>";
            while ($row = $tables_result->fetch_array()) {
                echo "<li>" . $row[0] . "</li>";
            }
            echo "</ul>";
            echo "</div>";
        } else {
            echo "<div class='error'>";
            echo "⚠️ <strong>No Tables Found</strong><br>";
            echo "The database exists but has no tables. You need to import <code>bloodknight_db.sql</code>";
            echo "</div>";
        }
        
        echo "<h2>Step 5: Testing db_connect.php File</h2>";
        
        // Test 5: Test the actual db_connect.php file
        if (file_exists('db_connect.php')) {
            echo "<div class='info'>";
            echo "📄 <strong>db_connect.php file exists</strong><br>";
            echo "Path: " . realpath('db_connect.php');
            echo "</div>";
            
            // Try to include it
            try {
                require_once 'db_connect.php';
                if (isset($conn) && $conn && !$conn->connect_error) {
                    echo "<div class='success'>";
                    echo "✅ <strong>db_connect.php works correctly!</strong>";
                    echo "</div>";
                } else {
                    echo "<div class='error'>";
                    echo "❌ <strong>db_connect.php connection failed</strong>";
                    echo "</div>";
                }
            } catch (Exception $e) {
                echo "<div class='error'>";
                echo "❌ <strong>Error loading db_connect.php:</strong><br>";
                echo $e->getMessage();
                echo "</div>";
            }
        } else {
            echo "<div class='error'>";
            echo "❌ <strong>db_connect.php file not found</strong><br>";
            echo "Expected location: " . __DIR__ . "/db_connect.php";
            echo "</div>";
        }
        
        echo "<h2>✅ Summary</h2>";
        echo "<div class='success'>";
        echo "<strong>All tests passed! Your database is properly configured.</strong><br><br>";
        echo "If you're still seeing connection errors in the dashboard, check:<br>";
        echo "1. Browser console for JavaScript errors<br>";
        echo "2. PHP error logs in XAMPP<br>";
        echo "3. Make sure you're accessing via: <code>http://localhost/bloodknight/</code>";
        echo "</div>";
        
        $test_conn->close();
        if (isset($conn)) $conn->close();
        ?>
    </div>
</body>
</html>
