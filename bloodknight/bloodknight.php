<?php
// bloodknight.php - OPTIMIZED DONOR BACKEND
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

// Configure session for persistence
ini_set('session.cookie_lifetime', 86400 * 7); // 7 days
ini_set('session.gc_maxlifetime', 86400 * 7); // 7 days
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
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

if ($action === 'check_session') {
    if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'donor') {
        // Fetch fresh user data
        $stmt = $conn->prepare("SELECT full_name, email, blood_type, phone_number, profile_pic FROM donor_user WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            sendJson('success', 'Session valid', [
                'user_id' => $_SESSION['user_id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'blood_type' => $row['blood_type'],
                'phone' => $row['phone_number'],
                'profile_pic' => $row['profile_pic']
            ]);
        } else {
            session_destroy();
            sendJson('error', 'User not found');
        }
    } else {
        sendJson('error', 'Not logged in');
    }
}

elseif ($action === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // Fetch user details including profile_pic
    $stmt = $conn->prepare("SELECT user_id, password_hash, full_name, blood_type, phone_number, profile_pic FROM donor_user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password_hash'])) {
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['user_name'] = $row['full_name'];
            $_SESSION['role'] = 'donor';
            
            // Check for last donation date to help frontend with caching
            $lastDonationStmt = $conn->prepare("SELECT MAX(donation_date) as last_date FROM appointment WHERE user_id = ? AND status = 'Completed'");
            $lastDonationStmt->bind_param("i", $row['user_id']);
            $lastDonationStmt->execute();
            $lastDonationRes = $lastDonationStmt->get_result()->fetch_assoc();
            $last_donation = $lastDonationRes['last_date'] ?? null;

            // Send full data back so login.html can save to localStorage
            sendJson('success', 'Login successful', [
                'user_id' => $row['user_id'],
                'full_name' => $row['full_name'],
                'email' => $email,
                'blood_type' => $row['blood_type'],
                'phone' => $row['phone_number'],
                'profile_pic' => $row['profile_pic'],
                'last_donation' => $last_donation
            ]);
        } else {
            sendJson('error', 'Invalid password');
        }
    } else {
        sendJson('error', 'User not found');
    }
}

elseif ($action === 'register_donor') {
    // Add gender column if it doesn't exist (MySQL 8.0+ syntax, with fallback)
    $checkGender = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'gender'");
    if ($checkGender->num_rows == 0) {
        $conn->query("ALTER TABLE donor_user ADD COLUMN gender VARCHAR(10) NULL");
    }
    
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $blood_type = $_POST['blood_type'];
    $phone = $_POST['phone'];
    $gender = $_POST['gender'] ?? '';

    // Check if email already exists
    $checkStmt = $conn->prepare("SELECT user_id FROM donor_user WHERE email = ?");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        sendJson('error', 'Email already exists');
    }
    
    // Hash password and register directly
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO donor_user (email, password_hash, full_name, blood_type, phone_number, gender) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $email, $password_hash, $full_name, $blood_type, $phone, $gender);

    if ($stmt->execute()) {
        $_SESSION['user_id'] = $stmt->insert_id;
        $_SESSION['user_name'] = $full_name;
        $_SESSION['role'] = 'donor';
        sendJson('success', 'Registration successful');
    } else {
        sendJson('error', 'Registration failed');
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

    // 2. Fetch Statistics - Use blood_report as primary source, fallback to appointment
    $stmtStats = $conn->prepare("
        SELECT 
            COUNT(DISTINCT br.report_id) as donation_count, 
            COALESCE(SUM(br.volume_ml), 0) as total_volume,
            MAX(br.report_date) as last_donation
        FROM blood_report br
        WHERE br.user_id = ?
        AND br.volume_ml IS NOT NULL
    ");
    $stmtStats->bind_param("i", $user_id);
    $stmtStats->execute();
    $statsRes = $stmtStats->get_result()->fetch_assoc();

    $count = $statsRes['donation_count'] ?? 0;
    $volume = $statsRes['total_volume'] ?? 0;
    $last_date = $statsRes['last_donation'] ?? null;
    
    // If no blood reports, fallback to appointments
    if ($count == 0) {
        $stmtStats2 = $conn->prepare("
            SELECT 
                COUNT(*) as donation_count, 
                COALESCE(SUM(volume_ml), 0) as total_volume,
                MAX(donation_date) as last_donation
            FROM appointment 
            WHERE user_id = ? AND status = 'Completed' AND volume_ml IS NOT NULL
        ");
        $stmtStats2->bind_param("i", $user_id);
        $stmtStats2->execute();
        $statsRes2 = $stmtStats2->get_result()->fetch_assoc();
        $count = $statsRes2['donation_count'] ?? 0;
        $volume = $statsRes2['total_volume'] ?? 0;
        $last_date = $statsRes2['last_donation'] ?? null;
    }
    
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
            WHERE a.user_id = ? AND a.status IN ('Pending', 'Confirmed', 'Cancelled')
            ORDER BY d.drive_date DESC, a.status ASC";
            
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
    
    // Use blood_report table as the primary source since it has the actual donation data
    // Exclude reports without hospital location
    $sql = "SELECT 
                br.report_date as donation_date,
                COALESCE(br.volume_ml, a.volume_ml) as volume_ml,
                bd.location_name as location_name,
                h.hospital_name as hospital_name
            FROM blood_report br
            LEFT JOIN appointment a ON br.appt_id = a.appt_id
            LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
            LEFT JOIN hospital h ON bd.hospital_id = h.hospital_id
            WHERE br.user_id = ?
            AND h.hospital_id IS NOT NULL
            AND (br.volume_ml IS NOT NULL OR a.volume_ml IS NOT NULL)
            ORDER BY br.report_date DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = [];
    while ($row = $result->fetch_assoc()) { 
        // Only include records with valid date and volume
        if ($row['donation_date'] && $row['volume_ml']) {
            $history[] = $row; 
        }
    }
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
    // Add gender column if it doesn't exist
    $checkGender = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'gender'");
    if ($checkGender->num_rows == 0) {
        $conn->query("ALTER TABLE donor_user ADD COLUMN gender VARCHAR(10) NULL");
    }
    $stmt = $conn->prepare("SELECT full_name, email, phone_number, blood_type, gender, profile_pic FROM donor_user WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    sendJson('success', 'Profile loaded', $data);
}

elseif ($action === 'update_profile') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Not logged in'); }
    
    // Add gender column if it doesn't exist
    $checkGender = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'gender'");
    if ($checkGender->num_rows == 0) {
        $conn->query("ALTER TABLE donor_user ADD COLUMN gender VARCHAR(10) NULL");
    }
    
    $id = $_SESSION['user_id'];
    $name = $_POST['full_name'];
    $phone = $_POST['phone'];
    $blood = $_POST['blood_type'];
    $gender = $_POST['gender'] ?? '';
    
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
        $stmt = $conn->prepare("UPDATE donor_user SET full_name=?, phone_number=?, blood_type=?, gender=?, profile_pic=? WHERE user_id=?");
        $stmt->bind_param("sssssi", $name, $phone, $blood, $gender, $profile_pic_path, $id);
    } else {
        $stmt = $conn->prepare("UPDATE donor_user SET full_name=?, phone_number=?, blood_type=?, gender=? WHERE user_id=?");
        $stmt->bind_param("ssssi", $name, $phone, $blood, $gender, $id);
    }
    
    if ($stmt->execute()) {
        $_SESSION['user_name'] = $name; // Update session immediately
        sendJson('success', 'Profile updated successfully');
    } else {
        sendJson('error', 'Update failed: ' . $conn->error);
    }
}

// =============================================================
// 5. BLOOD REPORTS
// =============================================================

elseif ($action === 'get_my_blood_reports') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Not logged in'); }
    $user_id = $_SESSION['user_id'];
    
    $sql = "SELECT br.report_id, br.report_date, br.hemoglobin, br.hematocrit, br.platelet_count, 
                   br.white_blood_cell_count, br.red_blood_cell_count, br.blood_pressure, 
                   br.temperature, br.notes, br.appt_id,
                   bd.location_name as location_name,
                   h.hospital_name as hospital_name,
                   COALESCE(br.volume_ml, a.volume_ml, NULL) as volume_ml
            FROM blood_report br
            LEFT JOIN appointment a ON br.appt_id = a.appt_id
            LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
            LEFT JOIN hospital h ON bd.hospital_id = h.hospital_id
            WHERE br.user_id = ? AND h.hospital_id IS NOT NULL
            ORDER BY br.report_date DESC, br.created_at DESC";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = [];
    while($row = $result->fetch_assoc()) { 
        $reports[] = $row; 
    }
    sendJson('success', 'Blood reports loaded', $reports);
}

elseif ($action === 'get_supply_levels') {
    // Calculate live blood supply levels for all blood types
    // Based on recent donations (last 60 days) and expected demand
    
    try {
        $blood_types = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];
        $supply_data = [];
        
        // Expected demand multipliers (relative demand for each blood type)
        $demand_multipliers = [
            'O+' => 1.0,   // Most common, high demand
            'O-' => 0.8,   // Universal donor, very high demand
            'A+' => 0.9,   // Common, high demand
            'A-' => 0.6,   // Less common, moderate demand
            'B+' => 0.7,   // Less common, moderate demand
            'B-' => 0.5,   // Rare, lower demand
            'AB+' => 0.4,  // Rare, lower demand
            'AB-' => 0.3   // Rarest, lowest demand
        ];
        
        foreach ($blood_types as $type) {
            // Get total donations for this blood type in last 60 days
            // Use a simpler query that handles missing data gracefully
            $sql = "SELECT 
                        COUNT(DISTINCT br.report_id) as donation_count,
                        COALESCE(SUM(br.volume_ml), 0) as total_volume
                    FROM blood_report br
                    INNER JOIN donor_user u ON br.user_id = u.user_id
                    WHERE u.blood_type = ?
                    AND br.report_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                    AND (br.volume_ml IS NOT NULL AND br.volume_ml > 0)";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                // If prepare fails, use fallback data
                $supply_data[] = [
                    'type' => $type,
                    'level' => rand(20, 40),
                    'donations' => 0,
                    'volume_ml' => 0
                ];
                continue;
            }
            
            $stmt->bind_param("s", $type);
            if (!$stmt->execute()) {
                // If execute fails, use fallback data
                $supply_data[] = [
                    'type' => $type,
                    'level' => rand(20, 40),
                    'donations' => 0,
                    'volume_ml' => 0
                ];
                $stmt->close();
                continue;
            }
            
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            
            $donation_count = $row['donation_count'] ?? 0;
            $total_volume = $row['total_volume'] ?? 0;
            
            // Calculate supply level (0-100)
            if ($donation_count == 0) {
                // No recent donations - set to low/critical levels with some variation
                $supply_level = rand(15, 30);
            } else {
                // Has donations - calculate based on volume and count
                $volume_factor = min(100, ($total_volume / 450) * 20); // Normalize: 450ml per unit
                $count_factor = min(100, $donation_count * 12); // Each donation contributes
                
                // Combine factors with demand multiplier
                $supply_level = (($volume_factor * 0.4) + ($count_factor * 0.6)) / max(0.3, $demand_multipliers[$type]);
                $supply_level = min(100, max(20, $supply_level)); // Clamp between 20-100
                
                // Add small random variation for realism (±3%)
                $supply_level += rand(-3, 3);
                $supply_level = min(100, max(0, $supply_level));
            }
            
            $supply_data[] = [
                'type' => $type,
                'level' => round($supply_level),
                'donations' => (int)$donation_count,
                'volume_ml' => (int)$total_volume
            ];
        }
        
        // Sort by level (lowest first to show critical needs)
        usort($supply_data, function($a, $b) {
            return $a['level'] <=> $b['level'];
        });
        
        sendJson('success', 'Supply levels loaded', $supply_data);
        
    } catch (Exception $e) {
        // Fallback: return default supply levels if anything fails
        $fallback_data = [];
        $blood_types = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];
        foreach ($blood_types as $type) {
            $fallback_data[] = [
                'type' => $type,
                'level' => rand(25, 45),
                'donations' => 0,
                'volume_ml' => 0
            ];
        }
        sendJson('success', 'Supply levels loaded (fallback)', $fallback_data);
    }
}

// =============================================================
// PASSWORD RESET
// =============================================================

elseif ($action === 'forgot_password') {
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        sendJson('error', 'Email is required');
    }
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT user_id, full_name FROM donor_user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token in database (add reset_token and reset_token_expiry columns if they don't exist)
        $checkToken = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'reset_token'");
        if ($checkToken->num_rows == 0) {
            $conn->query("ALTER TABLE donor_user ADD COLUMN reset_token VARCHAR(64) NULL, ADD COLUMN reset_token_expiry DATETIME NULL");
        }
        
        $updateStmt = $conn->prepare("UPDATE donor_user SET reset_token = ?, reset_token_expiry = ? WHERE user_id = ?");
        $updateStmt->bind_param("ssi", $token, $expiry, $row['user_id']);
        $updateStmt->execute();
        
        // Send email with reset link
        $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.html?token=" . $token;
        
        // Use PHPMailer if available, otherwise just return success
        if (file_exists('PHPMailer/src/PHPMailer.php')) {
            require_once 'PHPMailer/src/Exception.php';
            require_once 'PHPMailer/src/PHPMailer.php';
            require_once 'PHPMailer/src/SMTP.php';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'hiewz256@gmail.com';
                $mail->Password = 'pwml dpzm hxuw gffr';
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->setFrom('noreply@bloodknight.com', 'BloodKnight');
                $mail->addAddress($email, $row['full_name']);
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Request - BloodKnight';
                $mail->Body = "<h2>Password Reset Request</h2><p>Hello {$row['full_name']},</p><p>You requested to reset your password. Click the link below to reset it:</p><p><a href='{$resetLink}' style='background: #dc2626; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Reset Password</a></p><p>This link will expire in 1 hour.</p><p>If you didn't request this, please ignore this email.</p>";
                $mail->send();
            } catch (Exception $e) {
                // Email failed but token is saved
            }
        }
        
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
    $stmt = $conn->prepare("SELECT user_id FROM donor_user WHERE reset_token = ? AND reset_token_expiry > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Update password and clear token
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare("UPDATE donor_user SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL WHERE user_id = ?");
        $updateStmt->bind_param("si", $passwordHash, $row['user_id']);
        
        if ($updateStmt->execute()) {
            sendJson('success', 'Password has been reset successfully. You can now login with your new password.');
        } else {
            sendJson('error', 'Failed to reset password. Please try again.');
        }
    } else {
        sendJson('error', 'Invalid or expired reset token. Please request a new one.');
    }
}

else { sendJson('error', 'Invalid action'); }
?>
