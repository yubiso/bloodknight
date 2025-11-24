<?php
// bloodknight.php - MASTER CONTROLLER
header('Content-Type: application/json');
session_start();

require_once 'db_connect.php';

$action = $_REQUEST['action'] ?? '';

function sendJson($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

// --- 1. AUTHENTICATION ---

if ($action === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT user_id, password_hash, full_name FROM user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password_hash'])) {
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['user_name'] = $row['full_name'];
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

    $stmt = $conn->prepare("INSERT INTO user (email, password_hash, full_name, blood_type, phone_number) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $email, $password, $full_name, $blood_type, $phone);

    if ($stmt->execute()) {
        $_SESSION['user_id'] = $stmt->insert_id;
        $_SESSION['user_name'] = $full_name;
        sendJson('success', 'Registration successful');
    } else {
        sendJson('error', 'Email already exists');
    }
}

elseif ($action === 'logout') {
    session_destroy();
    sendJson('success', 'Logged out');
}

elseif ($action === 'get_user') {
    if (isset($_SESSION['user_id'])) {
        sendJson('success', 'User logged in', ['name' => $_SESSION['user_name']]);
    } else {
        sendJson('error', 'Not logged in');
    }
}

// --- 2. DASHBOARD DATA ---

elseif ($action === 'get_dashboard_data') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Not logged in'); }
    
    $user_id = $_SESSION['user_id'];

    // Get User Details
    $stmtUser = $conn->prepare("SELECT full_name, blood_type FROM user WHERE user_id = ?");
    $stmtUser->bind_param("i", $user_id);
    $stmtUser->execute();
    $userRes = $stmtUser->get_result()->fetch_assoc();

    // Get Donation Stats
    $stmtStats = $conn->prepare("SELECT COUNT(*) as donation_count, SUM(volume_ml) as total_volume FROM donation_history WHERE user_id = ?");
    $stmtStats->bind_param("i", $user_id);
    $stmtStats->execute();
    $statsRes = $stmtStats->get_result()->fetch_assoc();

    $count = $statsRes['donation_count'] ?? 0;
    $volume = $statsRes['total_volume'] ?? 0;
    
    // Calculate Rank
    $rank = 'Recruit';
    if ($count >= 5) $rank = 'Soldier';
    if ($count >= 10) $rank = 'Guardian';
    if ($count >= 20) $rank = 'Knight';
    if ($count >= 50) $rank = 'Legend';

    $data = [
        'name' => $userRes['full_name'],
        'blood_type' => $userRes['blood_type'],
        'rank' => $rank,
        'donations' => $count,
        'lives_saved' => $count * 3,
        'volume_l' => number_format($volume / 1000, 1),
        'next_milestone' => 5 - ($count % 5)
    ];

    sendJson('success', 'Dashboard loaded', $data);
}

elseif ($action === 'get_alerts') {
    $sql = "SELECT message_content as message, urgency_level as urgency FROM notification ORDER BY sent_at DESC LIMIT 1";
    $result = $conn->query($sql);
    $alerts = [];
    if ($row = $result->fetch_assoc()) { $alerts[] = $row; }
    sendJson('success', 'Alerts loaded', ['alerts' => $alerts]);
}

// --- 3. SUB-PAGE DATA (History & Drives) ---

elseif ($action === 'get_history') {
    if (!isset($_SESSION['user_id'])) { sendJson('error', 'Not logged in'); }

    $user_id = $_SESSION['user_id'];
    // Left Join allows showing history even if drive was deleted
    $sql = "SELECT h.donation_date, h.volume_ml, 
            COALESCE(bd.location_name, 'External/Walk-in') as location_name
            FROM donation_history h
            LEFT JOIN blood_drive bd ON h.drive_id = bd.drive_id
            WHERE h.user_id = ? 
            ORDER BY h.donation_date DESC";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while ($row = $result->fetch_assoc()) { $history[] = $row; }
    sendJson('success', 'History loaded', $history);
}

elseif ($action === 'get_drives') {
    $query = $_GET['query'] ?? '';
    // Fetch upcoming drives
    $sql = "SELECT d.drive_id, d.drive_date, d.start_time, d.end_time, d.location_name, b.organization_name 
            FROM blood_drive d 
            JOIN blood_bank b ON d.bank_id = b.bank_id 
            WHERE d.drive_date >= CURDATE()";

    if ($query) {
        $sql .= " AND (d.location_name LIKE ? OR b.organization_name LIKE ?)";
    }
    $sql .= " ORDER BY d.drive_date ASC";

    $stmt = $conn->prepare($sql);
    if ($query) {
        $search = "%$query%";
        $stmt->bind_param("ss", $search, $search);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $drives = [];
    while ($row = $result->fetch_assoc()) { $drives[] = $row; }
    sendJson('success', 'Drives loaded', $drives);
}

else {
    sendJson('error', 'Invalid action');
}
?>