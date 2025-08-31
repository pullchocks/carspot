<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config_mysql.php';

// GTA World OAuth configuration - Updated with correct credentials
$clientId = "72";
$clientSecret = "m6oBgUkqNlX8ex8Pf4o2chlCluAPR5nsfitdnxMM";
$redirectUri = "https://carspot.site/oauth/callback"; // Updated to match OAuth config
$tokenUrl = "https://ucp.gta.world/oauth/token";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? null;

if (!$code) {
    http_response_code(400);
    echo json_encode(['error' => 'Authorization code is required']);
    exit;
}

try {
    // Log the request parameters for debugging
    $requestParams = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'client_id' => $clientId,
        'client_secret' => $clientSecret
    ];
    error_log("GTA World token exchange request params: " . json_encode($requestParams));
    
    // Exchange authorization code for access token
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($requestParams));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("GTA World token exchange failed: HTTP $httpCode, Response: $response");
        http_response_code(400);
        
        // Try to parse the error response from GTA World
        $errorData = json_decode($response, true);
        if ($errorData && isset($errorData['error'])) {
            echo json_encode([
                'error' => 'GTA World OAuth error: ' . $errorData['error'],
                'details' => $errorData['error_description'] ?? 'No additional details provided',
                'http_code' => $httpCode
            ]);
        } else {
            echo json_encode([
                'error' => 'Failed to exchange authorization code',
                'details' => 'HTTP ' . $httpCode . ': ' . $response,
                'http_code' => $httpCode
            ]);
        }
        exit;
    }

    $tokenData = json_decode($response, true);
    
    if (!isset($tokenData['access_token'])) {
        error_log("GTA World token response missing access_token: " . $response);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid token response from GTA World']);
        exit;
    }

    // Return the token data
    echo json_encode([
        'access_token' => $tokenData['access_token'],
        'token_type' => $tokenData['token_type'] ?? 'Bearer',
        'expires_in' => $tokenData['expires_in'] ?? 3600,
        'refresh_token' => $tokenData['refresh_token'] ?? null
    ]);

} catch (Exception $e) {
    error_log("GTA World token exchange error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>
