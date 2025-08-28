<?php
session_start();
require_once '../config.php';
require_once '../database.php';

header('Content-Type: application/json');

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

// Get profile data
$input = json_decode(file_get_contents('php://input'), true);
$routingNumber = $input['routing_number'] ?? null;
$phoneNumber = $input['phone_number'] ?? null;

if (!$routingNumber || !$phoneNumber) {
    http_response_code(400);
    echo json_encode(['error' => 'Routing number and phone number are required']);
    exit;
}

// Validate routing number format (GTA World format)
if (!preg_match('/^[0-9]{6,8}$/', $routingNumber)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid routing number format']);
    exit;
}

// Validate phone number format
if (!preg_match('/^[0-9]{10,11}$/', $phoneNumber)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid phone number format']);
    exit;
}

try {
    $pdo = getConnection();
    
    // Check if routing number is already in use by another user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE routing_number = ? AND id != ?");
    $stmt->execute([$routingNumber, $_SESSION['user_id']]);
    if ($stmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Routing number is already in use by another user']);
        exit;
    }
    
    // Update user profile
    $stmt = $pdo->prepare("UPDATE users SET routing_number = ?, phone_number = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$routingNumber, $phoneNumber, $_SESSION['user_id']]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Profile completed successfully'
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update profile']);
    }
    
} catch (Exception $e) {
    error_log("Profile completion error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Profile update failed']);
}
?>
