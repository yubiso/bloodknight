<?php
// bk_admin_controller.php - BloodKnight Admin Backend Controller
// Suppress errors that might break JSON output
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configure session for persistence
ini_set('session.cookie_lifetime', 86400 * 7); // 7 days
ini_set('session.gc_maxlifetime', 86400 * 7); // 7 days
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Error handler to catch any errors and return JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $errstr]);
    exit;
});

try {
    require_once 'db_connect.php';
    // Verify database connection
    if (!isset($conn) || !$conn || $conn->connect_error) {
        throw new Exception('Database connection failed: ' . ($conn->connect_error ?? 'Unknown error'));
    }
    // Verify bk_admin table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'bk_admin'");
    if ($tableCheck->num_rows === 0) {
        throw new Exception('bk_admin table does not exist. Please run bloodknight_db.sql to create the database tables.');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// PHPMailer for email functionality
// Load PHPMailer files if they exist
$phpmailer_available = false;
if (file_exists('PHPMailer/src/PHPMailer.php')) {
    try {
        require 'PHPMailer/src/Exception.php';
        require 'PHPMailer/src/PHPMailer.php';
        require 'PHPMailer/src/SMTP.php';
        $phpmailer_available = class_exists('PHPMailer\PHPMailer\PHPMailer');
    } catch (Exception $e) {
        error_log('PHPMailer load error: ' . $e->getMessage());
    }
}

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']]);
        exit;
    }
});

// Get action from POST, GET, or REQUEST
$action = $_POST['action'] ?? $_GET['action'] ?? $_REQUEST['action'] ?? '';

// Debug endpoint to test if file is accessible
if ($action === 'test') {
    sendJson('success', 'Controller is working', [
        'method' => $_SERVER['REQUEST_METHOD'],
        'action' => $action,
        'post_data' => $_POST,
        'get_data' => $_GET,
        'request_data' => $_REQUEST,
        'db_connected' => isset($conn) && $conn->ping(),
        'db_name' => isset($conn) ? $conn->query("SELECT DATABASE()")->fetch_row()[0] : 'not connected'
    ]);
}

function sendJson($status, $message, $data = []) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

function sendEmail($toEmail, $toName, $subject, $body) {
    global $phpmailer_available;
    if (!$phpmailer_available) {
        return ['success' => false, 'msg' => 'PHPMailer not available'];
    }
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'bloodknight.about@gmail.com'; 
        $mail->Password   = 'lvua aqif zzia epqc';    
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->setFrom('admin@bloodknight.com', 'BloodKnight Admin');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Add logo as embedded image for emails (must be before setting Body)
        $logoPath = __DIR__ . '/assets/knight-shield.png';
        if (file_exists($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'knight-shield-logo', 'knight-shield.png');
        }
        
        $mail->Body = $body;
        $mail->send();
        return ['success' => true, 'msg' => 'Sent'];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return ['success' => false, 'msg' => $mail->ErrorInfo];
    }
}

function generatePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}

// =============================================================
// 1. AUTHENTICATION
// =============================================================

if ($action === 'check_session') {
    if (isset($_SESSION['bk_admin_id']) && isset($_SESSION['bk_role']) && $_SESSION['bk_role'] === 'bk_admin') {
        sendJson('success', 'Session valid', [
            'admin_id' => $_SESSION['bk_admin_id'],
            'admin_name' => $_SESSION['bk_admin_name']
        ]);
    } else {
        sendJson('error', 'Not logged in');
    }
}

elseif ($action === 'login') {
    try {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            sendJson('error', 'Email and password are required');
        }

        $stmt = $conn->prepare("SELECT admin_id, email, password_hash, full_name FROM bk_admin WHERE email = ?");
        if (!$stmt) {
            sendJson('error', 'Database error: ' . $conn->error);
        }
        
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password_hash'])) {
                $_SESSION['bk_admin_id'] = $row['admin_id'];
                $_SESSION['bk_admin_name'] = $row['full_name'];
                $_SESSION['bk_role'] = 'bk_admin';
                sendJson('success', 'Welcome, ' . $row['full_name']);
            } else {
                sendJson('error', 'Invalid password');
            }
        } else {
            sendJson('error', 'Email not found');
        }
    } catch (Exception $e) {
        sendJson('error', 'Login error: ' . $e->getMessage());
    }
}

elseif ($action === 'logout') {
    session_destroy();
    sendJson('success', 'Logged out successfully');
}

elseif ($action === 'register_admin') {
    // Registration for new BloodKnight admin (no authentication required)
    try {
        // Verify database connection
        if (!isset($conn) || !$conn || $conn->connect_error) {
            sendJson('error', 'Database connection failed. Please check your database configuration.');
        }
        
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $full_name = $_POST['full_name'] ?? '';
        
        // Validate required fields
        if (empty($email) || empty($password) || empty($full_name)) {
            sendJson('error', 'All fields are required');
        }
        
        // Validate password length
        if (strlen($password) < 6) {
            sendJson('error', 'Password must be at least 6 characters');
        }
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendJson('error', 'Invalid email format');
        }
        
        // Check if email already exists
        $check = $conn->prepare("SELECT admin_id FROM bk_admin WHERE email = ?");
        if (!$check) {
            sendJson('error', 'Database error: ' . $conn->error);
        }
        $check->bind_param("s", $email);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows > 0) {
            sendJson('error', 'An admin with this email already exists');
        }
        
        // Hash password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        if (!$password_hash) {
            sendJson('error', 'Failed to hash password');
        }
        
        // Insert new admin
        $stmt = $conn->prepare("INSERT INTO bk_admin (email, password_hash, full_name) VALUES (?, ?, ?)");
        if (!$stmt) {
            sendJson('error', 'Database error: ' . $conn->error);
        }
        $stmt->bind_param("sss", $email, $password_hash, $full_name);
        
        if ($stmt->execute()) {
            sendJson('success', 'Admin account created successfully! You can now login with your credentials.');
        } else {
            sendJson('error', 'Failed to create admin account: ' . $conn->error);
        }
    } catch (Exception $e) {
        sendJson('error', 'Registration error: ' . $e->getMessage());
    } catch (Error $e) {
        sendJson('error', 'Fatal error: ' . $e->getMessage());
    }
}

elseif ($action === 'request_hospital_registration') {
    // This action doesn't require authentication - it's for public hospital registration requests
    $hospital_name = $_POST['hospital_name'] ?? '';
    $hospital_location = $_POST['hospital_location'] ?? '';
    $hospital_email = $_POST['hospital_email'] ?? '';
    $hospital_phone = $_POST['hospital_phone'] ?? '';
    $hospital_type = $_POST['hospital_type'] ?? '';
    $admin_name = $_POST['admin_name'] ?? '';
    $recaptcha_response = $_POST['recaptcha_response'] ?? $_POST['g-recaptcha-response'] ?? '';
    
    // Verify reCAPTCHA
    if (!empty($recaptcha_response)) {
        $secretKey = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe'; // Test key - replace with production key
        $verifyURL = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret' => $secretKey,
            'response' => $recaptcha_response,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data)
            ]
        ];
        
        $context = stream_context_create($options);
        $result = file_get_contents($verifyURL, false, $context);
        $resultJson = json_decode($result, true);
        
        if (!isset($resultJson['success']) || !$resultJson['success']) {
            sendJson('error', 'reCAPTCHA verification failed. Please try again.');
        }
    } else {
        sendJson('error', 'Please complete the reCAPTCHA verification.');
    }
    
    // Validate required fields
    if (empty($hospital_name) || empty($hospital_location) || empty($hospital_email) || empty($hospital_phone) || empty($hospital_type)) {
        sendJson('error', 'All required fields must be filled');
    }
    
    // Validate hospital_type
    if (!in_array($hospital_type, ['Government', 'Private'])) {
        sendJson('error', 'Invalid hospital type. Must be Government or Private');
    }
    
    // Map hospital_type from request (Government/Private) to hospital table format (Government Hospital/Private Hospital)
    $mapped_hospital_type = ($hospital_type === 'Government') ? 'Government Hospital' : 'Private Hospital';
    
    // Check if email already exists in hospitals
    $check = $conn->prepare("SELECT hospital_id, status FROM hospital WHERE admin_email = ?");
    $check->bind_param("s", $hospital_email);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        $existing = $result->fetch_assoc();
        
        // Block if already pending or active
        if (in_array($existing['status'], ['Pending', 'Active'])) {
            sendJson('error', 'A registration request or hospital with this email already exists');
        }
        
        // If rejected, update the existing record to Pending status with new information
        // This allows rejected hospitals to resubmit with updated information (all fields except email can be changed)
        if ($existing['status'] === 'Rejected') {
            $update = $conn->prepare("UPDATE hospital SET hospital_name = ?, hospital_address = ?, contact_number = ?, hospital_type = ?, admin_name = ?, password_hash = NULL, status = 'Pending', rejection_reason = NULL, processed_at = NULL, processed_by = NULL, reset_token = NULL, reset_token_expiry = NULL WHERE hospital_id = ?");
            $update->bind_param("sssssi", $hospital_name, $hospital_location, $hospital_phone, $mapped_hospital_type, $admin_name, $existing['hospital_id']);
            
            if ($update->execute()) {
                sendJson('success', 'Registration request resubmitted successfully with updated information. You will receive an email once your request is reviewed.');
            } else {
                sendJson('error', 'Failed to resubmit request: ' . $conn->error);
            }
            exit;
        }
    }
    
    // Insert new request directly into hospital table with status='Pending' and password_hash=NULL
    $stmt = $conn->prepare("INSERT INTO hospital (hospital_name, hospital_address, contact_number, hospital_type, admin_email, admin_name, password_hash, status) VALUES (?, ?, ?, ?, ?, ?, NULL, 'Pending')");
    $stmt->bind_param("ssssss", $hospital_name, $hospital_location, $hospital_phone, $mapped_hospital_type, $hospital_email, $admin_name);
    
    if ($stmt->execute()) {
        sendJson('success', 'Registration request submitted successfully. You will receive an email once your request is reviewed.');
    } else {
        sendJson('error', 'Failed to submit request: ' . $conn->error);
    }
}

// --- MIDDLEWARE ---
if (!isset($_SESSION['bk_admin_id']) || $_SESSION['bk_role'] !== 'bk_admin') {
    sendJson('error', 'Unauthorized Access: Please Login');
}
$admin_id = $_SESSION['bk_admin_id'];

// =============================================================
// 2. OVERVIEW STATS
// =============================================================

if ($action === 'get_overview_stats') {
    $donors = $conn->query("SELECT COUNT(*) as c FROM donor_user")->fetch_assoc()['c'];
    $hospitals = $conn->query("SELECT COUNT(*) as c FROM hospital WHERE status = 'Active'")->fetch_assoc()['c'];
    $pending = $conn->query("SELECT COUNT(*) as c FROM hospital WHERE status = 'Pending'")->fetch_assoc()['c'];
    $donations = $conn->query("SELECT COUNT(*) as c FROM appointment WHERE status = 'Completed'")->fetch_assoc()['c'];
    
    sendJson('success', 'Stats loaded', [
        'total_donors' => $donors,
        'total_hospitals' => $hospitals,
        'pending_requests' => $pending,
        'total_donations' => $donations
    ]);
}

// =============================================================
// 3. HOSPITAL REGISTRATION REQUESTS
// =============================================================

elseif ($action === 'get_hospital_requests') {
    // Query hospital table for pending requests
    $stmt = $conn->prepare("SELECT hospital_id as request_id, hospital_name, hospital_address as hospital_location, admin_email as hospital_email, contact_number as hospital_phone, hospital_type, admin_name, status, created_at as requested_at FROM hospital WHERE status = 'Pending' ORDER BY created_at DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        // Map hospital_type back to Government/Private for display
        $row['hospital_type'] = ($row['hospital_type'] === 'Government Hospital') ? 'Government' : 'Private';
        $data[] = $row;
    }
    sendJson('success', 'Requests loaded', $data);
}

elseif ($action === 'approve_hospital_request') {
    $hospital_id = $_POST['request_id'] ?? 0;
    
    // First check if hospital exists at all
    $stmt = $conn->prepare("SELECT hospital_id, status FROM hospital WHERE hospital_id = ?");
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $hospital = $stmt->get_result()->fetch_assoc();
    
    if (!$hospital) {
        sendJson('error', 'Request not found');
    }
    
    // Check if already processed
    if ($hospital['status'] !== 'Pending') {
        sendJson('success', 'Request processed', ['already_processed' => true]);
    }
    
    // Get full hospital details for pending request
    $stmt = $conn->prepare("SELECT * FROM hospital WHERE hospital_id = ? AND status = 'Pending'");
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $hospital = $stmt->get_result()->fetch_assoc();
    
    // Generate reset token for password setup
    $reset_token = bin2hex(random_bytes(32));
    $reset_token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Ensure reset_token columns exist
    $checkToken = $conn->query("SHOW COLUMNS FROM hospital LIKE 'reset_token'");
    if ($checkToken->num_rows == 0) {
        $conn->query("ALTER TABLE hospital ADD COLUMN reset_token VARCHAR(64) NULL, ADD COLUMN reset_token_expiry DATETIME NULL");
    }
    
    // Generate temporary password (will be changed via reset link)
    $temp_password = generatePassword();
    $password_hash = password_hash($temp_password, PASSWORD_DEFAULT);
    
    // Update hospital record: set password, reset token, change status to Active, record processing info
    $stmt = $conn->prepare("UPDATE hospital SET password_hash = ?, reset_token = ?, reset_token_expiry = ?, status = 'Active', processed_at = NOW(), processed_by = ? WHERE hospital_id = ?");
    $stmt->bind_param("sssii", $password_hash, $reset_token, $reset_token_expiry, $admin_id, $hospital_id);
    
    if ($stmt->execute()) {
        
        // Generate reset link
        $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/admin_reset_password.html?token=" . $reset_token;
        
        // Send email with reset link
        $emailBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f9fafb; border-radius: 10px;'>
                <div style='text-align: center; margin-bottom: 30px;'>
                    <img src='cid:knight-shield-logo' alt='BloodKnight Logo' style='height: 60px; width: auto; margin-bottom: 15px;'>
                    <h1 style='font-family: \"Poppins\", sans-serif; font-weight: 800; color: #a51a3a; font-size: 1.5rem; margin: 0;'>BloodKnight</h1>
                    <p style='color: #6b7280; font-size: 14px; margin-top: 5px;'>Hospital Registration Approved</p>
                </div>
                <div style='background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                    <h2 style='color: #1a2332; font-size: 22px; margin-top: 0;'>Congratulations!</h2>
                    <p style='color: #4b5563; line-height: 1.6;'>Your hospital registration request for <strong>{$hospital['hospital_name']}</strong> has been approved.</p>
                    <div style='background: #f3f4f6; padding: 20px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #10b981;'>
                        <p style='color: #1a2332; font-weight: bold; margin: 0 0 10px 0;'>Your Login Email:</p>
                        <p style='color: #4b5563; margin: 5px 0;'><strong>Email:</strong> {$hospital['admin_email']}</p>
                    </div>
                    <div style='background: #fef3c7; padding: 20px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #f59e0b;'>
                        <p style='color: #1a2332; font-weight: bold; margin: 0 0 10px 0;'>Next Steps:</p>
                        <p style='color: #4b5563; margin: 5px 0; line-height: 1.6;'>Please set your password by clicking the link below. This link will expire in 24 hours.</p>
                        <div style='text-align: center; margin: 20px 0;'>
                            <a href='{$resetLink}' style='background: #dc3545; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold; font-size: 16px;'>Set Your Password</a>
                        </div>
                    </div>
                    <p style='color: #dc3545; font-weight: bold; margin-top: 20px;'>⚠️ Important: Please set your password using the link above before logging in.</p>
                    <div style='margin-top: 30px; text-align: center;'>
                        <p style='color: #4b5563; margin-bottom: 15px;'>After setting your password, you can login using your email and the password you created.</p>
                        <p style='color: #9ca3af; font-size: 12px;'>Visit the hospital login page on the BloodKnight website to access your dashboard.</p>
                    </div>
                </div>
                <p style='color: #9ca3af; font-size: 12px; text-align: center; margin-top: 20px;'>This is an automated message from BloodKnight Admin System</p>
            </div>
        ";
        
        $emailResult = sendEmail($hospital['admin_email'], $hospital['hospital_name'], 'Hospital Registration Approved - BloodKnight', $emailBody);
        
        sendJson('success', 'Hospital approved and email sent', ['hospital_id' => $hospital_id]);
    } else {
        sendJson('error', 'Failed to create hospital account: ' . $conn->error);
    }
}

elseif ($action === 'reject_hospital_request') {
    $hospital_id = $_POST['request_id'] ?? 0;
    $rejection_reason = $_POST['rejection_reason'] ?? 'No reason provided';
    
    // First check if hospital exists at all
    $stmt = $conn->prepare("SELECT hospital_id, status FROM hospital WHERE hospital_id = ?");
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $hospital = $stmt->get_result()->fetch_assoc();
    
    if (!$hospital) {
        sendJson('error', 'Request not found');
    }
    
    // Check if already processed
    if ($hospital['status'] !== 'Pending') {
        sendJson('success', 'Request processed', ['already_processed' => true]);
    }
    
    // Get full hospital details for pending request
    $stmt = $conn->prepare("SELECT * FROM hospital WHERE hospital_id = ? AND status = 'Pending'");
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $hospital = $stmt->get_result()->fetch_assoc();
    
    // Update hospital status to Rejected
    $stmt = $conn->prepare("UPDATE hospital SET status = 'Rejected', rejection_reason = ?, processed_at = NOW(), processed_by = ? WHERE hospital_id = ?");
    $stmt->bind_param("sii", $rejection_reason, $admin_id, $hospital_id);
    
    if ($stmt->execute()) {
        // Send rejection email
        $emailBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f9fafb; border-radius: 10px;'>
                <div style='text-align: center; margin-bottom: 30px;'>
                    <img src='cid:knight-shield-logo' alt='BloodKnight Logo' style='height: 60px; width: auto; margin-bottom: 15px;'>
                    <h1 style='font-family: \"Poppins\", sans-serif; font-weight: 800; color: #a51a3a; font-size: 1.5rem; margin: 0;'>BloodKnight</h1>
                    <p style='color: #6b7280; font-size: 14px; margin-top: 5px;'>Hospital Registration Status</p>
                </div>
                <div style='background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                    <h2 style='color: #dc3545; font-size: 22px; margin-top: 0;'>Registration Request Status</h2>
                    <p style='color: #4b5563; line-height: 1.6;'>We regret to inform you that your hospital registration request for <strong>{$hospital['hospital_name']}</strong> has been rejected.</p>
                    <div style='background: #fef2f2; padding: 20px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #dc3545;'>
                        <p style='color: #1a2332; font-weight: bold; margin: 0 0 10px 0;'>Reason for Rejection:</p>
                        <p style='color: #4b5563; margin: 0;'>{$rejection_reason}</p>
                    </div>
                    <p style='color: #4b5563; line-height: 1.6; margin-top: 20px;'>If you have any questions or would like to appeal this decision, please contact our support team.</p>
                </div>
                <p style='color: #9ca3af; font-size: 12px; text-align: center; margin-top: 20px;'>This is an automated message from BloodKnight Admin System</p>
            </div>
        ";
        
        $emailResult = sendEmail($hospital['admin_email'], $hospital['hospital_name'], 'Hospital Registration Rejected - BloodKnight', $emailBody);
        
        sendJson('success', 'Request rejected and email sent');
    } else {
        sendJson('error', 'Failed to reject request: ' . $conn->error);
    }
}

// =============================================================
// 4. USER MANAGEMENT
// =============================================================

elseif ($action === 'get_all_donors') {
    // Get donors with simplified display_status:
    // - Active: status='Active'
    // - Deactivated: status='Inactive' (admin-deactivated)
    // - Blacklisted: status='Blacklisted'
    $stmt = $conn->prepare("
        SELECT 
            d.user_id, 
            d.full_name, 
            d.email, 
            d.blood_type, 
            d.status,
            CASE 
                WHEN d.status = 'Inactive' THEN 'Deactivated'
                WHEN d.status = 'Blacklisted' THEN 'Blacklisted'
                ELSE 'Active'
            END as display_status
        FROM donor_user d
        ORDER BY d.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    sendJson('success', 'Donors loaded', $data);
}

elseif ($action === 'blacklist_donor') {
    $user_id = $_POST['user_id'] ?? 0;
    $blacklist_reason = $_POST['blacklist_reason'] ?? '';
    
    if (empty($blacklist_reason)) {
        sendJson('error', 'Blacklist reason is required');
        return;
    }
    
    // Check if user exists and is currently Active
    $check = $conn->prepare("SELECT user_id, full_name, email, status FROM donor_user WHERE user_id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    $result = $check->get_result();
    $donor = $result->fetch_assoc();
    
    if (!$donor) {
        sendJson('error', 'Donor not found');
        return;
    }
    
    if ($donor['status'] !== 'Active') {
        sendJson('error', 'Only Active donors can be blacklisted');
            return;
        }
    
    // Check if blacklist columns exist, if not add them
    $checkColumns = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'blacklist_reason'");
    if ($checkColumns->num_rows == 0) {
        $conn->query("ALTER TABLE donor_user ADD COLUMN blacklist_reason TEXT NULL, ADD COLUMN blacklisted_at TIMESTAMP NULL, ADD COLUMN blacklisted_by INT NULL");
    }
    
    // Update donor status to Blacklisted
    $stmt = $conn->prepare("UPDATE donor_user SET status = 'Blacklisted', blacklist_reason = ?, blacklisted_at = NOW(), blacklisted_by = ? WHERE user_id = ?");
    $stmt->bind_param("sii", $blacklist_reason, $admin_id, $user_id);
    
    if ($stmt->execute()) {
        // Send email notification to user
        $emailBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f9fafb; border-radius: 10px;'>
                <div style='text-align: center; margin-bottom: 30px;'>
                    <img src='cid:knight-shield-logo' alt='BloodKnight Logo' style='height: 60px; width: auto; margin-bottom: 15px;'>
                    <h1 style='font-family: \"Poppins\", sans-serif; font-weight: 800; color: #a51a3a; font-size: 1.5rem; margin: 0;'>BloodKnight</h1>
                    <p style='color: #6b7280; font-size: 14px; margin-top: 5px;'>Account Status Notification</p>
                </div>
                <div style='background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                    <h2 style='color: #dc3545; font-size: 22px; margin-top: 0;'>Account Blacklisted</h2>
                    <p style='color: #4b5563; line-height: 1.6;'>Dear <strong>{$donor['full_name']}</strong>,</p>
                    <p style='color: #4b5563; line-height: 1.6;'>We regret to inform you that your BloodKnight account has been blacklisted.</p>
                    <div style='background: #fef2f2; padding: 20px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #dc3545;'>
                        <p style='color: #1a2332; font-weight: bold; margin: 0 0 10px 0;'>Reason for Blacklisting:</p>
                        <p style='color: #4b5563; margin: 0;'>{$blacklist_reason}</p>
                    </div>
                    <p style='color: #4b5563; line-height: 1.6; margin-top: 20px;'>This action means your account access has been restricted. If you believe this is an error or would like to appeal this decision, please contact our support team.</p>
                    <div style='background: #f3f4f6; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                        <p style='color: #1a2332; font-weight: bold; margin: 0 0 5px 0;'>Contact Information:</p>
                        <p style='color: #4b5563; margin: 5px 0;'>Email: support@bloodknight.com</p>
                    </div>
                </div>
                <p style='color: #9ca3af; font-size: 12px; text-align: center; margin-top: 20px;'>This is an automated message from BloodKnight Admin System</p>
            </div>
        ";
        
        $emailResult = sendEmail($donor['email'], $donor['full_name'], 'Account Blacklisted - BloodKnight', $emailBody);
        
        sendJson('success', 'Donor blacklisted and email notification sent');
    } else {
        sendJson('error', 'Failed to blacklist donor: ' . $conn->error);
    }
}

elseif ($action === 'unblacklist_donor') {
    $user_id = $_POST['user_id'] ?? 0;
    
    // Check if user exists and is currently Blacklisted
    $check = $conn->prepare("SELECT user_id, full_name, email, status FROM donor_user WHERE user_id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    $result = $check->get_result();
    $donor = $result->fetch_assoc();
    
    if (!$donor) {
        sendJson('error', 'Donor not found');
            return;
        }
    
    if ($donor['status'] !== 'Blacklisted') {
        sendJson('error', 'Only Blacklisted donors can be unblacklisted');
            return;
    }
    
    // Update donor status to Active and clear blacklist fields
    $stmt = $conn->prepare("UPDATE donor_user SET status = 'Active', blacklist_reason = NULL, blacklisted_at = NULL, blacklisted_by = NULL WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        // Send email notification to user
        $emailBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f9fafb; border-radius: 10px;'>
                <div style='text-align: center; margin-bottom: 30px;'>
                    <img src='cid:knight-shield-logo' alt='BloodKnight Logo' style='height: 60px; width: auto; margin-bottom: 15px;'>
                    <h1 style='font-family: \"Poppins\", sans-serif; font-weight: 800; color: #a51a3a; font-size: 1.5rem; margin: 0;'>BloodKnight</h1>
                    <p style='color: #6b7280; font-size: 14px; margin-top: 5px;'>Account Status Notification</p>
                </div>
                <div style='background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                    <h2 style='color: #10b981; font-size: 22px; margin-top: 0;'>Account Restored</h2>
                    <p style='color: #4b5563; line-height: 1.6;'>Dear <strong>{$donor['full_name']}</strong>,</p>
                    <p style='color: #4b5563; line-height: 1.6;'>We are pleased to inform you that your BloodKnight account has been restored and is now active again.</p>
                    <div style='background: #f0fdf4; padding: 20px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #10b981;'>
                        <p style='color: #1a2332; font-weight: bold; margin: 0 0 10px 0;'>Account Status:</p>
                        <p style='color: #4b5563; margin: 0;'>Your account is now <strong style='color: #10b981;'>Active</strong> and you can log in and use all features of BloodKnight.</p>
                    </div>
                    <p style='color: #4b5563; line-height: 1.6; margin-top: 20px;'>You can now access your account, book appointments, and continue contributing to the blood donation community.</p>
                    <div style='background: #f3f4f6; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                        <p style='color: #1a2332; font-weight: bold; margin: 0 0 5px 0;'>Need Help?</p>
                        <p style='color: #4b5563; margin: 5px 0;'>Email: support@bloodknight.com</p>
                    </div>
                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='http://localhost/bloodknight/index.html' style='display: inline-block; background: #dc2626; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold;'>Login to BloodKnight</a>
                    </div>
                </div>
                <p style='color: #9ca3af; font-size: 12px; text-align: center; margin-top: 20px;'>This is an automated message from BloodKnight Admin System</p>
            </div>
        ";
        
        $emailResult = sendEmail($donor['email'], $donor['full_name'], 'Account Restored - BloodKnight', $emailBody);
        
        sendJson('success', 'Donor unblacklisted and email notification sent');
    } else {
        sendJson('error', 'Failed to unblacklist donor: ' . $conn->error);
    }
}

elseif ($action === 'toggle_donor_status') {
    // Simplified: Only for activating deactivated users (Inactive -> Active)
    $user_id = $_POST['user_id'] ?? 0;
    $new_status = $_POST['status'] ?? 'Active';
    
    // Get current status
    $check = $conn->prepare("SELECT user_id, status FROM donor_user WHERE user_id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    $result = $check->get_result();
    $donor = $result->fetch_assoc();
    
    if (!$donor) {
        sendJson('error', 'Donor not found');
        return;
    }
    
    // Only allow activating deactivated users (Inactive -> Active)
    if ($donor['status'] === 'Inactive' && $new_status === 'Active') {
    $stmt = $conn->prepare("UPDATE donor_user SET status = ? WHERE user_id = ?");
    $stmt->bind_param("si", $new_status, $user_id);
    
    if ($stmt->execute()) {
            sendJson('success', 'Donor activated successfully');
    } else {
        sendJson('error', 'Failed to update donor status: ' . $conn->error);
        }
    } else {
        sendJson('error', 'Invalid status transition');
    }
}

elseif ($action === 'get_all_hospitals') {
    // Only show Active, Inactive, and Blacklisted hospitals, exclude Pending and Rejected
    // Simplified display_status:
    // - Active: status='Active'
    // - Deactivated: status='Inactive' (admin-deactivated)
    // - Blacklisted: status='Blacklisted'
    $stmt = $conn->prepare("
        SELECT 
            h.hospital_id, 
            h.hospital_name, 
            h.hospital_address, 
            h.admin_email, 
            h.status,
            CASE 
                WHEN h.status = 'Inactive' THEN 'Deactivated'
                WHEN h.status = 'Blacklisted' THEN 'Blacklisted'
                ELSE 'Active'
            END as display_status
        FROM hospital h
        WHERE h.status IN ('Active', 'Inactive', 'Blacklisted')
        ORDER BY h.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    sendJson('success', 'Hospitals loaded', $data);
}

elseif ($action === 'blacklist_hospital') {
    $hospital_id = $_POST['hospital_id'] ?? 0;
    $blacklist_reason = $_POST['blacklist_reason'] ?? '';
    
    if (empty($blacklist_reason)) {
        sendJson('error', 'Blacklist reason is required');
        return;
    }
    
    // Check if hospital exists and is currently Active
    $check = $conn->prepare("SELECT hospital_id, hospital_name, admin_email, admin_name, status FROM hospital WHERE hospital_id = ?");
    $check->bind_param("i", $hospital_id);
    $check->execute();
    $result = $check->get_result();
    $hospital = $result->fetch_assoc();
    
    if (!$hospital) {
        sendJson('error', 'Hospital not found');
        return;
    }
    
    if ($hospital['status'] !== 'Active') {
        sendJson('error', 'Only Active hospitals can be blacklisted');
            return;
        }
    
    // Check if blacklist columns exist, if not add them
    $checkColumns = $conn->query("SHOW COLUMNS FROM hospital LIKE 'blacklist_reason'");
    if ($checkColumns->num_rows == 0) {
        $conn->query("ALTER TABLE hospital ADD COLUMN blacklist_reason TEXT NULL, ADD COLUMN blacklisted_at TIMESTAMP NULL, ADD COLUMN blacklisted_by INT NULL");
    }
    
    // Update hospital status to Blacklisted
    $stmt = $conn->prepare("UPDATE hospital SET status = 'Blacklisted', blacklist_reason = ?, blacklisted_at = NOW(), blacklisted_by = ? WHERE hospital_id = ?");
    $stmt->bind_param("sii", $blacklist_reason, $admin_id, $hospital_id);
    
    if ($stmt->execute()) {
        // Send email notification to hospital admin
        $emailBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f9fafb; border-radius: 10px;'>
                <div style='text-align: center; margin-bottom: 30px;'>
                    <img src='cid:knight-shield-logo' alt='BloodKnight Logo' style='height: 60px; width: auto; margin-bottom: 15px;'>
                    <h1 style='font-family: \"Poppins\", sans-serif; font-weight: 800; color: #a51a3a; font-size: 1.5rem; margin: 0;'>BloodKnight</h1>
                    <p style='color: #6b7280; font-size: 14px; margin-top: 5px;'>Hospital Account Status Notification</p>
                </div>
                <div style='background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                    <h2 style='color: #dc3545; font-size: 22px; margin-top: 0;'>Hospital Account Blacklisted</h2>
                    <p style='color: #4b5563; line-height: 1.6;'>Dear <strong>{$hospital['admin_name']}</strong>,</p>
                    <p style='color: #4b5563; line-height: 1.6;'>We regret to inform you that your BloodKnight hospital account for <strong>{$hospital['hospital_name']}</strong> has been blacklisted.</p>
                    <div style='background: #fef2f2; padding: 20px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #dc3545;'>
                        <p style='color: #1a2332; font-weight: bold; margin: 0 0 10px 0;'>Reason for Blacklisting:</p>
                        <p style='color: #4b5563; margin: 0;'>{$blacklist_reason}</p>
                    </div>
                    <p style='color: #4b5563; line-height: 1.6; margin-top: 20px;'>This action means your hospital account access has been restricted. If you believe this is an error or would like to appeal this decision, please contact our support team.</p>
                    <div style='background: #f3f4f6; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                        <p style='color: #1a2332; font-weight: bold; margin: 0 0 5px 0;'>Contact Information:</p>
                        <p style='color: #4b5563; margin: 5px 0;'>Email: support@bloodknight.com</p>
                    </div>
                </div>
                <p style='color: #9ca3af; font-size: 12px; text-align: center; margin-top: 20px;'>This is an automated message from BloodKnight Admin System</p>
            </div>
        ";
        
        $emailResult = sendEmail($hospital['admin_email'], $hospital['admin_name'], 'Hospital Account Blacklisted - BloodKnight', $emailBody);
        
        sendJson('success', 'Hospital blacklisted and email notification sent');
    } else {
        sendJson('error', 'Failed to blacklist hospital: ' . $conn->error);
    }
}

elseif ($action === 'toggle_hospital_status') {
    // Simplified: Only for activating deactivated hospitals (Inactive -> Active)
    $hospital_id = $_POST['hospital_id'] ?? 0;
    $new_status = $_POST['status'] ?? 'Active';
    
    // Get current status
    $check = $conn->prepare("SELECT hospital_id, status FROM hospital WHERE hospital_id = ?");
    $check->bind_param("i", $hospital_id);
    $check->execute();
    $result = $check->get_result();
    $hospital = $result->fetch_assoc();
    
    if (!$hospital) {
        sendJson('error', 'Hospital not found');
        return;
    }
    
    // Only allow activating deactivated hospitals (Inactive -> Active)
    if ($hospital['status'] === 'Inactive' && $new_status === 'Active') {
    $stmt = $conn->prepare("UPDATE hospital SET status = ? WHERE hospital_id = ?");
    $stmt->bind_param("si", $new_status, $hospital_id);
    
    if ($stmt->execute()) {
            sendJson('success', 'Hospital activated successfully');
    } else {
        sendJson('error', 'Failed to update hospital status: ' . $conn->error);
        }
    } else {
        sendJson('error', 'Invalid status transition');
    }
}

elseif ($action === 'get_active_hospitals') {
    try {
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            sendJson('error', 'Database connection failed');
        }
        
        $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
        if ($current_db !== 'bloodknight_db') {
            if (!$conn->select_db('bloodknight_db')) {
                sendJson('error', 'Database selection failed');
            }
        }
        
        $timeFilter = $_POST['time_filter'] ?? 'month';
        $dateCondition = '';
        
        // Handle period format (week, week_1, month, 1month_ago, year, year_1, etc.)
        if (preg_match('/^week_(\d+)$/', $timeFilter, $matches)) {
            $offset = (int)$matches[1];
            $today = new DateTime();
            $dayOfWeek = (int)$today->format('w');
            $daysToMonday = ($dayOfWeek == 0) ? 6 : ($dayOfWeek - 1);
            $weekStart = clone $today;
            $weekStart->modify("-{$offset} weeks")->modify("-{$daysToMonday} days")->setTime(0, 0, 0);
            $weekEnd = clone $weekStart;
            $weekEnd->modify('+6 days');
            $dateCondition = "AND bd.drive_date >= '" . $weekStart->format('Y-m-d') . "' AND bd.drive_date <= '" . $weekEnd->format('Y-m-d') . "'";
        } elseif (preg_match('/^(\d+)month_ago$/', $timeFilter, $matches)) {
            $offset = (int)$matches[1];
            $dateCondition = "AND YEAR(bd.drive_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL $offset MONTH)) AND MONTH(bd.drive_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL $offset MONTH))";
        } elseif (preg_match('/^year_(\d+)$/', $timeFilter, $matches)) {
            $offset = (int)$matches[1];
            $year = date('Y') - $offset;
            $dateCondition = "AND YEAR(bd.drive_date) = $year";
        } else {
            switch ($timeFilter) {
                case 'week':
                    // Current week (Monday to Sunday)
                    $today = new DateTime();
                    $dayOfWeek = (int)$today->format('w'); // 0 = Sunday, 1 = Monday, etc.
                    $daysToMonday = ($dayOfWeek == 0) ? 6 : ($dayOfWeek - 1);
                    $weekStart = clone $today;
                    $weekStart->modify("-{$daysToMonday} days")->setTime(0, 0, 0);
                    $weekEnd = clone $weekStart;
                    $weekEnd->modify('+6 days')->setTime(23, 59, 59);
                    $dateCondition = "AND bd.drive_date >= '" . $weekStart->format('Y-m-d') . "' AND bd.drive_date <= '" . $weekEnd->format('Y-m-d') . "'";
                    break;
                case 'month':
                    // Current month (first day to last day of current month)
                    $dateCondition = "AND YEAR(bd.drive_date) = YEAR(CURDATE()) AND MONTH(bd.drive_date) = MONTH(CURDATE())";
                    break;
                case 'year':
                    // Current year (January 1 to December 31 of current year)
                    $dateCondition = "AND YEAR(bd.drive_date) = YEAR(CURDATE())";
                    break;
                default:
                    $dateCondition = "AND YEAR(bd.drive_date) = YEAR(CURDATE()) AND MONTH(bd.drive_date) = MONTH(CURDATE())";
            }
        }
        
        $sql = "SELECT 
                    h.hospital_id,
                    h.hospital_name,
                    COUNT(DISTINCT bd.drive_id) as count
                FROM hospital h
                LEFT JOIN blood_drive bd ON h.hospital_id = bd.hospital_id AND h.status = 'Active' $dateCondition
                WHERE h.status = 'Active'
                GROUP BY h.hospital_id, h.hospital_name
                HAVING count > 0
                ORDER BY count DESC, h.hospital_name ASC
                LIMIT 10";
        
        $result = $conn->query($sql);
        if (!$result) {
            error_log("Failed to execute get_active_hospitals query: " . $conn->error);
            sendJson('error', 'Failed to load active hospitals: ' . $conn->error);
        }
        
        $hospitals = [];
        while ($row = $result->fetch_assoc()) {
            $hospitals[] = $row;
        }
        
        sendJson('success', 'Active hospitals loaded', $hospitals);
    } catch (Exception $e) {
        error_log("get_active_hospitals error: " . $e->getMessage());
        sendJson('error', 'Error loading active hospitals: ' . $e->getMessage());
    }
}

elseif ($action === 'get_detailed_hospital_report') {
    try {
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            sendJson('error', 'Database connection failed');
        }
        
        $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
        if ($current_db !== 'bloodknight_db') {
            if (!$conn->select_db('bloodknight_db')) {
                sendJson('error', 'Database selection failed');
            }
        }
        
        $sql = "SELECT 
                    h.hospital_id,
                    h.hospital_name,
                    h.hospital_address,
                    h.contact_number,
                    h.admin_name,
                    h.admin_email,
                    h.admin_phone,
                    COALESCE(SUM(a.volume_ml), 0) / 1000.0 as total_volume_l,
                    COUNT(DISTINCT a.appt_id) as total_donations,
                    COUNT(DISTINCT bd.drive_id) as total_drives,
                    GROUP_CONCAT(DISTINCT DATE(bd.drive_date) ORDER BY bd.drive_date ASC SEPARATOR ',') as drive_dates
                FROM hospital h
                LEFT JOIN blood_drive bd ON h.hospital_id = bd.hospital_id
                LEFT JOIN appointment a ON bd.drive_id = a.drive_id AND a.status = 'Completed' AND a.volume_ml IS NOT NULL
                WHERE h.status = 'Active'
                GROUP BY h.hospital_id, h.hospital_name, h.hospital_address, h.contact_number, h.admin_name, h.admin_email, h.admin_phone
                ORDER BY total_volume_l DESC, total_drives DESC, h.hospital_name ASC";
        
        $result = $conn->query($sql);
        if (!$result) {
            error_log("Failed to execute get_detailed_hospital_report query: " . $conn->error);
            sendJson('error', 'Failed to load hospital report: ' . $conn->error);
        }
        
        $hospitals = [];
        while ($row = $result->fetch_assoc()) {
            // Convert drive_dates string to array
            if (!empty($row['drive_dates'])) {
                $row['drive_dates'] = explode(',', $row['drive_dates']);
            } else {
                $row['drive_dates'] = [];
            }
            $hospitals[] = $row;
        }
        
        sendJson('success', 'Hospital report loaded', $hospitals);
    } catch (Exception $e) {
        error_log("get_detailed_hospital_report error: " . $e->getMessage());
        sendJson('error', 'Error loading hospital report: ' . $e->getMessage());
    }
}

else {
    sendJson('error', 'Invalid action');
}
?>
