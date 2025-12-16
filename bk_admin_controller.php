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
    // Get donors with computed display_status:
    // - Active: status='Active' AND recent activity (within 1 year)
    // - Inactive: status='Active' BUT no activity for 1+ year
    // - Deactivated: status='Inactive' (admin-deactivated)
    $stmt = $conn->prepare("
        SELECT 
            d.user_id, 
            d.full_name, 
            d.email, 
            d.blood_type, 
            d.status,
            COALESCE(MAX(a.donation_date), d.created_at) as last_activity_date,
            CASE 
                WHEN d.status = 'Inactive' THEN 'Deactivated'
                WHEN COALESCE(MAX(a.donation_date), d.created_at) < DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 'Inactive'
                ELSE 'Active'
            END as display_status,
            CASE 
                WHEN COALESCE(MAX(a.donation_date), d.created_at) < DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 1
                ELSE 0
            END as can_deactivate
        FROM donor_user d
        LEFT JOIN appointment a ON d.user_id = a.user_id AND a.status = 'Completed'
        GROUP BY d.user_id, d.full_name, d.email, d.blood_type, d.status, d.created_at
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

elseif ($action === 'toggle_donor_status') {
    $user_id = $_POST['user_id'] ?? 0;
    $new_status = $_POST['status'] ?? 'Active';
    
    // Get current status and activity info
    $check = $conn->prepare("
        SELECT 
            d.status as current_status,
            CASE 
                WHEN COALESCE(MAX(a.donation_date), d.created_at) < DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 1
                ELSE 0
            END as can_deactivate
        FROM donor_user d
        LEFT JOIN appointment a ON d.user_id = a.user_id AND a.status = 'Completed'
        WHERE d.user_id = ?
        GROUP BY d.user_id, d.status, d.created_at
    ");
    $check->bind_param("i", $user_id);
    $check->execute();
    $result = $check->get_result();
    $row = $result->fetch_assoc();
    
    if (!$row) {
        sendJson('error', 'Donor not found');
        return;
    }
    
    $current_status = $row['current_status'];
    $can_deactivate = $row['can_deactivate'];
    
    // If trying to deactivate (set to Inactive), check if allowed
    if ($new_status === 'Inactive' && $current_status === 'Active') {
        if ($can_deactivate == 0) {
            sendJson('error', 'Cannot deactivate: Donor has been active within the last year');
            return;
        }
    }
    
    // Allow activation (Inactive -> Active) or deactivation (Active -> Inactive) if conditions met
    $stmt = $conn->prepare("UPDATE donor_user SET status = ? WHERE user_id = ?");
    $stmt->bind_param("si", $new_status, $user_id);
    
    if ($stmt->execute()) {
        sendJson('success', 'Donor status updated');
    } else {
        sendJson('error', 'Failed to update donor status: ' . $conn->error);
    }
}

elseif ($action === 'get_all_hospitals') {
    // Only show Active and Inactive hospitals, exclude Pending and Rejected
    // Computed display_status:
    // - Active: status='Active' AND recent activity (within 1 year)
    // - Inactive: status='Active' BUT no activity for 1+ year
    // - Deactivated: status='Inactive' (admin-deactivated)
    $stmt = $conn->prepare("
        SELECT 
            h.hospital_id, 
            h.hospital_name, 
            h.hospital_address, 
            h.admin_email, 
            h.status,
            COALESCE(MAX(bd.drive_date), h.created_at) as last_activity_date,
            CASE 
                WHEN h.status = 'Inactive' THEN 'Deactivated'
                WHEN COALESCE(MAX(bd.drive_date), h.created_at) < DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 'Inactive'
                ELSE 'Active'
            END as display_status,
            CASE 
                WHEN COALESCE(MAX(bd.drive_date), h.created_at) < DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 1
                ELSE 0
            END as can_deactivate
        FROM hospital h
        LEFT JOIN blood_drive bd ON h.hospital_id = bd.hospital_id
        WHERE h.status IN ('Active', 'Inactive')
        GROUP BY h.hospital_id, h.hospital_name, h.hospital_address, h.admin_email, h.status, h.created_at
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

elseif ($action === 'toggle_hospital_status') {
    $hospital_id = $_POST['hospital_id'] ?? 0;
    $new_status = $_POST['status'] ?? 'Active';
    
    // Get current status and activity info
    $check = $conn->prepare("
        SELECT 
            h.status as current_status,
            CASE 
                WHEN COALESCE(MAX(bd.drive_date), h.created_at) < DATE_SUB(NOW(), INTERVAL 1 YEAR) THEN 1
                ELSE 0
            END as can_deactivate
        FROM hospital h
        LEFT JOIN blood_drive bd ON h.hospital_id = bd.hospital_id
        WHERE h.hospital_id = ?
        GROUP BY h.hospital_id, h.status, h.created_at
    ");
    $check->bind_param("i", $hospital_id);
    $check->execute();
    $result = $check->get_result();
    $row = $result->fetch_assoc();
    
    if (!$row) {
        sendJson('error', 'Hospital not found');
        return;
    }
    
    $current_status = $row['current_status'];
    $can_deactivate = $row['can_deactivate'];
    
    // If trying to deactivate (set to Inactive), check if allowed
    if ($new_status === 'Inactive' && $current_status === 'Active') {
        if ($can_deactivate == 0) {
            sendJson('error', 'Cannot deactivate: Hospital has been active within the last year');
            return;
        }
    }
    
    // Allow activation (Inactive -> Active) or deactivation (Active -> Inactive) if conditions met
    $stmt = $conn->prepare("UPDATE hospital SET status = ? WHERE hospital_id = ?");
    $stmt->bind_param("si", $new_status, $hospital_id);
    
    if ($stmt->execute()) {
        sendJson('success', 'Hospital status updated');
    } else {
        sendJson('error', 'Failed to update hospital status: ' . $conn->error);
    }
}

else {
    sendJson('error', 'Invalid action');
}
?>
