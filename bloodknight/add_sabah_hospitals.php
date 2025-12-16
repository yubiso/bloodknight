<?php
// add_sabah_hospitals.php
// Script to add multiple Sabah hospitals to the database
// Run this to populate your database with Sabah hospital locations

require_once 'db_connect.php';

echo "<h2>Adding Sabah Hospitals</h2>";

$common_pass = password_hash("password123", PASSWORD_DEFAULT);

// Sabah Hospitals data
$hospitals = [
    [
        'name' => 'Queen Elizabeth Hospital',
        'address' => 'Lorong Bersatu, Off Jalan Damai, 88300 Kota Kinabalu, Sabah',
        'contact' => '088-324600',
        'type' => 'Government Hospital',
        'admin_name' => 'Dr. Ahmad Razak',
        'admin_email' => 'admin@qeh.gov.my',
        'admin_phone' => '012-3456789'
    ],
    [
        'name' => 'KPJ Sabah Specialist Hospital',
        'address' => 'Jalan Damai, Luyang, 88300 Kota Kinabalu, Sabah',
        'contact' => '088-322000',
        'type' => 'Private Hospital',
        'admin_name' => 'Dr. Siti Nurhaliza',
        'admin_email' => 'admin@kpjsabah.com',
        'admin_phone' => '012-9999999'
    ],
    [
        'name' => 'Gleneagles Kota Kinabalu',
        'address' => 'Block A & B, Lot 1 & 2, Off Jalan Lapangan Terbang, 88000 Kota Kinabalu, Sabah',
        'contact' => '088-518888',
        'type' => 'Private Hospital',
        'admin_name' => 'Dr. Lim Wei Chuan',
        'admin_email' => 'admin@gleneagles-kk.com',
        'admin_phone' => '012-8888888'
    ],
    [
        'name' => 'Sabah Women and Children Hospital',
        'address' => 'Likas, 88400 Kota Kinabalu, Sabah',
        'contact' => '088-315555',
        'type' => 'Government Hospital',
        'admin_name' => 'Dr. Rosnah Binti Ahmad',
        'admin_email' => 'admin@swch.gov.my',
        'admin_phone' => '012-7777777'
    ],
    [
        'name' => 'Tawau Hospital',
        'address' => 'Jalan Apas, 91000 Tawau, Sabah',
        'contact' => '089-773333',
        'type' => 'Government Hospital',
        'admin_name' => 'Dr. Mohd Azmi',
        'admin_email' => 'admin@tawauhospital.gov.my',
        'admin_phone' => '012-6666666'
    ],
    [
        'name' => 'Sandakan Hospital',
        'address' => 'Jalan Utara, 90000 Sandakan, Sabah',
        'contact' => '089-221555',
        'type' => 'Government Hospital',
        'admin_name' => 'Dr. James Wong',
        'admin_email' => 'admin@sandakanhospital.gov.my',
        'admin_phone' => '012-5555555'
    ],
    [
        'name' => 'Keningau Hospital',
        'address' => 'Jalan Hospital, 89007 Keningau, Sabah',
        'contact' => '087-331222',
        'type' => 'Government Hospital',
        'admin_name' => 'Dr. Mary Lim',
        'admin_email' => 'admin@keningauhospital.gov.my',
        'admin_phone' => '012-4444444'
    ],
    [
        'name' => 'Lahad Datu Hospital',
        'address' => 'Jalan Hospital, 91100 Lahad Datu, Sabah',
        'contact' => '089-881222',
        'type' => 'Government Hospital',
        'admin_name' => 'Dr. Tan Kim Hock',
        'admin_email' => 'admin@lahaddatuhospital.gov.my',
        'admin_phone' => '012-3333333'
    ],
    [
        'name' => 'Beaufort Hospital',
        'address' => 'Jalan Padas, 89800 Beaufort, Sabah',
        'contact' => '087-211333',
        'type' => 'Government Hospital',
        'admin_name' => 'Dr. Sabri Bin Abdullah',
        'admin_email' => 'admin@beauforthospital.gov.my',
        'admin_phone' => '012-2222222'
    ],
    [
        'name' => 'Pantai Hospital Likas',
        'address' => 'Jalan Padas Baru, 88450 Kota Kinabalu, Sabah',
        'contact' => '088-321888',
        'type' => 'Private Hospital',
        'admin_name' => 'Dr. Angeline Tan',
        'admin_email' => 'admin@pantai-likas.com',
        'admin_phone' => '012-1111111'
    ]
];

$inserted = 0;
$skipped = 0;

foreach ($hospitals as $hospital) {
    // Check if hospital already exists
    $checkStmt = $conn->prepare("SELECT hospital_id FROM hospital WHERE admin_email = ?");
    $checkStmt->bind_param("s", $hospital['admin_email']);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        echo "<p style='color: orange;'>Hospital {$hospital['name']} already exists (skipped)</p>";
        $skipped++;
        continue;
    }
    
    // Insert hospital
    $stmt = $conn->prepare("INSERT INTO hospital (hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", 
        $hospital['name'],
        $hospital['address'],
        $hospital['contact'],
        $hospital['type'],
        $hospital['admin_name'],
        $hospital['admin_email'],
        $hospital['admin_phone'],
        $common_pass
    );
    
    if ($stmt->execute()) {
        echo "<p style='color: green;'>✓ Added: {$hospital['name']} - {$hospital['address']}</p>";
        $inserted++;
    } else {
        echo "<p style='color: red;'>✗ Failed to add {$hospital['name']}: " . $conn->error . "</p>";
    }
}

echo "<hr>";
echo "<h3>Summary</h3>";
echo "<p style='color: green;'><strong>Inserted: $inserted hospital(s)</strong></p>";
if ($skipped > 0) {
    echo "<p style='color: orange;'><strong>Skipped: $skipped hospital(s) (already exist)</strong></p>";
}

echo "<h3>Login Credentials (for all hospitals):</h3>";
echo "<ul>";
foreach ($hospitals as $hospital) {
    echo "<li><strong>{$hospital['admin_email']}</strong> / password123 - {$hospital['name']}</li>";
}
echo "</ul>";

echo "<br><a href='admin_dashboard.html' style='padding: 10px 20px; background: #dc2626; color: white; text-decoration: none; border-radius: 5px;'>Go to Admin Dashboard</a>";

?>

