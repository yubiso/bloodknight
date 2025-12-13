<?php
// assign_hospitals_to_blood_reports.php
// This script randomly assigns hospital_id to existing blood reports via appointments
// Run this once to populate hospital associations for existing blood reports

require_once 'db_connect.php';

echo "<h2>Assigning Hospitals to Blood Reports</h2>";

// Get all hospitals
$hospitals = $conn->query("SELECT hospital_id FROM hospital")->fetch_all(MYSQLI_ASSOC);

if (empty($hospitals)) {
    echo "<p style='color: red;'>No hospitals found. Please create hospitals first.</p>";
    exit;
}

$hospital_ids = array_column($hospitals, 'hospital_id');
echo "<p>Found " . count($hospital_ids) . " hospital(s)</p>";

// Get all blood reports that don't have an appointment (appt_id IS NULL)
$reportsWithoutAppt = $conn->query("SELECT report_id, user_id FROM blood_report WHERE appt_id IS NULL")->fetch_all(MYSQLI_ASSOC);
echo "<p>Found " . count($reportsWithoutAppt) . " blood report(s) without appointments</p>";

$updated = 0;
$assigned = 0;

// For reports without appointments, we'll create dummy appointments or link to random appointments
foreach ($reportsWithoutAppt as $report) {
    // Try to find an appointment for this user at any hospital
    $apptStmt = $conn->prepare("SELECT a.appt_id, d.hospital_id 
                                FROM appointment a 
                                JOIN blood_drive d ON a.drive_id = d.drive_id 
                                WHERE a.user_id = ? 
                                ORDER BY RAND() 
                                LIMIT 1");
    $apptStmt->bind_param("i", $report['user_id']);
    $apptStmt->execute();
    $apptResult = $apptStmt->get_result();
    
    if ($apptRow = $apptResult->fetch_assoc()) {
        // Link this report to an existing appointment
        $updateStmt = $conn->prepare("UPDATE blood_report SET appt_id = ? WHERE report_id = ?");
        $updateStmt->bind_param("ii", $apptRow['appt_id'], $report['report_id']);
        if ($updateStmt->execute()) {
            $updated++;
        }
    } else {
        // No appointment found, assign to a random hospital by creating a walk-in appointment reference
        // For now, we'll just leave it as NULL - it will show as "Walk-in / Other" in the admin
        $assigned++;
    }
}

// Get all appointments and update their blood reports to link properly
$appointments = $conn->query("SELECT a.appt_id, a.user_id, d.hospital_id, d.drive_date 
                              FROM appointment a 
                              JOIN blood_drive d ON a.drive_id = d.drive_id 
                              WHERE a.status IN ('Confirmed', 'Completed')")->fetch_all(MYSQLI_ASSOC);

echo "<p>Found " . count($appointments) . " appointments with hospitals</p>";

// For each appointment, check if there are blood reports for that user around that date
foreach ($appointments as $appt) {
    $dateRangeStart = date('Y-m-d', strtotime($appt['drive_date'] . ' -7 days'));
    $dateRangeEnd = date('Y-m-d', strtotime($appt['drive_date'] . ' +7 days'));
    
    $reportStmt = $conn->prepare("SELECT report_id FROM blood_report 
                                  WHERE user_id = ? 
                                  AND appt_id IS NULL 
                                  AND report_date BETWEEN ? AND ?
                                  LIMIT 1");
    $reportStmt->bind_param("iss", $appt['user_id'], $dateRangeStart, $dateRangeEnd);
    $reportStmt->execute();
    $reportResult = $reportStmt->get_result();
    
    if ($reportRow = $reportResult->fetch_assoc()) {
        $linkStmt = $conn->prepare("UPDATE blood_report SET appt_id = ? WHERE report_id = ?");
        $linkStmt->bind_param("ii", $appt['appt_id'], $reportRow['report_id']);
        if ($linkStmt->execute()) {
            $updated++;
        }
    }
}

echo "<p style='color: green;'>✓ Successfully linked $updated blood report(s) to appointments</p>";
echo "<p>Note: Reports without appointments will show as 'Walk-in / Other' and won't be filtered by hospital</p>";

echo "<br><a href='admin_dashboard.html'>Go to Admin Dashboard</a>";

?>

