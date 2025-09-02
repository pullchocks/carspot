<?php
// Payment Callback Handler for https://carspot.site/payment/callback
// This is the exact path that was requested from GTA World

session_start();
require_once '../../api/config.php';
require_once '../../api/database.php';

// Fleeca Gateway Configuration
$auth_key = 'g9Yfmpdfj8VuFbxG3dkoJwXmqqsMnKI12Zk82P6Uuel7zJNdWMMqB8N5hw2javMc';
$gateway_url = 'http://banking.gta.world/gateway_token/';

// Get token from query parameter or path
$token = $_GET['token'] ?? null;

if (!$token && !empty($_SERVER['PATH_INFO'])) {
    $token = ltrim($_SERVER['PATH_INFO'], '/');
}

if (!$token) {
    // Show payment callback info page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Payment Callback - Car Spot</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; background: #f0f0f0; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; }
            .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 5px; margin: 20px 0; }
            .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 5px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>💳 Payment Callback Handler</h1>
            
            <div class="info">
                <h3>Path: <code>https://carspot.site/payment/callback</code></h3>
                <p>This is the exact payment callback path that was requested from GTA World.</p>
            </div>
            
            <div class="success">
                <h3>✅ Ready for Payment Callbacks</h3>
                <p>This endpoint is configured to handle payment callbacks from the Fleeca Gateway.</p>
                <p>When payments complete, users will be redirected here with a token parameter.</p>
            </div>
            
            <p><a href="https://carspot.site/dashboard">Go to Dashboard</a></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Process payment callback
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
