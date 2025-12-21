<?php
// Test script to check reset token functionality
// Run this in browser: http://localhost/bloodknight/test_reset_token.php

require_once 'db_connect.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Reset Token Diagnostic Test</h2>";

// Check database connection
if (!isset($conn) || $conn === null || $conn->connect_error) {
    echo "<p style='color: red;'>✗ Database connection failed: " . ($conn->connect_error ?? 'Connection not set') . "</p>";
    exit;
}

// Ensure we're using the correct database
$current_db = $conn->query("SELECT DATABASE()")->fetch_row()[0] ?? '';
if ($current_db !== 'bloodknight_db') {
    if (!$conn->select_db('bloodknight_db')) {
        echo "<p style='color: red;'>✗ Failed to select bloodknight_db database</p>";
        exit;
    }
    echo "<p style='color: green;'>✓ Selected bloodknight_db database</p>";
} else {
    echo "<p style='color: green;'>✓ Connected to bloodknight_db database</p>";
}

// Check if columns exist
$checkToken = $conn->query("SHOW COLUMNS FROM hospital LIKE 'reset_token'");
if ($checkToken && $checkToken->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ reset_token column does not exist. Adding it...</p>";
    $alterResult = $conn->query("ALTER TABLE hospital ADD COLUMN reset_token VARCHAR(64) NULL, ADD COLUMN reset_token_expiry DATETIME NULL");
    if ($alterResult) {
        echo "<p style='color: green;'>✓ Added reset_token and reset_token_expiry columns</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to add columns: " . $conn->error . "</p>";
        exit;
    }
} else {
    echo "<p style='color: green;'>✓ reset_token column exists</p>";
}

// Check for a test hospital (Agent Clinic)
$testEmail = 'agentali14@gmail.com';
$stmt = $conn->prepare("SELECT hospital_id, admin_email, admin_name, reset_token, reset_token_expiry FROM hospital WHERE admin_email = ?");
$stmt->bind_param("s", $testEmail);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo "<hr>";
    echo "<h3>Test Hospital Found:</h3>";
    echo "<p><strong>Hospital ID:</strong> {$row['hospital_id']}</p>";
    echo "<p><strong>Email:</strong> {$row['admin_email']}</p>";
    echo "<p><strong>Admin Name:</strong> {$row['admin_name']}</p>";
    echo "<p><strong>Current Token:</strong> " . ($row['reset_token'] ?? 'NULL') . "</p>";
    echo "<p><strong>Token Expiry:</strong> " . ($row['reset_token_expiry'] ?? 'NULL') . "</p>";
    
    // Generate a test token
    echo "<hr>";
    echo "<h3>Generating Test Token:</h3>";
    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    echo "<p><strong>Generated Token:</strong> <code style='word-break: break-all;'>{$token}</code></p>";
    echo "<p><strong>Token Length:</strong> " . strlen($token) . " characters</p>";
    echo "<p><strong>Expiry:</strong> {$expiry}</p>";
    
    // Store token
    $updateStmt = $conn->prepare("UPDATE hospital SET reset_token = ?, reset_token_expiry = ? WHERE hospital_id = ?");
    $updateStmt->bind_param("ssi", $token, $expiry, $row['hospital_id']);
    
    if ($updateStmt->execute()) {
        echo "<p style='color: green;'>✓ Token stored successfully</p>";
        
        // Verify token was stored
        $verifyStmt = $conn->prepare("SELECT reset_token, reset_token_expiry FROM hospital WHERE hospital_id = ?");
        $verifyStmt->bind_param("i", $row['hospital_id']);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();
        if ($verifyRow = $verifyResult->fetch_assoc()) {
            echo "<p><strong>Stored Token:</strong> <code style='word-break: break-all;'>{$verifyRow['reset_token']}</code></p>";
            echo "<p><strong>Stored Expiry:</strong> {$verifyRow['reset_token_expiry']}</p>";
            
            if ($verifyRow['reset_token'] === $token) {
                echo "<p style='color: green;'>✓ Token matches!</p>";
            } else {
                echo "<p style='color: red;'>✗ Token mismatch!</p>";
            }
            
            // Test token retrieval
            $testStmt = $conn->prepare("SELECT hospital_id, admin_email FROM hospital WHERE reset_token = ? AND reset_token_expiry > NOW()");
            $testStmt->bind_param("s", $token);
            $testStmt->execute();
            $testResult = $testStmt->get_result();
            
            if ($testRow = $testResult->fetch_assoc()) {
                echo "<p style='color: green;'>✓ Token can be retrieved and is valid!</p>";
                echo "<p><strong>Test Reset Link:</strong> <a href='reset_password.html?token=" . urlencode($token) . "&user_type=hospital' target='_blank'>Click here to test</a></p>";
            } else {
                echo "<p style='color: red;'>✗ Token cannot be retrieved or has expired</p>";
            }
            $testStmt->close();
        }
        $verifyStmt->close();
    } else {
        echo "<p style='color: red;'>✗ Failed to store token: " . $updateStmt->error . "</p>";
    }
    $updateStmt->close();
} else {
    echo "<p style='color: orange;'>⚠ Test hospital ({$testEmail}) not found. Using first available hospital...</p>";
    $allHospitals = $conn->query("SELECT hospital_id, admin_email, admin_name FROM hospital LIMIT 1");
    if ($allHospitals && $allRow = $allHospitals->fetch_assoc()) {
        echo "<p>Using hospital: {$allRow['admin_email']}</p>";
        // Generate and store token for this hospital
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $updateStmt = $conn->prepare("UPDATE hospital SET reset_token = ?, reset_token_expiry = ? WHERE hospital_id = ?");
        $updateStmt->bind_param("ssi", $token, $expiry, $allRow['hospital_id']);
        if ($updateStmt->execute()) {
            echo "<p style='color: green;'>✓ Token stored for {$allRow['admin_email']}</p>";
            echo "<p><strong>Test Reset Link:</strong> <a href='reset_password.html?token=" . urlencode($token) . "&user_type=hospital' target='_blank'>Click here to test</a></p>";
        }
        $updateStmt->close();
    }
}

$stmt->close();

echo "<hr>";
echo "<h3>All Hospitals with Reset Tokens:</h3>";
$allTokens = $conn->query("SELECT hospital_id, admin_email, reset_token, reset_token_expiry, NOW() as current_time FROM hospital WHERE reset_token IS NOT NULL");
if ($allTokens && $allTokens->num_rows > 0) {
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Hospital ID</th><th>Email</th><th>Token (first 20 chars)</th><th>Expiry</th><th>Current Time</th><th>Valid?</th></tr>";
    while ($tokenRow = $allTokens->fetch_assoc()) {
        $isValid = strtotime($tokenRow['reset_token_expiry']) > strtotime($tokenRow['current_time']);
        $validText = $isValid ? '<span style="color: green;">✓ Valid</span>' : '<span style="color: red;">✗ Expired</span>';
        echo "<tr>";
        echo "<td>{$tokenRow['hospital_id']}</td>";
        echo "<td>{$tokenRow['admin_email']}</td>";
        echo "<td>" . substr($tokenRow['reset_token'], 0, 20) . "...</td>";
        echo "<td>{$tokenRow['reset_token_expiry']}</td>";
        echo "<td>{$tokenRow['current_time']}</td>";
        echo "<td>{$validText}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hospitals have reset tokens set.</p>";
}
?>
