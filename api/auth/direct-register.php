<?php
session_start();
require_once '../config_mysql.php';
require_once '../database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$name = $input['name'] ?? '';
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

if (empty($name) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Name, email, and password are required']);
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email format']);
    exit;
}

// Validate password strength
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 8 characters long']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch();
    
    if ($existingUser) {
        http_response_code(400);
        echo json_encode(['error' => 'Email already registered']);
        exit;
    }
    
    // Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    // Create user
    $insertStmt = $pdo->prepare("
        INSERT INTO users (
            name, 
            email, 
            password_hash,
            discord, 
            avatar_url, 
            gta_world_id, 
            gta_world_username, 
            is_dealer, 
            is_staff, 
            created_at, 
            last_login
        ) VALUES (?, ?, ?, NULL, NULL, NULL, NULL, false, false, NOW(), NOW())
    ");
    
    $insertStmt->execute([
        $name,
        $email,
        $passwordHash
    ]);
    
    $userId = $pdo->lastInsertId();
    
    // Create user's wallet/balance record
    try {
        $walletStmt = $pdo->prepare("INSERT INTO user_wallets (user_id, balance) VALUES (?, 0)");
        $walletStmt->execute([$userId]);
    } catch (Exception $walletError) {
        error_log("Failed to create user wallet for user ID $userId: " . $walletError->getMessage());
        // Continue without wallet - user can still function
    }
    
    // Set session variables
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;
    
    // Return user data
    $userData = [
        'id' => $userId,
        'name' => $name,
        'email' => $email,
        'discord' => null,
        'phone_number' => null,
        'routing_number' => null,
        'avatar_url' => null,
        'is_dealer' => false,
        'staff_role' => null,
        'gta_world_id' => null,
        'gta_world_username' => null,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode([
        'success' => true,
        'user' => $userData,
        'message' => 'Account created successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Direct registration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>
