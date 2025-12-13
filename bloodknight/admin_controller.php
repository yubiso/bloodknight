<?php
// admin_controller.php - COMMAND CENTER BACKEND
header('Content-Type: application/json');

// Configure session for persistence
ini_set('session.cookie_lifetime', 86400 * 7); // 7 days
ini_set('session.gc_maxlifetime', 86400 * 7); // 7 days
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

require_once 'db_connect.php'; 

// --- GMAIL INTEGRATION CONFIG ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$action = $_REQUEST['action'] ?? '';
$hospital_id = $_SESSION['hospital_id'] ?? 1;

function sendJson($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

function sendEmail($toEmail, $toName, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'hiewz256@gmail.com'; 
        $mail->Password   = 'pwml dpzm hxuw gffr';    
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->setFrom('alert@bloodknight.com', 'BloodKnight Command');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return ['success' => true, 'msg' => 'Sent'];
    } catch (Exception $e) {
        return ['success' => false, 'msg' => $mail->ErrorInfo];
    }
}

// =============================================================
// 1. AUTHENTICATION
// =============================================================

if ($action === 'check_session') {
    if (isset($_SESSION['hospital_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'hospital') {
        sendJson('success', 'Session valid', [
            'hospital_id' => $_SESSION['hospital_id'],
            'hospital_name' => $_SESSION['hospital_name'],
            'admin_name' => $_SESSION['admin_name']
        ]);
    } else {
        sendJson('error', 'Not logged in');
    }
}

// =============================================================
// 2. ANALYTICS & REPORTS
// =============================================================
// Ensure hospital_id is set (middleware check)
if (!isset($_SESSION['hospital_id']) && $action !== 'login' && $action !== 'logout' && $action !== 'check_session') {
    sendJson('error', 'Unauthorized: Please login');
}
$hospital_id = $_SESSION['hospital_id'] ?? 1;

if ($action === 'get_analytics') {
    try {
        // Helper function to safely execute queries with hospital filter
        function safeQuery($conn, $sql, $default = [], $params = [], $types = '') {
            try {
                if (!empty($params) && !empty($types)) {
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param($types, ...$params);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    } else {
                        error_log("Prepare Error: " . $conn->error . " | Query: " . $sql);
                        return $default;
                    }
                } else {
                    $result = $conn->query($sql);
                    if ($result === false) {
                        error_log("SQL Error: " . $conn->error . " | Query: " . $sql);
                        return $default;
                    }
                }
                $data = [];
                while ($row = $result->fetch_assoc()) { 
                    $data[] = $row; 
                }
                if (isset($stmt)) $stmt->close();
                return $data;
            } catch (Exception $e) {
                error_log("Query Exception: " . $e->getMessage() . " | Query: " . $sql);
                return $default;
            }
        }

        global $hospital_id;
        
        // Filter by hospital - get donors who donated at this hospital
        $bloodData = safeQuery($conn, "SELECT u.blood_type as type, COUNT(DISTINCT u.user_id) as count 
                                       FROM donor_user u
                                       JOIN appointment a ON u.user_id = a.user_id
                                       JOIN blood_drive d ON a.drive_id = d.drive_id
                                       WHERE d.hospital_id = ?
                                       GROUP BY u.blood_type", [], [$hospital_id], 'i');

        // Filter donation history by hospital - Use blood_report data
        // Exclude reports without hospital location
        $trendData = safeQuery($conn, "SELECT DATE_FORMAT(br.report_date, '%M') as month, SUM(COALESCE(br.volume_ml, a.volume_ml)) as volume 
                                       FROM blood_report br
                                       LEFT JOIN appointment a ON br.appt_id = a.appt_id
                                       LEFT JOIN blood_drive d ON a.drive_id = d.drive_id
                                       LEFT JOIN hospital h ON d.hospital_id = h.hospital_id
                                       WHERE d.hospital_id = ? AND h.hospital_id IS NOT NULL
                                       AND br.report_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                                       AND (br.volume_ml IS NOT NULL OR a.volume_ml IS NOT NULL)
                                       GROUP BY month ORDER BY br.report_date ASC", [], [$hospital_id], 'i');

        // Filter performance by hospital
        $perfData = safeQuery($conn, "SELECT d.location_name as location, COUNT(a.appt_id) as count 
                                      FROM appointment a 
                                      JOIN blood_drive d ON a.drive_id = d.drive_id 
                                      WHERE d.hospital_id = ? AND a.status = 'Completed' 
                                      GROUP BY d.location_name LIMIT 5", [], [$hospital_id], 'i');

        // Blood Reports Analytics - Filter by hospital
        // 1. Blood Reports Count Over Time (Last 6 Months) - for this hospital
        $bloodReportTrendData = safeQuery($conn, "SELECT DATE_FORMAT(br.report_date, '%M') as month, COUNT(*) as count 
                                                   FROM blood_report br
                                                   LEFT JOIN appointment a ON br.appt_id = a.appt_id
                                                   LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
                                                   WHERE (bd.hospital_id = ? OR br.appt_id IS NULL)
                                                   AND br.report_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                                                   GROUP BY month ORDER BY br.report_date ASC", [], [$hospital_id], 'i');

        // 2. Average Hemoglobin Levels by Month - for this hospital
        // Exclude reports without hospital location
        $hemoglobinTrendRaw = safeQuery($conn, "SELECT DATE_FORMAT(br.report_date, '%M') as month, AVG(CAST(br.hemoglobin AS DECIMAL(5,2))) as avg_hemoglobin 
                                                 FROM blood_report br
                                                 LEFT JOIN appointment a ON br.appt_id = a.appt_id
                                                 LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
                                                 LEFT JOIN hospital h ON bd.hospital_id = h.hospital_id
                                                 WHERE bd.hospital_id = ? AND h.hospital_id IS NOT NULL
                                                 AND br.report_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH) 
                                                 AND br.hemoglobin IS NOT NULL AND br.hemoglobin != ''
                                                 GROUP BY month ORDER BY br.report_date ASC", [], [$hospital_id], 'i');
        $hemoglobinTrendData = [];
        foreach ($hemoglobinTrendRaw as $row) {
            $row['avg_hemoglobin'] = round((float)$row['avg_hemoglobin'], 1);
            $hemoglobinTrendData[] = $row;
        }

        // 3. Blood Reports by Location - for this hospital (should show drive locations)
        // Exclude reports without hospital location
        $bloodReportLocationData = safeQuery($conn, "SELECT bd.location_name as location, COUNT(br.report_id) as count 
                                                      FROM blood_report br
                                                      LEFT JOIN appointment a ON br.appt_id = a.appt_id
                                                      LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
                                                      LEFT JOIN hospital h ON bd.hospital_id = h.hospital_id
                                                      WHERE bd.hospital_id = ? AND h.hospital_id IS NOT NULL
                                                      AND bd.location_name IS NOT NULL
                                                      GROUP BY location
                                                      ORDER BY count DESC
                                                      LIMIT 5", [], [$hospital_id], 'i');

        // 4. Blood Report Vital Stats Distribution (Hemoglobin ranges) - for this hospital
        // Exclude reports without hospital location
        $hemoglobinRangeData = safeQuery($conn, "SELECT 
            CASE 
                WHEN CAST(br.hemoglobin AS DECIMAL(5,2)) < 12 THEN 'Low (<12)'
                WHEN CAST(br.hemoglobin AS DECIMAL(5,2)) BETWEEN 12 AND 15 THEN 'Normal (12-15)'
                WHEN CAST(br.hemoglobin AS DECIMAL(5,2)) BETWEEN 15 AND 17 THEN 'High (15-17)'
                ELSE 'Very High (>17)'
            END as range_label,
            COUNT(*) as count
            FROM blood_report br
            LEFT JOIN appointment a ON br.appt_id = a.appt_id
            LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
            LEFT JOIN hospital h ON bd.hospital_id = h.hospital_id
            WHERE bd.hospital_id = ? AND h.hospital_id IS NOT NULL
            AND br.hemoglobin IS NOT NULL AND br.hemoglobin != ''
            GROUP BY range_label
            ORDER BY MIN(CAST(br.hemoglobin AS DECIMAL(5,2)))", [], [$hospital_id], 'i');

        sendJson('success', 'Report Generated', [
            'blood_types' => $bloodData, 
            'trends' => $trendData, 
            'performance' => $perfData,
            'blood_report_trends' => $bloodReportTrendData,
            'hemoglobin_trends' => $hemoglobinTrendData,
            'blood_report_locations' => $bloodReportLocationData,
            'hemoglobin_ranges' => $hemoglobinRangeData
        ]);
    } catch (Exception $e) {
        error_log("Analytics Error: " . $e->getMessage());
        sendJson('success', 'Report Generated (with errors)', [
            'blood_types' => [],
            'trends' => [],
            'performance' => [],
            'blood_report_trends' => [],
            'hemoglobin_trends' => [],
            'blood_report_locations' => [],
            'hemoglobin_ranges' => []
        ]);
    }
}

// =============================================================
// 2. GMAIL ALERTS
// =============================================================
elseif ($action === 'send_gmail_alert') {
    $target_type = $_POST['blood_type'];
    $urgency = $_POST['urgency'];
    $message_body = $_POST['message'];
    $test_email = $_POST['test_email'] ?? '';

    $stmt = $conn->prepare("INSERT INTO notification (hospital_id, target_blood_type, message_content, urgency_level) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $hospital_id, $target_type, $message_body, $urgency);
    $stmt->execute();

    $recipients = [];
    if (!empty($test_email)) {
        $recipients[] = ['email' => $test_email, 'name' => 'Test Admin'];
        $status_msg = "Test email sent to $test_email";
    } else {
        $emailStmt = $conn->prepare("SELECT email, full_name FROM donor_user WHERE blood_type = ?");
        $emailStmt->bind_param("s", $target_type);
        $emailStmt->execute();
        $result = $emailStmt->get_result();
        while ($row = $result->fetch_assoc()) { $recipients[] = ['email' => $row['email'], 'name' => $row['full_name']]; }
        $status_msg = "Alert broadcasted to " . count($recipients) . " donors.";
    }

    foreach ($recipients as $person) {
        sendEmail($person['email'], $person['name'], "[$urgency] Blood Request: Type $target_type", "<h1>Urgent Appeal</h1><p>$message_body</p>");
    }
    sendJson('success', $status_msg);
}

// =============================================================
// 3. APPOINTMENTS (PENDING & ACTIVE)
// =============================================================

elseif ($action === 'get_appointments') {
    // Get PENDING appointments for this hospital
    global $hospital_id;
    
    $stmt = $conn->prepare("SELECT a.appt_id, u.full_name, u.blood_type, d.location_name, a.selected_time 
                            FROM appointment a 
                            JOIN donor_user u ON a.user_id = u.user_id 
                            JOIN blood_drive d ON a.drive_id = d.drive_id 
                            WHERE d.hospital_id = ? AND a.status = 'Pending'");
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) { $data[] = $row; }
    sendJson('success', 'Loaded', $data);
}

elseif ($action === 'get_active_roster') {
    // Get active roster for this hospital
    global $hospital_id;
    
    $stmt = $conn->prepare("SELECT 
                                a.appt_id, 
                                u.full_name, 
                                u.blood_type, 
                                d.location_name, 
                                d.drive_date, 
                                a.selected_time, 
                                COALESCE(a.source, 'Online') as source 
                            FROM appointment a
                            JOIN donor_user u ON a.user_id = u.user_id
                            JOIN blood_drive d ON a.drive_id = d.drive_id
                            WHERE d.hospital_id = ? AND a.status = 'Confirmed' 
                            ORDER BY d.drive_date ASC, a.selected_time ASC");
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) { 
        if (empty($row['source'])) {
            $row['source'] = 'Online';
        }
        $data[] = $row; 
    }
    sendJson('success', 'Loaded active roster', $data);
}

elseif ($action === 'confirm_appt') {
    global $hospital_id;
    $id = $_POST['appt_id'];
    
    // 1. Get info to update timer and send email - verify it belongs to this hospital
    $sql = "SELECT u.user_id, u.email, u.full_name, a.selected_time, d.location_name, d.drive_date 
            FROM appointment a 
            JOIN donor_user u ON a.user_id = u.user_id 
            JOIN blood_drive d ON a.drive_id = d.drive_id 
            WHERE a.appt_id = ? AND d.hospital_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id, $hospital_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        $donor_email = $row['email'];
        $user_id = $row['user_id'];
        $date = $row['drive_date'];

        // 2. Update appointment status
        $conn->query("UPDATE appointment SET status='Confirmed' WHERE appt_id=$id");
        
        // 3. Update Donor Timer (Reset eligibility)
        $upd = $conn->prepare("UPDATE donor_user SET last_donation_date = ? WHERE user_id = ?");
        $upd->bind_param("si", $date, $user_id);
        $upd->execute();

        // 4. Send Email
        $emailStatus = sendEmail($donor_email, $row['full_name'], "Appointment Confirmed", "<p>Your appointment at {$row['location_name']} on {$date} is confirmed.</p>");
        
        if ($emailStatus['success']) {
            sendJson('success', "Confirmed & Timer Reset.");
        } else {
            sendJson('error', "Confirmed, but Email Failed.");
        }
    } else {
        sendJson('error', 'Appointment not found.');
    }
}

elseif ($action === 'reject_appt') {
    global $hospital_id;
    $id = $_POST['appt_id'];
    
    // 1. Verify appointment belongs to this hospital
    $sql = "SELECT u.user_id, u.email, u.full_name, a.selected_time, d.location_name, d.drive_date 
            FROM appointment a 
            JOIN donor_user u ON a.user_id = u.user_id 
            JOIN blood_drive d ON a.drive_id = d.drive_id 
            WHERE a.appt_id = ? AND d.hospital_id = ? AND a.status = 'Pending'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id, $hospital_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        $donor_email = $row['email'];
        
        // 2. Update appointment status to Cancelled
        $conn->query("UPDATE appointment SET status='Cancelled' WHERE appt_id=$id");
        
        // 3. Send Email notification
        $emailStatus = sendEmail($donor_email, $row['full_name'], "Appointment Rejected", "<p>Unfortunately, your appointment request at {$row['location_name']} on {$row['drive_date']} has been rejected. Please try booking another appointment.</p>");
        
        if ($emailStatus['success']) {
            sendJson('success', "Appointment rejected & donor notified.");
        } else {
            sendJson('success', "Appointment rejected, but email notification failed.");
        }
    } else {
        sendJson('error', 'Appointment not found or already processed.');
    }
}

// =============================================================
// 4. WALK-IN BOOKING
// =============================================================
elseif ($action === 'book_walkin_appointment') {
    $user_id = $_POST['user_id'];
    $drive_id = $_POST['drive_id'];
    $time = $_POST['time'];
    
    $check = $conn->prepare("SELECT appt_id FROM appointment WHERE drive_id = ? AND selected_time = ? AND status != 'Cancelled'");
    $check->bind_param("is", $drive_id, $time);
    $check->execute();
    if ($check->get_result()->num_rows > 0) { sendJson('error', 'Slot taken.'); }

    try {
        // Insert with source='Walk-in'
        $stmt = $conn->prepare("INSERT INTO appointment (user_id, drive_id, selected_time, status, source) VALUES (?, ?, ?, 'Confirmed', 'Walk-in')");
        $stmt->bind_param("iis", $user_id, $drive_id, $time);
        
        if ($stmt->execute()) { 
            // Update Timer
            $dDrive = $conn->query("SELECT drive_date, location_name FROM blood_drive WHERE drive_id = $drive_id")->fetch_assoc();
            $upd = $conn->prepare("UPDATE donor_user SET last_donation_date = ? WHERE user_id = ?");
            $upd->bind_param("si", $dDrive['drive_date'], $user_id);
            $upd->execute();

            // Email
            $uRow = $conn->query("SELECT email, full_name FROM donor_user WHERE user_id = $user_id")->fetch_assoc();
            if ($uRow) {
                sendEmail($uRow['email'], $uRow['full_name'], "Walk-in Confirmed", "<p>Your walk-in appointment at {$dDrive['location_name']} is confirmed.</p>");
            }
            sendJson('success', 'Walk-in Booked.'); 
        } else { sendJson('error', 'Booking failed: ' . $conn->error); }
    } catch (Exception $e) { sendJson('error', 'DB Error: ' . $e->getMessage()); }
}

// =============================================================
// 5. STATS & OVERVIEW
// =============================================================
elseif ($action === 'get_stats') {
    global $hospital_id;
    
    // Count donors who have appointments at this hospital
    $donorsStmt = $conn->prepare("SELECT COUNT(DISTINCT u.user_id) as c 
                                  FROM donor_user u
                                  JOIN appointment a ON u.user_id = a.user_id
                                  JOIN blood_drive d ON a.drive_id = d.drive_id
                                  WHERE d.hospital_id = ?");
    $donorsStmt->bind_param("i", $hospital_id);
    $donorsStmt->execute();
    $donors = $donorsStmt->get_result()->fetch_assoc()['c'];
    
    // Pending appointments for this hospital
    $pendingStmt = $conn->prepare("SELECT COUNT(*) as c 
                                   FROM appointment a 
                                   JOIN blood_drive d ON a.drive_id = d.drive_id 
                                   WHERE d.hospital_id = ? AND a.status='Pending'");
    $pendingStmt->bind_param("i", $hospital_id);
    $pendingStmt->execute();
    $pending = $pendingStmt->get_result()->fetch_assoc()['c'];
    
    // Volume from blood reports at this hospital (primary source)
    // Exclude reports without hospital location
    $volumeStmt = $conn->prepare("SELECT SUM(COALESCE(br.volume_ml, a.volume_ml)) as v 
                                  FROM blood_report br
                                  LEFT JOIN appointment a ON br.appt_id = a.appt_id
                                  LEFT JOIN blood_drive d ON a.drive_id = d.drive_id
                                  LEFT JOIN hospital h ON d.hospital_id = h.hospital_id
                                  WHERE d.hospital_id = ? AND h.hospital_id IS NOT NULL
                                  AND (br.volume_ml IS NOT NULL OR a.volume_ml IS NOT NULL)");
    $volumeStmt->bind_param("i", $hospital_id);
    $volumeStmt->execute();
    $volume = $volumeStmt->get_result()->fetch_assoc()['v'] ?? 0;
    
    // Confirmed appointments for this hospital
    $confirmedStmt = $conn->prepare("SELECT COUNT(*) as c 
                                     FROM appointment a 
                                     JOIN blood_drive d ON a.drive_id = d.drive_id 
                                     WHERE d.hospital_id = ? AND a.status='Confirmed'");
    $confirmedStmt->bind_param("i", $hospital_id);
    $confirmedStmt->execute();
    $confirmed_appt = $confirmedStmt->get_result()->fetch_assoc()['c'];
    
    // Total donations at this hospital
    $totalDonationsStmt = $conn->prepare("SELECT COUNT(*) as c 
                                          FROM appointment a 
                                          JOIN blood_drive d ON a.drive_id = d.drive_id 
                                          WHERE d.hospital_id = ? AND a.status='Completed'");
    $totalDonationsStmt->bind_param("i", $hospital_id);
    $totalDonationsStmt->execute();
    $total_donations = $totalDonationsStmt->get_result()->fetch_assoc()['c'];
    
    // Overview Roster - for this hospital
    $rosterStmt = $conn->prepare("SELECT u.user_id, u.full_name, d.location_name, d.drive_date, a.selected_time 
                                  FROM appointment a
                                  JOIN donor_user u ON a.user_id = u.user_id
                                  JOIN blood_drive d ON a.drive_id = d.drive_id
                                  WHERE d.hospital_id = ? AND a.status = 'Confirmed' 
                                  ORDER BY d.drive_date ASC, a.selected_time ASC
                                  LIMIT 5");
    $rosterStmt->bind_param("i", $hospital_id);
    $rosterStmt->execute();
    $confirmed_roster = $rosterStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Donation history for this hospital - Use blood_report as primary source
    // Exclude reports without hospital location
    $historyStmt = $conn->prepare("SELECT 
                                       u.user_id, 
                                       u.full_name, 
                                       br.report_date as donation_date, 
                                       COALESCE(br.volume_ml, a.volume_ml) as volume_ml 
                                   FROM blood_report br
                                   JOIN donor_user u ON br.user_id = u.user_id
                                   LEFT JOIN appointment a ON br.appt_id = a.appt_id
                                   LEFT JOIN blood_drive d ON a.drive_id = d.drive_id
                                   LEFT JOIN hospital h ON d.hospital_id = h.hospital_id
                                   WHERE d.hospital_id = ? AND h.hospital_id IS NOT NULL
                                   AND (br.volume_ml IS NOT NULL OR a.volume_ml IS NOT NULL)
                                   ORDER BY br.report_date DESC
                                   LIMIT 5");
    $historyStmt->bind_param("i", $hospital_id);
    $historyStmt->execute();
    $donation_history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    sendJson('success', 'Data loaded', [
        'donors' => $donors, 'pending_appt' => $pending, 'confirmed_appt' => $confirmed_appt,
        'total_donations' => $total_donations, 'volume_l' => number_format($volume/1000, 1),
        'confirmed_roster' => $confirmed_roster, 'donation_history' => $donation_history
    ]);
}

elseif ($action === 'get_donors_and_drives') {
    $donors = $conn->query("SELECT user_id, email, full_name FROM donor_user ORDER BY email ASC")->fetch_all(MYSQLI_ASSOC);
    $drives = $conn->query("SELECT drive_id, location_name, drive_date, start_time, end_time FROM blood_drive WHERE drive_date >= CURDATE() AND status = 'Upcoming' ORDER BY drive_date ASC")->fetch_all(MYSQLI_ASSOC);
    sendJson('success', 'Data loaded', ['donors' => $donors, 'drives' => $drives]);
}

elseif ($action === 'get_slots') {
    $drive_id = $_POST['drive_id'] ?? 0;
    $drive = $conn->query("SELECT start_time, end_time FROM blood_drive WHERE drive_id = $drive_id")->fetch_assoc();
    $booked = $conn->query("SELECT selected_time FROM appointment WHERE drive_id = $drive_id AND status != 'Cancelled'");
    $booked_times = [];
    while($r = $booked->fetch_assoc()) $booked_times[] = $r['selected_time'];
    
    $slots = [];
    $start = strtotime($drive['start_time']);
    $end = strtotime($drive['end_time']);
    while ($start < $end) {
        $t = date('H:i:s', $start);
        $slots[] = ['raw_time' => $t, 'display_time' => date('h:i A', $start), 'is_taken' => in_array($t, $booked_times)];
        $start += 1200; 
    }
    sendJson('success', 'Slots', $slots);
}

// === NEW: GET ACTIVE APPOINTMENT FOR DONOR (UPDATED to fetch Name) ===
elseif ($action === 'get_donor_active_appointment') {
    $user_id = $_POST['user_id'];
    
    // Join with blood_drive to get the name
    $stmt = $conn->prepare("
        SELECT a.drive_id, b.location_name, b.drive_date 
        FROM appointment a 
        JOIN blood_drive b ON a.drive_id = b.drive_id
        WHERE a.user_id = ? AND a.status IN ('Pending', 'Confirmed') 
        LIMIT 1
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        sendJson('success', 'Active appointment found', $row);
    } else {
        sendJson('success', 'No active appointment', null); 
    }
}

// NEW: Get Today's Drives for Dropdown
elseif ($action === 'get_todays_drives') {
    global $hospital_id;
    $stmt = $conn->prepare("SELECT drive_id, location_name, start_time, end_time FROM blood_drive WHERE drive_date = CURDATE() AND hospital_id = ?");
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $drives = $result->fetch_all(MYSQLI_ASSOC);
    sendJson('success', 'Today\'s drives loaded', $drives);
}

// NEW: Get Participants for a specific drive
elseif ($action === 'get_drive_participants') {
    $drive_id = $_POST['drive_id'] ?? 0;
    
    $stmt = $conn->prepare("
        SELECT u.user_id, u.full_name, u.email, u.blood_type, a.appt_id, a.status 
        FROM appointment a 
        JOIN donor_user u ON a.user_id = u.user_id 
        WHERE a.drive_id = ? AND a.status IN ('Pending', 'Confirmed')
        ORDER BY u.full_name ASC
    ");
    $stmt->bind_param("i", $drive_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $participants = $result->fetch_all(MYSQLI_ASSOC);
    
    if (empty($participants)) {
        sendJson('success', 'No participants found for this drive', []);
    } else {
        sendJson('success', 'Participants loaded', $participants);
    }
}

// === PROCESS DONATION ===
elseif ($action === 'process_donation') {
    $email = $_POST['donor_email'] ?? '';
    $user_id = $_POST['user_id'] ?? '';
    $vol = $_POST['volume']; 
    $notes = $_POST['notes'] ?? 'Standard donation';
    $drive_id = $_POST['drive_id'] ?? ''; 
    
    if (empty($vol)) { sendJson('error', 'Volume is required'); }

    if (!empty($user_id)) {
        $uRes = $conn->query("SELECT user_id FROM donor_user WHERE user_id = " . intval($user_id));
    } else {
        $safe_email = $conn->real_escape_string($email);
        $uRes = $conn->query("SELECT user_id FROM donor_user WHERE email = '$safe_email'");
    }

    if ($uRes && $row = $uRes->fetch_assoc()) {
        $found_user_id = $row['user_id'];

        $conn->begin_transaction();
        try {
            // UPDATE appointment table directly (don't insert into non-existent history table)
            if ($drive_id) {
                 // Updates specific appointment
                 $stmt = $conn->prepare("UPDATE appointment SET status = 'Completed', donation_date = CURDATE(), volume_ml = ?, notes = ? WHERE user_id = ? AND drive_id = ? AND status IN ('Pending', 'Confirmed')");
                 $stmt->bind_param("isii", $vol, $notes, $found_user_id, $drive_id);
            } else {
                // Fallback for general updates
                $stmt = $conn->prepare("UPDATE appointment SET status = 'Completed', donation_date = CURDATE(), volume_ml = ?, notes = ? WHERE user_id = ? AND status IN ('Pending', 'Confirmed') LIMIT 1");
                $stmt->bind_param("isi", $vol, $notes, $found_user_id);
            }
            $stmt->execute();
            
            // Update User Stats (Rank/Eligibility)
            $checkCol = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'last_donation_date'");
            if ($checkCol && $checkCol->num_rows > 0) {
                $checkTotal = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'total_donations'");
                if($checkTotal && $checkTotal->num_rows > 0) {
                     $updUser = $conn->prepare("UPDATE donor_user SET last_donation_date = NOW(), total_donations = total_donations + 1 WHERE user_id = ?");
                } else {
                     $updUser = $conn->prepare("UPDATE donor_user SET last_donation_date = NOW() WHERE user_id = ?");
                }
                $updUser->bind_param("i", $found_user_id);
                $updUser->execute();
            }

            $conn->commit();
            sendJson('success', 'Donation processed successfully. Records updated.');

        } catch (Exception $e) {
            $conn->rollback();
            sendJson('error', 'Database transaction failed: ' . $e->getMessage());
        }
    } else {
        sendJson('error', 'User not found. Please check the ID or Email.');
    }
}

elseif ($action === 'create_drive') {
    global $hospital_id;
    $loc = $_POST['location']; 
    $date = $_POST['date']; 
    $start = $_POST['start_time']; 
    $end = $_POST['end_time'];
    
    $stmt = $conn->prepare("INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status) VALUES (?, ?, ?, ?, ?, 'Upcoming')");
    $stmt->bind_param("issss", $hospital_id, $date, $start, $end, $loc);
    
    if ($stmt->execute()) {
        sendJson('success', 'Drive Created');
    } else {
        sendJson('error', 'Failed to create drive: ' . $conn->error);
    }
}

// =============================================================
// 6. BLOOD REPORTS (ADMIN)
// =============================================================

elseif ($action === 'get_all_blood_reports') {
    // Filter blood reports by hospital - only show reports from appointments at this hospital
    // Exclude reports without hospital location
    $stmt = $conn->prepare("SELECT br.report_id, br.report_date, br.hemoglobin, br.hematocrit, br.platelet_count,
                                   br.white_blood_cell_count, br.red_blood_cell_count, br.blood_pressure,
                                   br.temperature, br.volume_ml, br.notes, br.user_id, br.appt_id,
                                   u.full_name, u.blood_type, u.email,
                                   bd.location_name as location_name,
                                   h.hospital_name as hospital_name,
                                   COALESCE(br.volume_ml, a.volume_ml, NULL) as volume_ml
                            FROM blood_report br
                            JOIN donor_user u ON br.user_id = u.user_id
                            LEFT JOIN appointment a ON br.appt_id = a.appt_id
                            LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
                            LEFT JOIN hospital h ON bd.hospital_id = h.hospital_id
                            WHERE bd.hospital_id = ? AND h.hospital_id IS NOT NULL
                            ORDER BY br.report_date DESC, br.created_at DESC");
    
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = [];
    while($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
    sendJson('success', 'Blood reports loaded', $reports);
}

elseif ($action === 'create_blood_report') {
    $user_id = intval($_POST['user_id']);
    $appt_id = !empty($_POST['appt_id']) ? intval($_POST['appt_id']) : null;
    $report_date = $_POST['report_date'] ?? date('Y-m-d');
    $hemoglobin = !empty($_POST['hemoglobin']) ? floatval($_POST['hemoglobin']) : null;
    $hematocrit = !empty($_POST['hematocrit']) ? floatval($_POST['hematocrit']) : null;
    $platelet_count = !empty($_POST['platelet_count']) ? intval($_POST['platelet_count']) : null;
    $wbc_count = !empty($_POST['white_blood_cell_count']) ? floatval($_POST['white_blood_cell_count']) : null;
    $rbc_count = !empty($_POST['red_blood_cell_count']) ? floatval($_POST['red_blood_cell_count']) : null;
    $blood_pressure = !empty($_POST['blood_pressure']) ? $_POST['blood_pressure'] : null;
    $temperature = !empty($_POST['temperature']) ? floatval($_POST['temperature']) : null;
    $volume_ml = !empty($_POST['volume_ml']) ? intval($_POST['volume_ml']) : null;
    $notes = !empty($_POST['notes']) ? $_POST['notes'] : null;
    
    // If volume not provided but appt_id exists, try to get it from appointment
    if (!$volume_ml && $appt_id) {
        $volStmt = $conn->prepare("SELECT volume_ml FROM appointment WHERE appt_id = ?");
        $volStmt->bind_param("i", $appt_id);
        $volStmt->execute();
        $volResult = $volStmt->get_result();
        if ($volRow = $volResult->fetch_assoc()) {
            $volume_ml = $volRow['volume_ml'];
        }
    }
    
    // If appt_id is provided, verify it belongs to this hospital
    if ($appt_id) {
        $checkStmt = $conn->prepare("SELECT a.appt_id FROM appointment a JOIN blood_drive bd ON a.drive_id = bd.drive_id WHERE a.appt_id = ? AND bd.hospital_id = ?");
        $checkStmt->bind_param("ii", $appt_id, $hospital_id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows == 0) {
            sendJson('error', 'Appointment does not belong to this hospital');
            exit;
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO blood_report (user_id, appt_id, report_date, hemoglobin, hematocrit, platelet_count, white_blood_cell_count, red_blood_cell_count, blood_pressure, temperature, volume_ml, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // For nullable values in MySQLi, we pass NULL directly and use appropriate type
    $stmt->bind_param("iisddiddddis", $user_id, $appt_id, $report_date, $hemoglobin, $hematocrit, $platelet_count, $wbc_count, $rbc_count, $blood_pressure, $temperature, $volume_ml, $notes);
    
    if ($stmt->execute()) {
        sendJson('success', 'Blood report created successfully');
    } else {
        sendJson('error', 'Failed to create report: ' . $conn->error);
    }
}

elseif ($action === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT hospital_id, hospital_name, admin_name, password_hash FROM hospital WHERE admin_email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password_hash'])) {
            $_SESSION['hospital_id'] = $row['hospital_id'];
            $_SESSION['hospital_name'] = $row['hospital_name'];
            $_SESSION['admin_name'] = $row['admin_name'];
            $_SESSION['role'] = 'hospital';
            sendJson('success', 'Welcome, Commander ' . $row['admin_name']);
        } else {
            sendJson('error', 'Access Denied: Invalid Password');
        }
    } else {
        sendJson('error', 'Access Denied: Email not registered');
    }
}
elseif ($action === 'logout') { 
    session_destroy(); 
    sendJson('success', 'Logged out'); 
}
// =============================================================
// PASSWORD RESET
// =============================================================

elseif ($action === 'forgot_password') {
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        sendJson('error', 'Email is required');
    }
    
    // Check if admin exists
    $stmt = $conn->prepare("SELECT hospital_id, admin_name FROM hospital WHERE admin_email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token in database (add reset_token and reset_token_expiry columns if they don't exist)
        $checkToken = $conn->query("SHOW COLUMNS FROM hospital LIKE 'reset_token'");
        if ($checkToken->num_rows == 0) {
            $conn->query("ALTER TABLE hospital ADD COLUMN reset_token VARCHAR(64) NULL, ADD COLUMN reset_token_expiry DATETIME NULL");
        }
        
        $updateStmt = $conn->prepare("UPDATE hospital SET reset_token = ?, reset_token_expiry = ? WHERE hospital_id = ?");
        $updateStmt->bind_param("ssi", $token, $expiry, $row['hospital_id']);
        $updateStmt->execute();
        
        // Send email with reset link
        $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/admin_reset_password.html?token=" . $token;
        
        // Send email
        $emailStatus = sendEmail($email, $row['admin_name'], 'Password Reset Request - BloodKnight Command Center', 
            "<h2>Password Reset Request</h2>
            <p>Hello {$row['admin_name']},</p>
            <p>You requested to reset your password for the BloodKnight Command Center. Click the link below to reset it:</p>
            <p><a href='{$resetLink}' style='background: #dc2626; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Reset Password</a></p>
            <p>This link will expire in 1 hour.</p>
            <p>If you didn't request this, please ignore this email.</p>");
        
        sendJson('success', 'Password reset link has been sent to your email address.');
    } else {
        // Don't reveal if email exists or not (security best practice)
        sendJson('success', 'If that email exists, a password reset link has been sent.');
    }
}

elseif ($action === 'reset_password') {
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($token) || empty($password)) {
        sendJson('error', 'Token and password are required');
    }
    
    if (strlen($password) < 6) {
        sendJson('error', 'Password must be at least 6 characters long');
    }
    
    // Verify token
    $stmt = $conn->prepare("SELECT hospital_id FROM hospital WHERE reset_token = ? AND reset_token_expiry > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Update password and clear token
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare("UPDATE hospital SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL WHERE hospital_id = ?");
        $updateStmt->bind_param("si", $passwordHash, $row['hospital_id']);
        
        if ($updateStmt->execute()) {
            sendJson('success', 'Password has been reset successfully. You can now login with your new password.');
        } else {
            sendJson('error', 'Failed to reset password. Please try again.');
        }
    } else {
        sendJson('error', 'Invalid or expired reset token. Please request a new one.');
    }
}

else { sendJson('error', 'Invalid Action'); }
?>
