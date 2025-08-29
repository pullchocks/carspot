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
    // Fetch user info (which includes characters) from GTA World
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
        echo json_encode(['error' => 'Failed to fetch user data from GTA World']);
        exit;
    }

    $userData = json_decode($response, true);
    
    if (!$userData || !isset($userData['user']) || !isset($userData['user']['character'])) {
        error_log("GTA World user API invalid response: " . $response);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid user data from GTA World']);
        exit;
    }

    // Extract characters from the nested structure
    $charactersData = $userData['user']['character'];
    
    if (!is_array($charactersData)) {
        echo json_encode([]);
        exit;
    }

    // Process and return the characters data
    $characters = [];
    foreach ($charactersData as $character) {
        if (isset($character['id']) && isset($character['firstname']) && isset($character['lastname'])) {
            $characters[] = [
                'id' => $character['id'],
                'name' => $character['firstname'] . ' ' . $character['lastname'],
                'firstname' => $character['firstname'],
                'lastname' => $character['lastname'],
                'memberid' => $character['memberid'] ?? null,
                'model' => $character['model'] ?? 'Unknown',
                'level' => $character['level'] ?? 1,
                'job' => $character['job'] ?? null,
                'faction' => $character['faction'] ?? null,
                'avatar_url' => $character['avatar_url'] ?? null
            ];
        }
    }

    echo json_encode($characters);

} catch (Exception $e) {
    error_log("GTA World characters API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>
