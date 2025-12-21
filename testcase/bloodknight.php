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
            $required_tables = ['donor_user', 'appointment', 'blood_drive', 'hospital'];
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
        // Fetch fresh user data from database (bloodknight_db) including status
        $stmt = $conn->prepare("SELECT full_name, email, blood_type, phone_number, profile_pic, status FROM donor_user WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            // Check if user is blacklisted - if so, destroy session and deny access
            if (isset($row['status']) && $row['status'] === 'Blacklisted') {
                error_log("check_session - User blacklisted, destroying session for user_id: " . $_SESSION['user_id']);
                session_destroy();
                sendJson('error', 'Your account has been blacklisted. Please contact the administrator for assistance.');
                exit;
            }
            
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

    // Fetch user details including profile_pic and status
    $stmt = $conn->prepare("SELECT user_id, password_hash, full_name, blood_type, phone_number, profile_pic, status FROM donor_user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // Check if user is blacklisted BEFORE password verification
        if (isset($row['status']) && $row['status'] === 'Blacklisted') {
            sendJson('error', 'Your account has been blacklisted. Please contact the administrator for assistance.');
            exit;
        }
        
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
    
    // Validate IC number format (YYMMDD-XX-XXXX - Malaysian IC format)
    $ic_cleaned = preg_replace('/[^0-9]/', '', $ic_number);
    if (strlen($ic_cleaned) !== 12) {
        sendJson('error', 'IC number must be 12 digits (format: YYMMDD-XX-XXXX)');
    }
    
    // Extract date components (YYMMDD)
    $yy = (int)substr($ic_cleaned, 0, 2);
    $mm = (int)substr($ic_cleaned, 2, 2);
    $dd = (int)substr($ic_cleaned, 4, 2);
    
    // Validate month (01-12)
    if ($mm < 1 || $mm > 12) {
        sendJson('error', 'Invalid IC number: Month must be between 01-12');
    }
    
    // Validate day (01-31, but we'll be lenient and check basic range)
    if ($dd < 1 || $dd > 31) {
        sendJson('error', 'Invalid IC number: Day must be between 01-31');
    }
    
    // Determine century for birth year
    // Malaysian IC: YY is last 2 digits of birth year
    // Logic: If YY <= current year's last 2 digits, assume 2000s, else 1900s
    $current_year = (int)date('Y');
    $current_yy = $current_year % 100;
    $century = ($yy <= $current_yy) ? 2000 : 1900;
    $birth_year = $century + $yy;
    
    // Edge case: If birth year is in the future (shouldn't happen for 18+), adjust
    if ($birth_year > $current_year) {
        $birth_year = 1900 + $yy; // Must be 1900s
    }
    
    // Validate birth date
    if (!checkdate($mm, $dd, $birth_year)) {
        sendJson('error', 'Invalid IC number: Invalid date of birth');
    }
    
    // Calculate age
    $birth_date = new DateTime("$birth_year-$mm-$dd");
    $today = new DateTime();
    $age = $today->diff($birth_date)->y;
    
    // Check if user is 18 or above
    if ($age < 18) {
        sendJson('error', 'You must be 18 years or older to register as a donor. Your age based on IC number: ' . $age . ' years');
    }
    
    // Format IC number as YYMMDD-XX-XXXX
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
    
    // Validate and format IC number (YYMMDD-XX-XXXX - Malaysian IC format)
    $ic_cleaned = preg_replace('/[^0-9]/', '', $ic_number);
    if (strlen($ic_cleaned) !== 12) {
        sendJson('error', 'IC number must be 12 digits (format: YYMMDD-XX-XXXX)');
    }
    
    // Extract date components (YYMMDD)
    $yy = (int)substr($ic_cleaned, 0, 2);
    $mm = (int)substr($ic_cleaned, 2, 2);
    $dd = (int)substr($ic_cleaned, 4, 2);
    
    // Validate month (01-12)
    if ($mm < 1 || $mm > 12) {
        sendJson('error', 'Invalid IC number: Month must be between 01-12');
    }
    
    // Validate day (01-31, but we'll be lenient and check basic range)
    if ($dd < 1 || $dd > 31) {
        sendJson('error', 'Invalid IC number: Day must be between 01-31');
    }
    
    // Determine century for birth year
    // Malaysian IC: YY is last 2 digits of birth year
    // Logic: If YY <= current year's last 2 digits, assume 2000s, else 1900s
    $current_year = (int)date('Y');
    $current_yy = $current_year % 100;
    $century = ($yy <= $current_yy) ? 2000 : 1900;
    $birth_year = $century + $yy;
    
    // Edge case: If birth year is in the future (shouldn't happen for 18+), adjust
    if ($birth_year > $current_year) {
        $birth_year = 1900 + $yy; // Must be 1900s
    }
    
    // Validate birth date
    if (!checkdate($mm, $dd, $birth_year)) {
        sendJson('error', 'Invalid IC number: Invalid date of birth');
    }
    
    // Calculate age
    $birth_date = new DateTime("$birth_year-$mm-$dd");
    $today = new DateTime();
    $age = $today->diff($birth_date)->y;
    
    // Check if user is 18 or above
    if ($age < 18) {
        sendJson('error', 'You must be 18 years or older to register as a donor. Your age based on IC number: ' . $age . ' years');
    }
    
    // Format IC number as YYMMDD-XX-XXXX
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
    
    // Check if user has donated within 90 days - not eligible to sign up
    if (!empty($q3) && $q3 === 'yes') {
        sendJson('error', 'You are not eligible to sign up as a donor. You must wait at least 90 days after your last donation before registering.');
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

    // 1. Fetch Basic User Info including status
    $stmtUser = $conn->prepare("SELECT full_name, blood_type, profile_pic, status FROM donor_user WHERE user_id = ?");
    $stmtUser->bind_param("i", $user_id);
    $stmtUser->execute();
    $userRes = $stmtUser->get_result()->fetch_assoc();

    if (!$userRes) { sendJson('error', 'User data not found'); }
    
    // Check if user is blacklisted - if so, destroy session and deny access
    if (isset($userRes['status']) && $userRes['status'] === 'Blacklisted') {
        error_log("get_dashboard_data - User blacklisted, destroying session for user_id: " . $user_id);
        session_destroy();
        sendJson('error', 'Your account has been blacklisted. Please contact the administrator for assistance.');
        exit;
    }

    // FORCE REFRESH SESSION NAME (Fixes stale name issue)
    $_SESSION['user_name'] = $userRes['full_name'];

    // 2. Fetch Statistics - Use appointment table to get volume_ml
    $stmtStats = $conn->prepare("
        SELECT 
            COUNT(*) as donation_count, 
            COALESCE(SUM(volume_ml), 0) as total_volume,
            MAX(donation_date) as last_donation
        FROM appointment 
        WHERE user_id = ? AND status = 'Completed' AND volume_ml IS NOT NULL
    ");
    $stmtStats->bind_param("i", $user_id);
    $stmtStats->execute();
    $statsRes = $stmtStats->get_result()->fetch_assoc();

    $count = $statsRes['donation_count'] ?? 0;
    $volume = $statsRes['total_volume'] ?? 0;
    $last_date = $statsRes['last_donation'] ?? null;
    
    // Calculate Rank (Healer-themed ranks)
    $rank = 'Support';
    if ($count >= 5) $rank = 'Medic';
    if ($count >= 10) $rank = 'Healer';
    if ($count >= 20) $rank = 'Apothecary';
    if ($count >= 50) $rank = 'Master Healer';

    // Calculate next milestone based on rank thresholds
    $rankThresholds = [
        'Support' => 0,
        'Medic' => 5,
        'Healer' => 10,
        'Apothecary' => 20,
        'Master Healer' => 50
    ];
    
    $nextMilestone = 5; // Default
    if ($rank === 'Master Healer') {
        $nextMilestone = 0; // Already at max rank
    } else {
        $rankOrder = ['Support', 'Medic', 'Healer', 'Apothecary', 'Master Healer'];
        $currentIndex = array_search($rank, $rankOrder);
        if ($currentIndex !== false && isset($rankOrder[$currentIndex + 1])) {
            $nextRank = $rankOrder[$currentIndex + 1];
            $nextThreshold = $rankThresholds[$nextRank];
            $nextMilestone = $nextThreshold - $count;
        }
    }

    $data = [
        'name' => $userRes['full_name'], // Always sends the fresh DB name
        'full_name' => $userRes['full_name'], // Also include full_name explicitly
        'blood_type' => $userRes['blood_type'],
        'profile_pic' => $userRes['profile_pic'], 
        'rank' => $rank,
        'donations' => $count,
        'lives_saved' => $count * 3,
        'volume_l' => number_format($volume / 1000, 1),
        'next_milestone' => max(0, $nextMilestone),
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
    // Get all notifications for the logged-in user's blood type, or all if not logged in
    $user_id = $_SESSION['user_id'] ?? null;
    $user_blood_type = null;
    
    if ($user_id) {
        $userStmt = $conn->prepare("SELECT blood_type FROM donor_user WHERE user_id = ?");
        $userStmt->bind_param("i", $user_id);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        if ($userRow = $userResult->fetch_assoc()) {
            $user_blood_type = $userRow['blood_type'];
        }
        $userStmt->close();
    }
    
    // Check if title column exists
    $hasTitle = false;
    $checkColumn = $conn->query("SHOW COLUMNS FROM notification LIKE 'title'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        $hasTitle = true;
    }

    // Get notifications matching user's blood type or all notifications if no user
    if ($user_blood_type) {
        if ($hasTitle) {
            $sql = "SELECT n.alert_id, n.title, n.message_content as message, n.urgency_level as urgency, n.target_blood_type, n.sent_at, 
                           h.hospital_name, h.hospital_id
                    FROM notification n 
                    JOIN hospital h ON n.hospital_id = h.hospital_id 
                    WHERE n.target_blood_type = ? OR n.target_blood_type IS NULL
                    ORDER BY n.sent_at DESC";
        } else {
            $sql = "SELECT n.alert_id, n.message_content as message, n.urgency_level as urgency, n.target_blood_type, n.sent_at, 
                           h.hospital_name, h.hospital_id
                    FROM notification n 
                    JOIN hospital h ON n.hospital_id = h.hospital_id 
                    WHERE n.target_blood_type = ? OR n.target_blood_type IS NULL
                    ORDER BY n.sent_at DESC";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $user_blood_type);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        if ($hasTitle) {
            $sql = "SELECT n.alert_id, n.title, n.message_content as message, n.urgency_level as urgency, n.target_blood_type, n.sent_at,
                           h.hospital_name, h.hospital_id
                    FROM notification n 
                    JOIN hospital h ON n.hospital_id = h.hospital_id 
                    ORDER BY n.sent_at DESC";
        } else {
            $sql = "SELECT n.alert_id, n.message_content as message, n.urgency_level as urgency, n.target_blood_type, n.sent_at,
                           h.hospital_name, h.hospital_id
                    FROM notification n 
                    JOIN hospital h ON n.hospital_id = h.hospital_id 
                    ORDER BY n.sent_at DESC";
        }
    $result = $conn->query($sql);
    }
    
    $alerts = [];
    while ($row = $result->fetch_assoc()) { 
        $alerts[] = $row; 
    }
    
    if (isset($stmt)) $stmt->close();
    sendJson('success', 'Alerts loaded', ['alerts' => $alerts]);
}

elseif ($action === 'get_drives') {
    try {
    $query = $_GET['query'] ?? '';
        
        // Check if latitude/longitude columns exist, otherwise use coordinates only
        $checkColumns = $conn->query("SHOW COLUMNS FROM blood_drive LIKE 'latitude'");
        $hasLatLon = $checkColumns && $checkColumns->num_rows > 0;
        
        // Check if full_address column exists
        $checkFullAddress = $conn->query("SHOW COLUMNS FROM blood_drive LIKE 'full_address'");
        $hasFullAddress = $checkFullAddress && $checkFullAddress->num_rows > 0;
        
        if ($hasLatLon) {
            if ($hasFullAddress) {
                $sql = "SELECT d.drive_id, d.drive_date, d.start_time, d.end_time, d.location_name, d.full_address, d.coordinates, d.latitude, d.longitude, h.hospital_name as organization_name, h.hospital_address 
            FROM blood_drive d 
            JOIN hospital h ON d.hospital_id = h.hospital_id 
            WHERE d.drive_date >= CURDATE() AND d.status = 'Upcoming'";
            } else {
                $sql = "SELECT d.drive_id, d.drive_date, d.start_time, d.end_time, d.location_name, d.coordinates, d.latitude, d.longitude, h.hospital_name as organization_name, h.hospital_address 
                FROM blood_drive d 
                JOIN hospital h ON d.hospital_id = h.hospital_id 
                WHERE d.drive_date >= CURDATE() AND d.status = 'Upcoming'";
            }
        } else {
            if ($hasFullAddress) {
                $sql = "SELECT d.drive_id, d.drive_date, d.start_time, d.end_time, d.location_name, d.full_address, d.coordinates, h.hospital_name as organization_name, h.hospital_address 
                        FROM blood_drive d 
                        JOIN hospital h ON d.hospital_id = h.hospital_id 
                        WHERE d.drive_date >= CURDATE() AND d.status = 'Upcoming'";
            } else {
                $sql = "SELECT d.drive_id, d.drive_date, d.start_time, d.end_time, d.location_name, d.coordinates, h.hospital_name as organization_name, h.hospital_address 
                        FROM blood_drive d 
                        JOIN hospital h ON d.hospital_id = h.hospital_id 
                        WHERE d.drive_date >= CURDATE() AND d.status = 'Upcoming'";
            }
        }

        // Search by location_name, full_address (if exists), hospital_name, and hospital_address
        if ($query) { 
            if ($hasFullAddress) {
                $sql .= " AND (d.location_name LIKE ? OR d.full_address LIKE ? OR h.hospital_name LIKE ? OR h.hospital_address LIKE ?)";
            } else {
                $sql .= " AND (d.location_name LIKE ? OR h.hospital_name LIKE ? OR h.hospital_address LIKE ?)";
            }
        }
    $sql .= " ORDER BY d.drive_date ASC";

    $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("Failed to prepare get_drives query: " . $conn->error);
            sendJson('error', 'Failed to prepare query: ' . $conn->error);
        }
        
        if ($query) { 
            $search = "%$query%"; 
            if ($hasFullAddress) {
                $stmt->bind_param("ssss", $search, $search, $search, $search); 
            } else {
                $stmt->bind_param("sss", $search, $search, $search); 
            }
        }
        
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
    
    // Check database connection first
    if (!isset($conn) || $conn === null || $conn->connect_error) {
        error_log("get_history - Database connection failed");
        sendJson('error', 'Database connection failed. Please ensure XAMPP MySQL is running and the database "bloodknight_db" exists.');
    }
    
    // Ensure we're using the correct database
    $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
    if ($current_db !== 'bloodknight_db') {
        if (!$conn->select_db('bloodknight_db')) {
            error_log("get_history - Failed to select bloodknight_db database");
            sendJson('error', 'Database selection failed. Please ensure the database "bloodknight_db" exists.');
        }
    }
    
    // Log session state for debugging
    error_log("get_history - Session ID: " . session_id());
    error_log("get_history - user_id in session: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'NOT SET'));
    error_log("get_history - role in session: " . (isset($_SESSION['role']) ? $_SESSION['role'] : 'NOT SET'));
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'donor') { 
        error_log("get_history - Session not valid, returning error");
        sendJson('error', 'Not logged in. Please log in again.'); 
    }
    
    $user_id = $_SESSION['user_id'];
    error_log("get_history - Fetching history for user_id: " . $user_id);
    
    // Get history from appointments (via view) AND failed donations
    // Also includes failed donations (status = 'Failed' or 'Did Not Show')
    $sql = "(SELECT 
                vdh.donation_date,
                vdh.volume_ml,
                vdh.hospital_name,
                COALESCE(bd.location_name, 'Walk-in Donation') as location_name,
                'Completed' as donation_status
            FROM view_donation_history vdh
            LEFT JOIN appointment a ON vdh.appt_id = a.appt_id
            LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
            WHERE vdh.user_id = ?
            AND vdh.donation_date IS NOT NULL
            AND vdh.volume_ml IS NOT NULL)
            
            UNION
            
            (SELECT 
                a.donation_date,
                0 as volume_ml,
                COALESCE(h.hospital_name, 'N/A') as hospital_name,
                COALESCE(bd.location_name, 'Walk-in Donation') as location_name,
                a.status as donation_status
            FROM appointment a
            LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
            LEFT JOIN hospital h ON bd.hospital_id = h.hospital_id
            WHERE a.user_id = ?
            AND a.donation_date IS NOT NULL
            AND a.status IN ('Failed', 'Did Not Show'))
            
            ORDER BY donation_date DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("get_history - Prepare failed: " . $conn->error);
        sendJson('error', 'Database error: ' . $conn->error);
    }
    
    $stmt->bind_param("ii", $user_id, $user_id);
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
            'location_name' => $row['location_name'],
            'donation_status' => $row['donation_status'] ?? 'Completed'
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

// BLOOD REPORTS - REMOVED

elseif ($action === 'get_supply_levels') {
    // Calculate live blood supply levels using benchmark formula: Tbenchmark = N × B × U × S
    // Track weekly donations per blood type and compare against benchmark
    // Status: Stable (>= benchmark) or Unstable (< benchmark)
    
    try {
        // ===== BENCHMARK CALCULATION =====
        // Get number of hospitals (N)
        $N = 15; // Default value
        try {
            // Try to get count with status filter first
            $hospital_count_sql = "SELECT COUNT(*) as hospital_count FROM hospital WHERE status = 'Active'";
            $hospital_result = $conn->query($hospital_count_sql);
            if ($hospital_result) {
                $hospital_row = $hospital_result->fetch_assoc();
                if ($hospital_row && isset($hospital_row['hospital_count'])) {
                    $N = (int)$hospital_row['hospital_count'];
                }
            }
        } catch (Exception $e) {
            // If status column doesn't exist, try without filter
            try {
                $hospital_count_sql = "SELECT COUNT(*) as hospital_count FROM hospital";
                $hospital_result = $conn->query($hospital_count_sql);
                if ($hospital_result) {
                    $hospital_row = $hospital_result->fetch_assoc();
                    if ($hospital_row && isset($hospital_row['hospital_count'])) {
                        $N = (int)$hospital_row['hospital_count'];
                    }
                }
            } catch (Exception $e2) {
                // Use default value of 15
                error_log("Could not get hospital count, using default: " . $e2->getMessage());
            }
        }
        
        // Benchmark parameters (can be made configurable later)
        $B = 150; // Average beds per hospital
        $U = 0.1; // Average blood units used per bed per week
        $S = 1.5; // Safety factor (1.5 weeks of supply)
        
        // Calculate total benchmark: Tbenchmark = N × B × U × S
        $Tbenchmark = $N * $B * $U * $S;
        
        // Blood type distribution percentages (P)
        $blood_type_distribution = [
            'O' => 0.45,  // 45%
            'A' => 0.30,  // 30%
            'B' => 0.20,  // 20%
            'AB' => 0.05  // 5%
        ];
        
        // Calculate benchmark for each base blood type
        $base_type_benchmarks = [];
        foreach ($blood_type_distribution as $base_type => $percentage) {
            $base_type_benchmarks[$base_type] = round($Tbenchmark * $percentage);
        }
        
        // Distribute benchmarks for Rh+ and Rh- (assume 85% Rh+, 15% Rh-)
        $rh_positive_ratio = 0.85;
        $rh_negative_ratio = 0.15;
        
        // ===== GET CURRENT WEEK'S DONATIONS =====
        // Get start and end of current week (Monday to Sunday)
        $today = new DateTime();
        $day_of_week = (int)$today->format('w'); // 0 = Sunday, 1 = Monday, etc.
        $days_to_monday = $day_of_week == 0 ? 6 : $day_of_week - 1; // Adjust for Sunday = 0
        $week_start = clone $today;
        $week_start->modify("-{$days_to_monday} days")->setTime(0, 0, 0);
        $week_end = clone $week_start;
        $week_end->modify('+6 days')->setTime(23, 59, 59);
        
        $week_start_str = $week_start->format('Y-m-d H:i:s');
        $week_end_str = $week_end->format('Y-m-d H:i:s');
        
        // Get weekly donations per blood type
        $weekly_donations_sql = "SELECT 
                    u.blood_type,
                    COUNT(DISTINCT a.appt_id) as donation_count,
                    COALESCE(SUM(a.volume_ml), 0) as total_volume_ml
                FROM appointment a
                INNER JOIN donor_user u ON a.user_id = u.user_id
                WHERE a.status = 'Completed'
                AND a.donation_date >= ?
                AND a.donation_date <= ?
                AND (a.volume_ml IS NOT NULL AND a.volume_ml > 0)
                GROUP BY u.blood_type";
        
        $stmt = $conn->prepare($weekly_donations_sql);
        $weekly_donations = [];
        
        if ($stmt) {
            $stmt->bind_param("ss", $week_start_str, $week_end_str);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $weekly_donations[$row['blood_type']] = [
                        'count' => (int)$row['donation_count'],
                        'volume_ml' => (int)$row['total_volume_ml']
                    ];
                }
            }
            $stmt->close();
        }
        
        // ===== CALCULATE STATUS FOR EACH BLOOD TYPE =====
        $blood_types = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];
        $supply_data = [];
        
        foreach ($blood_types as $type) {
            // Extract base type (O, A, B, AB) and Rh factor
            $base_type = preg_replace('/[+-]/', '', $type);
            $is_positive = strpos($type, '+') !== false;
            
            // Calculate benchmark for this specific type
            $base_benchmark = $base_type_benchmarks[$base_type] ?? 0;
            $type_benchmark = $is_positive 
                ? round($base_benchmark * $rh_positive_ratio)
                : round($base_benchmark * $rh_negative_ratio);
            
            // Get actual weekly donations for this type
            $actual_donations = $weekly_donations[$type] ?? ['count' => 0, 'volume_ml' => 0];
            $actual_count = $actual_donations['count'];
            $actual_volume_ml = $actual_donations['volume_ml'];
            
            // Convert volume to units (assuming ~450ml per unit)
            // Prefer volume-based calculation when available, fallback to count
            if ($actual_volume_ml > 0) {
                $actual_units = round($actual_volume_ml / 450);
            } else {
                // If no volume data, assume each donation = 1 unit (450ml)
                $actual_units = $actual_count;
            }
            
            // Determine status: Stable (>= benchmark) or Unstable (< benchmark)
            $is_stable = $actual_units >= $type_benchmark;
            $status = $is_stable ? 'stable' : 'unstable';
            
            // Calculate percentage of benchmark achieved
            $percentage = $type_benchmark > 0 
                ? min(100, round(($actual_units / $type_benchmark) * 100))
                : 0;
            
            // Calculate difference from benchmark
            $difference = $actual_units - $type_benchmark;
            
            $supply_data[] = [
                'type' => $type,
                'status' => $status,
                'benchmark' => $type_benchmark,
                'actual' => $actual_units,
                'difference' => $difference,
                'percentage' => $percentage,
                'donations' => $actual_count,
                'volume_ml' => $actual_donations['volume_ml'],
                'week_start' => $week_start->format('Y-m-d'),
                'week_end' => $week_end->format('Y-m-d')
            ];
        }
        
        // Sort by status (unstable first) then by difference (most negative first)
        usort($supply_data, function($a, $b) {
            // Unstable types first
            if ($a['status'] !== $b['status']) {
                return $a['status'] === 'unstable' ? -1 : 1;
            }
            // Then by difference (most negative first)
            return $a['difference'] <=> $b['difference'];
        });
        
        sendJson('success', 'Supply levels loaded', $supply_data);
        
    } catch (Exception $e) {
        error_log("Error in get_supply_levels: " . $e->getMessage());
        // Fallback: return default supply levels if anything fails
        $fallback_data = [];
        $blood_types = ['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'];
        foreach ($blood_types as $type) {
            $fallback_data[] = [
                'type' => $type,
                'status' => 'unstable',
                'benchmark' => 0,
                'actual' => 0,
                'difference' => 0,
                'percentage' => 0,
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
    
    // Check if user exists and get status
    $stmt = $conn->prepare("SELECT user_id, full_name, status FROM donor_user WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Check if user is blacklisted - don't reveal this to prevent email enumeration
        if (isset($row['status']) && $row['status'] === 'Blacklisted') {
            // Return generic message (don't reveal account status for security)
            sendJson('success', 'If that email exists, a password reset link has been sent.');
            exit;
        }
        
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
    
    // Verify token - check if token exists and is not expired, also check status
    $stmt = $conn->prepare("SELECT user_id, reset_token_expiry, status FROM donor_user WHERE reset_token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Check if user is blacklisted
        if (isset($row['status']) && $row['status'] === 'Blacklisted') {
            sendJson('error', 'Your account has been blacklisted. Password reset is not allowed. Please contact the administrator for assistance.');
            exit;
        }
        
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

elseif ($action === 'geocode_address') {
    // Proxy endpoint for geocoding to avoid CORS issues
    $address = $_GET['address'] ?? $_POST['address'] ?? '';
    
    if (empty($address)) {
        sendJson('error', 'Address is required');
    }
    
    try {
        // Make request to Nominatim API from server-side (no CORS issues)
        $url = 'https://nominatim.openstreetmap.org/search?format=json&q=' . urlencode($address . ', Sabah, Malaysia') . '&limit=1';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BloodKnight Hospital Map');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Geocoding curl error: " . $error);
            sendJson('error', 'Geocoding service unavailable: ' . $error);
        }
        
        if ($httpCode !== 200) {
            error_log("Geocoding HTTP error: " . $httpCode);
            sendJson('error', 'Geocoding service returned error: HTTP ' . $httpCode);
        }
        
        $data = json_decode($response, true);
        
        if ($data && is_array($data) && count($data) > 0) {
            $coords = [
                'lat' => floatval($data[0]['lat']),
                'lon' => floatval($data[0]['lon'])
            ];
            sendJson('success', 'Address geocoded successfully', $coords);
        } else {
            sendJson('error', 'No geocoding results found for this address');
        }
    } catch (Exception $e) {
        error_log("Geocoding exception: " . $e->getMessage());
        sendJson('error', 'Geocoding failed: ' . $e->getMessage());
    }
}

elseif ($action === 'get_counter_stats') {
    // Get active donor count, calculate lives saved, and count active hospitals/clinics
    try {
        // Count active donors (status = 'Active')
        $stmt = $conn->prepare("SELECT COUNT(*) as donor_count FROM donor_user WHERE status = 'Active'");
        if (!$stmt) {
            error_log("Failed to prepare get_counter_stats query: " . $conn->error);
            sendJson('error', 'Database query failed');
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $donor_count = (int)($row['donor_count'] ?? 0);
        $lives_saved = $donor_count * 3; // 1 donation saves 3 lives
        
        // Count active hospitals/clinics (status = 'Active')
        $hospitalStmt = $conn->prepare("SELECT COUNT(*) as hospital_count FROM hospital WHERE status = 'Active'");
        if (!$hospitalStmt) {
            error_log("Failed to prepare hospital count query: " . $conn->error);
            // Continue without hospital count if query fails
            $hospital_count = 0;
        } else {
            $hospitalStmt->execute();
            $hospitalResult = $hospitalStmt->get_result();
            $hospitalRow = $hospitalResult->fetch_assoc();
            $hospital_count = (int)($hospitalRow['hospital_count'] ?? 0);
            $hospitalStmt->close();
        }
        
        sendJson('success', 'Counter stats loaded', [
            'donors' => $donor_count,
            'lives_saved' => $lives_saved,
            'clinics' => $hospital_count
        ]);
    } catch (Exception $e) {
        error_log("Error in get_counter_stats: " . $e->getMessage());
        sendJson('error', 'Failed to load counter stats: ' . $e->getMessage());
    }
}

else { sendJson('error', 'Invalid action'); }
?>
