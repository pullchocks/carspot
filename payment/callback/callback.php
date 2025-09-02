<?php
session_start();
require_once '../api/config.php';
require_once '../api/database.php';

// Fleeca Gateway Configuration
$auth_key = 'g9Yfmpdfj8VuFbxG3dkoJwXmqqsMnKI12Zk82P6Uuel7zJNdWMMqB8N5hw2javMc';
$gateway_url = 'http://banking.gta.world/gateway_token/';

// Get token from query parameter or path
$token = $_GET['token'] ?? null;

if (!$token && !empty($_SERVER['PATH_INFO'])) {
    $token = ltrim($_SERVER['PATH_INFO'], '/');
}

if (!$token) {
    exit("Missing payment token.");
}

// Validate token with Fleeca Gateway
$validation_url = $gateway_url . $token;
$response = file_get_contents($validation_url);

if (!$response) {
    exit("Unable to contact Fleeca gateway.");
}

$data = json_decode($response, true);

// Validate response structure
if (!$data || !isset($data['token'], $data['auth_key'], $data['message'], $data['payment'])) {
    exit("Invalid gateway response format.");
}

// Check if auth_key matches
if ($data['auth_key'] !== $auth_key) {
    exit("Authentication key mismatch.");
}

// Check if payment was successful
if ($data['message'] !== 'successful_payment') {
    exit("Payment was not successful: " . ($data['message'] ?? 'Unknown error'));
}

// Check if token is expired
if ($data['token_expired'] === true) {
    exit("Payment token has expired.");
}

$amount = (float) $data['payment'];
$routingFrom = $data['routing_from'];
$routingTo = $data['routing_to'];
$isSandbox = $data['sandbox'] ?? false;

// Log sandbox payments for debugging
if ($isSandbox) {
    error_log("Sandbox payment received: Amount: $amount, From: $routingFrom, To: $routingTo");
}

try {
    $pdo = getConnection();
    
    // Find user by routing number
    $stmt = $pdo->prepare("SELECT id FROM users WHERE routing_number = ?");
    $stmt->execute([$routingTo]);
    $user = $stmt->fetch();
    
    if (!$user) {
        exit("User not found for routing number: $routingTo");
    }
    
    $userId = $user['id'];
    
    // Prevent duplicate payment processing
    $exists = $pdo->prepare("SELECT id FROM transactions WHERE reference_id = ?");
    $exists->execute([$token]);
    if ($exists->rowCount() > 0) {
        header("Location: https://carspot.site/?deposit=already");
        exit;
    }
    
    // Add amount to user's balance
    $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
    $stmt->execute([$amount, $userId]);
    
    // Log transaction
    $log = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, reference_id, routing_from, routing_to, status, sandbox, timestamp) VALUES (?, 'deposit', ?, ?, ?, ?, 'completed', ?, NOW())");
    $log->execute([$userId, $amount, $token, $routingFrom, $routingTo, $isSandbox ? 1 : 0]);
    
    // Redirect to dashboard with success message
    header("Location: https://carspot.site/?deposit=success&amount=" . urlencode($amount));
    exit;
    
} catch (Exception $e) {
    error_log("Payment callback error: " . $e->getMessage());
    exit("Payment processing error occurred. Please contact support.");
}
?>
