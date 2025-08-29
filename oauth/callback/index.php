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
    
    // Redirect to character selection
    header("Location: https://carspot.site/oauth/character-select.php");
    exit;
}

// If no code, show OAuth test page
?>
<!DOCTYPE html>
<html>
<head>
    <title>OAuth Callback - Car Spot</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f0f0f0; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 5px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 OAuth Callback Handler</h1>
        
        <div class="info">
            <h3>Path: <code>https://carspot.site/oauth/callback</code></h3>
            <p>This is the exact OAuth callback path that was requested from GTA World.</p>
        </div>
        
        <div class="success">
            <h3>✅ Ready for OAuth Callbacks</h3>
            <p>This endpoint is configured to handle OAuth callbacks from GTA World.</p>
            <p>When users complete OAuth, they will be redirected here with a code parameter.</p>
        </div>
        
        <p><a href="https://carspot.site/oauth/login.php">Go to OAuth Login</a></p>
    </div>
</body>
</html>
