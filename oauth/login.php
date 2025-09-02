<?php
session_start();
require_once 'oauth-config.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: https://carspot.site/");
    exit;
}

// Build OAuth authorization URL
$authUrl = AUTH_URL . '?' . http_build_query([
    'client_id' => CLIENT_ID,
    'redirect_uri' => REDIRECT_URI,
    'response_type' => 'code',
    'state' => bin2hex(random_bytes(16)) // CSRF protection
]);

// Redirect to GTA World OAuth
header("Location: " . $authUrl);
exit;
?>
