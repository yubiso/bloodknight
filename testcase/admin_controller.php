<?php
// admin_controller.php - COMMAND CENTER BACKEND
header('Content-Type: application/json');

// Configure session for persistence - MUST be set before session_start()
ini_set('session.cookie_lifetime', 86400 * 7); // 7 days
ini_set('session.gc_maxlifetime', 86400 * 7); // 7 days
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);

// Set session cookie parameters explicitly for better compatibility (before session_start)
session_set_cookie_params([
    'lifetime' => 86400 * 7, // 7 days
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']), // Secure if HTTPS
    'httponly' => true,
    'samesite' => 'Lax' // Allow cookies in cross-site redirects
]);

session_start();

require_once 'db_connect.php'; 

// Verify database connection (only log warnings, don't block password reset actions)
if (!isset($conn) || $conn === null || ($conn && $conn->connect_error)) {
    error_log("Database connection issue in admin_controller.php");
    // Don't block here - let individual actions handle errors
}

// Verify we're connected to the correct database (bloodknight_db) - only if connection exists
if (isset($conn) && $conn && !$conn->connect_error) {
    $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
    if ($current_db !== 'bloodknight_db') {
        error_log("WARNING: Connected to wrong database. Expected: bloodknight_db, Got: $current_db");
        // Try to select the correct database
        if (!$conn->select_db('bloodknight_db')) {
            error_log("ERROR: Failed to select bloodknight_db database");
        }
    }
}

// --- GMAIL INTEGRATION CONFIG ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$action = $_REQUEST['action'] ?? '';

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
        $mail->Username   = 'bloodknight.about@gmail.com'; 
        $mail->Password   = 'lvua aqif zzia epqc';    
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
// 1. AUTHENTICATION
// =============================================================

if ($action === 'check_session') {
    if (isset($_SESSION['hospital_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'hospital') {
        sendJson('success', 'Session valid', [
            'hospital_id' => $_SESSION['hospital_id'],
            'hospital_name' => $_SESSION['hospital_name'],
            'admin_name' => $_SESSION['admin_name']
        ]);
    } else {
        sendJson('error', 'Not logged in');
    }
}

// =============================================================
// 2. ANALYTICS & REPORTS
// =============================================================
// Ensure hospital_id is set (middleware check)
// Allow password reset and forgot password without session (they use tokens)
if (!isset($_SESSION['hospital_id']) && $action !== 'login' && $action !== 'logout' && $action !== 'check_session' && $action !== 'reset_password' && $action !== 'forgot_password') {
    sendJson('error', 'Unauthorized: Please login');
}
// Ensure hospital_id is set from session - don't default to 1
// But allow reset_password and forgot_password to proceed without session (they use tokens)
if (!isset($_SESSION['hospital_id']) && $action !== 'reset_password' && $action !== 'forgot_password') {
    error_log("WARNING: hospital_id not set in session for action: $action");
    sendJson('error', 'Session expired. Please login again.');
}
// Only set hospital_id if we have a session (not needed for password reset/forgot)
$hospital_id = $_SESSION['hospital_id'] ?? null;

if ($action === 'get_analytics') {
    try {
        // Helper function to safely execute queries with hospital filter
        function safeQuery($conn, $sql, $default = [], $params = [], $types = '') {
            try {
                if (!empty($params) && !empty($types)) {
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param($types, ...$params);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    } else {
                        error_log("Prepare Error: " . $conn->error . " | Query: " . $sql);
                        return $default;
                    }
                } else {
                    $result = $conn->query($sql);
                    if ($result === false) {
                        error_log("SQL Error: " . $conn->error . " | Query: " . $sql);
                        return $default;
                    }
                }
                $data = [];
                while ($row = $result->fetch_assoc()) { 
                    $data[] = $row; 
                }
                if (isset($stmt)) $stmt->close();
                return $data;
            } catch (Exception $e) {
                error_log("Query Exception: " . $e->getMessage() . " | Query: " . $sql);
                return $default;
            }
        }

        global $hospital_id;
        
        // Filter by hospital - get donors who donated at this hospital
        $bloodData = safeQuery($conn, "SELECT u.blood_type as type, COUNT(DISTINCT u.user_id) as count 
                                       FROM donor_user u
                                       JOIN appointment a ON u.user_id = a.user_id
                                       JOIN blood_drive d ON a.drive_id = d.drive_id
                                       WHERE d.hospital_id = ?
                                       GROUP BY u.blood_type", [], [$hospital_id], 'i');

        // Filter donation history by hospital - Use appointment data
        $trendData = safeQuery($conn, "SELECT DATE_FORMAT(a.donation_date, '%M') as month, SUM(COALESCE(a.volume_ml, 450)) as volume 
                                       FROM appointment a
                                       JOIN blood_drive d ON a.drive_id = d.drive_id
                                       JOIN hospital h ON d.hospital_id = h.hospital_id
                                       WHERE d.hospital_id = ? AND h.hospital_id IS NOT NULL
                                       AND a.status = 'Completed'
                                       AND a.donation_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                                       AND a.volume_ml IS NOT NULL
                                       GROUP BY month ORDER BY a.donation_date ASC", [], [$hospital_id], 'i');

        // Filter performance by hospital
        $perfData = safeQuery($conn, "SELECT d.location_name as location, COUNT(a.appt_id) as count 
                                      FROM appointment a 
                                      JOIN blood_drive d ON a.drive_id = d.drive_id 
                                      WHERE d.hospital_id = ? AND a.status = 'Completed' 
                                      GROUP BY d.location_name LIMIT 5", [], [$hospital_id], 'i');

        // Blood Reports Analytics - REMOVED (using appointment data instead)
        $bloodReportTrendData = [];
        $hemoglobinTrendData = [];
        $bloodReportLocationData = [];
        $hemoglobinRangeData = [];

        sendJson('success', 'Report Generated', [
            'blood_types' => $bloodData, 
            'trends' => $trendData, 
            'performance' => $perfData,
            'blood_report_trends' => $bloodReportTrendData,
            'hemoglobin_trends' => $hemoglobinTrendData,
            'blood_report_locations' => $bloodReportLocationData,
            'hemoglobin_ranges' => $hemoglobinRangeData
        ]);
    } catch (Exception $e) {
        error_log("Analytics Error: " . $e->getMessage());
        sendJson('success', 'Report Generated (with errors)', [
            'blood_types' => [],
            'trends' => [],
            'performance' => [],
            'blood_report_trends' => [],
            'hemoglobin_trends' => [],
            'blood_report_locations' => [],
            'hemoglobin_ranges' => []
        ]);
    }
}

// Get Donations by Location Analytics (blood drive locations created by current hospital)
elseif ($action === 'get_donations_by_area') {
    try {
        // Check database connection
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            error_log("get_donations_by_location - Database connection failed");
            sendJson('error', 'Database connection failed. Please ensure XAMPP MySQL is running and the database "bloodknight_db" exists.');
        }
        
        // Ensure we're using the correct database
        $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
        if ($current_db !== 'bloodknight_db') {
            if (!$conn->select_db('bloodknight_db')) {
                error_log("get_donations_by_location - Failed to select bloodknight_db database");
                sendJson('error', 'Database selection failed. Please ensure the database "bloodknight_db" exists.');
            }
        }
        
        global $hospital_id;
        $timeFilter = $_POST['time_filter'] ?? 'month';
        
        // Calculate date range based on filter
        $dateCondition = '';
        // Handle new period format (week_1, month_1, year_1, etc.) or old format
        if (preg_match('/^week_(\d+)$/', $timeFilter, $matches)) {
            // Previous week (offset)
            $offset = (int)$matches[1];
            $today = new DateTime();
            $dayOfWeek = (int)$today->format('w'); // 0 = Sunday, 1 = Monday, etc.
            $daysToMonday = ($dayOfWeek == 0) ? 6 : ($dayOfWeek - 1); // Days to get to Monday
            $weekStart = clone $today;
            $weekStart->modify("-{$offset} weeks")->modify("-{$daysToMonday} days")->setTime(0, 0, 0);
            $weekEnd = clone $weekStart;
            $weekEnd->modify('+6 days');
            $dateCondition = "AND a.donation_date >= '" . $weekStart->format('Y-m-d') . "' AND a.donation_date <= '" . $weekEnd->format('Y-m-d') . "'";
        } elseif (preg_match('/^(\d+)month_ago$/', $timeFilter, $matches)) {
            // Previous month (offset)
            $offset = (int)$matches[1];
            $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL $offset MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL $offset MONTH))";
        } elseif (preg_match('/^year_(\d+)$/', $timeFilter, $matches)) {
            // Previous year (offset)
            $offset = (int)$matches[1];
            $year = date('Y') - $offset;
            $dateCondition = "AND YEAR(a.donation_date) = $year";
        } else {
            // Original format handling
            switch ($timeFilter) {
                case 'week':
                    // This week (last 7 days)
                    $dateCondition = "AND a.donation_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                    break;
                case 'month':
                    // This month (current month)
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(CURDATE()) AND MONTH(a.donation_date) = MONTH(CURDATE())";
                    break;
                case '1month_ago':
                    // 1 month ago (specific month)
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
                    break;
                case '2month_ago':
                    // 2 months ago
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 2 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 2 MONTH))";
                    break;
                case '3month_ago':
                    // 3 months ago
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 3 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 3 MONTH))";
                    break;
                case '4month_ago':
                    // 4 months ago
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 4 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 4 MONTH))";
                    break;
                case '5month_ago':
                    // 5 months ago
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 5 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 5 MONTH))";
                    break;
                case '6month_ago':
                    // 6 months ago
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 6 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 6 MONTH))";
                    break;
                case 'year':
                    // This year
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(CURDATE())";
                    break;
                default:
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(CURDATE()) AND MONTH(a.donation_date) = MONTH(CURDATE())";
            }
        }
        
        // Get donations by specific location extracted from blood drive location
        // Extract locations from bd.location_name or bd.full_address (blood drive location data)
        // This groups donations by where the blood drive was actually held
        // Only shows donations from blood drives created by the current hospital (filtered by bd.hospital_id = ?)
        $sql = "SELECT 
                    CASE 
                        -- Use location_name first, then fallback to full_address
                        -- Kota Kinabalu areas (check most specific first)
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Luyang%' THEN 'Luyang'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Penampang%' OR COALESCE(bd.location_name, bd.full_address, '') LIKE '%Bundusan%' THEN 'Penampang'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Likas%' THEN 'Likas'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Jalan Damai%' OR (COALESCE(bd.location_name, bd.full_address, '') LIKE '%Damai%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Kota Kinabalu%') THEN 'Damai'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Jalan Harapan%' OR COALESCE(bd.location_name, bd.full_address, '') LIKE '%Daya%' THEN 'Daya'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Jalan Lintas%' OR COALESCE(bd.location_name, bd.full_address, '') LIKE '%Lintas%' THEN 'Lintas'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Tanjung Aru%' OR COALESCE(bd.location_name, bd.full_address, '') LIKE '%Tg Aru%' THEN 'Tanjung Aru'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Inanam%' THEN 'Inanam'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Karamunsing%' THEN 'Karamunsing'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Kepayan%' THEN 'Kepayan'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Putatan%' THEN 'Putatan'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Jalan Lapangan Terbang%' OR COALESCE(bd.location_name, bd.full_address, '') LIKE '%Lapangan Terbang%' THEN 'Lapangan Terbang'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Sabah Trade Centre%' OR COALESCE(bd.location_name, bd.full_address, '') LIKE '%Sabah Trade%' THEN 'Sabah Trade Centre'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Mega City%' THEN 'Mega City'
                        -- Tawau areas
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Jalan Apas%' OR (COALESCE(bd.location_name, bd.full_address, '') LIKE '%Apas%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Tawau%') THEN 'Apas (Tawau)'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Tawau%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Square%' THEN 'Tawau Square'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Tawau%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Town%' THEN 'Tawau Town'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Tawau%' THEN 'Tawau'
                        -- Sandakan areas
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Jalan Utara%' OR (COALESCE(bd.location_name, bd.full_address, '') LIKE '%Utara%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Sandakan%') THEN 'Utara (Sandakan)'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Sandakan%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Town%' THEN 'Sandakan Town'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Sandakan%' THEN 'Sandakan'
                        -- Lahad Datu areas
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Jalan Segama%' OR (COALESCE(bd.location_name, bd.full_address, '') LIKE '%Segama%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Lahad Datu%') THEN 'Segama (Lahad Datu)'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Lahad Datu%' THEN 'Lahad Datu'
                        -- Kudat areas
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Jalan Sikuati%' OR (COALESCE(bd.location_name, bd.full_address, '') LIKE '%Sikuati%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Kudat%') THEN 'Sikuati (Kudat)'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Kudat%' THEN 'Kudat'
                        -- Keningau areas
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Keningau%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Town%' THEN 'Keningau Town'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Keningau%' THEN 'Keningau'
                        -- Beaufort areas
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Beaufort%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Town%' THEN 'Beaufort Town'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Beaufort%' THEN 'Beaufort'
                        -- Semporna areas
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Semporna%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Town%' THEN 'Semporna Town'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Semporna%' THEN 'Semporna'
                        -- Tuaran areas
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Tuaran%' AND COALESCE(bd.location_name, bd.full_address, '') LIKE '%Town%' THEN 'Tuaran Town'
                        WHEN COALESCE(bd.location_name, bd.full_address, '') LIKE '%Tuaran%' THEN 'Tuaran'
                        -- If location_name exists and doesn't match any pattern, use it directly (truncated if too long)
                        WHEN bd.location_name IS NOT NULL AND bd.location_name != '' THEN 
                            CASE 
                                WHEN LENGTH(bd.location_name) > 50 THEN CONCAT(LEFT(bd.location_name, 47), '...')
                                ELSE bd.location_name
                            END
                        -- Fallback: Extract area from full_address (part before first comma or before postcode)
                        WHEN bd.full_address IS NOT NULL AND bd.full_address != '' THEN 
                            TRIM(COALESCE(
                                SUBSTRING_INDEX(SUBSTRING_INDEX(bd.full_address, ',', -2), ',', 1),
                                SUBSTRING_INDEX(bd.full_address, ',', 1),
                                LEFT(bd.full_address, 50)
                            ))
                        -- Final fallback: Use location_name as-is or 'Unknown Location'
                        ELSE COALESCE(bd.location_name, 'Unknown Location')
                    END as area,
                    COUNT(a.appt_id) as count
                FROM appointment a
                JOIN blood_drive bd ON a.drive_id = bd.drive_id
                WHERE bd.hospital_id = ?
                AND a.status = 'Completed'
                AND a.donation_date IS NOT NULL
                $dateCondition
                GROUP BY area
                ORDER BY count DESC";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("get_donations_by_location - Prepare failed: " . $conn->error);
            sendJson('error', 'Database error: ' . $conn->error);
        }
        
        $stmt->bind_param("i", $hospital_id);
        if (!$stmt->execute()) {
            error_log("get_donations_by_location - Execute failed: " . $stmt->error);
            sendJson('error', 'Failed to fetch data: ' . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'location' => $row['area'], // Keep 'location' key for frontend compatibility
                'count' => $row['count']
            ];
        }
        
        sendJson('success', 'Donations by location loaded', $data);
    } catch (Exception $e) {
        error_log("get_donations_by_location Error: " . $e->getMessage());
        sendJson('error', 'Error loading donations by location: ' . $e->getMessage());
    }
}

// Get Peak Session Analysis
elseif ($action === 'get_peak_session_analysis') {
    try {
        // Check database connection
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            error_log("get_peak_session_analysis - Database connection failed");
            sendJson('error', 'Database connection failed. Please ensure XAMPP MySQL is running and the database "bloodknight_db" exists.');
        }
        
        // Ensure we're using the correct database
        $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
        if ($current_db !== 'bloodknight_db') {
            if (!$conn->select_db('bloodknight_db')) {
                error_log("get_peak_session_analysis - Failed to select bloodknight_db database");
                sendJson('error', 'Database selection failed. Please ensure the database "bloodknight_db" exists.');
            }
        }
        
        global $hospital_id;
        $timeFilter = $_POST['time_filter'] ?? 'month';
        
        // Calculate date range based on filter
        // For peak session analysis, filter by donation_date for completed donations only
        // This ensures weekly shows only last 7 days, monthly shows only current month, etc.
        $dateCondition = '';
        
        // Handle new period format (week_1, month_1, year_1, etc.) or old format
        if (preg_match('/^week_(\d+)$/', $timeFilter, $matches)) {
            // Previous week (offset)
            $offset = (int)$matches[1];
            $today = new DateTime();
            $dayOfWeek = (int)$today->format('w'); // 0 = Sunday, 1 = Monday, etc.
            $daysToMonday = ($dayOfWeek == 0) ? 6 : ($dayOfWeek - 1); // Days to get to Monday
            $weekStart = clone $today;
            $weekStart->modify("-{$offset} weeks")->modify("-{$daysToMonday} days")->setTime(0, 0, 0);
            $weekEnd = clone $weekStart;
            $weekEnd->modify('+6 days');
            $weekStartStr = $weekStart->format('Y-m-d');
            $weekEndStr = $weekEnd->format('Y-m-d');
            // Filter by donation_date for completed donations
            $dateCondition = "AND a.donation_date >= '$weekStartStr' AND a.donation_date <= '$weekEndStr'";
        } elseif (preg_match('/^(\d+)month_ago$/', $timeFilter, $matches)) {
            // Previous month (offset)
            $offset = (int)$matches[1];
            // Filter by donation_date for completed donations
            $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL $offset MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL $offset MONTH))";
        } elseif (preg_match('/^year_(\d+)$/', $timeFilter, $matches)) {
            // Previous year (offset)
            $offset = (int)$matches[1];
            $year = date('Y') - $offset;
            // Filter by donation_date for completed donations
            $dateCondition = "AND YEAR(a.donation_date) = $year";
        } else {
            // Original format handling
            switch ($timeFilter) {
                case 'week':
                    // This week (last 7 days) - filter by donation_date
                    $dateCondition = "AND a.donation_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                    break;
                case 'month':
                    // This month (current month) - filter by donation_date
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(CURDATE()) AND MONTH(a.donation_date) = MONTH(CURDATE())";
                    break;
                case '1month_ago':
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
                    break;
                case '2month_ago':
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 2 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 2 MONTH))";
                    break;
                case '3month_ago':
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 3 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 3 MONTH))";
                    break;
                case '4month_ago':
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 4 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 4 MONTH))";
                    break;
                case '5month_ago':
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 5 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 5 MONTH))";
                    break;
                case '6month_ago':
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 6 MONTH)) AND MONTH(a.donation_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 6 MONTH))";
                    break;
                case 'year':
                    // This year - filter by donation_date
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(CURDATE())";
                    break;
                default:
                    $dateCondition = "AND YEAR(a.donation_date) = YEAR(CURDATE()) AND MONTH(a.donation_date) = MONTH(CURDATE())";
            }
        }
        
        // Get appointments grouped by time period (hour slots)
        // Show all time slots from 6am to 9pm (06:00-21:00) for comparison
        // Count only completed donations that occurred during the specific time slot within the selected period
        // Filter by donation_date to ensure we're counting actual donations within the period (weekly/monthly/yearly)
        // Use a generated time series to ensure all slots are shown even with 0 appointments
        $sql = "SELECT 
                    CONCAT(
                        LPAD(time_slot.hour, 2, '0'), ':00 - ',
                        LPAD(time_slot.hour + 1, 2, '0'), ':00'
                    ) as time_period,
                    COALESCE(COUNT(a.appt_id), 0) as count
                FROM (
                    SELECT 6 as hour UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION
                    SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION
                    SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 UNION SELECT 20 UNION
                    SELECT 21
                ) as time_slot
                LEFT JOIN appointment a ON HOUR(a.selected_time) = time_slot.hour
                    AND a.status = 'Completed'
                    AND a.donation_date IS NOT NULL
                    " . (!empty($dateCondition) ? $dateCondition : "") . "
                LEFT JOIN blood_drive bd ON a.drive_id = bd.drive_id
                    AND bd.hospital_id = ?
                GROUP BY time_slot.hour
                ORDER BY time_slot.hour ASC";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("get_peak_session_analysis - Prepare failed: " . $conn->error);
            sendJson('error', 'Database error: ' . $conn->error);
        }
        
        $stmt->bind_param("i", $hospital_id);
        if (!$stmt->execute()) {
            error_log("get_peak_session_analysis - Execute failed: " . $stmt->error);
            sendJson('error', 'Failed to fetch data: ' . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'time_period' => $row['time_period'],
                'count' => $row['count']
            ];
        }
        
        sendJson('success', 'Peak session analysis loaded', $data);
    } catch (Exception $e) {
        error_log("get_peak_session_analysis Error: " . $e->getMessage());
        sendJson('error', 'Error loading peak session analysis: ' . $e->getMessage());
    }
}

// =============================================================
// 2. URGENT ALERTS (NOTIFICATIONS ONLY)
// =============================================================
elseif ($action === 'send_urgent_alert') {
    $target_type = $_POST['blood_type'] ?? '';
    $urgency = $_POST['urgency'];
    $message_body = $_POST['message'];
    $title = $_POST['title'] ?? 'Urgent Blood Request';

    // Handle "All Blood Types" - set to NULL for broadcast to everyone
    if (empty($target_type) || $target_type === '') {
        $target_type = null;
    } else {
    // Convert blood type format from "A+" to "A+" (already in correct format)
    // But ensure consistency - if backend receives "A Positive", convert to "A+"
    $target_type = str_replace(' Positive', '+', $target_type);
    $target_type = str_replace(' Negative', '-', $target_type);
    }

    // Check if title column exists in notification table, add if not
    $checkColumn = $conn->query("SHOW COLUMNS FROM notification LIKE 'title'");
    if ($checkColumn->num_rows == 0) {
        $conn->query("ALTER TABLE notification ADD COLUMN title VARCHAR(100) DEFAULT 'Urgent Blood Request' AFTER alert_id");
    }

    // Check if title column exists to determine which INSERT to use
    $hasTitle = false;
    $checkColumn = $conn->query("SHOW COLUMNS FROM notification LIKE 'title'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        $hasTitle = true;
    }

    if ($hasTitle) {
        if ($target_type === null) {
            // Use NULL directly in SQL for "All Blood Types"
            $stmt = $conn->prepare("INSERT INTO notification (hospital_id, title, target_blood_type, message_content, urgency_level) VALUES (?, ?, NULL, ?, ?)");
            if (!$stmt) {
                error_log("Failed to prepare send_urgent_alert query: " . $conn->error);
                sendJson('error', 'Failed to prepare query: ' . $conn->error);
            }
            $stmt->bind_param("isss", $hospital_id, $title, $message_body, $urgency);
        } else {
        $stmt = $conn->prepare("INSERT INTO notification (hospital_id, title, target_blood_type, message_content, urgency_level) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            error_log("Failed to prepare send_urgent_alert query: " . $conn->error);
            sendJson('error', 'Failed to prepare query: ' . $conn->error);
        }
        $stmt->bind_param("issss", $hospital_id, $title, $target_type, $message_body, $urgency);
        }
    } else {
        if ($target_type === null) {
            // Use NULL directly in SQL for "All Blood Types"
            $stmt = $conn->prepare("INSERT INTO notification (hospital_id, target_blood_type, message_content, urgency_level) VALUES (?, NULL, ?, ?)");
            if (!$stmt) {
                error_log("Failed to prepare send_urgent_alert query: " . $conn->error);
                sendJson('error', 'Failed to prepare query: ' . $conn->error);
            }
            $stmt->bind_param("iss", $hospital_id, $message_body, $urgency);
    } else {
    $stmt = $conn->prepare("INSERT INTO notification (hospital_id, target_blood_type, message_content, urgency_level) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            error_log("Failed to prepare send_urgent_alert query: " . $conn->error);
            sendJson('error', 'Failed to prepare query: ' . $conn->error);
        }
    $stmt->bind_param("isss", $hospital_id, $target_type, $message_body, $urgency);
        }
    }
    
    if (!$stmt->execute()) {
        error_log("Failed to execute send_urgent_alert query: " . $stmt->error);
        sendJson('error', 'Failed to create alert: ' . $stmt->error);
    }
    
    $stmt->close();
    
    // Count how many donors will see this notification
    if ($target_type === null) {
        // Broadcast to all active donors
        $countResult = $conn->query("SELECT COUNT(*) as count FROM donor_user WHERE status = 'Active'");
        $countRow = $countResult->fetch_assoc();
        $donorCount = $countRow['count'] ?? 0;
        $status_msg = "Urgent alert created successfully. All " . $donorCount . " active donor(s) will see this notification.";
    } else {
    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM donor_user WHERE blood_type = ? AND status = 'Active'");
    $countStmt->bind_param("s", $target_type);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $countRow = $countResult->fetch_assoc();
    $donorCount = $countRow['count'] ?? 0;
    $countStmt->close();
    $status_msg = "Urgent alert created successfully. " . $donorCount . " donor(s) with blood type " . $target_type . " will see this notification.";
    }
    
    sendJson('success', $status_msg);
}

// =============================================================
// 3. LEADERBOARD
// =============================================================
elseif ($action === 'get_leaderboard') {
    // Get leaderboard: hospitals with most donations for selected period
    // Only show Active hospitals
    // Only count completed appointments with volume_ml > 0 (successful donations)
    // Supports: weekly, monthly, yearly, total (all-time)
    try {
        // Check database connection
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            error_log("get_leaderboard - Database connection failed");
            sendJson('error', 'Database connection failed. Please ensure XAMPP MySQL is running.');
            return;
        }
        
        // Ensure we're using the correct database
        $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
        if ($current_db !== 'bloodknight_db') {
            if (!$conn->select_db('bloodknight_db')) {
                error_log("get_leaderboard - Failed to select bloodknight_db database");
                sendJson('error', 'Database selection failed.');
                return;
            }
        }
        
        // Get period parameter (default to 'weekly')
        $period = isset($_POST['period']) ? $_POST['period'] : 'weekly';
        
        // Build date condition based on period
        $dateCondition = '';
        switch ($period) {
            case 'weekly':
                // Last 7 days
                $dateCondition = "AND a.donation_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'monthly':
                // Current month
                $dateCondition = "AND YEAR(a.donation_date) = YEAR(CURDATE()) AND MONTH(a.donation_date) = MONTH(CURDATE())";
                break;
            case 'yearly':
                // Current year
                $dateCondition = "AND YEAR(a.donation_date) = YEAR(CURDATE())";
                break;
            case 'total':
                // All time (no date restriction)
                $dateCondition = "";
                break;
            default:
                // Default to weekly
                $dateCondition = "AND a.donation_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        }
        
        // Build SQL query with dynamic date condition
        $sql = "SELECT 
                    h.hospital_id,
                    h.hospital_name,
                    h.hospital_address,
                    h.hospital_type,
                    h.contact_number,
                    h.admin_name,
                    h.admin_email,
                    h.admin_phone,
                    COALESCE(SUM(CASE 
                        WHEN a.status = 'Completed' 
                        AND a.volume_ml IS NOT NULL 
                        AND a.volume_ml > 0 
                        " . (!empty($dateCondition) ? $dateCondition : "") . "
                        THEN a.volume_ml 
                        ELSE 0 
                    END), 0) / 1000.0 as total_volume_l,
                    COUNT(DISTINCT CASE 
                        WHEN a.status = 'Completed' 
                        AND a.volume_ml IS NOT NULL 
                        AND a.volume_ml > 0 
                        " . (!empty($dateCondition) ? $dateCondition : "") . "
                        THEN a.appt_id 
                    END) as total_donations
                FROM hospital h
                LEFT JOIN blood_drive bd ON h.hospital_id = bd.hospital_id
                LEFT JOIN appointment a ON bd.drive_id = a.drive_id
                    AND a.status = 'Completed'
                    AND a.volume_ml IS NOT NULL
                    AND a.volume_ml > 0
                    " . (!empty($dateCondition) ? $dateCondition : "") . "
                WHERE h.status = 'Active'
                GROUP BY h.hospital_id, h.hospital_name, h.hospital_address, h.hospital_type, 
                         h.contact_number, h.admin_name, h.admin_email, h.admin_phone
                ORDER BY total_donations DESC, total_volume_l DESC, h.hospital_name ASC";
        
        $result = $conn->query($sql);
        if (!$result) {
            error_log("Failed to execute leaderboard query: " . $conn->error);
            sendJson('error', 'Failed to load leaderboard: ' . $conn->error);
            return;
        }
        
        $leaderboard = [];
        while ($row = $result->fetch_assoc()) {
            // Ensure numeric values are properly formatted
            $row['total_volume_l'] = round(floatval($row['total_volume_l']), 2);
            $row['total_donations'] = intval($row['total_donations']);
            // Add rank for frontend display
            $leaderboard[] = $row;
        }
        
        // Log for debugging
        error_log("Leaderboard loaded: " . count($leaderboard) . " hospitals");
        
        sendJson('success', 'Leaderboard loaded', $leaderboard);
    } catch (Exception $e) {
        error_log("Error in get_leaderboard: " . $e->getMessage());
        sendJson('error', 'Failed to load leaderboard: ' . $e->getMessage());
    }
}

elseif ($action === 'get_hospital_contact') {
    $target_hospital_id = $_POST['hospital_id'] ?? null;
    
    if (!$target_hospital_id) {
        sendJson('error', 'Hospital ID is required');
    }
    
    $stmt = $conn->prepare("SELECT hospital_id, hospital_name, hospital_address, hospital_type, contact_number, admin_name, admin_email, admin_phone FROM hospital WHERE hospital_id = ? AND status = 'Active'");
    if (!$stmt) {
        error_log("Failed to prepare get_hospital_contact query: " . $conn->error);
        sendJson('error', 'Failed to prepare query: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $target_hospital_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        sendJson('success', 'Hospital contact loaded', $row);
    } else {
        sendJson('error', 'Hospital not found');
    }
    
    $stmt->close();
}

elseif ($action === 'send_blood_request') {
    global $hospital_id;
    $target_hospital_id = $_POST['target_hospital_id'] ?? null;
    $blood_type = $_POST['blood_type'] ?? '';
    $quantity = $_POST['quantity'] ?? 0;
    $urgency = $_POST['urgency'] ?? 'High';
    $message = $_POST['message'] ?? '';
    
    if (!$target_hospital_id || !$blood_type || !$quantity) {
        sendJson('error', 'Missing required fields');
    }
    
    // Get requesting hospital info
    $requestingStmt = $conn->prepare("SELECT hospital_name, admin_email, admin_name, contact_number FROM hospital WHERE hospital_id = ?");
    $requestingStmt->bind_param("i", $hospital_id);
    $requestingStmt->execute();
    $requestingResult = $requestingStmt->get_result();
    $requestingHospital = $requestingResult->fetch_assoc();
    $requestingStmt->close();
    
    // Get target hospital info
    $targetStmt = $conn->prepare("SELECT hospital_name, admin_email, admin_name FROM hospital WHERE hospital_id = ? AND status = 'Active'");
    $targetStmt->bind_param("i", $target_hospital_id);
    $targetStmt->execute();
    $targetResult = $targetStmt->get_result();
    $targetHospital = $targetResult->fetch_assoc();
    $targetStmt->close();
    
    if (!$targetHospital) {
        sendJson('error', 'Target hospital not found or inactive');
    }
    
    // Send email to target hospital
    $targetEmail = $targetHospital['admin_email'];
    $subject = "Blood Transfer Request - " . $urgency . " Priority";
    $emailBody = "
        <h2>Blood Transfer Request</h2>
        <p><strong>From:</strong> {$requestingHospital['hospital_name']}</p>
        <p><strong>Contact:</strong> {$requestingHospital['admin_name']} ({$requestingHospital['contact_number']})</p>
        <p><strong>Email:</strong> {$requestingHospital['admin_email']}</p>
        <hr>
        <p><strong>Blood Type Needed:</strong> {$blood_type}</p>
        <p><strong>Quantity:</strong> {$quantity} units</p>
        <p><strong>Urgency:</strong> {$urgency}</p>
        " . (!empty($message) ? "<p><strong>Message:</strong><br>{$message}</p>" : "") . "
        <hr>
        <p>Please contact the requesting hospital to coordinate the blood transfer.</p>
    ";
    
    $emailStatus = sendEmail($targetEmail, $targetHospital['admin_name'], $subject, $emailBody);
    
    if ($emailStatus['success']) {
        sendJson('success', 'Blood request sent successfully. The hospital has been notified via email.');
    } else {
        sendJson('error', 'Failed to send email notification: ' . $emailStatus['error']);
    }
}

// =============================================================
// 3. APPOINTMENTS (PENDING & ACTIVE)
// =============================================================

elseif ($action === 'get_appointments') {
    // Get PENDING appointments for this hospital
    try {
    global $hospital_id;
    
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            sendJson('error', 'Database connection failed', []);
        }
        
        $stmt = $conn->prepare("SELECT a.appt_id, u.full_name, u.blood_type, d.location_name, d.drive_date, a.selected_time 
                            FROM appointment a 
                            JOIN donor_user u ON a.user_id = u.user_id 
                            JOIN blood_drive d ON a.drive_id = d.drive_id 
                            WHERE d.hospital_id = ? AND a.status = 'Pending'");
        
        if (!$stmt) {
            error_log("Failed to prepare get_appointments query: " . $conn->error);
            sendJson('error', 'Failed to prepare query', []);
        }
        
    $stmt->bind_param("i", $hospital_id);
        
        if (!$stmt->execute()) {
            error_log("Failed to execute get_appointments query: " . $stmt->error);
            sendJson('error', 'Failed to execute query', []);
        }
        
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) { $data[] = $row; }
        $stmt->close();
    sendJson('success', 'Loaded', $data);
    } catch (Exception $e) {
        error_log("Error in get_appointments: " . $e->getMessage());
        sendJson('error', 'Server error: ' . $e->getMessage(), []);
    }
}

elseif ($action === 'get_active_roster') {
    // Get active roster for this hospital
    try {
    global $hospital_id;
        
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            sendJson('error', 'Database connection failed', []);
        }
    
    $stmt = $conn->prepare("SELECT 
                                a.appt_id, 
                                u.full_name, 
                                u.blood_type, 
                                d.location_name, 
                                d.drive_date, 
                                a.selected_time, 
                                COALESCE(a.source, 'Online') as source 
                            FROM appointment a
                            JOIN donor_user u ON a.user_id = u.user_id
                            JOIN blood_drive d ON a.drive_id = d.drive_id
                            WHERE d.hospital_id = ? AND a.status = 'Confirmed' 
                            ORDER BY d.drive_date ASC, a.selected_time ASC");
        
        if (!$stmt) {
            error_log("Failed to prepare get_active_roster query: " . $conn->error);
            sendJson('error', 'Failed to prepare query', []);
        }
        
    $stmt->bind_param("i", $hospital_id);
        
        if (!$stmt->execute()) {
            error_log("Failed to execute get_active_roster query: " . $stmt->error);
            sendJson('error', 'Failed to execute query', []);
        }
        
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) { 
        if (empty($row['source'])) {
            $row['source'] = 'Online';
        }
        $data[] = $row; 
    }
        $stmt->close();
    sendJson('success', 'Loaded active roster', $data);
    } catch (Exception $e) {
        error_log("Error in get_active_roster: " . $e->getMessage());
        sendJson('error', 'Server error: ' . $e->getMessage(), []);
    }
}

elseif ($action === 'get_cancelled_appointments') {
    // Get cancelled appointments for this hospital
    try {
        global $hospital_id;
        
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            sendJson('error', 'Database connection failed', []);
        }
    
        $stmt = $conn->prepare("SELECT 
                                    a.appt_id, 
                                    u.full_name, 
                                    u.blood_type, 
                                    d.location_name, 
                                    d.drive_date, 
                                    a.selected_time, 
                                    COALESCE(a.source, 'Online') as source,
                                    a.notes,
                                    a.status
                                FROM appointment a
                                JOIN donor_user u ON a.user_id = u.user_id
                                JOIN blood_drive d ON a.drive_id = d.drive_id
                                WHERE d.hospital_id = ? AND a.status = 'Cancelled' 
                                ORDER BY d.drive_date DESC, a.selected_time DESC");
        
        if (!$stmt) {
            error_log("Failed to prepare get_cancelled_appointments query: " . $conn->error);
            sendJson('error', 'Failed to prepare query', []);
        }
    
        $stmt->bind_param("i", $hospital_id);
        
        if (!$stmt->execute()) {
            error_log("Failed to execute get_cancelled_appointments query: " . $stmt->error);
            sendJson('error', 'Failed to execute query', []);
        }
    
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) { 
            if (empty($row['source'])) {
                $row['source'] = 'Online';
            }
            $data[] = $row; 
        }
        $stmt->close();
        sendJson('success', 'Loaded cancelled appointments', $data);
    } catch (Exception $e) {
        error_log("Error in get_cancelled_appointments: " . $e->getMessage());
        sendJson('error', 'Server error: ' . $e->getMessage(), []);
    }
}

elseif ($action === 'delete_cancelled_appointment') {
    global $hospital_id;
    $appt_id = $_POST['appt_id'] ?? null;
    
    if (!$appt_id) {
        sendJson('error', 'Appointment ID is required');
    }
    
    // Verify appointment belongs to this hospital and is cancelled
    $stmt = $conn->prepare("SELECT a.appt_id FROM appointment a 
                            JOIN blood_drive d ON a.drive_id = d.drive_id 
                            WHERE a.appt_id = ? AND d.hospital_id = ? AND a.status = 'Cancelled'");
    $stmt->bind_param("ii", $appt_id, $hospital_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        sendJson('error', 'Cancelled appointment not found or does not belong to this hospital');
    }
    
    // Delete the appointment
    $deleteStmt = $conn->prepare("DELETE FROM appointment WHERE appt_id = ?");
    $deleteStmt->bind_param("i", $appt_id);
    
    if ($deleteStmt->execute()) {
        sendJson('success', 'Cancelled appointment deleted successfully');
    } else {
        sendJson('error', 'Failed to delete appointment: ' . $conn->error);
    }
    
    $stmt->close();
    $deleteStmt->close();
}

elseif ($action === 'confirm_appt') {
    global $hospital_id;
    $id = $_POST['appt_id'];
    
    // 1. Get info to update timer and send email - verify it belongs to this hospital
    $sql = "SELECT u.user_id, u.email, u.full_name, a.selected_time, d.location_name, d.drive_date 
            FROM appointment a 
            JOIN donor_user u ON a.user_id = u.user_id 
            JOIN blood_drive d ON a.drive_id = d.drive_id 
            WHERE a.appt_id = ? AND d.hospital_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id, $hospital_id);
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

elseif ($action === 'reject_appt') {
    global $hospital_id;
    $id = $_POST['appt_id'];
    
    // 1. Verify appointment belongs to this hospital
    $sql = "SELECT u.user_id, u.email, u.full_name, a.selected_time, d.location_name, d.drive_date 
            FROM appointment a 
            JOIN donor_user u ON a.user_id = u.user_id 
            JOIN blood_drive d ON a.drive_id = d.drive_id 
            WHERE a.appt_id = ? AND d.hospital_id = ? AND a.status = 'Pending'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id, $hospital_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        $donor_email = $row['email'];
        
        // 2. Update appointment status to Cancelled
        $conn->query("UPDATE appointment SET status='Cancelled' WHERE appt_id=$id");
        
        // 3. Send Email notification
        $emailStatus = sendEmail($donor_email, $row['full_name'], "Appointment Rejected", "<p>Unfortunately, your appointment request at {$row['location_name']} on {$row['drive_date']} has been rejected. Please try booking another appointment.</p>");
        
        if ($emailStatus['success']) {
            sendJson('success', "Appointment rejected & donor notified.");
        } else {
            sendJson('success', "Appointment rejected, but email notification failed.");
        }
    } else {
        sendJson('error', 'Appointment not found or already processed.');
    }
}

elseif ($action === 'cancel_appt') {
    global $hospital_id;
    $id = $_POST['appt_id'] ?? null;
    $reason = $_POST['reason'] ?? '';
    
    if (!$id) {
        sendJson('error', 'Appointment ID is required.');
    }
    
    if (empty(trim($reason))) {
        sendJson('error', 'Cancellation reason is required.');
    }
    
    // 1. Verify appointment belongs to this hospital and is Confirmed
    $sql = "SELECT u.user_id, u.email, u.full_name, a.selected_time, d.location_name, d.drive_date 
            FROM appointment a 
            JOIN donor_user u ON a.user_id = u.user_id 
            JOIN blood_drive d ON a.drive_id = d.drive_id 
            WHERE a.appt_id = ? AND d.hospital_id = ? AND a.status = 'Confirmed'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id, $hospital_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        $donor_email = $row['email'];
        
        // 2. Update appointment status to Cancelled and store reason in notes
        $reasonEscaped = $conn->real_escape_string($reason);
        $updateSql = "UPDATE appointment SET status='Cancelled', notes=CONCAT(COALESCE(notes, ''), ' Cancellation reason: ', ?) WHERE appt_id=?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("si", $reason, $id);
        $updateStmt->execute();
        $updateStmt->close();
        
        // 3. Send Email notification with reason
        $emailBody = "<p>Your confirmed appointment at <strong>{$row['location_name']}</strong> on <strong>{$row['drive_date']}</strong> at <strong>{$row['selected_time']}</strong> has been cancelled.</p>";
        $emailBody .= "<p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>";
        $emailBody .= "<p>Please feel free to book another appointment at your convenience.</p>";
        
        $emailStatus = sendEmail($donor_email, $row['full_name'], "Appointment Cancelled", $emailBody);
        
        if ($emailStatus['success']) {
            sendJson('success', "Confirmed appointment cancelled & donor notified.");
        } else {
            sendJson('success', "Confirmed appointment cancelled, but email notification failed.");
        }
    } else {
        sendJson('error', 'Appointment not found, not confirmed, or does not belong to this hospital.');
    }
}

// =============================================================
// =============================================================
// 5. STATS & OVERVIEW
// =============================================================
elseif ($action === 'get_stats') {
    global $hospital_id;
    
    // Verify hospital_id is set
    if (!isset($hospital_id) || empty($hospital_id)) {
        error_log("get_stats: hospital_id not set. Session hospital_id: " . ($_SESSION['hospital_id'] ?? 'NOT SET'));
        sendJson('error', 'Hospital ID not found. Please login again.');
    }
    
    // Check database connection
    if (!isset($conn) || $conn === null || $conn->connect_error) {
        error_log("get_stats - Database connection failed for hospital_id: $hospital_id");
        sendJson('error', 'Database connection failed. Please ensure XAMPP MySQL is running.');
    }
    
    // Ensure we're using the correct database
    $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
    if ($current_db !== 'bloodknight_db') {
        if (!$conn->select_db('bloodknight_db')) {
            error_log("get_stats - Failed to select bloodknight_db database");
            sendJson('error', 'Database selection failed.');
        }
    }
    
    // Count donors who have appointments at this hospital
    $donorsStmt = $conn->prepare("SELECT COUNT(DISTINCT u.user_id) as c 
                                  FROM donor_user u
                                  JOIN appointment a ON u.user_id = a.user_id
                                  JOIN blood_drive d ON a.drive_id = d.drive_id
                                  WHERE d.hospital_id = ?");
    $donorsStmt->bind_param("i", $hospital_id);
    if (!$donorsStmt->execute()) {
        error_log("get_stats - Failed to execute donors query: " . $donorsStmt->error);
        sendJson('error', 'Failed to load stats: ' . $donorsStmt->error);
    }
    $donors = $donorsStmt->get_result()->fetch_assoc()['c'] ?? 0;
    
    // Pending appointments for this hospital
    $pendingStmt = $conn->prepare("SELECT COUNT(*) as c 
                                   FROM appointment a 
                                   JOIN blood_drive d ON a.drive_id = d.drive_id 
                                   WHERE d.hospital_id = ? AND a.status='Pending'");
    if (!$pendingStmt) {
        error_log("get_stats - Failed to prepare pending query: " . $conn->error);
        sendJson('error', 'Failed to prepare query: ' . $conn->error);
    }
    $pendingStmt->bind_param("i", $hospital_id);
    if (!$pendingStmt->execute()) {
        error_log("get_stats - Failed to execute pending query: " . $pendingStmt->error);
        sendJson('error', 'Failed to load stats: ' . $pendingStmt->error);
    }
    $pending = $pendingStmt->get_result()->fetch_assoc()['c'] ?? 0;
    
    // Volume from completed appointments at this hospital
    $volumeStmt = $conn->prepare("SELECT COALESCE(SUM(a.volume_ml), 0) as v 
                                  FROM appointment a
                                  JOIN blood_drive d ON a.drive_id = d.drive_id
                                  WHERE d.hospital_id = ? 
                                  AND a.status = 'Completed'
                                  AND a.volume_ml IS NOT NULL
                                  AND a.volume_ml > 0");
    if (!$volumeStmt) {
        error_log("get_stats - Failed to prepare volume query: " . $conn->error);
        sendJson('error', 'Failed to prepare query: ' . $conn->error);
    }
    $volumeStmt->bind_param("i", $hospital_id);
    if (!$volumeStmt->execute()) {
        error_log("get_stats - Failed to execute volume query: " . $volumeStmt->error);
        sendJson('error', 'Failed to load stats: ' . $volumeStmt->error);
    }
    $volume = $volumeStmt->get_result()->fetch_assoc()['v'] ?? 0;
    
    // Confirmed appointments for this hospital
    $confirmedStmt = $conn->prepare("SELECT COUNT(*) as c 
                                     FROM appointment a 
                                     JOIN blood_drive d ON a.drive_id = d.drive_id 
                                     WHERE d.hospital_id = ? AND a.status='Confirmed'");
    if (!$confirmedStmt) {
        error_log("get_stats - Failed to prepare confirmed query: " . $conn->error);
        sendJson('error', 'Failed to prepare query: ' . $conn->error);
    }
    $confirmedStmt->bind_param("i", $hospital_id);
    if (!$confirmedStmt->execute()) {
        error_log("get_stats - Failed to execute confirmed query: " . $confirmedStmt->error);
        sendJson('error', 'Failed to load stats: ' . $confirmedStmt->error);
    }
    $confirmed_appt = $confirmedStmt->get_result()->fetch_assoc()['c'] ?? 0;
    
    // Total donations at this hospital
    $totalDonationsStmt = $conn->prepare("SELECT COUNT(*) as c 
                                          FROM appointment a 
                                          JOIN blood_drive d ON a.drive_id = d.drive_id 
                                          WHERE d.hospital_id = ? AND a.status='Completed' AND a.volume_ml > 0");
    if (!$totalDonationsStmt) {
        error_log("get_stats - Failed to prepare total donations query: " . $conn->error);
        sendJson('error', 'Failed to prepare query: ' . $conn->error);
    }
    $totalDonationsStmt->bind_param("i", $hospital_id);
    if (!$totalDonationsStmt->execute()) {
        error_log("get_stats - Failed to execute total donations query: " . $totalDonationsStmt->error);
        sendJson('error', 'Failed to load stats: ' . $totalDonationsStmt->error);
    }
    $total_donations = $totalDonationsStmt->get_result()->fetch_assoc()['c'] ?? 0;
    
    // Today's Appointments - for this hospital (only confirmed/approved appointments scheduled for today)
    $rosterStmt = $conn->prepare("SELECT u.user_id, u.full_name, d.location_name, d.drive_date, a.selected_time, a.status
                                  FROM appointment a
                                  JOIN donor_user u ON a.user_id = u.user_id
                                  JOIN blood_drive d ON a.drive_id = d.drive_id
                                  WHERE d.hospital_id = ? 
                                  AND d.drive_date = CURDATE()
                                  AND a.status = 'Confirmed'
                                  ORDER BY a.selected_time ASC");
    $rosterStmt->bind_param("i", $hospital_id);
    if (!$rosterStmt->execute()) {
        error_log("get_stats - Failed to execute roster query: " . $rosterStmt->error);
        sendJson('error', 'Failed to load stats: ' . $rosterStmt->error);
    }
    $confirmed_roster = $rosterStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Today's Completed Donations - for this hospital (donations completed today)
    $historyStmt = $conn->prepare("SELECT 
                                       u.user_id, 
                                       u.full_name, 
                                       a.donation_date, 
                                       a.selected_time,
                                       COALESCE(a.volume_ml, 450) as volume_ml 
                                   FROM appointment a
                                   JOIN donor_user u ON a.user_id = u.user_id
                                   JOIN blood_drive d ON a.drive_id = d.drive_id
                                   JOIN hospital h ON d.hospital_id = h.hospital_id
                                   WHERE d.hospital_id = ? 
                                   AND h.hospital_id IS NOT NULL
                                   AND a.status = 'Completed'
                                   AND a.donation_date = CURDATE()
                                   AND a.volume_ml IS NOT NULL
                                   AND a.volume_ml > 0
                                   ORDER BY a.selected_time DESC");
    $historyStmt->bind_param("i", $hospital_id);
    if (!$historyStmt->execute()) {
        error_log("get_stats - Failed to execute history query: " . $historyStmt->error);
        sendJson('error', 'Failed to load stats: ' . $historyStmt->error);
    }
    $donation_history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);

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

// NEW: Get Today's Drives for Dropdown
elseif ($action === 'get_todays_drives') {
    global $hospital_id;
    $stmt = $conn->prepare("SELECT drive_id, location_name, drive_date, start_time, end_time FROM blood_drive WHERE drive_date = CURDATE() AND hospital_id = ? AND status = 'Upcoming'");
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $drives = $result->fetch_all(MYSQLI_ASSOC);
    sendJson('success', 'Today\'s drives loaded', $drives);
}

elseif ($action === 'get_upcoming_drives') {
    global $hospital_id;
    // Get all drives (upcoming and past) for this hospital, ordered by date, including location data
    // Note: latitude and longitude columns may not exist, so we only select columns that definitely exist
    $stmt = $conn->prepare("SELECT drive_id, location_name, drive_date, start_time, end_time, status, full_address, coordinates FROM blood_drive WHERE hospital_id = ? AND status IN ('Upcoming', 'Active', 'Completed') ORDER BY drive_date DESC");
    $stmt->bind_param("i", $hospital_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $drives = $result->fetch_all(MYSQLI_ASSOC);
    
    // Try to extract latitude/longitude from coordinates if it's in "lat,lng" format
    foreach ($drives as &$drive) {
        if (!empty($drive['coordinates']) && strpos($drive['coordinates'], ',') !== false) {
            $coords = explode(',', $drive['coordinates']);
            if (count($coords) >= 2) {
                $drive['latitude'] = trim($coords[0]);
                $drive['longitude'] = trim($coords[1]);
            }
        }
    }
    unset($drive); // Break reference
    
    sendJson('success', 'Drives loaded', $drives);
}

// NEW: Get Participants for a specific drive
elseif ($action === 'get_drive_participants') {
    $drive_id = $_POST['drive_id'] ?? 0;
    
    $stmt = $conn->prepare("
        SELECT u.user_id, u.full_name, u.email, u.blood_type, a.appt_id, a.status 
        FROM appointment a 
        JOIN donor_user u ON a.user_id = u.user_id 
        WHERE a.drive_id = ? AND a.status IN ('Pending', 'Confirmed')
        ORDER BY u.full_name ASC
    ");
    $stmt->bind_param("i", $drive_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $participants = $result->fetch_all(MYSQLI_ASSOC);
    
    if (empty($participants)) {
        sendJson('success', 'No participants found for this drive', []);
    } else {
        sendJson('success', 'Participants loaded', $participants);
    }
}

// === SEARCH DONOR BY IC ===
elseif ($action === 'search_donor_by_ic') {
    $ic_number = $_POST['ic_number'] ?? '';
    $drive_id = $_POST['drive_id'] ?? 0;
    
    if (empty($ic_number)) {
        sendJson('error', 'IC number is required');
    }
    
    // Clean IC number (remove dashes and spaces)
    $ic_cleaned = preg_replace('/[^0-9]/', '', $ic_number);
    
    // Search for donor by IC number who has an appointment for this drive
    $stmt = $conn->prepare("
        SELECT u.user_id, u.full_name, u.email, u.blood_type, u.ic_number, a.appt_id, a.status 
        FROM donor_user u
        JOIN appointment a ON u.user_id = a.user_id
        WHERE REPLACE(REPLACE(u.ic_number, '-', ''), ' ', '') = ? 
        AND a.drive_id = ? 
        AND a.status IN ('Pending', 'Confirmed')
        LIMIT 1
    ");
    $stmt->bind_param("si", $ic_cleaned, $drive_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        sendJson('success', 'Donor found', [
            'user_id' => $row['user_id'],
            'full_name' => $row['full_name'],
            'blood_type' => $row['blood_type'],
            'email' => $row['email']
        ]);
    } else {
        sendJson('error', 'Donor not found or not registered for this blood drive');
    }
}

// === PROCESS SUCCESSFUL DONATION ===
elseif ($action === 'process_successful_donation') {
    $user_id = $_POST['user_id'] ?? '';
    $vol = $_POST['volume'] ?? 0; 
    $notes = $_POST['notes'] ?? 'Successful donation';
    $drive_id = $_POST['drive_id'] ?? ''; 
    
    if (empty($user_id) || empty($vol)) { 
        sendJson('error', 'User ID and volume are required'); 
    }
    
    if (empty($drive_id)) {
        sendJson('error', 'Blood drive selection is required');
    }

    // Verify user exists in database
    $checkUser = $conn->prepare("SELECT user_id FROM donor_user WHERE user_id = ?");
    $checkUser->bind_param("i", $user_id);
    $checkUser->execute();
    $userResult = $checkUser->get_result();
    
    if ($userResult->num_rows === 0) {
        sendJson('error', 'User not found in database. Please search for the donor again.');
    }
    $checkUser->close();

    $conn->begin_transaction();
    try {
        // Update appointment status to Completed
        $stmt = $conn->prepare("UPDATE appointment SET status = 'Completed', donation_date = CURDATE(), volume_ml = ?, notes = ? WHERE user_id = ? AND drive_id = ? AND status IN ('Pending', 'Confirmed')");
        $stmt->bind_param("isii", $vol, $notes, $user_id, $drive_id);
        $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            throw new Exception('No matching appointment found for this user and blood drive');
        }
        
        // Update User Stats (Rank/Eligibility)
        $checkCol = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'last_donation_date'");
        if ($checkCol && $checkCol->num_rows > 0) {
            $checkTotal = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'total_donations'");
            if($checkTotal && $checkTotal->num_rows > 0) {
                 $updUser = $conn->prepare("UPDATE donor_user SET last_donation_date = NOW(), total_donations = total_donations + 1 WHERE user_id = ?");
            } else {
                 $updUser = $conn->prepare("UPDATE donor_user SET last_donation_date = NOW() WHERE user_id = ?");
            }
            $updUser->bind_param("i", $user_id);
            $updUser->execute();
            $updUser->close();
        }

        $conn->commit();
        sendJson('success', 'Successful donation processed. Records updated.');

    } catch (Exception $e) {
        $conn->rollback();
        sendJson('error', 'Database transaction failed: ' . $e->getMessage());
    }
}

// === PROCESS FAILED DONATION ===
elseif ($action === 'process_failed_donation') {
    $user_id = $_POST['user_id'] ?? '';
    $failure_reasons = $_POST['failure_reasons'] ?? [];
    $notes = $_POST['notes'] ?? '';
    $drive_id = $_POST['drive_id'] ?? ''; 
    
    if (empty($user_id)) { 
        sendJson('error', 'User ID is required'); 
    }
    
    if (empty($drive_id)) {
        sendJson('error', 'Blood drive selection is required');
    }
    
    if (empty($failure_reasons) || !is_array($failure_reasons) || count($failure_reasons) === 0) {
        sendJson('error', 'At least one failure reason must be selected');
    }
    
    // Combine failure reasons into a string
    $failure_reasons_str = implode(', ', $failure_reasons);
    $combined_notes = $failure_reasons_str;
    if (!empty($notes)) {
        $combined_notes .= ' | ' . $notes;
    }

    $conn->begin_transaction();
    try {
        // Check if failure_reason column exists in appointment table, add if not
        $checkCol = $conn->query("SHOW COLUMNS FROM appointment LIKE 'failure_reason'");
        if ($checkCol->num_rows == 0) {
            $conn->query("ALTER TABLE appointment ADD COLUMN failure_reason TEXT NULL");
        }
        
        // Update appointment status to 'Failed' (or 'Did Not Show' if that's the status used)
        // First check if 'Failed' status exists in ENUM
        $checkStatus = $conn->query("SHOW COLUMNS FROM appointment WHERE Field = 'status'");
        $statusRow = $checkStatus->fetch_assoc();
        $statusEnum = $statusRow['Type'];
        
        // Use 'Did Not Show' if 'Failed' doesn't exist, otherwise use 'Failed'
        $failedStatus = (strpos($statusEnum, "'Failed'") !== false) ? 'Failed' : 'Did Not Show';
        
        $stmt = $conn->prepare("UPDATE appointment SET status = ?, donation_date = CURDATE(), notes = ?, failure_reason = ? WHERE user_id = ? AND drive_id = ? AND status IN ('Pending', 'Confirmed')");
        $stmt->bind_param("sssii", $failedStatus, $combined_notes, $failure_reasons_str, $user_id, $drive_id);
        $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            throw new Exception('No matching appointment found');
        }

        $conn->commit();
        sendJson('success', 'Failed donation processed. Records updated.');

    } catch (Exception $e) {
        $conn->rollback();
        sendJson('error', 'Database transaction failed: ' . $e->getMessage());
    }
}

// === MARK MISSED APPOINTMENTS ===
elseif ($action === 'mark_missed_appointments') {
    try {
        // Check if 'Missed' status exists in appointment table
        $checkStatus = $conn->query("SHOW COLUMNS FROM appointment WHERE Field = 'status'");
        $statusRow = $checkStatus->fetch_assoc();
        $statusEnum = $statusRow['Type'];
        
        // Use 'Did Not Show' if 'Missed' doesn't exist
        $missedStatus = (strpos($statusEnum, "'Missed'") !== false) ? 'Missed' : 'Did Not Show';
        
        // Mark appointments as missed where:
        // - Status is Pending or Confirmed
        // - Drive date is in the past (before today)
        $stmt = $conn->prepare("
            UPDATE appointment a
            JOIN blood_drive bd ON a.drive_id = bd.drive_id
            SET a.status = ?
            WHERE a.status IN ('Pending', 'Confirmed')
            AND bd.drive_date < CURDATE()
        ");
        $stmt->bind_param("s", $missedStatus);
        $stmt->execute();
        
        $affected = $stmt->affected_rows;
        sendJson('success', "Marked $affected appointment(s) as missed", ['affected' => $affected]);
        
    } catch (Exception $e) {
        sendJson('error', 'Failed to mark missed appointments: ' . $e->getMessage());
    }
}

// === PROCESS DONATION (LEGACY - KEEP FOR BACKWARD COMPATIBILITY) ===
elseif ($action === 'process_donation') {
    $email = $_POST['donor_email'] ?? '';
    $user_id = $_POST['user_id'] ?? '';
    $vol = $_POST['volume']; 
    $notes = $_POST['notes'] ?? 'Standard donation';
    $drive_id = $_POST['drive_id'] ?? ''; 
    
    if (empty($vol)) { sendJson('error', 'Volume is required'); }

    if (!empty($user_id)) {
        $uRes = $conn->query("SELECT user_id FROM donor_user WHERE user_id = " . intval($user_id));
    } else {
        $safe_email = $conn->real_escape_string($email);
        $uRes = $conn->query("SELECT user_id FROM donor_user WHERE email = '$safe_email'");
    }

    if ($uRes && $row = $uRes->fetch_assoc()) {
        $found_user_id = $row['user_id'];

        $conn->begin_transaction();
        try {
            // UPDATE appointment table directly (don't insert into non-existent history table)
            if ($drive_id) {
                 // Updates specific appointment
                 $stmt = $conn->prepare("UPDATE appointment SET status = 'Completed', donation_date = CURDATE(), volume_ml = ?, notes = ? WHERE user_id = ? AND drive_id = ? AND status IN ('Pending', 'Confirmed')");
                 $stmt->bind_param("isii", $vol, $notes, $found_user_id, $drive_id);
            } else {
                // Fallback for general updates
                $stmt = $conn->prepare("UPDATE appointment SET status = 'Completed', donation_date = CURDATE(), volume_ml = ?, notes = ? WHERE user_id = ? AND status IN ('Pending', 'Confirmed') LIMIT 1");
                $stmt->bind_param("isi", $vol, $notes, $found_user_id);
            }
            $stmt->execute();
            
            // Update User Stats (Rank/Eligibility)
            $checkCol = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'last_donation_date'");
            if ($checkCol && $checkCol->num_rows > 0) {
                $checkTotal = $conn->query("SHOW COLUMNS FROM donor_user LIKE 'total_donations'");
                if($checkTotal && $checkTotal->num_rows > 0) {
                     $updUser = $conn->prepare("UPDATE donor_user SET last_donation_date = NOW(), total_donations = total_donations + 1 WHERE user_id = ?");
                } else {
                     $updUser = $conn->prepare("UPDATE donor_user SET last_donation_date = NOW() WHERE user_id = ?");
                }
                $updUser->bind_param("i", $found_user_id);
                $updUser->execute();
            }

            $conn->commit();
            sendJson('success', 'Donation processed successfully. Records updated.');

        } catch (Exception $e) {
            $conn->rollback();
            sendJson('error', 'Database transaction failed: ' . $e->getMessage());
        }
    } else {
        sendJson('error', 'User not found. Please check the ID or Email.');
    }
}

elseif ($action === 'create_drive') {
    try {
    global $hospital_id;
        
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            error_log("Database connection error in create_drive: " . ($conn ? $conn->connect_error : "Connection is null"));
            sendJson('error', 'Database connection failed. Please check your database connection.');
        }
        
        // Ensure we're using the correct database
        if ($conn && !$conn->connect_error) {
            $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
            if ($current_db !== 'bloodknight_db') {
                if (!$conn->select_db('bloodknight_db')) {
                    error_log("Failed to select bloodknight_db database");
                    sendJson('error', 'Database selection failed. Please check your database configuration.');
                }
            }
        }
        
        $loc = $_POST['location'] ?? ''; 
        $date = $_POST['date'] ?? ''; 
        $start = $_POST['start_time'] ?? ''; 
        $end = $_POST['end_time'] ?? '';
        $latitude = $_POST['latitude'] ?? null;
        $longitude = $_POST['longitude'] ?? null;
        $full_address = $_POST['full_address'] ?? '';
        
        if (empty($loc) || empty($date) || empty($start) || empty($end)) {
            sendJson('error', 'All fields are required');
        }
        
        // Build coordinates string if provided
        $coordinates = null;
        if ($latitude !== null && $longitude !== null) {
            $coordinates = "$latitude,$longitude";
        }
        
        // Check if latitude/longitude columns exist
        $checkColumns = $conn->query("SHOW COLUMNS FROM blood_drive LIKE 'latitude'");
        $hasLatLon = $checkColumns && $checkColumns->num_rows > 0;
        
        // Check if full_address column exists, if not add it
        $checkFullAddress = $conn->query("SHOW COLUMNS FROM blood_drive LIKE 'full_address'");
        $hasFullAddress = $checkFullAddress && $checkFullAddress->num_rows > 0;
        
        if (!$hasFullAddress) {
            // Add full_address column if it doesn't exist
            $alterSql = "ALTER TABLE blood_drive ADD COLUMN full_address TEXT NULL AFTER location_name";
            if (!$conn->query($alterSql)) {
                error_log("Failed to add full_address column: " . $conn->error);
                // Continue without full_address if column addition fails
    } else {
                $hasFullAddress = true;
    }
}

        // Check for duplicate blood drive: same hospital, same location, same date, overlapping times
        // Match by location_name, or by full_address if both are provided
        $duplicateCheckSql = "SELECT drive_id, location_name, drive_date, start_time, end_time";
        if ($hasFullAddress) {
            $duplicateCheckSql .= ", full_address";
        }
        $duplicateCheckSql .= " FROM blood_drive 
                              WHERE hospital_id = ? 
                              AND drive_date = ? 
                              AND (location_name = ?";
        
        // If full_address is provided, also check by full_address
        if ($hasFullAddress && !empty($full_address)) {
            $duplicateCheckSql .= " OR (full_address = ? AND full_address IS NOT NULL AND full_address != '')";
        }
        
        $duplicateCheckSql .= ") AND status != 'Cancelled'";
        
        $duplicateStmt = $conn->prepare($duplicateCheckSql);
        if (!$duplicateStmt) {
            error_log("Failed to prepare duplicate check query: " . $conn->error);
            sendJson('error', 'Failed to validate drive: ' . $conn->error);
        }
        
        if ($hasFullAddress && !empty($full_address)) {
            $duplicateStmt->bind_param("isss", $hospital_id, $date, $loc, $full_address);
        } else {
            $duplicateStmt->bind_param("iss", $hospital_id, $date, $loc);
        }
        
        $duplicateStmt->execute();
        $duplicateResult = $duplicateStmt->get_result();
        
        // Check for time overlaps
        while ($existingDrive = $duplicateResult->fetch_assoc()) {
            $existingStart = $existingDrive['start_time'];
            $existingEnd = $existingDrive['end_time'];
            
            // Check if new time slot overlaps with existing time slot
            // Overlap occurs if: new_start < existing_end AND new_end > existing_start
            if ($start < $existingEnd && $end > $existingStart) {
                $duplicateStmt->close();
                $locationInfo = $existingDrive['location_name'];
                if ($hasFullAddress && !empty($existingDrive['full_address'])) {
                    $locationInfo = $existingDrive['full_address'];
                }
                sendJson('error', 'A blood drive already exists at "' . $locationInfo . '" on ' . $date . ' with overlapping time (' . $existingStart . ' - ' . $existingEnd . '). Please choose a different time or location.');
            }
        }
        
        $duplicateStmt->close();
        
        if ($hasLatLon && $hasFullAddress) {
            // Insert with latitude, longitude, and full_address columns
            $stmt = $conn->prepare("INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, full_address, coordinates, latitude, longitude, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Upcoming')");
            
            if (!$stmt) {
                error_log("Failed to prepare create_drive query: " . $conn->error);
                sendJson('error', 'Failed to prepare query: ' . $conn->error);
            }
            
            $stmt->bind_param("issssssdd", $hospital_id, $date, $start, $end, $loc, $full_address, $coordinates, $latitude, $longitude);
        } elseif ($hasLatLon) {
            // Insert with latitude and longitude columns (no full_address)
            $stmt = $conn->prepare("INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, coordinates, latitude, longitude, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Upcoming')");
            
            if (!$stmt) {
                error_log("Failed to prepare create_drive query: " . $conn->error);
                sendJson('error', 'Failed to prepare query: ' . $conn->error);
            }
            
            $stmt->bind_param("isssssdd", $hospital_id, $date, $start, $end, $loc, $coordinates, $latitude, $longitude);
        } elseif ($hasFullAddress) {
            // Insert with full_address but no latitude/longitude columns
            $stmt = $conn->prepare("INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, full_address, coordinates, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Upcoming')");
            
            if (!$stmt) {
                error_log("Failed to prepare create_drive query: " . $conn->error);
                sendJson('error', 'Failed to prepare query: ' . $conn->error);
            }
            
            $stmt->bind_param("issssss", $hospital_id, $date, $start, $end, $loc, $full_address, $coordinates);
    } else {
            // Insert with coordinates only (no latitude/longitude, no full_address)
            $stmt = $conn->prepare("INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, coordinates, status) VALUES (?, ?, ?, ?, ?, ?, 'Upcoming')");
            
            if (!$stmt) {
                error_log("Failed to prepare create_drive query: " . $conn->error);
                sendJson('error', 'Failed to prepare query: ' . $conn->error);
            }
            
            $stmt->bind_param("isssss", $hospital_id, $date, $start, $end, $loc, $coordinates);
        }
        
        if (!$stmt->execute()) {
            error_log("Failed to execute create_drive query: " . $stmt->error);
            sendJson('error', 'Failed to create drive: ' . $stmt->error);
        }
        
        $stmt->close();
        sendJson('success', 'Drive Created');
    } catch (Exception $e) {
        error_log("Error in create_drive: " . $e->getMessage());
        sendJson('error', 'Server error: ' . $e->getMessage());
    }
}

// Edit Blood Drive
elseif ($action === 'edit_drive') {
    try {
        global $hospital_id;
        
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            error_log("Database connection error in edit_drive: " . ($conn ? $conn->connect_error : "Connection is null"));
            sendJson('error', 'Database connection failed. Please check your database connection.');
        }
        
        // Ensure we're using the correct database
        if ($conn && !$conn->connect_error) {
            $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
            if ($current_db !== 'bloodknight_db') {
                if (!$conn->select_db('bloodknight_db')) {
                    error_log("Failed to select bloodknight_db database");
                    sendJson('error', 'Database selection failed. Please check your database configuration.');
                }
            }
        }
        
        $driveId = $_POST['drive_id'] ?? null;
        $loc = $_POST['location'] ?? ''; 
        $date = $_POST['date'] ?? ''; 
        $start = $_POST['start_time'] ?? ''; 
        $end = $_POST['end_time'] ?? '';
        $latitude = $_POST['latitude'] ?? null;
        $longitude = $_POST['longitude'] ?? null;
        
        if (!$driveId) {
            sendJson('error', 'Drive ID is required.');
        }
        
        if (empty($loc) || empty($date) || empty($start) || empty($end)) {
            sendJson('error', 'Location, date, start time, and end time are required.');
        }
        
        // Verify the drive belongs to this hospital
        $verifyStmt = $conn->prepare("SELECT drive_id FROM blood_drive WHERE drive_id = ? AND hospital_id = ?");
        if (!$verifyStmt) {
            error_log("Failed to prepare verify query: " . $conn->error);
            sendJson('error', 'Failed to verify drive: ' . $conn->error);
        }
        $verifyStmt->bind_param("ii", $driveId, $hospital_id);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();
        
        if ($verifyResult->num_rows === 0) {
            $verifyStmt->close();
            sendJson('error', 'Blood drive not found or you do not have permission to edit it.');
        }
        $verifyStmt->close();
        
        // Build coordinates string if latitude and longitude are provided
        $coordinates = null;
        if ($latitude !== null && $longitude !== null && $latitude !== '' && $longitude !== '') {
            $coordinates = $latitude . ',' . $longitude;
        }
        
        // Check if latitude and longitude columns exist
        $hasLatLon = false;
        $columnCheck = $conn->query("SHOW COLUMNS FROM blood_drive LIKE 'latitude'");
        if ($columnCheck && $columnCheck->num_rows > 0) {
            $hasLatLon = true;
        }
        
        // Update the blood drive
        if ($hasLatLon && $latitude !== null && $longitude !== null && $latitude !== '' && $longitude !== '') {
            // Update with latitude and longitude
            $stmt = $conn->prepare("UPDATE blood_drive SET drive_date = ?, start_time = ?, end_time = ?, location_name = ?, coordinates = ?, latitude = ?, longitude = ? WHERE drive_id = ? AND hospital_id = ?");
            if (!$stmt) {
                error_log("Failed to prepare edit_drive query: " . $conn->error);
                sendJson('error', 'Failed to prepare query: ' . $conn->error);
            }
            $stmt->bind_param("sssssddii", $date, $start, $end, $loc, $coordinates, $latitude, $longitude, $driveId, $hospital_id);
        } else {
            // Update without latitude and longitude
            $stmt = $conn->prepare("UPDATE blood_drive SET drive_date = ?, start_time = ?, end_time = ?, location_name = ?, coordinates = ? WHERE drive_id = ? AND hospital_id = ?");
            if (!$stmt) {
                error_log("Failed to prepare edit_drive query: " . $conn->error);
                sendJson('error', 'Failed to prepare query: ' . $conn->error);
            }
            $stmt->bind_param("sssssii", $date, $start, $end, $loc, $coordinates, $driveId, $hospital_id);
        }
        
        if (!$stmt->execute()) {
            error_log("Failed to execute edit_drive query: " . $stmt->error);
            sendJson('error', 'Failed to update drive: ' . $stmt->error);
        }
        
        $stmt->close();
        sendJson('success', 'Blood drive updated successfully');
    } catch (Exception $e) {
        error_log("Error in edit_drive: " . $e->getMessage());
        sendJson('error', 'Server error: ' . $e->getMessage());
    }
}

elseif ($action === 'delete_drive') {
    try {
        global $hospital_id;
        
        if (!isset($conn) || $conn === null || $conn->connect_error) {
            error_log("Database connection error in delete_drive: " . ($conn ? $conn->connect_error : "Connection is null"));
            sendJson('error', 'Database connection failed. Please check your database connection.');
        }
        
        $drive_id = intval($_POST['drive_id'] ?? 0);
        
        if (!$hospital_id) {
            sendJson('error', 'Not authenticated');
        }
        
        if (!$drive_id) {
            sendJson('error', 'Drive ID is required');
        }
        
        // Verify the drive belongs to this hospital
        $verifyStmt = $conn->prepare("SELECT drive_id, location_name FROM blood_drive WHERE drive_id = ? AND hospital_id = ?");
        if (!$verifyStmt) {
            error_log("Failed to prepare verify query: " . $conn->error);
            sendJson('error', 'Failed to verify drive: ' . $conn->error);
        }
        $verifyStmt->bind_param("ii", $drive_id, $hospital_id);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();
        
        if ($verifyResult->num_rows === 0) {
            $verifyStmt->close();
            sendJson('error', 'Blood drive not found or does not belong to this hospital');
        }
        
        $driveInfo = $verifyResult->fetch_assoc();
        $verifyStmt->close();
        
        // Start transaction
        $conn->begin_transaction();
        
        // Cancel all appointments for this drive
        $cancelApptStmt = $conn->prepare("UPDATE appointment SET status = 'Cancelled' WHERE drive_id = ? AND status IN ('Pending', 'Confirmed')");
        if (!$cancelApptStmt) {
            $conn->rollback();
            throw new Exception("Prepare failed for cancel appointments: " . $conn->error);
        }
        $cancelApptStmt->bind_param("i", $drive_id);
        $cancelApptStmt->execute();
        $cancelledApptCount = $cancelApptStmt->affected_rows;
        $cancelApptStmt->close();
        
        // Delete the blood drive
        $deleteStmt = $conn->prepare("DELETE FROM blood_drive WHERE drive_id = ? AND hospital_id = ?");
        if (!$deleteStmt) {
            $conn->rollback();
            throw new Exception("Prepare failed for delete drive: " . $conn->error);
        }
        $deleteStmt->bind_param("ii", $drive_id, $hospital_id);
        $deleteStmt->execute();
        
        if ($deleteStmt->affected_rows > 0) {
            $conn->commit();
            $deleteStmt->close();
            
            error_log("Blood drive deleted: drive_id={$drive_id}, hospital_id={$hospital_id}, location={$driveInfo['location_name']}, cancelled_appointments={$cancelledApptCount}");
            
            sendJson('success', "Blood drive deleted successfully. {$cancelledApptCount} appointment(s) were cancelled.");
        } else {
            $conn->rollback();
            $deleteStmt->close();
            sendJson('error', 'Failed to delete blood drive');
        }
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        error_log("Error in delete_drive: " . $e->getMessage());
        sendJson('error', 'Server error: ' . $e->getMessage());
    }
}

// =============================================================
// 6. BLOOD REPORTS (ADMIN) - REMOVED

elseif ($action === 'login') {
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
elseif ($action === 'logout') { 
    session_destroy(); 
    sendJson('success', 'Logged out'); 
}
// =============================================================
// PASSWORD RESET
// =============================================================

elseif ($action === 'forgot_password') {
    // Ensure database connection exists
    if (!isset($conn) || $conn === null || $conn->connect_error) {
        error_log("Database connection error in forgot_password");
        sendJson('error', 'Database connection error. Please try again later.');
        exit;
    }
    
    // Ensure we're using the correct database
    $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
    if ($current_db !== 'bloodknight_db') {
        if (!$conn->select_db('bloodknight_db')) {
            error_log("Failed to select bloodknight_db in forgot_password");
            sendJson('error', 'Database selection error. Please try again later.');
            exit;
        }
    }
    
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        sendJson('error', 'Email is required');
    }
    
    // Check if admin exists and get status
    $stmt = $conn->prepare("SELECT hospital_id, admin_name, status FROM hospital WHERE admin_email = ?");
    if (!$stmt) {
        error_log("Prepare error in forgot_password: " . $conn->error);
        sendJson('error', 'Database error. Please try again.');
    }
    
    $stmt->bind_param("s", $email);
    if (!$stmt->execute()) {
        error_log("Execute error in forgot_password: " . $stmt->error);
        sendJson('error', 'Database error. Please try again.');
    }
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Check if hospital is Active - only allow password reset for Active hospitals
        if (isset($row['status']) && $row['status'] !== 'Active') {
            // Don't reveal account status for security (prevent email enumeration)
            error_log("Password reset attempted for non-Active hospital: email={$email}, status={$row['status']}");
            sendJson('success', 'If that email exists, a password reset link has been sent.');
            exit;
        }
        // Generate reset token (64 characters: bin2hex of 32 bytes)
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        error_log("Generated token for hospital_id={$row['hospital_id']}, email={$email}, token={$token}, expiry={$expiry}");
        
        // Store token in database (add reset_token and reset_token_expiry columns if they don't exist)
        $checkToken = $conn->query("SHOW COLUMNS FROM hospital LIKE 'reset_token'");
        if ($checkToken && $checkToken->num_rows == 0) {
            $alterResult = $conn->query("ALTER TABLE hospital ADD COLUMN reset_token VARCHAR(64) NULL, ADD COLUMN reset_token_expiry DATETIME NULL");
            if (!$alterResult) {
                error_log("Failed to add reset_token columns: " . $conn->error);
            } else {
                error_log("Added reset_token and reset_token_expiry columns to hospital table");
            }
        }
        
        $updateStmt = $conn->prepare("UPDATE hospital SET reset_token = ?, reset_token_expiry = ? WHERE hospital_id = ?");
        if (!$updateStmt) {
            error_log("Prepare error in forgot_password update: " . $conn->error);
            sendJson('error', 'Database error. Please try again.');
        }
        
        $updateStmt->bind_param("ssi", $token, $expiry, $row['hospital_id']);
        if (!$updateStmt->execute()) {
            error_log("Execute error in forgot_password update: " . $updateStmt->error);
            sendJson('error', 'Failed to generate reset token. Please try again.');
        }
        
        // Verify token was stored
        $verifyStmt = $conn->prepare("SELECT reset_token, reset_token_expiry FROM hospital WHERE hospital_id = ?");
        $verifyStmt->bind_param("i", $row['hospital_id']);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();
        if ($verifyRow = $verifyResult->fetch_assoc()) {
            error_log("Token stored verification - stored_token={$verifyRow['reset_token']}, stored_expiry={$verifyRow['reset_token_expiry']}");
            if ($verifyRow['reset_token'] !== $token) {
                error_log("ERROR: Token mismatch! Generated={$token}, Stored={$verifyRow['reset_token']}");
                sendJson('error', 'Token storage error. Please try again.');
            }
        }
        $verifyStmt->close();
        $updateStmt->close();
        
        // Send email with reset link (use unified reset_password.html with user_type=hospital)
        $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.html?token=" . urlencode($token) . "&user_type=hospital";
        
        error_log("Reset link generated: {$resetLink}");
        
        // Send email
        $emailStatus = sendEmail($email, $row['admin_name'], 'Password Reset Request - BloodKnight Command Center', 
            "<h2>Password Reset Request</h2>
            <p>Hello {$row['admin_name']},</p>
            <p>You requested to reset your password for the BloodKnight Command Center. Click the link below to reset it:</p>
            <p><a href='{$resetLink}' style='background: #dc2626; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Reset Password</a></p>
            <p>This link will expire in 1 hour.</p>
            <p>If you didn't request this, please ignore this email.</p>");
        
        if (!$emailStatus['success']) {
            error_log("Email send failed: " . $emailStatus['msg']);
        }
        
        sendJson('success', 'Password reset link has been sent to your email address.');
    } else {
        // Don't reveal if email exists or not (security best practice)
        sendJson('success', 'If that email exists, a password reset link has been sent.');
    }
    $stmt->close();
}

elseif ($action === 'reset_password') {
    // Ensure database connection exists
    if (!isset($conn) || $conn === null || $conn->connect_error) {
        sendJson('error', 'Database connection error. Please try again later.');
    }
    
    // Ensure we're using the correct database
    $current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
    if ($current_db !== 'bloodknight_db') {
        if (!$conn->select_db('bloodknight_db')) {
            sendJson('error', 'Database selection error. Please try again later.');
        }
    }
    
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    
    error_log("Reset password attempt - token length: " . strlen($token) . ", token: " . substr($token, 0, 20) . "...");
    
    if (empty($token) || empty($password)) {
        sendJson('error', 'Token and password are required');
    }
    
    if (strlen($password) < 6) {
        sendJson('error', 'Password must be at least 6 characters long');
    }
    
    // Verify token - check for hospitals with status='Active' (approved hospitals)
    // This allows reset for existing emails registered in the database with Active status
    $stmt = $conn->prepare("SELECT hospital_id, admin_email, hospital_name, admin_name, status, reset_token, reset_token_expiry FROM hospital WHERE reset_token = ? AND reset_token_expiry > NOW()");
    if (!$stmt) {
        error_log("Prepare error in reset_password: " . $conn->error);
        sendJson('error', 'Database error. Please try again.');
    }
    
    $stmt->bind_param("s", $token);
    if (!$stmt->execute()) {
        error_log("Execute error in reset_password: " . $stmt->error);
        sendJson('error', 'Database error. Please try again.');
    }
    $result = $stmt->get_result();
    
    // Debug: Check if any tokens exist at all
    $debugStmt = $conn->query("SELECT hospital_id, admin_email, reset_token, reset_token_expiry, NOW() as current_time FROM hospital WHERE reset_token IS NOT NULL LIMIT 5");
    if ($debugStmt) {
        error_log("Debug: Checking existing tokens in database:");
        while ($debugRow = $debugStmt->fetch_assoc()) {
            error_log("  hospital_id={$debugRow['hospital_id']}, email={$debugRow['admin_email']}, token=" . substr($debugRow['reset_token'] ?? 'NULL', 0, 20) . "..., expiry={$debugRow['reset_token_expiry']}, current_time={$debugRow['current_time']}");
        }
    }
    
    if ($row = $result->fetch_assoc()) {
        error_log("Token found and valid for hospital_id={$row['hospital_id']}, email={$row['admin_email']}");
        // Check if hospital is Active (approved) - only allow reset for approved hospitals
        if ($row['status'] !== 'Active') {
            sendJson('error', 'Hospital account is not active. Please contact admin for approval.');
            exit;
        }
        
        // Update password and clear token
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare("UPDATE hospital SET password_hash = ?, reset_token = NULL, reset_token_expiry = NULL WHERE hospital_id = ?");
        if (!$updateStmt) {
            error_log("Prepare error in reset_password update: " . $conn->error);
            sendJson('error', 'Database error. Please try again.');
        }
        
        $updateStmt->bind_param("si", $passwordHash, $row['hospital_id']);
        
        if ($updateStmt->execute()) {
            // Automatically log in the hospital after successful password reset
            $_SESSION['hospital_id'] = $row['hospital_id'];
            $_SESSION['hospital_name'] = $row['hospital_name'];
            $_SESSION['admin_name'] = $row['admin_name'];
            $_SESSION['role'] = 'hospital';
            
            // Regenerate session ID for security after password change
            session_regenerate_id(true);
            
            error_log("Password reset successful - Hospital auto-logged in: hospital_id=" . $row['hospital_id'] . ", hospital_name=" . $row['hospital_name']);
            error_log("Session data after reset: hospital_id=" . ($_SESSION['hospital_id'] ?? 'NOT SET') . ", role=" . ($_SESSION['role'] ?? 'NOT SET') . ", session_id=" . session_id());
            
            sendJson('success', 'Password reset successfully! Redirecting to dashboard...', [
                'hospital_id' => $row['hospital_id'],
                'hospital_name' => $row['hospital_name'],
                'admin_name' => $row['admin_name'],
                'session_id' => session_id() // Include session ID for debugging
            ]);
        } else {
            error_log("Execute error in reset_password update: " . $updateStmt->error);
            sendJson('error', 'Failed to reset password. Please try again.');
        }
        $updateStmt->close();
    } else {
        error_log("Token not found or expired. Token received: " . substr($token, 0, 20) . "...");
        // Check if token exists but expired
        $expiredStmt = $conn->prepare("SELECT hospital_id, reset_token, reset_token_expiry, NOW() as current_time FROM hospital WHERE reset_token = ?");
        if ($expiredStmt) {
            $expiredStmt->bind_param("s", $token);
            if ($expiredStmt->execute()) {
                $expiredResult = $expiredStmt->get_result();
                if ($expiredRow = $expiredResult->fetch_assoc()) {
                    error_log("Token exists but expired - expiry={$expiredRow['reset_token_expiry']}, current_time={$expiredRow['current_time']}");
                    sendJson('error', 'Reset token has expired. Please request a new password reset link.');
                } else {
                    error_log("Token not found in database at all");
                    sendJson('error', 'Invalid reset token. Please request a new password reset link.');
                }
            }
            $expiredStmt->close();
    } else {
        sendJson('error', 'Invalid or expired reset token. Please request a new one.');
    }
    }
    $stmt->close();
}

else { sendJson('error', 'Invalid Action'); }
?>
