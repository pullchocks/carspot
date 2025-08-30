<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization header required']);
    exit;
}

$accessToken = $matches[1];

try {
    // Fetch user info from GTA World
    $ch = curl_init("https://ucp.gta.world/api/user");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("GTA World user API failed: HTTP $httpCode, Response: $response");
        http_response_code(400);
        echo json_encode(['error' => 'Failed to fetch user info from GTA World']);
        exit;
    }

    $userData = json_decode($response, true);
    
    // Debug: Log what we received from GTA World
    error_log("GTA World API Response: " . $response);
    error_log("Decoded user data: " . print_r($userData, true));
    
    if (!$userData || !isset($userData['user']) || !isset($userData['user']['id'])) {
        error_log("GTA World user API invalid response: " . $response);
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid user data from GTA World',
            'received_data' => $userData,
            'gta_world_response' => $response
        ]);
        exit;
    }

    // Extract user data from the nested structure
    $user = $userData['user'];
    
    // Return the user data
    echo json_encode([
        'id' => $user['id'],
        'username' => $user['username'] ?? '',
        'discord' => $user['discord'] ?? null,
        'email' => $user['email'] ?? null,
        'avatar_url' => $user['avatar_url'] ?? null
    ]);

} catch (Exception $e) {
    error_log("GTA World user API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>
