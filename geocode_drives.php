<?php
// geocode_drives.php - Geocode blood drive locations and update coordinates
require_once 'db_connect.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Geocode Blood Drives</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .success { color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px; margin: 10px 0; }
        .error { color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; margin: 10px 0; }
        .info { color: blue; padding: 10px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 5px; margin: 10px 0; }
        .drive-item { padding: 10px; margin: 5px 0; background: white; border-radius: 5px; border-left: 4px solid #007bff; }
    </style>
</head>
<body>
    <h1>Geocode Blood Drive Locations</h1>
    <p>This script will find coordinates for all blood drives without coordinates and update them in the database.</p>
    
<?php
if (!$conn || $conn->connect_error) {
    echo '<div class="error">Database connection failed. Please check your database settings.</div>';
    exit;
}

// Function to geocode an address using Nominatim
function geocodeAddress($address) {
    $query = urlencode($address . ', Sabah, Malaysia');
    $url = "https://nominatim.openstreetmap.org/search?format=json&q={$query}&limit=1&viewbox=115.0,7.5,119.5,4.0&bounded=1";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: BloodKnight/1.0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if ($data && count($data) > 0) {
            return [
                'lat' => $data[0]['lat'],
                'lon' => $data[0]['lon'],
                'display_name' => $data[0]['display_name']
            ];
        }
    }
    return null;
}

// Get all drives without coordinates or with empty coordinates, with hospital info
$sql = "SELECT d.drive_id, d.location_name, d.drive_date, d.hospital_id, h.hospital_name, h.hospital_address
        FROM blood_drive d
        LEFT JOIN hospital h ON d.hospital_id = h.hospital_id
        WHERE (d.coordinates IS NULL OR d.coordinates = '' OR d.coordinates = 'NULL')
        AND d.drive_date >= CURDATE()
        AND d.status = 'Upcoming'
        ORDER BY d.drive_date ASC";
        
$result = $conn->query($sql);

if (!$result) {
    echo '<div class="error">Error querying database: ' . $conn->error . '</div>';
    exit;
}

$drives = [];
while ($row = $result->fetch_assoc()) {
    $drives[] = $row;
}

if (count($drives) === 0) {
    echo '<div class="info">No drives found that need geocoding. All drives already have coordinates.</div>';
    exit;
}

echo '<div class="info">Found ' . count($drives) . ' drive(s) that need coordinates.</div>';
echo '<hr>';

$successCount = 0;
$failCount = 0;

foreach ($drives as $drive) {
    $driveId = $drive['drive_id'];
    $locationName = $drive['location_name'];
    $hospitalName = $drive['hospital_name'] ?? '';
    $hospitalAddress = $drive['hospital_address'] ?? '';
    
    echo '<div class="drive-item">';
    echo '<strong>Drive ID:</strong> ' . $driveId . '<br>';
    echo '<strong>Location:</strong> ' . htmlspecialchars($locationName) . '<br>';
    if ($hospitalName) {
        echo '<strong>Hospital:</strong> ' . htmlspecialchars($hospitalName) . '<br>';
    }
    echo '<strong>Date:</strong> ' . $drive['drive_date'] . '<br>';
    echo '<strong>Status:</strong> Geocoding...<br>';
    
    // Build geocoding query with context
    $geocodeQuery = $locationName;
    $coords = null;
    
    // For generic locations, use hospital address or hospital name
    $genericLocations = ['Hospital Foyer', 'Town Square', 'University Hall', 'Community Hall', 'Shopping Mall'];
    
    if (in_array($locationName, $genericLocations)) {
        // For generic locations, use hospital address if available
        if ($hospitalAddress) {
            $geocodeQuery = $hospitalAddress;
        } elseif ($hospitalName) {
            // Fallback to hospital name
            $geocodeQuery = $hospitalName . ', Sabah, Malaysia';
        }
    } else {
        // For specific locations, try multiple strategies
        // Strategy 1: Try location name as-is
        $coords = geocodeAddress($locationName);
        
        // Strategy 2: If that fails, try with "Sabah, Malaysia" explicitly
        if (!$coords && strpos(strtolower($locationName), 'sabah') === false && strpos(strtolower($locationName), 'malaysia') === false) {
            $coords = geocodeAddress($locationName . ', Sabah, Malaysia');
        }
        
        // Strategy 3: If still fails, try with hospital address
        if (!$coords && $hospitalAddress) {
            $geocodeQuery = $locationName . ', ' . $hospitalAddress;
        } elseif (!$coords && $hospitalName && strpos($locationName, $hospitalName) === false) {
            // Strategy 4: Try with hospital name
            $geocodeQuery = $locationName . ', ' . $hospitalName . ', Sabah, Malaysia';
        }
    }
    
    // Geocode the location with context (if not already geocoded above)
    if (!$coords) {
        $coords = geocodeAddress($geocodeQuery);
    }
    
    // Try alternative location names for known places
    if (!$coords) {
        $alternatives = [];
        
        // Specific known locations in Sabah
        if (stripos($locationName, '1borneo') !== false || stripos($locationName, '1 borneo') !== false) {
            $alternatives = [
                '1Borneo Hypermall Kota Kinabalu',
                '1 Borneo Mall Kota Kinabalu',
                '1Borneo Shopping Mall',
                '1 Borneo'
            ];
        } elseif (stripos($locationName, 'tawau') !== false && stripos($locationName, 'square') !== false) {
            $alternatives = [
                'Tawau Town Square',
                'Tawau Square',
                'Tawau Sabah Malaysia',
                'Tawau'
            ];
        }
        
        // Try alternatives
        foreach ($alternatives as $alt) {
            $coords = geocodeAddress($alt);
            if ($coords) {
                echo '<div style="font-size: 0.9em; color: #666;">Used alternative: ' . htmlspecialchars($alt) . '</div>';
                break;
            }
            usleep(500000); // 0.5 second delay between attempts
        }
    }
    
    if ($coords) {
        // Update the database - update both coordinates (string) and latitude/longitude (if columns exist)
        $coordinates = $coords['lat'] . ',' . $coords['lon'];
        $latitude = $coords['lat'];
        $longitude = $coords['lon'];
        
        // Try to update with latitude and longitude columns first (newer structure)
        $updateSql = "UPDATE blood_drive SET coordinates = ?, latitude = ?, longitude = ? WHERE drive_id = ?";
        $stmt = $conn->prepare($updateSql);
        
        // If that fails (columns don't exist), fall back to just coordinates
        if (!$stmt) {
            $updateSql = "UPDATE blood_drive SET coordinates = ? WHERE drive_id = ?";
            $stmt = $conn->prepare($updateSql);
            if ($stmt) {
                $stmt->bind_param("si", $coordinates, $driveId);
            }
        } else {
            $stmt->bind_param("sddi", $coordinates, $latitude, $longitude, $driveId);
        }
        
        if ($stmt->execute()) {
            echo '<div class="success">✓ Coordinates updated: ' . $coords['lat'] . ', ' . $coords['lon'] . '</div>';
            echo '<div style="font-size: 0.9em; color: #666;">Address: ' . htmlspecialchars($coords['display_name']) . '</div>';
            $successCount++;
        } else {
            echo '<div class="error">✗ Failed to update database: ' . $stmt->error . '</div>';
            $failCount++;
        }
        $stmt->close();
    } else {
        echo '<div class="error">✗ Could not geocode this location. Please check the location name.</div>';
        $failCount++;
    }
    
    echo '</div>';
    
    // Small delay to respect Nominatim rate limits
    usleep(1000000); // 1 second delay
}

echo '<hr>';
echo '<div class="info">';
echo '<strong>Summary:</strong><br>';
echo 'Successfully geocoded: ' . $successCount . ' drive(s)<br>';
echo 'Failed: ' . $failCount . ' drive(s)<br>';
echo '</div>';

echo '<br><a href="dashboard.html">Go to Donor Dashboard</a> | ';
echo '<a href="admin_dashboard.html">Go to Admin Dashboard</a>';
?>

</body>
</html>

