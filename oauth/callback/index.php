<?php
// OAuth Callback Handler for https://carspot.site/oauth/callback
// This is the exact path that was requested from GTA World

session_start();
require_once '../oauth-config.php';
require_once '../../api/config.php';
require_once '../../api/database.php';

// Check if this is an OAuth callback
if (isset($_GET['code'])) {
    // This is an OAuth callback, process it
    $code = $_GET['code'];
    
    // Step 2: Exchange code for access token using cURL
    $ch = curl_init(TOKEN_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => CLIENT_ID,
        'client_secret' => CLIENT_SECRET,
        'redirect_uri' => 'https://carspot.site/oauth/callback',
        'code' => $code,
    ]));
    $response = curl_exec($ch);
    curl_close($ch);
    
    // Step 3: Decode token
    $tokenData = json_decode($response, true);
    $accessToken = $tokenData['access_token'] ?? null;
    
    if (!$accessToken) {
        die("Failed to get access token.");
    }
    
    // Step 4: Fetch user info using the token
    $ch = curl_init(API_URL . 'user');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken"
    ]);
    $userJson = curl_exec($ch);
    curl_close($ch);
    $user = json_decode($userJson, true);
    
    if (!$user) {
        die("Failed to fetch user info.");
    }
    
    // Store OAuth data in session
    $_SESSION['oauth_user'] = $user;
    $_SESSION['oauth_characters'] = $user['user']['character'] ?? [];
    $_SESSION['access_token'] = $accessToken;
    
    // Redirect to PHP character selection page
    header("Location: https://carspot.site/oauth/character-select.php");
    exit;
}

// If no code, redirect to OAuth login
header("Location: https://carspot.site/oauth/login.php");
exit;
?>
