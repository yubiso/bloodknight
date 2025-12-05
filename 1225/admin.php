<?php
// admin.php - OPTIMIZED HOSPITAL COMMAND CENTER CONTROLLER
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
session_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'db_connect.php';

$action = $_REQUEST['action'] ?? '';

function sendJson($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

// =============================================================
// 1. HOSPITAL AUTHENTICATION
// =============================================================

if ($action === 'login') {
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

elseif ($action === 'register_hospital') {
    $hospital_name = $_POST['hospital_name'] ?? '';
    $hospital_address = $_POST['hospital_address'] ?? '';
    $hospital_type = $_POST['hospital_type'] ?? '';
    $admin_name = $_POST['admin_name'] ?? '';
    $admin_email = $_POST['admin_email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $check = $conn->prepare("SELECT hospital_id FROM hospital WHERE admin_email = ?");
    $check->bind_param("s", $admin_email);
    $check->execute();
    if($check->get_result()->num_rows > 0) { sendJson('error', 'Hospital email already exists'); }

    $pass_hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO hospital (hospital_name, hospital_address, hospital_type, admin_name, admin_email, password_hash) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $hospital_name, $hospital_address, $hospital_type, $admin_name, $admin_email, $pass_hash);
    
    if ($stmt->execute()) {
        $_SESSION['hospital_id'] = $stmt->insert_id;
        $_SESSION['role'] = 'hospital';
        sendJson('success', 'Facility Registered. Welcome to the network.');
    } else {
        sendJson('error', 'Registration Failed: ' . $conn->error);
    }
}

elseif ($action === 'logout') {
    session_destroy();
    sendJson('success', 'Logged out successfully');
}

// --- MIDDLEWARE ---
if (!isset($_SESSION['hospital_id']) || $_SESSION['role'] !== 'hospital') {
    sendJson('error', 'Unauthorized Access: Please Login');
}
$hospital_id = $_SESSION['hospital_id'];

// =============================================================
// 2. DASHBOARD FUNCTIONS
// =============================================================

if ($action === 'get_stats') {
    $donors = $conn->query("SELECT COUNT(*) as c FROM donor_user")->fetch_assoc()['c'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM appointment a JOIN blood_drive d ON a.drive_id = d.drive_id WHERE d.hospital_id = ? AND a.status = 'Pending'");
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $pending = $stmt->get_result()->fetch_assoc()['c'];

    $stmtVol = $conn->prepare("SELECT SUM(volume_ml) as v FROM appointment a JOIN blood_drive d ON a.drive_id = d.drive_id WHERE d.hospital_id = ? AND a.status = 'Completed'");
    $stmtVol->bind_param("i", $hospital_id);
    $stmtVol->execute();
    $volume = $stmtVol->get_result()->fetch_assoc()['v'] ?? 0;
    
    sendJson('success', 'Stats loaded', ['donors' => $donors, 'pending_appt' => $pending, 'volume_l' => number_format($volume / 1000, 1)]);
}

elseif ($action === 'create_drive') {
    $location = $_POST['location']; $date = $_POST['date']; $start = $_POST['start_time']; $end = $_POST['end_time'];
    $stmt = $conn->prepare("INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status) VALUES (?, ?, ?, ?, ?, 'Upcoming')");
    $stmt->bind_param("issss", $hospital_id, $date, $start, $end, $location);
    if ($stmt->execute()) sendJson('success', 'Mission Created Successfully');
    else sendJson('error', 'Failed to create mission');
}

// --- GET PENDING APPOINTMENTS ---
elseif ($action === 'get_appointments') {
    $stmt = $conn->prepare("SELECT a.appt_id, u.full_name, u.blood_type, d.location_name, a.selected_time FROM appointment a JOIN donor_user u ON a.user_id = u.user_id JOIN blood_drive d ON a.drive_id = d.drive_id WHERE d.hospital_id = ? AND a.status = 'Pending' ORDER BY d.drive_date ASC, a.selected_time ASC");
    $stmt->bind_param("i", $hospital_id); $stmt->execute();
    $result = $stmt->get_result(); $data = []; while ($row = $result->fetch_assoc()) { $data[] = $row; }
    sendJson('success', 'Data loaded', $data);
}

// --- NEW FEATURE: GET APPROVED APPOINTMENTS ---
elseif ($action === 'get_approved_appointments') {
    $stmt = $conn->prepare("
        SELECT a.appt_id, u.full_name, u.email, u.blood_type, d.location_name, d.drive_date, a.selected_time 
        FROM appointment a 
        JOIN donor_user u ON a.user_id = u.user_id 
        JOIN blood_drive d ON a.drive_id = d.drive_id 
        WHERE d.hospital_id = ? AND a.status = 'Confirmed' 
        ORDER BY d.drive_date DESC, a.selected_time ASC
    ");
    $stmt->bind_param("i", $hospital_id); 
    $stmt->execute();
    $result = $stmt->get_result(); 
    $data = []; 
    while ($row = $result->fetch_assoc()) { $data[] = $row; }
    sendJson('success', 'Roster loaded', $data);
}

elseif ($action === 'confirm_appt') {
    $id = $_POST['appt_id'];
    $stmt = $conn->prepare("UPDATE appointment a JOIN blood_drive d ON a.drive_id = d.drive_id SET a.status='Confirmed' WHERE a.appt_id = ? AND d.hospital_id = ?");
    $stmt->bind_param("ii", $id, $hospital_id);
    if($stmt->execute()) sendJson('success', 'Appointment Approved');
    else sendJson('error', 'Update failed');
}

elseif ($action === 'process_donation') {
    $email = $_POST['donor_email']; $volume = $_POST['volume']; $notes = $_POST['notes'];
    $uRes = $conn->query("SELECT user_id FROM donor_user WHERE email = '$email'");
    if ($row = $uRes->fetch_assoc()) {
        $user_id = $row['user_id']; $date = date('Y-m-d');
        $stmt = $conn->prepare("UPDATE appointment a JOIN blood_drive d ON a.drive_id = d.drive_id SET a.status = 'Completed', a.volume_ml = ?, a.donation_date = ?, a.notes = ? WHERE a.user_id = ? AND d.hospital_id = ? AND a.status IN ('Confirmed', 'Pending') LIMIT 1");
        $stmt->bind_param("issii", $volume, $date, $notes, $user_id, $hospital_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
             $conn->query("UPDATE donor_user SET total_points = total_points + 10 WHERE user_id = $user_id");
             sendJson('success', 'Donation processed. Unit secured.');
        } else { sendJson('error', 'No active appointment found.'); }
    } else { sendJson('error', 'Donor email not found.'); }
}

elseif ($action === 'send_alert') {
    $type = $_POST['blood_type']; $urgency = $_POST['urgency']; $msg = $_POST['message'];
    $stmt = $conn->prepare("INSERT INTO notification (hospital_id, target_blood_type, message_content, urgency_level) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $hospital_id, $type, $msg, $urgency);
    if ($stmt->execute()) sendJson('success', 'Alert Broadcasted');
    else sendJson('error', 'Broadcast failed');
}

else { sendJson('error', 'Invalid Action'); }
?>