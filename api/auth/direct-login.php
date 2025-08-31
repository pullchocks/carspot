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
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    
    // Find user by email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND password_hash IS NOT NULL");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        exit;
    }
    
    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        exit;
    }
    
    // Update last login
    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $updateStmt->execute([$user['id']]);
    
    // Check if user has dealer account
    $dealerStmt = $pdo->prepare("
        SELECT da.*, dur.role as user_role 
        FROM dealer_accounts da 
        INNER JOIN dealer_user_roles dur ON da.id = dur.dealer_account_id 
        WHERE dur.user_id = ? AND dur.is_active = TRUE AND da.status = 'active'
    ");
    $dealerStmt->execute([$user['id']]);
    $dealerAccount = $dealerStmt->fetch();
    
    // Set session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    
    // Return user data
    $userData = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'discord' => $user['discord'],
        'phone_number' => $user['phone_number'],
        'routing_number' => $user['routing_number'],
        'avatar_url' => $user['avatar_url'],
        'is_dealer' => $user['is_dealer'],
        'staff_role' => $user['staff_role'],
        'gta_world_id' => $user['gta_world_id'],
        'gta_world_username' => $user['gta_world_username'],
        'created_at' => $user['created_at']
    ];
    
    if ($dealerAccount) {
        $userData['dealer_account'] = [
            'id' => $dealerAccount['id'],
            'name' => $dealerAccount['name'],
            'role' => $dealerAccount['user_role']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'user' => $userData
    ]);
    
} catch (Exception $e) {
    error_log("Direct login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>
