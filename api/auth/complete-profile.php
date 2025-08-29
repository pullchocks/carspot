<?php
// Configure session to ensure proper sharing
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_path', '/');

session_start();
require_once '../config_mysql.php';
require_once '../database.php';

header('Content-Type: application/json');

// Debug logging
error_log("Session data: " . print_r($_SESSION, true));
error_log("Cookie data: " . print_r($_COOKIE, true));

// Check if user is logged in via session or request body
$userId = $_SESSION['user_id'] ?? null;
error_log("Session user_id: " . ($userId ?? 'NULL'));

// If no session, try to get user ID from request body (for frontend compatibility)
if (!$userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("Request body: " . print_r($input, true));
    
    $requestUserId = $input['user_id'] ?? null;
    error_log("Request user_id: " . ($requestUserId ?? 'NULL'));
    
    if ($requestUserId) {
        // Verify the user exists and is valid
        try {
            $pdo = getConnection();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$requestUserId]);
            if ($stmt->rowCount() > 0) {
                $userId = $requestUserId;
                error_log("User verified from request body, userId: " . $userId);
            } else {
                error_log("User not found in database for ID: " . $requestUserId);
            }
        } catch (Exception $e) {
            error_log("User verification error: " . $e->getMessage());
        }
    }
}

if (!$userId) {
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
$discord = $input['discord'] ?? null;

if (!$routingNumber || !$phoneNumber) {
    http_response_code(400);
    echo json_encode(['error' => 'Routing number and phone number are required']);
    exit;
}

// Validate routing number format (Fleeca routing number - 9 digits)
if (!preg_match('/^[0-9]{9}$/', $routingNumber)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid routing number format']);
    exit;
}

// Validate phone number format - allow any number of digits
if (!preg_match('/^[0-9]+$/', $phoneNumber)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid phone number format']);
    exit;
}

try {
    $pdo = getConnection();
    
    // Check if routing number is already in use by another user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE routing_number = ? AND id != ?");
    $stmt->execute([$routingNumber, $userId]);
    if ($stmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Routing number is already in use by another user']);
        exit;
    }
    
    // Update user profile
    $stmt = $pdo->prepare("UPDATE users SET routing_number = ?, phone_number = ?, discord = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$routingNumber, $phoneNumber, $discord, $userId]);
    
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
