<?php
// insert_sample_blood_reports.php - Inserts sample blood reports for hiewz256@gmail.com
require_once 'db_connect.php';

echo "<h2>Inserting Sample Blood Reports</h2>";

// Get user_id for hiewz256@gmail.com
$email = 'hiewz256@gmail.com';
$stmt = $conn->prepare("SELECT user_id, full_name FROM donor_user WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "<p style='color: red;'>✗ User with email '$email' not found. Please register this account first.</p>";
    echo "<br><a href='donorsignup.html'>Register Account</a>";
    exit;
}

$user = $result->fetch_assoc();
$user_id = $user['user_id'];
$full_name = $user['full_name'];

echo "<p>Found user: <strong>$full_name</strong> (ID: $user_id)</p>";

// Check if reports already exist
$checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM blood_report WHERE user_id = ?");
$checkStmt->bind_param("i", $user_id);
$checkStmt->execute();
$countResult = $checkStmt->get_result()->fetch_assoc();
$existingCount = $countResult['count'];

if ($existingCount > 0) {
    echo "<p style='color: orange;'>⚠ This user already has $existingCount blood report(s). Adding more...</p>";
} else {
    echo "<p>No existing reports found. Creating sample reports...</p>";
}

// Get all hospitals for randomization
$all_hospitals = $conn->query("SELECT hospital_id FROM hospital")->fetch_all(MYSQLI_ASSOC);
$hospital_ids = array_column($all_hospitals, 'hospital_id');

if (empty($hospital_ids)) {
    echo "<p style='color: orange;'>⚠ No hospitals found. Reports will be created without hospital association.</p>";
}

// Insert sample blood reports
$reports = [
    [
        'appt_id' => null,
        'report_date' => date('Y-m-d', strtotime('-90 days')),
        'hemoglobin' => 15.0,
        'hematocrit' => 44.5,
        'platelet_count' => 275000,
        'white_blood_cell_count' => 7.1,
        'red_blood_cell_count' => 5.0,
        'blood_pressure' => '118/76',
        'temperature' => 98.5,
        'volume_ml' => 450,
        'notes' => 'First blood donation checkup. All parameters excellent. Eligible for donation.'
    ],
    [
        'appt_id' => null,
        'report_date' => date('Y-m-d', strtotime('-45 days')),
        'hemoglobin' => 14.8,
        'hematocrit' => 43.8,
        'platelet_count' => 268000,
        'white_blood_cell_count' => 6.9,
        'red_blood_cell_count' => 4.9,
        'blood_pressure' => '120/78',
        'temperature' => 98.6,
        'volume_ml' => 470,
        'notes' => 'Post-donation follow-up. Recovery progressing well. All values within normal range.'
    ],
    [
        'appt_id' => null,
        'report_date' => date('Y-m-d', strtotime('-20 days')),
        'hemoglobin' => 15.2,
        'hematocrit' => 45.2,
        'platelet_count' => 282000,
        'white_blood_cell_count' => 7.3,
        'red_blood_cell_count' => 5.1,
        'blood_pressure' => '116/74',
        'temperature' => 98.4,
        'volume_ml' => 480,
        'notes' => 'Regular health screening. Excellent cardiovascular health. Ready for next donation.'
    ],
    [
        'appt_id' => null,
        'report_date' => date('Y-m-d', strtotime('-7 days')),
        'hemoglobin' => 14.9,
        'hematocrit' => 44.0,
        'platelet_count' => 270000,
        'white_blood_cell_count' => 7.0,
        'red_blood_cell_count' => 4.95,
        'blood_pressure' => '119/77',
        'temperature' => 98.5,
        'volume_ml' => 450,
        'notes' => 'Latest checkup before donation. All parameters optimal. Cleared for blood donation.'
    ]
];

$insertStmt = $conn->prepare("INSERT INTO blood_report (user_id, appt_id, report_date, hemoglobin, hematocrit, platelet_count, white_blood_cell_count, red_blood_cell_count, blood_pressure, temperature, volume_ml, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$inserted = 0;
$errors = 0;
$created_appointments = 0;

foreach ($reports as $index => $report) {
    $appt_id = $report['appt_id']; // Start with the report's appt_id (usually null)
    
    // Randomly assign a hospital for each report
    if (!empty($hospital_ids)) {
        $report_hospital_id = $hospital_ids[array_rand($hospital_ids)];
        
        // Try to find or create an appointment for this hospital and date
        $dateRangeStart = date('Y-m-d', strtotime($report['report_date'] . ' -7 days'));
        $dateRangeEnd = date('Y-m-d', strtotime($report['report_date'] . ' +7 days'));
        
        // Try to find existing appointment
        $findApptStmt = $conn->prepare("SELECT a.appt_id 
                                       FROM appointment a 
                                       JOIN blood_drive d ON a.drive_id = d.drive_id 
                                       WHERE a.user_id = ? AND d.hospital_id = ? 
                                       AND d.drive_date BETWEEN ? AND ?
                                       LIMIT 1");
        $findApptStmt->bind_param("iiss", $user_id, $report_hospital_id, $dateRangeStart, $dateRangeEnd);
        $findApptStmt->execute();
        $apptResult = $findApptStmt->get_result();
        
        if ($apptRow = $apptResult->fetch_assoc()) {
            $appt_id = $apptRow['appt_id'];
        } else {
            // Try to find a drive or create one
            $driveStmt = $conn->prepare("SELECT drive_id FROM blood_drive 
                                        WHERE hospital_id = ? 
                                        AND drive_date BETWEEN ? AND ?
                                        LIMIT 1");
            $driveStmt->bind_param("iss", $report_hospital_id, $dateRangeStart, $dateRangeEnd);
            $driveStmt->execute();
            $driveResult = $driveStmt->get_result();
            
            if ($driveRow = $driveResult->fetch_assoc()) {
                $drive_id = $driveRow['drive_id'];
                $time = '10:00:00';
                
                $createApptStmt = $conn->prepare("INSERT INTO appointment (user_id, drive_id, selected_time, status, source) VALUES (?, ?, ?, 'Completed', 'Walk-in')");
                $createApptStmt->bind_param("iis", $user_id, $drive_id, $time);
                if ($createApptStmt->execute()) {
                    $appt_id = $createApptStmt->insert_id;
                    $created_appointments++;
                }
            } else {
                // Create drive and appointment
                $drive_date = $report['report_date'];
                $createDriveStmt = $conn->prepare("INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status) VALUES (?, ?, '09:00:00', '17:00:00', 'Walk-in Screening', 'Completed')");
                $createDriveStmt->bind_param("is", $report_hospital_id, $drive_date);
                
                if ($createDriveStmt->execute()) {
                    $drive_id = $createDriveStmt->insert_id;
                    $time = '10:00:00';
                    
                    $createApptStmt = $conn->prepare("INSERT INTO appointment (user_id, drive_id, selected_time, status, source) VALUES (?, ?, ?, 'Completed', 'Walk-in')");
                    $createApptStmt->bind_param("iis", $user_id, $drive_id, $time);
                    if ($createApptStmt->execute()) {
                        $appt_id = $createApptStmt->insert_id;
                        $created_appointments++;
                    }
                }
            }
        }
    }
    
    $insertStmt->bind_param("iisddiddddis", 
        $user_id,
        $appt_id,
        $report['report_date'],
        $report['hemoglobin'],
        $report['hematocrit'],
        $report['platelet_count'],
        $report['white_blood_cell_count'],
        $report['red_blood_cell_count'],
        $report['blood_pressure'],
        $report['temperature'],
        $report['volume_ml'],
        $report['notes']
    );
    
    if ($insertStmt->execute()) {
        $inserted++;
    } else {
        $errors++;
        echo "<p style='color: red;'>Error inserting report: " . $insertStmt->error . "</p>";
    }
}

if ($inserted > 0) {
    echo "<p style='color: green; font-size: 18px;'>✓ Successfully inserted $inserted blood report(s) with randomized hospital assignments!</p>";
    if ($created_appointments > 0) {
        echo "<p style='color: blue;'>ℹ Created $created_appointments appointment(s) to link reports to hospitals.</p>";
    }
}

if ($errors > 0) {
    echo "<p style='color: red;'>✗ Failed to insert $errors report(s)</p>";
}

echo "<br><a href='dashboard.html' style='padding: 10px 20px; background: #dc2626; color: white; text-decoration: none; border-radius: 5px;'>Go to Dashboard</a>";

?>

