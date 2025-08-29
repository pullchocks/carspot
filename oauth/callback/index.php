<?php
// OAuth Callback Directory Index - Handle OAuth callback
require_once '../oauth-config.php';
require_once '../../api/config.php';
require_once '../../api/database.php';

// Check if this is an OAuth callback
if (isset($_GET['code'])) {
    // This is an OAuth callback, process it
    require_once 'callback.php';
    exit;
}

// If no code, redirect to OAuth login
header("Location: https://carspot.site/oauth/login.php");
exit;
?>
