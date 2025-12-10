<?php
// admin_controller.php - COMMAND CENTER BACKEND
header('Content-Type: application/json');
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
// 1. ANALYTICS & REPORTS
// =============================================================
if ($action === 'get_analytics') {
    $bloodRes = $conn->query("SELECT blood_type as type, COUNT(*) as count FROM donor_user GROUP BY blood_type");
    $bloodData = [];
    while ($row = $bloodRes->fetch_assoc()) { $bloodData[] = $row; }

    $trendRes = $conn->query("SELECT DATE_FORMAT(donation_date, '%M') as month, SUM(volume_ml) as volume FROM donation_history WHERE donation_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY month ORDER BY donation_date ASC");
    $trendData = [];
    while ($row = $trendRes->fetch_assoc()) { $trendData[] = $row; }

    $perfRes = $conn->query("SELECT d.location_name as location, COUNT(a.appt_id) as count FROM appointment a JOIN blood_drive d ON a.drive_id = d.drive_id WHERE a.status = 'Completed' GROUP BY d.location_name LIMIT 5");
    $perfData = [];
    while ($row = $perfRes->fetch_assoc()) { $perfData[] = $row; }

    sendJson('success', 'Report Generated', ['blood_types' => $bloodData, 'trends' => $trendData, 'performance' => $perfData]);
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
    // Get PENDING appointments
    $sql = "SELECT a.appt_id, u.full_name, u.blood_type, d.location_name, a.selected_time 
            FROM appointment a 
            JOIN donor_user u ON a.user_id = u.user_id 
            JOIN blood_drive d ON a.drive_id = d.drive_id 
            WHERE a.status = 'Pending'";
    $result = $conn->query($sql);
    $data = [];
    while ($row = $result->fetch_assoc()) { $data[] = $row; }
    sendJson('success', 'Loaded', $data);
}

elseif ($action === 'get_active_roster') {
    // === CLEAN CODE: USING YOUR UPDATED VIEW ===
    $sql = "SELECT 
                appt_id, 
                full_name, 
                blood_type, 
                location_name, 
                drive_date, 
                selected_time, 
                COALESCE(source, 'Online') as source 
            FROM view_upcoming_appointments 
            WHERE status = 'Confirmed' 
            ORDER BY drive_date ASC, selected_time ASC";
            
    $result = $conn->query($sql);
    
    if (!$result) {
        sendJson('error', 'View Error: ' . $conn->error);
    }

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
    $id = $_POST['appt_id'];
    
    // 1. Get info to update timer and send email
    $sql = "SELECT u.user_id, u.email, u.full_name, a.selected_time, d.location_name, d.drive_date 
            FROM appointment a 
            JOIN donor_user u ON a.user_id = u.user_id 
            JOIN blood_drive d ON a.drive_id = d.drive_id 
            WHERE a.appt_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
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
    $donors = $conn->query("SELECT COUNT(*) as c FROM donor_user")->fetch_assoc()['c'];
    $pending = $conn->query("SELECT COUNT(*) as c FROM appointment WHERE status='Pending'")->fetch_assoc()['c'];
    $volume = $conn->query("SELECT SUM(volume_ml) as v FROM donation_history")->fetch_assoc()['v'] ?? 0;
    $confirmed_appt = $conn->query("SELECT COUNT(*) as c FROM appointment WHERE status='Confirmed'")->fetch_assoc()['c'];
    $total_donations = $conn->query("SELECT COUNT(*) as c FROM donation_history")->fetch_assoc()['c'];
    
    // Overview Roster
    $roster_sql = "SELECT user_id, full_name, location_name, drive_date, selected_time 
                   FROM view_upcoming_appointments
                   WHERE status = 'Confirmed' 
                   ORDER BY drive_date ASC, selected_time ASC
                   LIMIT 5";
    $confirmed_roster = $conn->query($roster_sql)->fetch_all(MYSQLI_ASSOC);
    
    $history_sql = "SELECT u.user_id, u.full_name, d.donation_date, d.volume_ml 
                    FROM donation_history d
                    JOIN donor_user u ON d.user_id = u.user_id
                    ORDER BY d.donation_date DESC
                    LIMIT 5";
    $donation_history = $conn->query($history_sql)->fetch_all(MYSQLI_ASSOC);

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

// === PROCESS DONATION ===
elseif ($action === 'process_donation') {
    $email = $_POST['donor_email'] ?? '';
    $user_id = $_POST['user_id'] ?? '';
    $vol = $_POST['volume']; 
    $notes = $_POST['notes'];
    
    if ($user_id) {
        $uRes = $conn->query("SELECT user_id FROM donor_user WHERE user_id = $user_id");
    } else {
        $uRes = $conn->query("SELECT user_id FROM donor_user WHERE email = '$email'");
    }

    if ($row = $uRes->fetch_assoc()) {
        $found_user_id = $row['user_id'];
        $stmt = $conn->prepare("INSERT INTO donation_history (user_id, donation_date, volume_ml, notes) VALUES (?, NOW(), ?, ?)");
        $stmt->bind_param("iis", $found_user_id, $vol, $notes);
        $stmt->execute();
        
        // Mark appointment as Completed
        $conn->query("UPDATE appointment SET status = 'Completed' WHERE user_id = $found_user_id AND status IN ('Pending', 'Confirmed')");
        
        sendJson('success', 'Processed');
    } else {
        sendJson('error', 'User not found');
    }
}

elseif ($action === 'create_drive') {
    $loc = $_POST['location']; $date = $_POST['date']; $start = $_POST['start_time']; $end = $_POST['end_time'];
    $stmt = $conn->prepare("INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $hospital_id, $date, $start, $end, $loc);
    $stmt->execute();
    sendJson('success', 'Drive Created');
}

elseif ($action === 'login') { $_SESSION['hospital_id'] = 1; sendJson('success', 'Logged in'); }
elseif ($action === 'logout') { session_destroy(); sendJson('success', 'Logged out'); }
else { sendJson('error', 'Invalid Action'); }
?>