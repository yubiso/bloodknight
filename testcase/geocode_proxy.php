<?php
// geocode_proxy.php - Proxy for Nominatim API to avoid CORS issues
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? '';

if ($action === 'reverse') {
    $lat = $_GET['lat'] ?? '';
    $lon = $_GET['lon'] ?? '';
    
    if (empty($lat) || empty($lon)) {
        echo json_encode(['error' => 'Missing coordinates']);
        exit;
    }
    
    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lon}&zoom=18&addressdetails=1";
    
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
        echo $response;
    } else {
        echo json_encode(['error' => 'Geocoding failed', 'status' => $httpCode]);
    }
}
elseif ($action === 'search') {
    $query = $_GET['q'] ?? '';
    
    if (empty($query)) {
        echo json_encode(['error' => 'Missing query']);
        exit;
    }
    
    // Add Sabah, Malaysia to the query and restrict to Sabah region
    $url = "https://nominatim.openstreetmap.org/search?format=json&q=" . urlencode($query . ', Sabah, Malaysia') . "&limit=5&viewbox=115.0,7.5,119.5,4.0&bounded=1&addressdetails=1";
    
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
        echo $response;
    } else {
        echo json_encode(['error' => 'Search failed', 'status' => $httpCode]);
    }
}
else {
    echo json_encode(['error' => 'Invalid action']);
}
?>
