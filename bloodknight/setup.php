<?php
// setup.php - DATABASE INITIALIZER
// Run this file ONCE to reset your database with working login credentials.

$servername = "localhost";
$username = "root";
$password = ""; 

// 1. Connect to MySQL
$conn = new mysqli($servername, $username, $password);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

// 2. Initialize Database
$sql = "CREATE DATABASE IF NOT EXISTS bloodknight_db";
if ($conn->query($sql) === TRUE) {
    echo "<h3>1. Database Checked/Created.</h3>";
} else {
    die("Error creating database: " . $conn->error);
}
$conn->select_db("bloodknight_db");

// 3. Drop old tables to ensure clean slate (Fixes mismatch issues)
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$tables = ['appointment', 'notification', 'blood_drive', 'donor_user', 'hospital'];
foreach($tables as $t) { $conn->query("DROP TABLE IF EXISTS $t"); }
$views = ['view_donor_profile', 'view_hospital_directory', 'view_active_drives', 'view_urgent_alerts', 'view_donation_history', 'view_upcoming_appointments'];
foreach($views as $v) { $conn->query("DROP VIEW IF EXISTS $v"); }
$conn->query("SET FOREIGN_KEY_CHECKS = 1");
echo "<h3>2. Old tables cleared.</h3>";

// 4. Create Tables (Matching Documentation exactly)

// Table: donor_user
$conn->query("CREATE TABLE donor_user (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    blood_type VARCHAR(5),
    phone_number VARCHAR(20),
    total_points INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Table: hospital
$conn->query("CREATE TABLE hospital (
    hospital_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_name VARCHAR(255) NOT NULL,
    hospital_address TEXT NOT NULL,
    contact_number VARCHAR(20),
    hospital_type VARCHAR(50) NOT NULL,
    admin_name VARCHAR(100) NOT NULL,
    admin_email VARCHAR(100) NOT NULL UNIQUE,
    admin_phone VARCHAR(20),
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved') DEFAULT 'approved',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Table: blood_drive
$conn->query("CREATE TABLE blood_drive (
    drive_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    drive_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    location_name VARCHAR(150),
    status ENUM('Upcoming', 'Active', 'Completed') DEFAULT 'Upcoming',
    FOREIGN KEY (hospital_id) REFERENCES hospital(hospital_id) ON DELETE CASCADE
)");

// Table: appointment
$conn->query("CREATE TABLE appointment (
    appt_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    drive_id INT NOT NULL,
    selected_time TIME NOT NULL,
    status ENUM('Pending', 'Confirmed', 'Completed', 'Cancelled') DEFAULT 'Pending',
    donation_date DATE NULL, 
    volume_ml INT NULL,
    notes TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES donor_user(user_id) ON DELETE CASCADE,
    FOREIGN KEY (drive_id) REFERENCES blood_drive(drive_id) ON DELETE CASCADE,
    UNIQUE INDEX unique_slot (drive_id, selected_time)
)");

// Table: notification
$conn->query("CREATE TABLE notification (
    alert_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    target_blood_type VARCHAR(5),
    message_content TEXT NOT NULL,
    urgency_level ENUM('Low', 'High', 'Critical') DEFAULT 'High',
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id) REFERENCES hospital(hospital_id) ON DELETE CASCADE
)");

echo "<h3>3. Tables Created Successfully.</h3>";

// 5. INSERT VALID DUMMY DATA
// This is the most important part for your login to work!

$common_pass = password_hash("password123", PASSWORD_DEFAULT); // Creates a REAL hash

// Insert Donors
$sql_donor = "INSERT INTO donor_user (email, password_hash, full_name, blood_type, phone_number, total_points) VALUES 
('soldier1@example.com', '$common_pass', 'John Doe', 'A+', '012-1111111', 50),
('sarah@example.com', '$common_pass', 'Sarah Jenkins', 'O+', '012-3333333', 120)";

if ($conn->query($sql_donor)) {
    echo "<p style='color:green'>✔ Donors inserted. Login: <b>soldier1@example.com</b> / <b>password123</b></p>";
} else {
    echo "<p style='color:red'>✘ Donor insert failed: " . $conn->error . "</p>";
}

// Insert Hospitals (Sabah, Malaysia locations)
$sql_hospital = "INSERT INTO hospital (hospital_name, hospital_address, hospital_type, admin_name, admin_email, password_hash) VALUES 
('Queen Elizabeth Hospital', 'Lorong Bersatu, Off Jalan Damai, 88300 Kota Kinabalu, Sabah', 'Government Hospital', 'Dr. Ahmad Razak', 'admin@qeh.gov.my', '$common_pass'),
('KPJ Sabah Specialist Hospital', 'Jalan Damai, Luyang, 88300 Kota Kinabalu, Sabah', 'Private Hospital', 'Dr. Siti Nurhaliza', 'admin@kpjsabah.com', '$common_pass'),
('Gleneagles Kota Kinabalu', 'Block A & B, Lot 1 & 2, Off Jalan Lapangan Terbang, 88000 Kota Kinabalu, Sabah', 'Private Hospital', 'Dr. Lim Wei Chuan', 'admin@gleneagles-kk.com', '$common_pass'),
('Sabah Women and Children Hospital', 'Likas, 88400 Kota Kinabalu, Sabah', 'Government Hospital', 'Dr. Rosnah Binti Ahmad', 'admin@swch.gov.my', '$common_pass'),
('Tawau Hospital', 'Jalan Apas, 91000 Tawau, Sabah', 'Government Hospital', 'Dr. Mohd Azmi', 'admin@tawauhospital.gov.my', '$common_pass'),
('Sandakan Hospital', 'Jalan Utara, 90000 Sandakan, Sabah', 'Government Hospital', 'Dr. James Wong', 'admin@sandakanhospital.gov.my', '$common_pass'),
('Keningau Hospital', 'Jalan Hospital, 89007 Keningau, Sabah', 'Government Hospital', 'Dr. Mary Lim', 'admin@keningauhospital.gov.my', '$common_pass')";

if ($conn->query($sql_hospital)) {
    echo "<p style='color:green'>✔ Hospitals inserted (Sabah locations). Logins:</p>";
    echo "<ul style='margin-left: 20px;'>";
    echo "<li><b>admin@qeh.gov.my</b> / password123 (Queen Elizabeth Hospital, Kota Kinabalu)</li>";
    echo "<li><b>admin@kpjsabah.com</b> / password123 (KPJ Sabah, Kota Kinabalu)</li>";
    echo "<li><b>admin@gleneagles-kk.com</b> / password123 (Gleneagles, Kota Kinabalu)</li>";
    echo "<li><b>admin@swch.gov.my</b> / password123 (Sabah Women & Children Hospital, Kota Kinabalu)</li>";
    echo "<li><b>admin@tawauhospital.gov.my</b> / password123 (Tawau Hospital)</li>";
    echo "<li><b>admin@sandakanhospital.gov.my</b> / password123 (Sandakan Hospital)</li>";
    echo "<li><b>admin@keningauhospital.gov.my</b> / password123 (Keningau Hospital)</li>";
    echo "</ul>";
} else {
    echo "<p style='color:red'>✘ Hospital insert failed: " . $conn->error . "</p>";
}

// Insert Drives with Sabah locations
$sabah_locations = [
    'Suria Sabah Shopping Mall, Kota Kinabalu',
    '1Borneo Hypermall, Kota Kinabalu',
    'Imago Shopping Mall, Kota Kinabalu',
    'Kompleks Karamunsing, Kota Kinabalu',
    'Tawau Town Square, Tawau',
    'Sandakan Central Market, Sandakan',
    'Keningau Community Hall, Keningau'
];

foreach ($sabah_locations as $index => $location) {
    $hospital_id = ($index % 7) + 1; // Distribute across 7 hospitals
    $days = 5 + ($index * 7); // Stagger dates
    $conn->query("INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status) 
                  VALUES ($hospital_id, CURDATE() + INTERVAL $days DAY, '09:00', '17:00', '$location', 'Upcoming')");
}

echo "<p style='color:green'>✔ Blood drives created at Sabah locations</p>";

echo "<br><strong>SETUP COMPLETE!</strong> <a href='index.html'>Go to Homepage</a>";
?>
