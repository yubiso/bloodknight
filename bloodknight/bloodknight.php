<?php
// bloodknight.php - OPTIMIZED DONOR BACKEND
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
session_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Ensure db_connect.php exists or fallback to inline connection
if (file_exists('db_connect.php')) {
    require_once 'db_connect.php';
} else {
    // Fallback connection if file missing (Update credentials as needed)
    $host = "localhost";
    $user = "root";
    $pass = "";
    $db   = "bloodknight_db";
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        echo json_encode(['status' => 'error', 'message' => 'DB Connection Failed']);
        exit;
    }
}

$action = $_REQUEST['action'] ?? '';

function sendJson($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

// =============================================================
// 1. AUTHENTICATION
// =============================================================

if ($action === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT user_id, password_hash, full_name FROM donor_user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password_hash'])) {
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['user_name'] = $row['full_name'];
            $_SESSION['role'] = 'donor';
            sendJson('success', 'Login successful');
        } else {
            sendJson('error', 'Invalid password');
        }
    } else {
        sendJson('error', 'User not found');
    }
}

elseif ($action === 'register_donor') {
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $blood_type = $_POST['blood_type'];
    $phone = $_POST['phone'];

    $stmt = $conn->prepare("INSERT INTO donor_user (email, password_hash, full_name, blood_type, phone_number) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $email, $password, $full_name, $blood_type, $phone);

    if ($stmt->execute()) {
        $_SESSION['user_id'] = $stmt->insert_id;
        $_SESSION['user_name'] = $full_name;
        $_SESSION['role'] = 'donor';
        sendJson('success', 'Registration successful');
    } else {
        sendJson('error', 'Email already exists');
    }
}

elseif ($action === 'logout') {
    session_destroy();
    sendJson('success', 'Logged out');
}

// =============================================================
// 2. DASHBOARD DATA
// =============================================================

elseif ($action === 'get_dashboard_data') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Not logged in'); }
    
    $user_id = $_SESSION['user_id'];

    // 1. Fetch Basic User Info
    $stmtUser = $conn->prepare("SELECT full_name, blood_type, profile_pic FROM donor_user WHERE user_id = ?");
    $stmtUser->bind_param("i", $user_id);
    $stmtUser->execute();
    $userRes = $stmtUser->get_result()->fetch_assoc();

    if (!$userRes) { sendJson('error', 'User data not found'); }

    // FORCE REFRESH SESSION NAME (Fixes stale name issue)
    $_SESSION['user_name'] = $userRes['full_name'];

    // 2. Fetch Statistics
    $stmtStats = $conn->prepare("
        SELECT 
            COUNT(*) as donation_count, 
            COALESCE(SUM(volume_ml), 0) as total_volume,
            MAX(donation_date) as last_donation
        FROM appointment 
        WHERE user_id = ? AND status = 'Completed'
    ");
    $stmtStats->bind_param("i", $user_id);
    $stmtStats->execute();
    $statsRes = $stmtStats->get_result()->fetch_assoc();

    $count = $statsRes['donation_count'] ?? 0;
    $volume = $statsRes['total_volume'] ?? 0;
    $last_date = $statsRes['last_donation'] ?? null;
    
    // Calculate Rank
    $rank = 'Recruit';
    if ($count >= 5) $rank = 'Soldier';
    if ($count >= 10) $rank = 'Guardian';
    if ($count >= 20) $rank = 'Knight';
    if ($count >= 50) $rank = 'Legend';

    $data = [
        'name' => $userRes['full_name'], // Always sends the fresh DB name
        'blood_type' => $userRes['blood_type'],
        'profile_pic' => $userRes['profile_pic'], 
        'rank' => $rank,
        'donations' => $count,
        'lives_saved' => $count * 3,
        'volume_l' => number_format($volume / 1000, 1),
        'next_milestone' => 5 - ($count % 5),
        'last_donation' => $last_date
    ];

    sendJson('success', 'Dashboard loaded', $data);
}

// =============================================================
// 3. APPOINTMENTS & MISSIONS
// =============================================================

elseif ($action === 'get_my_appointments') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Not logged in'); }
    $user_id = $_SESSION['user_id'];
    
    $sql = "SELECT a.appt_id, a.selected_time, a.status, d.drive_date, d.location_name, h.hospital_name 
            FROM appointment a 
            JOIN blood_drive d ON a.drive_id = d.drive_id 
            JOIN hospital h ON d.hospital_id = h.hospital_id 
            WHERE a.user_id = ? AND a.status IN ('Pending', 'Confirmed')
            ORDER BY d.drive_date ASC";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while($row = $result->fetch_assoc()) { $data[] = $row; }
    sendJson('success', 'Appointments loaded', $data);
}

elseif ($action === 'get_alerts') {
    $sql = "SELECT message_content as message, urgency_level as urgency FROM notification ORDER BY sent_at DESC LIMIT 1";
    $result = $conn->query($sql);
    $alerts = [];
    if ($row = $result->fetch_assoc()) { $alerts[] = $row; }
    sendJson('success', 'Alerts loaded', ['alerts' => $alerts]);
}

elseif ($action === 'get_drives') {
    $query = $_GET['query'] ?? '';
    $sql = "SELECT d.drive_id, d.drive_date, d.start_time, d.end_time, d.location_name, h.hospital_name as organization_name 
            FROM blood_drive d 
            JOIN hospital h ON d.hospital_id = h.hospital_id 
            WHERE d.drive_date >= CURDATE() AND d.status = 'Upcoming'";

    if ($query) { $sql .= " AND (d.location_name LIKE ? OR h.hospital_name LIKE ?)"; }
    $sql .= " ORDER BY d.drive_date ASC";

    $stmt = $conn->prepare($sql);
    if ($query) { $search = "%$query%"; $stmt->bind_param("ss", $search, $search); }
    $stmt->execute();
    $result = $stmt->get_result();
    $drives = [];
    while ($row = $result->fetch_assoc()) { $drives[] = $row; }
    sendJson('success', 'Drives loaded', $drives);
}

elseif ($action === 'get_history') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Not logged in'); }
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT a.donation_date, a.volume_ml, COALESCE(bd.location_name, 'External/Walk-in') as location_name
            FROM appointment a
            LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
            WHERE a.user_id = ? AND a.status = 'Completed'
            ORDER BY a.donation_date DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = [];
    while ($row = $result->fetch_assoc()) { $history[] = $row; }
    sendJson('success', 'History loaded', $history);
}

elseif ($action === 'get_slots') {
    $drive_id = $_GET['drive_id'] ?? 0;
    $stmt = $conn->prepare("SELECT start_time, end_time FROM blood_drive WHERE drive_id = ?");
    $stmt->bind_param("i", $drive_id);
    $stmt->execute();
    $drive = $stmt->get_result()->fetch_assoc();
    if (!$drive) { sendJson('error', 'Drive not found'); }
    
    $stmt2 = $conn->prepare("SELECT selected_time FROM appointment WHERE drive_id = ? AND status != 'Cancelled'");
    $stmt2->bind_param("i", $drive_id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    $booked_times = [];
    while ($row = $result2->fetch_assoc()) { $booked_times[] = $row['selected_time']; }

    $slots = [];
    $start = strtotime($drive['start_time']);
    $end = strtotime($drive['end_time']);
    $interval = 20 * 60; 

    while ($start < $end) {
        $timeStr = date('H:i:s', $start); 
        $displayTime = date('h:i A', $start); 
        $slots[] = ['raw_time' => $timeStr, 'display_time' => $displayTime, 'is_taken' => in_array($timeStr, $booked_times)];
        $start += $interval;
    }
    sendJson('success', 'Slots loaded', $slots);
}

elseif ($action === 'book_appointment') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Please login first'); }
    $user_id = $_SESSION['user_id'];
    $drive_id = $_POST['drive_id'];
    $time = $_POST['time'];
    
    // --- CRITICAL CHECK: ENSURE USER HAS NO ACTIVE APPOINTMENTS ---
    $check_active = $conn->prepare("SELECT appt_id FROM appointment WHERE user_id = ? AND status IN ('Pending', 'Confirmed')");
    $check_active->bind_param("i", $user_id);
    $check_active->execute();
    if ($check_active->get_result()->num_rows > 0) {
        sendJson('error', 'Booking failed: You already have an active mission scheduled. Please cancel it first.');
    }
    // -----------------------------------------------------------
    
    // 1. Check if the specific slot is taken (time-slot collision)
    $check_slot = $conn->prepare("SELECT appt_id FROM appointment WHERE drive_id = ? AND selected_time = ? AND status != 'Cancelled'");
    $check_slot->bind_param("is", $drive_id, $time);
    $check_slot->execute();
    if ($check_slot->get_result()->num_rows > 0) { 
        sendJson('error', 'Sorry! This specific time slot was just taken.'); 
    }
    
    // 2. Insert the new appointment
    try {
        $stmt = $conn->prepare("INSERT INTO appointment (user_id, drive_id, selected_time, status) VALUES (?, ?, ?, 'Pending')");
        $stmt->bind_param("iis", $user_id, $drive_id, $time);
        if ($stmt->execute()) { 
            // Return the new ID to the frontend to set the local storage flag correctly
            sendJson('success', 'Appointment requested! Wait for admin approval.', ['appt_id' => $conn->insert_id]); 
        } 
        else { 
            sendJson('error', 'Booking failed.'); 
        }
    } catch (Exception $e) { 
        sendJson('error', 'Booking failed due to a database issue.'); 
    }
}

// --- NEW ACTION: CANCEL APPOINTMENT ---
elseif ($action === 'cancel_appointment') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Please login first'); }
    $user_id = $_SESSION['user_id'];
    $appt_id = $_POST['appt_id'];
    
    // Set the status to 'Cancelled' for the specific appointment and user
    $stmt = $conn->prepare("UPDATE appointment SET status='Cancelled' WHERE appt_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $appt_id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        sendJson('success', 'Appointment cancelled successfully!');
    } else {
        sendJson('error', 'Cancellation failed. Appointment not found or already completed/cancelled.');
    }
}


// =============================================================
// 4. PROFILE MANAGEMENT
// =============================================================

elseif ($action === 'get_profile') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Not logged in'); }
    $stmt = $conn->prepare("SELECT full_name, email, phone_number, blood_type, profile_pic FROM donor_user WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    sendJson('success', 'Profile loaded', $data);
}

elseif ($action === 'update_profile') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Not logged in'); }
    
    $id = $_SESSION['user_id'];
    $name = $_POST['full_name'];
    $phone = $_POST['phone'];
    $blood = $_POST['blood_type'];
    
    // --- 1. HANDLE FILE UPLOAD ---
    $profile_pic_path = null;
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $target_dir = "uploads/";
        if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
        $file_ext = strtolower(pathinfo($_FILES["profile_pic"]["name"], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($file_ext, $allowed)) {
            $new_filename = "profile_" . $id . "_" . time() . "." . $file_ext;
            $target_file = $target_dir . $new_filename;
            if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_file)) {
                $profile_pic_path = $target_file;
            }
        }
    }

    // --- 2. UPDATE DATABASE ---
    if ($profile_pic_path) {
        $stmt = $conn->prepare("UPDATE donor_user SET full_name=?, phone_number=?, blood_type=?, profile_pic=? WHERE user_id=?");
        $stmt->bind_param("ssssi", $name, $phone, $blood, $profile_pic_path, $id);
    } else {
        $stmt = $conn->prepare("UPDATE donor_user SET full_name=?, phone_number=?, blood_type=? WHERE user_id=?");
        $stmt->bind_param("sssi", $name, $phone, $blood, $id);
    }
    
    if ($stmt->execute()) {
        $_SESSION['user_name'] = $name; // Update session immediately
        sendJson('success', 'Profile updated successfully');
    } else {
        sendJson('error', 'Update failed: ' . $conn->error);
    }
}

else { sendJson('error', 'Invalid action'); }
?>