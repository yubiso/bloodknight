<?php
// create_blood_reports.php - Creates sample blood reports for any user by email
require_once 'db_connect.php';

$message = '';
$error = '';
$user_info = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    $num_reports = isset($_POST['num_reports']) ? intval($_POST['num_reports']) : 4;
    $hospital_option = isset($_POST['hospital_option']) ? $_POST['hospital_option'] : 'random';
    $selected_hospital_id = isset($_POST['hospital_id']) ? intval($_POST['hospital_id']) : null;
    
    if (empty($email)) {
        $error = "Please enter an email address.";
    } else {
        // Get user_id for the email
        $stmt = $conn->prepare("SELECT user_id, full_name, blood_type FROM donor_user WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) {
            $error = "User with email '$email' not found. Please register this account first.";
        } else {
            $user = $result->fetch_assoc();
            $user_id = $user['user_id'];
            $user_info = $user;
            
            // Generate sample blood reports
            $reports = [];
            $days_ago = [90, 60, 45, 30, 20, 15, 10, 7];
            
            // Take only the number requested
            $days_to_use = array_slice($days_ago, 0, min($num_reports, count($days_ago)));
            
            foreach ($days_to_use as $index => $days) {
                // Vary the values slightly for each report to make them realistic
                $base_hemoglobin = 14.5 + (rand(-5, 10) / 10);
                $base_hematocrit = 42.0 + (rand(-5, 10) / 10);
                
                // Generate random volume (standard donation is 450-500ml)
                $volume_ml = 450 + rand(0, 50);
                
                $reports[] = [
                    'appt_id' => null,
                    'report_date' => date('Y-m-d', strtotime("-$days days")),
                    'hemoglobin' => round($base_hemoglobin, 1),
                    'hematocrit' => round($base_hematocrit, 1),
                    'platelet_count' => 250000 + rand(-20000, 30000),
                    'white_blood_cell_count' => round(6.5 + (rand(-10, 15) / 10), 1),
                    'red_blood_cell_count' => round(4.5 + (rand(-5, 10) / 10), 2),
                    'blood_pressure' => (110 + rand(0, 15)) . '/' . (70 + rand(0, 12)),
                    'temperature' => round(98.0 + (rand(0, 10) / 10), 1),
                    'volume_ml' => $volume_ml,
                    'notes' => getSampleNotes($index, $days)
                ];
            }
            
            // Determine hospital assignment strategy
            if ($hospital_option === 'specific' && $selected_hospital_id) {
                // Use specific hospital for all reports
                $target_hospital_id = $selected_hospital_id;
            } else {
                // Randomize hospital for each report (or use one random hospital for all)
                $hospitals = $conn->query("SELECT hospital_id FROM hospital")->fetch_all(MYSQLI_ASSOC);
                if (empty($hospitals)) {
                    $error = "No hospitals found in database. Please add hospitals first.";
                } else {
                    if ($hospital_option === 'random_per_report') {
                        // Will be handled per report in loop
                        $target_hospital_id = null;
                    } else {
                        // One random hospital for all reports
                        $randomIndex = array_rand($hospitals);
                        $target_hospital_id = $hospitals[$randomIndex]['hospital_id'];
                    }
                }
            }
            
            // Get all hospitals for randomization if needed
            $all_hospitals = $conn->query("SELECT hospital_id FROM hospital")->fetch_all(MYSQLI_ASSOC);
            $hospital_ids = array_column($all_hospitals, 'hospital_id');
            
            // Insert reports
            // Prepare insert statement once before the loop
            $insertStmt = $conn->prepare("INSERT INTO blood_report (user_id, appt_id, report_date, hemoglobin, hematocrit, platelet_count, white_blood_cell_count, red_blood_cell_count, blood_pressure, temperature, volume_ml, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            // Initialize counters
            $inserted = 0;
            $skipped = 0;
            $created_appointments = 0;
            
            if (!$insertStmt) {
                $error = "Failed to prepare insert statement: " . $conn->error;
            } else {
                
                foreach ($reports as $index => $report) {
                    // Check if report already exists for this date
                    $checkStmt = $conn->prepare("SELECT report_id FROM blood_report WHERE user_id = ? AND report_date = ?");
                    if (!$checkStmt) {
                        error_log("Prepare error (checkStmt): " . $conn->error);
                        continue;
                    }
                    $checkStmt->bind_param("is", $user_id, $report['report_date']);
                    $checkStmt->execute();
                    if ($checkStmt->get_result()->num_rows > 0) {
                        $skipped++;
                        $checkStmt->close();
                        continue;
                    }
                    $checkStmt->close();
                    
                    // Determine hospital for this report
                    if ($hospital_option === 'random_per_report' && !empty($hospital_ids)) {
                        $report_hospital_id = $hospital_ids[array_rand($hospital_ids)];
                    } else {
                        $report_hospital_id = $target_hospital_id;
                    }
                    
                    // Try to find or create an appointment for this hospital and date
                    $appt_id = null;
                
                // If we have an appointment, try to get volume from it
                if ($appt_id) {
                    $volStmt = $conn->prepare("SELECT volume_ml FROM appointment WHERE appt_id = ?");
                    if ($volStmt) {
                        $volStmt->bind_param("i", $appt_id);
                        $volStmt->execute();
                        $volResult = $volStmt->get_result();
                        if ($volRow = $volResult->fetch_assoc() && $volRow['volume_ml']) {
                            $report['volume_ml'] = $volRow['volume_ml'];
                        }
                    }
                }
                
                // First, try to find an existing appointment at this hospital around the report date
                $dateRangeStart = date('Y-m-d', strtotime($report['report_date'] . ' -7 days'));
                $dateRangeEnd = date('Y-m-d', strtotime($report['report_date'] . ' +7 days'));
                
                $findApptStmt = $conn->prepare("SELECT a.appt_id 
                                               FROM appointment a 
                                               JOIN blood_drive d ON a.drive_id = d.drive_id 
                                               WHERE a.user_id = ? AND d.hospital_id = ? 
                                               AND d.drive_date BETWEEN ? AND ?
                                               ORDER BY ABS(DATEDIFF(d.drive_date, ?))
                                               LIMIT 1");
                if (!$findApptStmt) {
                    error_log("Prepare error (findAppt): " . $conn->error);
                } else {
                    $findApptStmt->bind_param("iisss", $user_id, $report_hospital_id, $dateRangeStart, $dateRangeEnd, $report['report_date']);
                    $findApptStmt->execute();
                    $apptResult = $findApptStmt->get_result();
                    
                    if ($apptRow = $apptResult->fetch_assoc()) {
                        $appt_id = $apptRow['appt_id'];
                    } else {
                        // No appointment found, try to create one or find a drive at this hospital
                        $driveStmt = $conn->prepare("SELECT drive_id, drive_date 
                                                    FROM blood_drive 
                                                    WHERE hospital_id = ? 
                                                    AND drive_date BETWEEN ? AND ?
                                                    ORDER BY ABS(DATEDIFF(drive_date, ?))
                                                    LIMIT 1");
                        if (!$driveStmt) {
                            error_log("Prepare error (driveStmt): " . $conn->error);
                        } else {
                            $driveStmt->bind_param("isss", $report_hospital_id, $dateRangeStart, $dateRangeEnd, $report['report_date']);
                            $driveStmt->execute();
                            $driveResult = $driveStmt->get_result();
                            
                            if ($driveRow = $driveResult->fetch_assoc()) {
                                // Found a drive, create an appointment
                                $drive_id = $driveRow['drive_id'];
                                $time_slots = ['09:00:00', '10:30:00', '12:00:00', '14:00:00', '15:30:00'];
                                
                                // Find an available time slot
                                foreach ($time_slots as $time) {
                                    $checkSlotStmt = $conn->prepare("SELECT appt_id FROM appointment WHERE drive_id = ? AND selected_time = ?");
                                    if (!$checkSlotStmt) {
                                        error_log("Prepare error (checkSlot): " . $conn->error);
                                        continue;
                                    }
                                    $checkSlotStmt->bind_param("is", $drive_id, $time);
                                    $checkSlotStmt->execute();
                                    
                                    if ($checkSlotStmt->get_result()->num_rows == 0) {
                                        // Slot available, create appointment
                                        // Try with source column first, fallback to without if it doesn't exist
                                        $createApptStmt = $conn->prepare("INSERT INTO appointment (user_id, drive_id, selected_time, status, source) VALUES (?, ?, ?, 'Completed', 'Walk-in')");
                                        if (!$createApptStmt) {
                                            // Try without source column
                                            $createApptStmt = $conn->prepare("INSERT INTO appointment (user_id, drive_id, selected_time, status) VALUES (?, ?, ?, 'Completed')");
                                        }
                                        if ($createApptStmt) {
                                            $createApptStmt->bind_param("iis", $user_id, $drive_id, $time);
                                            if ($createApptStmt->execute()) {
                                                $appt_id = $createApptStmt->insert_id;
                                                $created_appointments++;
                                                break;
                                            } else {
                                                error_log("Execute error (createAppt): " . $createApptStmt->error);
                                            }
                                        } else {
                                            error_log("Prepare error (createAppt): " . $conn->error);
                                        }
                                    }
                                }
                            } else {
                            // No drive found, create a drive and appointment
                            $drive_date = $report['report_date'];
                            $createDriveStmt = $conn->prepare("INSERT INTO blood_drive (hospital_id, drive_date, start_time, end_time, location_name, status) VALUES (?, ?, '09:00:00', '17:00:00', 'Walk-in Screening', 'Completed')");
                            if (!$createDriveStmt) {
                                error_log("Prepare error (createDrive): " . $conn->error);
                            } else {
                                $createDriveStmt->bind_param("is", $report_hospital_id, $drive_date);
                                
                                if ($createDriveStmt->execute()) {
                                    $drive_id = $createDriveStmt->insert_id;
                                    $time = '10:00:00';
                                    
                                    // Try with source column first, fallback to without if it doesn't exist
                                    $createApptStmt = $conn->prepare("INSERT INTO appointment (user_id, drive_id, selected_time, status, source) VALUES (?, ?, ?, 'Completed', 'Walk-in')");
                                    if (!$createApptStmt) {
                                        // Try without source column
                                        $createApptStmt = $conn->prepare("INSERT INTO appointment (user_id, drive_id, selected_time, status) VALUES (?, ?, ?, 'Completed')");
                                    }
                                    if ($createApptStmt) {
                                        $createApptStmt->bind_param("iis", $user_id, $drive_id, $time);
                                        if ($createApptStmt->execute()) {
                                            $appt_id = $createApptStmt->insert_id;
                                            $created_appointments++;
                                        } else {
                                            error_log("Execute error (createAppt): " . $createApptStmt->error);
                                        }
                                    } else {
                                        error_log("Prepare error (createAppt): " . $conn->error);
                                    }
                                } else {
                                    error_log("Execute error (createDrive): " . $createDriveStmt->error);
                                }
                            }
                        }
                        }
                    }
                    $findApptStmt->close();
                }
                
                // Correct parameter types based on table schema:
                // user_id (INT=i), appt_id (INT=i), report_date (DATE=s), 
                // hemoglobin (DECIMAL=d), hematocrit (DECIMAL=d), 
                // platelet_count (INT=i), white_blood_cell_count (DECIMAL=d),
                // red_blood_cell_count (DECIMAL=d), blood_pressure (VARCHAR=s),
                // temperature (DECIMAL=d), volume_ml (INT=i), notes (TEXT=s)
                $insertStmt->bind_param("iisddiddddis", 
                    $user_id,
                    $appt_id,
                    $report['report_date'],
                    $report['hemoglobin'],
                    $report['hematocrit'],
                    $report['platelet_count'],
                    $report['white_blood_cell_count'],
                    $report['red_blood_cell_count'],
                    $report['blood_pressure'],
                    $report['temperature'],
                    $report['volume_ml'],
                    $report['notes']
                );
                
                if ($insertStmt->execute()) {
                    $inserted++;
                } else {
                    error_log("Execute error (insertStmt): " . $insertStmt->error);
                }
            }
            
            // Close the statement after loop
            $insertStmt->close();
        }
            
            if ($inserted > 0) {
                $hospital_info = "";
                if ($hospital_option === 'specific' && $selected_hospital_id) {
                    $hospNameStmt = $conn->prepare("SELECT hospital_name FROM hospital WHERE hospital_id = ?");
                    $hospNameStmt->bind_param("i", $selected_hospital_id);
                    $hospNameStmt->execute();
                    $hospName = $hospNameStmt->get_result()->fetch_assoc()['hospital_name'] ?? 'Selected Hospital';
                    $hospital_info = " at $hospName";
                } elseif ($hospital_option === 'random_per_report') {
                    $hospital_info = " (randomized across different hospitals)";
                } else {
                    $hospital_info = " (random hospital assigned)";
                }
                
                $message = "✓ Successfully created $inserted blood report(s) for " . htmlspecialchars($user['full_name']) . " (" . htmlspecialchars($email) . ")" . $hospital_info;
                if ($created_appointments > 0) {
                    $message .= ". Created $created_appointments appointment(s) to link reports to hospitals.";
                }
                if ($skipped > 0) {
                    $message .= " ($skipped report(s) already existed and were skipped)";
                }
            } else {
                $error = "No new reports created. All reports for this user already exist.";
            }
        }
    }
}

function getSampleNotes($index, $days) {
    $notes_list = [
        "Initial blood donation screening. All parameters within normal range. Eligible for donation.",
        "Post-donation follow-up. Recovery progressing well. Blood counts stable.",
        "Regular health checkup. Excellent cardiovascular health. All values optimal.",
        "Pre-donation screening. All parameters normal. Cleared for blood donation.",
        "Routine health monitoring. Blood work shows good health indicators.",
        "Follow-up examination after donation. Recovery status excellent.",
        "Health screening check. All blood parameters within healthy ranges.",
        "Latest checkup. All values normal and stable. Ready for next donation."
    ];
    
    $base_notes = $notes_list[$index % count($notes_list)];
    
    if ($days >= 60) {
        return $base_notes . " Date: " . $days . " days ago.";
    } else {
        return $base_notes;
    }
}

// Get list of all users for dropdown
$allUsers = $conn->query("SELECT user_id, email, full_name, blood_type FROM donor_user ORDER BY email ASC")->fetch_all(MYSQLI_ASSOC);

// Get list of all hospitals for dropdown
$allHospitals = $conn->query("SELECT hospital_id, hospital_name, hospital_address FROM hospital ORDER BY hospital_name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Blood Reports - BloodKnight</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 p-8">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-2xl shadow-lg p-8">
            <div class="flex items-center gap-3 mb-6">
                <i class="fas fa-file-medical text-3xl text-red-600"></i>
                <h1 class="text-3xl font-bold text-slate-900">Create Blood Reports</h1>
            </div>
            
            <?php if ($message): ?>
                <div class="bg-green-50 border border-green-200 text-green-800 p-4 rounded-lg mb-6">
                    <i class="fas fa-check-circle mr-2"></i><?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-lg mb-6">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($user_info): ?>
                <div class="bg-blue-50 border border-blue-200 p-4 rounded-lg mb-6">
                    <p><strong>User Info:</strong></p>
                    <p>Name: <?php echo htmlspecialchars($user_info['full_name']); ?></p>
                    <p>Email: <?php echo htmlspecialchars($email); ?></p>
                    <p>Blood Type: <?php echo htmlspecialchars($user_info['blood_type'] ?? 'N/A'); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-6">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">User Email</label>
                    <input type="email" name="email" required 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                           placeholder="user@example.com"
                           class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                    <p class="text-xs text-slate-500 mt-1">Enter the email address of the user to create reports for</p>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Number of Reports</label>
                    <select name="num_reports" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        <option value="1">1 Report</option>
                        <option value="2">2 Reports</option>
                        <option value="3">3 Reports</option>
                        <option value="4" selected>4 Reports</option>
                        <option value="5">5 Reports</option>
                        <option value="6">6 Reports</option>
                        <option value="8">8 Reports</option>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Number of sample blood reports to create (will span over recent months)</p>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-2">Hospital Assignment</label>
                    <select name="hospital_option" id="hospital_option" onchange="toggleHospitalSelect()" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        <option value="random">Random Hospital (Same for all reports)</option>
                        <option value="random_per_report">Random Hospital (Different for each report)</option>
                        <option value="specific">Specific Hospital</option>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Choose how to assign hospitals to the blood reports</p>
                </div>
                
                <div id="hospital_select_container" class="hidden">
                    <label class="block text-sm font-bold text-slate-700 mb-2">Select Hospital</label>
                    <select name="hospital_id" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        <option value="">-- Select Hospital --</option>
                        <?php foreach ($allHospitals as $hospital): ?>
                            <option value="<?php echo $hospital['hospital_id']; ?>">
                                <?php echo htmlspecialchars($hospital['hospital_name']); ?> - <?php echo htmlspecialchars($hospital['hospital_address']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">All reports will be linked to this hospital</p>
                </div>
                
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-6 rounded-lg transition-colors">
                    <i class="fas fa-plus-circle mr-2"></i>Create Blood Reports
                </button>
            </form>
            
            <?php if (count($allUsers) > 0): ?>
                <div class="mt-8 pt-8 border-t border-slate-200">
                    <h3 class="text-lg font-bold text-slate-900 mb-4">Registered Users</h3>
                    <div class="bg-slate-50 rounded-lg p-4 max-h-64 overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200">
                                    <th class="text-left py-2 px-3 font-bold text-slate-700">Email</th>
                                    <th class="text-left py-2 px-3 font-bold text-slate-700">Name</th>
                                    <th class="text-left py-2 px-3 font-bold text-slate-700">Blood Type</th>
                                    <th class="text-left py-2 px-3 font-bold text-slate-700">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allUsers as $user): ?>
                                    <tr class="border-b border-slate-100 hover:bg-white">
                                        <td class="py-2 px-3"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="py-2 px-3"><?php echo htmlspecialchars($user['full_name']); ?></td>
                                        <td class="py-2 px-3"><?php echo htmlspecialchars($user['blood_type'] ?? 'N/A'); ?></td>
                                        <td class="py-2 px-3">
                                            <button onclick="fillEmail('<?php echo htmlspecialchars($user['email']); ?>')" 
                                                    class="text-red-600 hover:text-red-800 font-medium text-xs">
                                                <i class="fas fa-mouse-pointer mr-1"></i>Use
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="mt-8 pt-8 border-t border-slate-200">
                <a href="dashboard.html" class="text-red-600 hover:text-red-800 font-medium">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
                <span class="mx-2 text-slate-400">|</span>
                <a href="admin_dashboard.html" class="text-red-600 hover:text-red-800 font-medium">
                    <i class="fas fa-shield-alt mr-2"></i>Admin Dashboard
                </a>
            </div>
        </div>
    </div>
    
    <script>
        function fillEmail(email) {
            document.querySelector('input[name="email"]').value = email;
            document.querySelector('input[name="email"]').focus();
        }
        
        function toggleHospitalSelect() {
            const option = document.getElementById('hospital_option').value;
            const container = document.getElementById('hospital_select_container');
            const select = container.querySelector('select[name="hospital_id"]');
            
            if (option === 'specific') {
                container.classList.remove('hidden');
                select.required = true;
            } else {
                container.classList.add('hidden');
                select.required = false;
                select.value = '';
            }
        }
    </script>
</body>
</html>

