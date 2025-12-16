<?php
// bloodknight.php - OPTIMIZED DONOR BACKEND

// CRITICAL: Start output buffering FIRST to catch any errors
ob_start();

// CRITICAL: Suppress ALL error display BEFORE any output
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL); // Log errors but don't display them

// Set error handler to log but not output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
}, E_ALL);

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean(); // Clear any output
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Server error occurred. Please check server logs.',
            'data' => []
        ]);
        exit;
    }
});

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");

// Configure session BEFORE starting it (must be before session_start())
ini_set('session.gc_maxlifetime', 86400 * 7); // 7 days
ini_set('session.cookie_lifetime', 86400 * 7); // 7 days
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0); // false for localhost

// Start session BEFORE any output
// Use session name to ensure consistent session handling
if (session_status() === PHP_SESSION_NONE) {
    session_name('BLOODKNIGHT_SESSION');
    session_start();
} else {
    session_start();
}

// Set session cookie parameters explicitly after session_start
session_set_cookie_params([
    'lifetime' => 86400 * 7, // 7 days
    'path' => '/', // Root path for all pages
    'domain' => '',
    'secure' => false, // false for localhost, true for HTTPS
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Ensure session is writeable and track activity
if (session_status() === PHP_SESSION_ACTIVE) {
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
    }
}

// Ensure db_connect.php exists or fallback to inline connection
if (file_exists('db_connect.php')) {
    try {
        @require_once 'db_connect.php';
        // Verify connection was established
        if (!isset($conn) || !$conn || $conn->connect_error) {
            $error_msg = isset($conn) && $conn ? $conn->connect_error : 'Connection variable not set';
            error_log("Database connection failed: " . $error_msg);
            sendJson('error', 'Database Connection Failed: ' . $error_msg . '. Please ensure: 1) XAMPP MySQL is running, 2) Database "bloodknight_db" exists in phpMyAdmin');
        } else {
            // Verify we're connected to the correct database (bloodknight_db)
            $current_db = @$conn->query("SELECT DATABASE()")->fetch_row()[0];
            if ($current_db !== 'bloodknight_db') {
                error_log("CRITICAL: Wrong database selected! Expected: bloodknight_db, Got: $current_db");
                // Try to select the correct database
                if (!$conn->select_db('bloodknight_db')) {
                    sendJson('error', 'Database Selection Failed: Could not select bloodknight_db. Please ensure the database exists in phpMyAdmin');
                } else {
                    error_log("Database corrected: Now using bloodknight_db");
                }
            } else {
                error_log("Database connection verified: Using bloodknight_db");
            }
        }
    } catch (Exception $e) {
        error_log("Exception in db_connect: " . $e->getMessage());
        sendJson('error', 'Database connection error: ' . $e->getMessage());
    }
} else {
    // Fallback connection if file missing (Update credentials as needed)
    try {
        $host = "localhost";
        $user = "root";
        $pass = "";
        $db   = "bloodknight_db";
        @$conn = new mysqli($host, $user, $pass, $db);
        if ($conn->connect_error) {
            error_log("Fallback connection failed: " . $conn->connect_error);
            sendJson('error', 'Database Connection Failed: ' . $conn->connect_error . '. Please ensure: 1) XAMPP MySQL is running, 2) Database "bloodknight_db" exists in phpMyAdmin');
        } else {
            // Verify database selection
            $current_db = @$conn->query("SELECT DATABASE()")->fetch_row()[0];
            if ($current_db !== 'bloodknight_db') {
                if (!$conn->select_db('bloodknight_db')) {
                    sendJson('error', 'Database Selection Failed: Could not select bloodknight_db');
                }
            }
            error_log("Fallback connection: Using bloodknight_db");
        }
    } catch (Exception $e) {
        error_log("Exception in fallback connection: " . $e->getMessage());
        sendJson('error', 'Database connection error: ' . $e->getMessage());
    }
}

$action = $_REQUEST['action'] ?? '';

function sendJson($status, $message, $data = []) {
    // Clean any output before sending JSON
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    ob_end_flush();
    exit;
}

// Test database connection endpoint
if ($action === 'test_db_connection') {
    if (isset($conn) && $conn && !$conn->connect_error) {
        // Verify we're using the correct database
        $current_db = @$conn->query("SELECT DATABASE()")->fetch_row()[0];
        if ($current_db !== 'bloodknight_db') {
            sendJson('error', 'Wrong database selected. Expected: bloodknight_db, Got: ' . $current_db);
        }
        
        // Test query
        $test_query = $conn->query("SELECT 1");
        if ($test_query) {
            // Check if required tables exist
            $tables = [];
            $required_tables = ['donor_user', 'appointment', 'blood_report', 'blood_drive', 'hospital'];
            foreach ($required_tables as $table) {
                $check = $conn->query("SHOW TABLES LIKE '$table'");
                $tables[$table] = $check->num_rows > 0 ? 'exists' : 'missing';
            }
            
            sendJson('success', 'Database connection successful', [
                'database' => $current_db,
                'server' => 'localhost',
                'tables' => $tables,
                'connection_status' => 'connected'
            ]);
        } else {
            sendJson('error', 'Database query failed: ' . $conn->error);
        }
    } else {
        sendJson('error', 'Database connection not established');
    }
}

// reCAPTCHA verification function
function verifyRecaptcha($recaptchaResponse) {
    // Use Google's test keys for development (always passes)
    // Replace with your own keys in production
    $secretKey = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe'; // Test secret key
    
    if (empty($recaptchaResponse)) {
        return false;
    }
    
    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret' => $secretKey,
        'response' => $recaptchaResponse,
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
    $result = file_get_contents($url, false, $context);
    
    if ($result === FALSE) {
        // If verification fails, allow in development mode
        return true; // For development, allow if API fails
    }
    
    $json = json_decode($result, true);
    return isset($json['success']) && $json['success'] === true;
}

// =============================================================
// 1. AUTHENTICATION
// =============================================================

if ($action === 'check_session') {
    // Ensure session is active
    if (session_status() !== PHP_SESSION_ACTIVE) {
        error_log("check_session - Session not active, starting session");
        session_start();
    }
    
    // Update last activity
    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
    
    // Log session state for debugging
    error_log("check_session - Session ID: " . session_id());
    error_log("check_session - user_id: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));
    error_log("check_session - role: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'NOT SET'));
    error_log("check_session - Session cookie params: " . print_r(session_get_cookie_params(), true));
    error_log("check_session - All session data: " . print_r($_SESSION, true));
    
    if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'donor') {
        // Fetch fresh user data from database (bloodknight_db)
        $stmt = $conn->prepare("SELECT full_name, email, blood_type, phone_number, profile_pic FROM donor_user WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            error_log("check_session - Success: User found in database (bloodknight_db)");
            // Update session name to keep it fresh
            $_SESSION['user_name'] = $row['full_name'];
            sendJson('success', 'Session valid', [
                'user_id' => $_SESSION['user_id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'blood_type' => $row['blood_type'],
                'phone' => $row['phone_number'],
                'profile_pic' => $row['profile_pic']
            ]);
        } else {
            error_log("check_session - Error: User not found in database for user_id: " . $_SESSION['user_id']);
            // Don't destroy session - just return error
            sendJson('error', 'User not found in database');
        }
    } else {
        error_log("check_session - Error: Session not set or invalid role");
        error_log("check_session - Session keys: " . implode(', ', array_keys($_SESSION)));
        sendJson('error', 'Not logged in');
    }
}

elseif ($action === 'login') {
    // Verify reCAPTCHA
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
    if (!verifyRecaptcha($recaptchaResponse)) {
        sendJson('error', 'reCAPTCHA verification failed. Please try again.');
    }
    
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // Fetch user details including profile_pic
    $stmt = $conn->prepare("SELECT user_id, password_hash, full_name, blood_type, phone_number, profile_pic FROM donor_user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password_hash'])) {
            // Set session variables - do this BEFORE regenerating ID
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['user_name'] = $row['full_name'];
            $_SESSION['role'] = 'donor';
            $_SESSION['last_activity'] = time();
            $_SESSION['login_time'] = time();
            
            // Regenerate session ID for security (AFTER setting session vars)
            // Use true to delete old session file and create new one
            $old_session_id = session_id();
            session_regenerate_id(true);
            $new_session_id = session_id();
            
            // Ensure session variables persist after regeneration (they should, but verify)
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['user_name'] = $row['full_name'];
            $_SESSION['role'] = 'donor';
            $_SESSION['last_activity'] = time();
            $_SESSION['login_time'] = time();
            
            // Log for debugging
            error_log("Login successful - Old Session ID: $old_session_id, New Session ID: $new_session_id");
            error_log("Login - user_id: " . $_SESSION['user_id'] . ", role: " . $_SESSION['role']);
            error_log("Login - Session cookie params: " . print_r(session_get_cookie_params(), true));
            error_log("Login - Session data verified: user_id=" . $_SESSION['user_id'] . ", role=" . $_SESSION['role']);
            
            // Check for last donation date to help frontend with caching
            $lastDonationStmt = $conn->prepare("SELECT MAX(donation_date) as last_date FROM appointment WHERE user_id = ? AND status = 'Completed'");
            $lastDonationStmt->bind_param("i", $row['user_id']);
            $lastDonationStmt->execute();
            $lastDonationRes = $lastDonationStmt->get_result()->fetch_assoc();
            $last_donation = $lastDonationRes['last_date'] ?? null;

            // Send full data back so index.html can save to localStorage
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

// =============================================================
// OTP VERIFICATION FOR REGISTRATION
// =============================================================

elseif ($action === 'send_otp') {
    // Verify reCAPTCHA
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
    if (!verifyRecaptcha($recaptchaResponse)) {
        sendJson('error', 'reCAPTCHA verification failed. Please try again.');
    }
    
    $email = $_POST['email'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $ic_number = $_POST['ic_number'] ?? '';
    
    if (empty($email)) {
        sendJson('error', 'Email is required');
    }
    
    if (empty($ic_number)) {
        sendJson('error', 'IC number is required');
    }
    
    // Validate IC number format (xxxxxx-xx-xxxx)
    $ic_cleaned = preg_replace('/[^0-9]/', '', $ic_number);
    if (strlen($ic_cleaned) !== 12) {
        sendJson('error', 'IC number must be 12 digits (format: xxxxxx-xx-xxxx)');
    }
    $ic_formatted = substr($ic_cleaned, 0, 6) . '-' . substr($ic_cleaned, 6, 2) . '-' . substr($ic_cleaned, 8, 4);
    
    // Check if email already exists
    $checkStmt = $conn->prepare("SELECT user_id FROM donor_user WHERE email = ?");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        sendJson('error', 'Email already exists');
    }
    
    // Check if IC number already exists
    // Ensure ic_number column exists
    $checkIcColumn = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'ic_number'");
    if ($checkIcColumn->num_rows == 0) {
        $conn->query("ALTER TABLE donor_user ADD COLUMN ic_number VARCHAR(14) NULL");
        // Add unique constraint if possible
        try {
            $conn->query("ALTER TABLE donor_user ADD UNIQUE KEY unique_ic_number (ic_number)");
        } catch (Exception $e) {
            // Unique constraint might already exist or table might have duplicates
        }
    }
    
    $checkIcStmt = $conn->prepare("SELECT user_id FROM donor_user WHERE ic_number = ?");
    $checkIcStmt->bind_param("s", $ic_formatted);
    $checkIcStmt->execute();
    if ($checkIcStmt->get_result()->num_rows > 0) {
        sendJson('error', 'IC number already registered. Please use a different IC number.');
    }
    
    // Create OTP table if it doesn't exist
    $conn->query("CREATE TABLE IF NOT EXISTS registration_otp (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL,
        otp_code VARCHAR(6) NOT NULL,
        expires_at DATETIME NOT NULL,
        verified TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_expires (expires_at)
    )");
    
    // Clean up expired OTPs (older than 10 minutes)
    $conn->query("DELETE FROM registration_otp WHERE expires_at < NOW() OR (verified = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))");
    
    // Generate 6-digit OTP
    $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    
    // Delete old OTPs for this email
    $deleteStmt = $conn->prepare("DELETE FROM registration_otp WHERE email = ?");
    $deleteStmt->bind_param("s", $email);
    $deleteStmt->execute();
    
    // Insert new OTP
    $insertStmt = $conn->prepare("INSERT INTO registration_otp (email, otp_code, expires_at) VALUES (?, ?, ?)");
    $insertStmt->bind_param("sss", $email, $otp, $expires_at);
    
    if (!$insertStmt->execute()) {
        sendJson('error', 'Failed to generate OTP');
    }
    
    // Send OTP via email
    if (file_exists('PHPMailer/src/PHPMailer.php')) {
        require_once 'PHPMailer/src/Exception.php';
        require_once 'PHPMailer/src/PHPMailer.php';
        require_once 'PHPMailer/src/SMTP.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'bloodknight.about@gmail.com';
            $mail->Password = 'lvua aqif zzia epqc';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->setFrom('noreply@bloodknight.com', 'BloodKnight');
            $mail->addAddress($email, $full_name ?: 'User');
            $mail->isHTML(true);
            $mail->Subject = 'Email Verification Code - BloodKnight';
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #dc2626;'>Email Verification</h2>
                    <p>Hello " . htmlspecialchars($full_name ?: 'User') . ",</p>
                    <p>Thank you for registering with BloodKnight! Please use the following verification code to complete your registration:</p>
                    <div style='background: #f3f4f6; border: 2px solid #dc2626; border-radius: 8px; padding: 20px; text-align: center; margin: 20px 0;'>
                        <h1 style='color: #dc2626; font-size: 32px; letter-spacing: 8px; margin: 0; font-family: monospace;'>" . htmlspecialchars($otp) . "</h1>
                    </div>
                    <p style='color: #6b7280; font-size: 14px;'>This code will expire in 10 minutes.</p>
                    <p style='color: #6b7280; font-size: 14px;'>If you didn't request this code, please ignore this email.</p>
                    <hr style='border: none; border-top: 1px solid #e5e7eb; margin: 20px 0;'>
                    <p style='color: #9ca3af; font-size: 12px;'>© BloodKnight - Saving Lives, One Donation at a Time</p>
                </div>
            ";
            $mail->send();
        } catch (Exception $e) {
            // Email failed but OTP is saved - still return success for security
        }
    }
    
    sendJson('success', 'OTP has been sent to your email address', ['expires_in' => 600]);
}

elseif ($action === 'verify_otp') {
    $email = $_POST['email'] ?? '';
    $otp = $_POST['otp'] ?? '';
    
    if (empty($email) || empty($otp)) {
        sendJson('error', 'Email and OTP are required');
    }
    
    // Verify OTP
    $stmt = $conn->prepare("SELECT id, expires_at FROM registration_otp WHERE email = ? AND otp_code = ? AND verified = 0");
    $stmt->bind_param("ss", $email, $otp);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Check if OTP is expired
        if (strtotime($row['expires_at']) < time()) {
            sendJson('error', 'OTP has expired. Please request a new one.');
        }
        
        // Mark OTP as verified and generate verification token
        $verification_token = bin2hex(random_bytes(32));
        $updateStmt = $conn->prepare("UPDATE registration_otp SET verified = 1 WHERE id = ?");
        $updateStmt->bind_param("i", $row['id']);
        $updateStmt->execute();
        
        // Store verification token in session or return it
        $_SESSION['otp_verified_' . $email] = $verification_token;
        $_SESSION['otp_verified_email'] = $email;
        
        sendJson('success', 'OTP verified successfully', ['verification_token' => $verification_token]);
    } else {
        sendJson('error', 'Invalid OTP code');
    }
}

elseif ($action === 'register_donor') {
    // Add gender column if it doesn't exist (MySQL 8.0+ syntax, with fallback)
    $checkGender = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'gender'");
    if ($checkGender->num_rows == 0) {
        $conn->query("ALTER TABLE donor_user ADD COLUMN gender VARCHAR(10) NULL");
    }
    
    // Ensure ic_number column exists
    $checkIcColumn = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'ic_number'");
    if ($checkIcColumn->num_rows == 0) {
        $conn->query("ALTER TABLE donor_user ADD COLUMN ic_number VARCHAR(14) NULL");
        // Add unique constraint if possible
        try {
            $conn->query("ALTER TABLE donor_user ADD UNIQUE KEY unique_ic_number (ic_number)");
        } catch (Exception $e) {
            // Unique constraint might already exist or table might have duplicates
        }
    }
    
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $ic_number = $_POST['ic_number'] ?? '';
    $password = $_POST['password'];
    $blood_type = $_POST['blood_type'];
    $phone = $_POST['phone'];
    $gender = $_POST['gender'] ?? '';
    $verification_token = $_POST['verification_token'] ?? '';

    // Validate IC number
    if (empty($ic_number)) {
        sendJson('error', 'IC number is required');
    }
    
    // Validate and format IC number (xxxxxx-xx-xxxx)
    $ic_cleaned = preg_replace('/[^0-9]/', '', $ic_number);
    if (strlen($ic_cleaned) !== 12) {
        sendJson('error', 'IC number must be 12 digits (format: xxxxxx-xx-xxxx)');
    }
    $ic_formatted = substr($ic_cleaned, 0, 6) . '-' . substr($ic_cleaned, 6, 2) . '-' . substr($ic_cleaned, 8, 4);

    // Verify OTP was completed
    if (empty($verification_token) || !isset($_SESSION['otp_verified_' . $email]) || $_SESSION['otp_verified_' . $email] !== $verification_token) {
        sendJson('error', 'Please verify your email with OTP first');
    }
    
    if ($_SESSION['otp_verified_email'] !== $email) {
        sendJson('error', 'Email verification mismatch');
    }

    // Check if email already exists (double check)
    $checkStmt = $conn->prepare("SELECT user_id FROM donor_user WHERE email = ?");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        sendJson('error', 'Email already exists');
    }
    
    // Check if IC number already exists
    $checkIcStmt = $conn->prepare("SELECT user_id FROM donor_user WHERE ic_number = ?");
    $checkIcStmt->bind_param("s", $ic_formatted);
    $checkIcStmt->execute();
    if ($checkIcStmt->get_result()->num_rows > 0) {
        sendJson('error', 'IC number already registered. Please use a different IC number.');
    }
    
    // Process QnA answers (if provided)
    $q1 = $_POST['q1'] ?? '';
    $q2 = $_POST['q2'] ?? '';
    $q3 = $_POST['q3'] ?? '';
    $q4 = $_POST['q4'] ?? '';
    $q5 = $_POST['q5'] ?? '';
    
    // Validate QnA answers (must pass critical questions)
    if (!empty($q1) && !empty($q2) && !empty($q4)) {
        if ($q1 === 'no' || $q2 === 'no' || $q4 === 'no') {
            sendJson('error', 'You do not meet the basic eligibility requirements');
        }
    }
    
    // Hash password and register
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO donor_user (email, password_hash, full_name, ic_number, blood_type, phone_number, gender) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $email, $password_hash, $full_name, $ic_formatted, $blood_type, $phone, $gender);

    if ($stmt->execute()) {
        // Clear OTP verification session
        unset($_SESSION['otp_verified_' . $email]);
        unset($_SESSION['otp_verified_email']);
        
        // Clean up verified OTPs for this email
        $cleanupStmt = $conn->prepare("DELETE FROM registration_otp WHERE email = ? AND verified = 1");
        $cleanupStmt->bind_param("s", $email);
        $cleanupStmt->execute();
        
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
    // Log session state for debugging
    error_log("get_dashboard_data - Session ID: " . session_id());
    error_log("get_dashboard_data - user_id in session: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));
    error_log("get_dashboard_data - role in session: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'NOT SET'));
    error_log("get_dashboard_data - Session cookie params: " . print_r(session_get_cookie_params(), true));
    
    // Ensure session is active and writeable
    if (session_status() !== PHP_SESSION_ACTIVE) {
        error_log("get_dashboard_data - Session not active, starting session");
        session_start();
    }
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'donor') { 
        error_log("get_dashboard_data - Session not valid, returning error");
        sendJson('error', 'Not logged in', []);
    }
    
    $user_id = $_SESSION['user_id'];
    
    // Log for debugging
    error_log("get_dashboard_data - Session ID: " . session_id() . ", user_id: " . $user_id);

    // 1. Fetch Basic User Info
    $stmtUser = $conn->prepare("SELECT full_name, blood_type, profile_pic FROM donor_user WHERE user_id = ?");
    $stmtUser->bind_param("i", $user_id);
    $stmtUser->execute();
    $userRes = $stmtUser->get_result()->fetch_assoc();

    if (!$userRes) { sendJson('error', 'User data not found'); }

    // FORCE REFRESH SESSION NAME (Fixes stale name issue)
    $_SESSION['user_name'] = $userRes['full_name'];

    // 2. Fetch Statistics - Use blood_report joined with appointment to get volume_ml
    // Note: volume_ml is in appointment table, not blood_report table
    $stmtStats = $conn->prepare("
        SELECT 
            COUNT(DISTINCT br.report_id) as donation_count, 
            COALESCE(SUM(a.volume_ml), 0) as total_volume,
            MAX(br.report_date) as last_donation
        FROM blood_report br
        LEFT JOIN appointment a ON br.appt_id = a.appt_id
        WHERE br.user_id = ?
        AND a.volume_ml IS NOT NULL
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
        'full_name' => $userRes['full_name'], // Also include full_name explicitly
        'blood_type' => $userRes['blood_type'],
        'profile_pic' => $userRes['profile_pic'], 
        'rank' => $rank,
        'donations' => $count,
        'lives_saved' => $count * 3,
        'volume_l' => number_format($volume / 1000, 1),
        'next_milestone' => 5 - ($count % 5),
        'last_donation' => $last_date
    ];
    
    // Log successful database connection
    error_log("get_dashboard_data - Successfully fetched data from bloodknight_db for user_id: " . $user_id);

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
    try {
        $query = $_GET['query'] ?? '';
        
        // Check if latitude/longitude columns exist, otherwise use coordinates only
        $checkColumns = $conn->query("SHOW COLUMNS FROM blood_drive LIKE 'latitude'");
        $hasLatLon = $checkColumns && $checkColumns->num_rows > 0;
        
        if ($hasLatLon) {
            $sql = "SELECT d.drive_id, d.drive_date, d.start_time, d.end_time, d.location_name, d.coordinates, d.latitude, d.longitude, h.hospital_name as organization_name 
                    FROM blood_drive d 
                    JOIN hospital h ON d.hospital_id = h.hospital_id 
                    WHERE d.drive_date >= CURDATE() AND d.status = 'Upcoming'";
        } else {
            $sql = "SELECT d.drive_id, d.drive_date, d.start_time, d.end_time, d.location_name, d.coordinates, h.hospital_name as organization_name 
                    FROM blood_drive d 
                    JOIN hospital h ON d.hospital_id = h.hospital_id 
                    WHERE d.drive_date >= CURDATE() AND d.status = 'Upcoming'";
        }

        if ($query) { $sql .= " AND (d.location_name LIKE ? OR h.hospital_name LIKE ?)"; }
        $sql .= " ORDER BY d.drive_date ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Failed to prepare get_drives query: " . $conn->error);
            sendJson('error', 'Failed to prepare query: ' . $conn->error);
        }
        
        if ($query) { $search = "%$query%"; $stmt->bind_param("ss", $search, $search); }
        
        if (!$stmt->execute()) {
            error_log("Failed to execute get_drives query: " . $stmt->error);
            sendJson('error', 'Failed to execute query: ' . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $drives = [];
        while ($row = $result->fetch_assoc()) { 
            // Parse coordinates if latitude/longitude not available
            if (!$hasLatLon && isset($row['coordinates']) && $row['coordinates']) {
                $coords = explode(',', $row['coordinates']);
                if (count($coords) === 2) {
                    $row['latitude'] = trim($coords[0]);
                    $row['longitude'] = trim($coords[1]);
                }
            } elseif ($hasLatLon && (!isset($row['latitude']) || !$row['latitude']) && isset($row['coordinates']) && $row['coordinates']) {
                // Fallback: parse coordinates if latitude/longitude are null
                $coords = explode(',', $row['coordinates']);
                if (count($coords) === 2) {
                    $row['latitude'] = trim($coords[0]);
                    $row['longitude'] = trim($coords[1]);
                }
            }
            $drives[] = $row; 
        }
        $stmt->close();
        sendJson('success', 'Drives loaded', $drives);
    } catch (Exception $e) {
        error_log("Error in get_drives: " . $e->getMessage());
        sendJson('error', 'Server error loading drives: ' . $e->getMessage());
    }
}

elseif ($action === 'get_history') {
    // Ensure session is active
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Log session state for debugging
    error_log("get_history - Session ID: " . session_id());
    error_log("get_history - user_id in session: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));
    error_log("get_history - role in session: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'NOT SET'));
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'donor') { 
        error_log("get_history - Session not valid, returning error");
        sendJson('error', 'Not logged in'); 
    }
    
    $user_id = $_SESSION['user_id'];
    error_log("get_history - Fetching history for user_id: " . $user_id);
    
    // Use the view_donation_history view from bloodknight_db.sql
    // This view already joins appointment, donor_user, blood_drive, and hospital tables
    // and filters for completed appointments only
    // We join with appointment and blood_drive to get location_name
    $sql = "SELECT 
                vdh.donation_date,
                vdh.volume_ml,
                vdh.hospital_name,
                COALESCE(bd.location_name, 'Walk-in Donation') as location_name
            FROM view_donation_history vdh
            LEFT JOIN appointment a ON vdh.appt_id = a.appt_id
            LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
            WHERE vdh.user_id = ?
            AND vdh.donation_date IS NOT NULL
            AND vdh.volume_ml IS NOT NULL
            ORDER BY vdh.donation_date DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("get_history - Prepare failed: " . $conn->error);
        sendJson('error', 'Database error: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        error_log("get_history - Execute failed: " . $stmt->error);
        sendJson('error', 'Failed to fetch history: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $history = [];
    
    while ($row = $result->fetch_assoc()) {
        $history[] = [
            'donation_date' => $row['donation_date'],
            'volume_ml' => $row['volume_ml'],
            'hospital_name' => $row['hospital_name'],
            'location_name' => $row['location_name']
        ];
    }
    
    error_log("get_history - Found " . count($history) . " history records from view_donation_history for user_id: " . $user_id);
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
    $cancellation_reason = $_POST['cancellation_reason'] ?? 'No reason provided';
    
    // First check appointment status - cannot cancel if Approved, Confirmed, or Completed
    $checkStmt = $conn->prepare("SELECT status FROM appointment WHERE appt_id = ? AND user_id = ?");
    $checkStmt->bind_param("ii", $appt_id, $user_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkRow = $checkResult->fetch_assoc()) {
        $status = $checkRow['status'];
        if ($status === 'Approved' || $status === 'Confirmed') {
            sendJson('error', 'This appointment has been approved/confirmed and cannot be cancelled. Please contact the hospital if you need to reschedule.');
        } else if ($status === 'Completed') {
            sendJson('error', 'This donation has been completed and cannot be cancelled.');
        } else if ($status === 'Cancelled' || $status === 'Rejected') {
            sendJson('error', 'This appointment has already been cancelled/rejected.');
        }
    }
    
    // Set the status to 'Cancelled' and store cancellation reason in notes field
    $reason_note = "Cancellation reason: " . $cancellation_reason;
    $stmt = $conn->prepare("UPDATE appointment SET status='Cancelled', notes=? WHERE appt_id = ? AND user_id = ? AND status = 'Pending'");
    $stmt->bind_param("sii", $reason_note, $appt_id, $user_id);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        sendJson('success', 'Appointment cancelled successfully!');
    } else {
        sendJson('error', 'Cancellation failed. Appointment not found or cannot be cancelled.');
    }
}


// =============================================================
// 4. PROFILE MANAGEMENT
// =============================================================

elseif ($action === 'get_profile') {
    // Ensure session is active
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Log session state for debugging
    error_log("get_profile - Session ID: " . session_id());
    error_log("get_profile - user_id in session: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'donor') { 
        error_log("get_profile - Session not valid, returning error");
        sendJson('error', 'Not logged in'); 
    }
    
    $user_id = $_SESSION['user_id'];
    error_log("get_profile - Fetching profile for user_id: " . $user_id);
    
    // Ensure all required columns exist (auto-add if missing)
    $requiredColumns = [
        'gender' => "ALTER TABLE donor_user ADD COLUMN gender VARCHAR(10) NULL",
        'ic_number' => "ALTER TABLE donor_user ADD COLUMN ic_number VARCHAR(14) NULL",
        'profile_pic' => "ALTER TABLE donor_user ADD COLUMN profile_pic VARCHAR(255) NULL"
    ];
    
    foreach ($requiredColumns as $column => $alterSql) {
        $check = $conn->query("SHOW COLUMNS FROM donor_user LIKE '$column'");
        if ($check->num_rows == 0) {
            if (!$conn->query($alterSql)) {
                // Check if error is because column already exists (race condition)
                if (strpos($conn->error, 'Duplicate column name') === false) {
                    error_log("Failed to add column $column: " . $conn->error);
                } else {
                    error_log("Column $column already exists (race condition)");
                }
            } else {
                error_log("Successfully added column $column to donor_user table");
            }
        }
    }
    
    $stmt = $conn->prepare("SELECT full_name, email, phone_number, blood_type, gender, profile_pic, ic_number FROM donor_user WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    if (!$data) {
        error_log("get_profile - User not found in database for user_id: " . $user_id);
        sendJson('error', 'User not found in database');
    }
    
    error_log("get_profile - Successfully loaded profile for user_id: " . $user_id);
    sendJson('success', 'Profile loaded', $data);
}

elseif ($action === 'update_profile') {
    // Ensure session is active
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Log session state for debugging
    error_log("update_profile - Session ID: " . session_id());
    error_log("update_profile - user_id in session: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'donor') { 
        error_log("update_profile - Session not valid, returning error");
        sendJson('error', 'Not logged in'); 
    }
    
    // Ensure all required columns exist (auto-add if missing)
    $requiredColumns = [
        'gender' => "ALTER TABLE donor_user ADD COLUMN gender VARCHAR(10) NULL",
        'profile_pic' => "ALTER TABLE donor_user ADD COLUMN profile_pic VARCHAR(255) NULL"
    ];
    
    foreach ($requiredColumns as $column => $alterSql) {
        $check = $conn->query("SHOW COLUMNS FROM donor_user LIKE '$column'");
        if ($check->num_rows == 0) {
            if (!$conn->query($alterSql)) {
                // Check if error is because column already exists (race condition)
                if (strpos($conn->error, 'Duplicate column name') === false) {
                    error_log("update_profile - Failed to add column $column: " . $conn->error);
                } else {
                    error_log("update_profile - Column $column already exists (race condition)");
                }
            } else {
                error_log("update_profile - Successfully added column $column to donor_user table");
            }
        }
    }
    
    $id = $_SESSION['user_id'];
    
    // Validate required fields
    if (empty($_POST['full_name'])) {
        sendJson('error', 'Full name is required');
    }
    
    $name = trim($_POST['full_name']);
    $phone = trim($_POST['phone'] ?? '');
    $blood = trim($_POST['blood_type'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    
    error_log("update_profile - Updating user_id: $id, name: $name, phone: $phone, blood: $blood, gender: $gender");
    
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
    // If new profile pic uploaded, update it. Otherwise keep existing.
    if ($profile_pic_path) {
        // New profile picture uploaded - update with new path
        $stmt = $conn->prepare("UPDATE donor_user SET full_name=?, phone_number=?, blood_type=?, gender=?, profile_pic=? WHERE user_id=?");
        if (!$stmt) {
            error_log("update_profile - Prepare failed: " . $conn->error);
            sendJson('error', 'Database error: ' . $conn->error);
        }
        $stmt->bind_param("sssssi", $name, $phone, $blood, $gender, $profile_pic_path, $id);
    } else {
        // No new file - update other fields but keep existing profile_pic
        $stmt = $conn->prepare("UPDATE donor_user SET full_name=?, phone_number=?, blood_type=?, gender=? WHERE user_id=?");
        if (!$stmt) {
            error_log("update_profile - Prepare failed: " . $conn->error);
            sendJson('error', 'Database error: ' . $conn->error);
        }
        $stmt->bind_param("ssssi", $name, $phone, $blood, $gender, $id);
    }
    
    if (!$stmt->execute()) {
        error_log("update_profile - Execute failed: " . $stmt->error);
        sendJson('error', 'Failed to update profile: ' . $stmt->error);
    }
    
    // Update successful - update session
    $_SESSION['user_name'] = $name;
    
    // Verify we're still connected to the correct database
    $current_db = @$conn->query("SELECT DATABASE()")->fetch_row()[0];
    if ($current_db !== 'bloodknight_db') {
        error_log("update_profile - Wrong database detected: $current_db, selecting bloodknight_db...");
        if (!$conn->select_db('bloodknight_db')) {
            error_log("update_profile - Failed to select bloodknight_db: " . $conn->error);
            sendJson('error', 'Database connection error. Please refresh and try again.');
        }
    }
    
    error_log("update_profile - Database verified: " . $current_db . ", user_id: " . $id);
    
    // Return updated profile data including profile_pic path
    $updatedStmt = $conn->prepare("SELECT full_name, email, phone_number, blood_type, gender, profile_pic, ic_number FROM donor_user WHERE user_id = ?");
    if (!$updatedStmt) {
        error_log("update_profile - Prepare failed for SELECT: " . $conn->error);
        error_log("update_profile - Connection status: " . ($conn->connect_error ? $conn->connect_error : 'Connected'));
        sendJson('error', 'Database error: ' . $conn->error);
    }
    
    $updatedStmt->bind_param("i", $id);
    if (!$updatedStmt->execute()) {
        error_log("update_profile - Execute failed for SELECT: " . $updatedStmt->error);
        error_log("update_profile - User ID used: " . $id);
        sendJson('error', 'Failed to retrieve updated profile: ' . $updatedStmt->error);
    }
    
    $result = $updatedStmt->get_result();
    $updatedRes = $result->fetch_assoc();
    
    if (!$updatedRes) {
        error_log("update_profile - User not found after update for user_id: " . $id);
        error_log("update_profile - Checking if user exists in database...");
        // Double-check if user exists
        $checkStmt = $conn->prepare("SELECT user_id FROM donor_user WHERE user_id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult->num_rows === 0) {
            error_log("update_profile - CRITICAL: User ID $id does not exist in database!");
            sendJson('error', 'User account not found. Please contact support.');
        } else {
            error_log("update_profile - User exists but SELECT query returned no data - possible column mismatch");
            // Return what we know from the update
            sendJson('success', 'Profile updated successfully', [
                'full_name' => $name,
                'email' => $_SESSION['email'] ?? '',
                'phone' => $phone,
                'phone_number' => $phone,
                'blood_type' => $blood,
                'gender' => $gender,
                'profile_pic' => $profile_pic_path ?? '',
                'ic_number' => ''
            ]);
        }
    } else {
        error_log("update_profile - Successfully retrieved updated profile for user_id: " . $id);
        
        sendJson('success', 'Profile updated successfully', [
            'full_name' => $updatedRes['full_name'],
            'email' => $updatedRes['email'],
            'phone' => $updatedRes['phone_number'],
            'phone_number' => $updatedRes['phone_number'], // Include both for compatibility
            'blood_type' => $updatedRes['blood_type'],
            'gender' => $updatedRes['gender'] ?? '',
            'profile_pic' => $updatedRes['profile_pic'] ?? '',
            'ic_number' => $updatedRes['ic_number'] ?? ''
        ]);
    }
}

// =============================================================
// 5. BLOOD REPORTS
// =============================================================

elseif ($action === 'get_my_blood_reports') {
    // Ensure session is active
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Log session state for debugging
    error_log("get_my_blood_reports - Session ID: " . session_id());
    error_log("get_my_blood_reports - user_id in session: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));
    error_log("get_my_blood_reports - role in session: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'NOT SET'));
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'donor') { 
        error_log("get_my_blood_reports - Session not valid, returning error");
        sendJson('error', 'Not logged in'); 
    }
    $user_id = $_SESSION['user_id'];
    error_log("get_my_blood_reports - Fetching reports for user_id: " . $user_id);
    
    // Verify database connection
    $current_db = @$conn->query("SELECT DATABASE()")->fetch_row()[0];
    if ($current_db !== 'bloodknight_db') {
        error_log("get_my_blood_reports - Wrong database detected: $current_db, selecting bloodknight_db...");
        if (!$conn->select_db('bloodknight_db')) {
            error_log("get_my_blood_reports - Failed to select bloodknight_db: " . $conn->error);
            sendJson('error', 'Database connection error. Please refresh and try again.');
        }
    }
    
    // Query blood reports - removed restrictive hospital_id check to show all reports
    // Include reports even if they don't have hospital associations
    // Note: volume_ml is in appointment table, not blood_report table
    $sql = "SELECT br.report_id, br.report_date, br.hemoglobin, br.hematocrit, br.platelet_count, 
                   br.white_blood_cell_count, br.red_blood_cell_count, br.blood_pressure, 
                   br.temperature, br.notes, br.appt_id,
                   COALESCE(bd.location_name, 'Walk-in Donation') as location_name,
                   COALESCE(h.hospital_name, 'Unknown Hospital') as hospital_name,
                   a.volume_ml as volume_ml
            FROM blood_report br
            LEFT JOIN appointment a ON br.appt_id = a.appt_id
            LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
            LEFT JOIN hospital h ON bd.hospital_id = h.hospital_id
            WHERE br.user_id = ?
            ORDER BY br.report_date DESC, br.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("get_my_blood_reports - Prepare failed: " . $conn->error);
        sendJson('error', 'Database error: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        error_log("get_my_blood_reports - Execute failed: " . $stmt->error);
        sendJson('error', 'Failed to fetch blood reports: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $reports = [];
    while($row = $result->fetch_assoc()) { 
        $reports[] = $row; 
    }
    
    error_log("get_my_blood_reports - Found " . count($reports) . " blood reports for user_id: " . $user_id);
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
            // Note: volume_ml is in appointment table, not blood_report table
            $sql = "SELECT 
                        COUNT(DISTINCT br.report_id) as donation_count,
                        COALESCE(SUM(a.volume_ml), 0) as total_volume
                    FROM blood_report br
                    INNER JOIN donor_user u ON br.user_id = u.user_id
                    LEFT JOIN appointment a ON br.appt_id = a.appt_id
                    WHERE u.blood_type = ?
                    AND br.report_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                    AND (a.volume_ml IS NOT NULL AND a.volume_ml > 0)";
            
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
        
        // Send email with reset link (include user_type=donor)
        $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.html?token=" . $token . "&user_type=donor";
        
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
                $mail->Username = 'bloodknight.about@gmail.com';
                $mail->Password = 'lvua aqif zzia epqc';
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
    
    // Ensure reset_token columns exist
    $checkToken = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'reset_token'");
    if ($checkToken->num_rows == 0) {
        $conn->query("ALTER TABLE donor_user ADD COLUMN reset_token VARCHAR(64) NULL, ADD COLUMN reset_token_expiry DATETIME NULL");
    }
    
    // Verify token - check if token exists and is not expired
    $stmt = $conn->prepare("SELECT user_id, reset_token_expiry FROM donor_user WHERE reset_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Check if token is expired
        if ($row['reset_token_expiry'] && strtotime($row['reset_token_expiry']) > time()) {
            // Update password and clear token
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE donor_user SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL WHERE user_id = ?");
            $updateStmt->bind_param("si", $passwordHash, $row['user_id']);
            
            if ($updateStmt->execute()) {
                sendJson('success', 'Password has been reset successfully. You can now login with your new password.');
            } else {
                sendJson('error', 'Failed to reset password: ' . $conn->error);
            }
        } else {
            sendJson('error', 'Reset token has expired. Please request a new password reset link.');
        }
    } else {
        sendJson('error', 'Invalid reset token. Please check the link or request a new password reset.');
    }
}

elseif ($action === 'get_hospitals') {
    // Get all hospitals with their details
    $sql = "SELECT hospital_id, hospital_name, hospital_address, contact_number, hospital_type 
            FROM hospital 
            ORDER BY hospital_name ASC";
    
    $result = $conn->query($sql);
    $hospitals = [];
    while ($row = $result->fetch_assoc()) {
        $hospitals[] = $row;
    }
    sendJson('success', 'Hospitals loaded', $hospitals);
}

else { sendJson('error', 'Invalid action'); }
?>
