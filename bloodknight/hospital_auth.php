<?php
// hospital_auth.php
header('Content-Type: application/json');
session_start();

require_once 'db_connect.php';

$action = $_REQUEST['action'] ?? '';

function sendJson($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

if ($action === 'register_hospital') {
    $hospital_name = $_POST['hospital_name'] ?? '';
    $hospital_address = $_POST['hospital_address'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $hospital_type = $_POST['hospital_type'] ?? '';
    $admin_name = $_POST['admin_name'] ?? '';
    $admin_email = $_POST['admin_email'] ?? '';
    $admin_phone = $_POST['admin_phone'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Validate required fields
    if (empty($hospital_name) || empty($hospital_address) || empty($admin_name) || empty($admin_email) || empty($password)) {
        sendJson('error', 'All required fields must be filled');
    }
    
    // Validate password strength
    if (strlen($password) < 8) {
        sendJson('error', 'Password must be at least 8 characters long');
    }
    
    // Check if hospital already exists
    $checkStmt = $conn->prepare("SELECT hospital_id FROM hospital WHERE admin_email = ?");
    $checkStmt->bind_param("s", $admin_email);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        sendJson('error', 'Hospital with this email already exists');
    }
    
    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert hospital
    $stmt = $conn->prepare("INSERT INTO hospital (hospital_name, hospital_address, contact_number, hospital_type, admin_name, admin_email, admin_phone, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss", $hospital_name, $hospital_address, $contact_number, $hospital_type, $admin_name, $admin_email, $admin_phone, $password_hash);
    
    if ($stmt->execute()) {
        $_SESSION['hospital_id'] = $stmt->insert_id;
        $_SESSION['hospital_name'] = $hospital_name;
        $_SESSION['admin_name'] = $admin_name;
        $_SESSION['role'] = 'hospital';
        
        sendJson('success', 'Hospital registered successfully!', [
            'hospital_id' => $stmt->insert_id,
            'hospital_name' => $hospital_name
        ]);
    } else {
        sendJson('error', 'Registration failed: ' . $conn->error);
    }
}

elseif ($action === 'login_hospital') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        sendJson('error', 'Email and password are required');
    }
    
    $stmt = $conn->prepare("SELECT hospital_id, hospital_name, admin_name, password_hash, status FROM hospital WHERE admin_email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Check if hospital is approved
        if ($row['status'] !== 'approved') {
            sendJson('error', 'Your hospital registration is pending approval');
        }
        
        if (password_verify($password, $row['password_hash'])) {
            $_SESSION['hospital_id'] = $row['hospital_id'];
            $_SESSION['hospital_name'] = $row['hospital_name'];
            $_SESSION['admin_name'] = $row['admin_name'];
            $_SESSION['role'] = 'hospital';
            
            sendJson('success', 'Login successful', [
                'hospital_name' => $row['hospital_name'],
                'admin_name' => $row['admin_name']
            ]);
        } else {
            sendJson('error', 'Invalid password');
        }
    } else {
        sendJson('error', 'Hospital not found with this email');
    }
}

elseif ($action === 'logout_hospital') {
    session_destroy();
    sendJson('success', 'Logged out successfully');
}

else {
    sendJson('error', 'Invalid action');
}
?>