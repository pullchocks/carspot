<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Configure session to ensure proper sharing
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_path', '/');

try {
    session_start();
    require_once '../config_mysql.php';
    require_once '../database_mysql.php';
    
    header('Content-Type: application/json');
} catch (Exception $e) {
    error_log("Initialization error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server initialization failed']);
    exit;
}

// Debug logging
error_log("Session data: " . print_r($_SESSION, true));
error_log("Cookie data: " . print_r($_COOKIE, true));

// Check if user is logged in via session or request body
$userId = $_SESSION['user_id'] ?? null;
error_log("Session user_id: " . ($userId ?? 'NULL'));

// Read input data once and store it
$rawInput = file_get_contents('php://input');
error_log("Raw input: " . $rawInput);

if (empty($rawInput)) {
    error_log("Empty input received");
    http_response_code(400);
    echo json_encode(['error' => 'No input data received']);
    exit;
}

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error: " . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data received']);
    exit;
}

error_log("Request body: " . print_r($input, true));

// If no session, try to get user ID from request body (for frontend compatibility)
if (!$userId) {
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

// Get profile data from the already decoded input
$routingNumber = $input['routing_number'] ?? null;
$phoneNumber = $input['phone_number'] ?? null;


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
    error_log("Database connection established successfully");
    
    // Check if routing number is already in use by another user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE routing_number = ? AND id != ?");
    $stmt->execute([$routingNumber, $userId]);
    error_log("Routing number check - found " . $stmt->rowCount() . " users with this routing number");
    
    if ($stmt->rowCount() > 0) {
        error_log("Routing number conflict detected");
        http_response_code(400);
        echo json_encode(['error' => 'Routing number is already in use by another user']);
        exit;
    }
    
    // Log the values being updated
    error_log("Updating user ID: $userId with routing_number: $routingNumber, phone_number: $phoneNumber");
    
    // Update user profile
    $stmt = $pdo->prepare("UPDATE users SET routing_number = ?, phone_number = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$routingNumber, $phoneNumber, $userId]);
    
    if ($result === false) {
        error_log("SQL execution failed: " . print_r($stmt->errorInfo(), true));
        http_response_code(500);
        echo json_encode(['error' => 'SQL execution failed']);
        exit;
    }
    
    if ($stmt->rowCount() > 0) {
        error_log("Profile update successful - " . $stmt->rowCount() . " rows affected");
        echo json_encode([
            'success' => true,
            'message' => 'Profile completed successfully'
        ]);
    } else {
        error_log("Profile update failed - no rows affected. User ID: $userId");
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update profile - no rows affected']);
    }
    
} catch (Exception $e) {
    error_log("Profile completion error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Profile update failed: ' . $e->getMessage()]);
}
?>
