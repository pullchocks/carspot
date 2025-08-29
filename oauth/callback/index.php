<?php
// Simple redirect to React app - let React handle the OAuth flow
if (isset($_GET['code'])) {
    // Redirect to React with the OAuth code
    header("Location: https://carspot.site/auth/callback?code=" . urlencode($_GET['code']));
    exit;
} else {
    // No code, redirect to login
    header("Location: https://carspot.site/oauth/login.php");
    exit;
}
?>
