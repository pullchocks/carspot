<?php
session_start();
require_once 'config.php';
require_once 'database.php';
require_once 'auth/oauth-config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'User not authenticated']);
    exit;
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get payment amount
$input = json_decode(file_get_contents('php://input'), true);
$amount = $input['amount'] ?? null;

if (!$amount || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid amount']);
    exit;
}

try {
    $pdo = getConnection();
    
    // Get user information
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    
    // Check if user has routing number set up
    if (empty($user['routing_number'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Routing number not configured. Please complete your profile first.']);
        exit;
    }
    
    // Generate unique payment reference
    $paymentReference = 'CS_' . time() . '_' . bin2hex(random_bytes(8));
    
    // Create pending transaction record
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, reference_id, routing_from, status, timestamp) VALUES (?, 'pending_deposit', ?, ?, ?, 'pending', NOW())");
    $stmt->execute([$_SESSION['user_id'], $amount, $paymentReference, $user['routing_number']]);
    
    // Build Fleeca payment URL
    $fleecaUrl = "http://banking.gta.world/pay?" . http_build_query([
        'amount' => $amount,
        'routing_to' => $user['routing_number'],
        'reference' => $paymentReference,
        'callback' => 'https://carspot.site/payment/callback.php',
        'auth_key' => FLEECA_AUTH_KEY
    ]);
    
    // Return payment URL and reference
    echo json_encode([
        'success' => true,
        'payment_url' => $fleecaUrl,
        'reference' => $paymentReference,
        'amount' => $amount
    ]);
    
} catch (Exception $e) {
    error_log("Payment initiation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Payment initiation failed']);
}
?>
