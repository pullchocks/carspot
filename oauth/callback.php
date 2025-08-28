<?php
session_start();
require_once 'oauth-config.php';
require_once 'api/config.php';
require_once 'api/database.php';

// Step 1: Get authorization code
if (!isset($_GET['code'])) {
    die("Authorization failed. No code received.");
}

$code = $_GET['code'];

// Step 2: Exchange code for access token using cURL
$ch = curl_init(TOKEN_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type' => 'authorization_code',
    'client_id' => CLIENT_ID,
    'client_secret' => CLIENT_SECRET,
    'redirect_uri' => REDIRECT_URI,
    'code' => $code,
]));
$response = curl_exec($ch);
if (!$response) {
    die("CURL ERROR: " . curl_error($ch));
}
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

// Check if user exists in Car Spot database
$forumId = $user['id'];
$username = $user['username'];
$discord = $user['discord'] ?? null;

try {
    $pdo = getConnection();
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT * FROM users WHERE forum_id = ?");
    $stmt->execute([$forumId]);
    $existingUser = $stmt->fetch();

    if (!$existingUser) {
        // Create new user
        $insert = $pdo->prepare("INSERT INTO users (forum_id, username, discord_id, email, created_at) VALUES (?, ?, ?, ?, NOW())");
        $insert->execute([$forumId, $username, $discord, $user['email'] ?? null]);
        $userId = $pdo->lastInsertId();
        
        // Set session
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['forum_id'] = $forumId;
        
        // Redirect to complete profile if needed
        header("Location: https://carspot.site/complete-profile");
        exit;
    } else {
        // User exists, set session
        $_SESSION['user_id'] = $existingUser['id'];
        $_SESSION['username'] = $existingUser['username'];
        $_SESSION['forum_id'] = $existingUser['forum_id'];
        
        // Check if profile is complete
        if (empty($existingUser['phone_number']) || empty($existingUser['routing_number'])) {
            header("Location: https://carspot.site/complete-profile");
        } else {
            header("Location: https://carspot.site/dashboard");
        }
        exit;
    }
} catch (Exception $e) {
    error_log("OAuth callback error: " . $e->getMessage());
    die("Authentication error occurred. Please try again.");
}
?>
