<?php
// setup.php
$servername = "localhost";
$username = "root";
$password = ""; // Default XAMPP password

// 1. Create Connection
$conn = new mysqli($servername, $username, $password);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// 2. Create Database
$sql = "CREATE DATABASE IF NOT EXISTS bloodknight_db";
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully.<br>";
} else {
    echo "Error creating database: " . $conn->error . "<br>";
}

// 3. Select Database
$conn->select_db("bloodknight_db");

// 4. Create Tables
$tables = [
    "user" => "CREATE TABLE IF NOT EXISTS user (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        blood_type VARCHAR(5),
        phone_number VARCHAR(20),
        total_points INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    "blood_bank" => "CREATE TABLE IF NOT EXISTS blood_bank (
        bank_id INT AUTO_INCREMENT PRIMARY KEY,
        organization_name VARCHAR(150) NOT NULL,
        address TEXT NOT NULL,
        contact_number VARCHAR(20),
        admin_email VARCHAR(100) NOT NULL
    )",
    "blood_drive" => "CREATE TABLE IF NOT EXISTS blood_drive (
        drive_id INT AUTO_INCREMENT PRIMARY KEY,
        bank_id INT NOT NULL,
        drive_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        location_name VARCHAR(150),
        coordinates VARCHAR(100),
        FOREIGN KEY (bank_id) REFERENCES blood_bank(bank_id) ON DELETE CASCADE
    )",
    "donation_history" => "CREATE TABLE IF NOT EXISTS donation_history (
        history_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        drive_id INT,
        donation_date DATE NOT NULL,
        volume_ml INT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES user(user_id) ON DELETE CASCADE
    )",
    "notification" => "CREATE TABLE IF NOT EXISTS notification (
        alert_id INT AUTO_INCREMENT PRIMARY KEY,
        bank_id INT NOT NULL,
        target_blood_type VARCHAR(5),
        message_content TEXT NOT NULL,
        urgency_level ENUM('Low', 'High', 'Critical') DEFAULT 'High',
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (bank_id) REFERENCES blood_bank(bank_id) ON DELETE CASCADE
    )"
];

foreach ($tables as $name => $sql) {
    if ($conn->query($sql) === TRUE) {
        echo "Table '$name' check/creation passed.<br>";
    } else {
        echo "Error creating table '$name': " . $conn->error . "<br>";
    }
}

// 5. Insert Dummy Data (Only if empty)
$checkUser = $conn->query("SELECT * FROM user WHERE email='sarah@example.com'");
if ($checkUser->num_rows == 0) {
    $pass = password_hash("password123", PASSWORD_DEFAULT);
    $conn->query("INSERT INTO user (email, password_hash, full_name, blood_type, total_points) VALUES ('sarah@example.com', '$pass', 'Sarah Jenkins', 'O+', 150)");
    echo "Dummy User 'Sarah' created.<br>";
    
    // Insert History for Sarah (User ID 1)
    $conn->query("INSERT INTO donation_history (user_id, donation_date, volume_ml) VALUES (1, '2023-01-10', 450), (1, '2023-05-15', 450), (1, '2023-09-20', 450)");
    echo "Dummy History created.<br>";
}

$checkBank = $conn->query("SELECT * FROM blood_bank");
if ($checkBank->num_rows == 0) {
    $conn->query("INSERT INTO blood_bank (organization_name, address, admin_email) VALUES ('Pusat Darah Negara', 'KL', 'admin@pdn.gov.my')");
    $conn->query("INSERT INTO blood_drive (bank_id, drive_date, start_time, end_time, location_name) VALUES (1, '2025-12-25', '09:00', '17:00', 'Mid Valley Megamall')");
    $conn->query("INSERT INTO notification (bank_id, target_blood_type, message_content, urgency_level) VALUES (1, 'O-', 'Urgent O- needed at PDN', 'Critical')");
    echo "Dummy Bank and Drive Data created.<br>";
}

echo "<br><strong>SETUP COMPLETE!</strong> You can now use the app.";
?>