<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config_mysql.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$gtaWorldUser = $input['gta_world_user'] ?? null;
$selectedCharacter = $input['selected_character'] ?? null;

if (!$gtaWorldUser || !isset($gtaWorldUser['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'GTA World user data is required']);
    exit;
}

try {
    $gtaWorldId = $gtaWorldUser['id'];
    $gtaWorldUsername = $gtaWorldUser['username'] ?? '';
    $discord = $gtaWorldUser['discord'] ?? null;
    $email = $gtaWorldUser['email'] ?? null;
    $avatarUrl = $gtaWorldUser['avatar_url'] ?? null;
    
    // Check if user already exists in our system
    $stmt = $pdo->prepare("SELECT * FROM users WHERE gta_world_id = ?");
    $stmt->execute([$gtaWorldId]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        // User exists, update last login and return user data
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$existingUser['id']]);
        
        // Check if user has dealer account
        $dealerStmt = $pdo->prepare("SELECT * FROM dealer_accounts WHERE user_id = ? AND status = 'active'");
        $dealerStmt->execute([$existingUser['id']]);
        $dealerAccount = $dealerStmt->fetch();
        
        $userData = [
            'id' => $existingUser['id'],
            'name' => $existingUser['name'],
            'email' => $existingUser['email'],
            'discord' => $existingUser['discord'],
            'avatar_url' => $existingUser['avatar_url'],
            'is_dealer' => !empty($dealerAccount),
            'is_staff' => $existingUser['is_staff'] ? true : false,
            'staff_role' => $existingUser['staff_role'] ?? null,
            'company_name' => $dealerAccount['company_name'] ?? null,
            'gta_world_id' => $existingUser['gta_world_id'],
            'gta_world_username' => $existingUser['gta_world_username']
        ];
        
        echo json_encode($userData);
        exit;
    }

    // User doesn't exist, create new user
    $characterName = $selectedCharacter ? $selectedCharacter['name'] : $gtaWorldUsername;
    
    $insertStmt = $pdo->prepare("
        INSERT INTO users (
            name, 
            email, 
            discord, 
            avatar_url, 
            gta_world_id, 
            gta_world_username, 
            is_dealer, 
            is_staff, 
            created_at, 
            last_login
        ) VALUES (?, ?, ?, ?, ?, ?, false, false, NOW(), NOW())
    ");
    
    $insertStmt->execute([
        $characterName,
        $email,
        $discord,
        $avatarUrl,
        $gtaWorldId,
        $gtaWorldUsername
    ]);
    
    $userId = $pdo->lastInsertId();
    
    // Create user's wallet/balance record
    $walletStmt = $pdo->prepare("INSERT INTO user_wallets (user_id, balance) VALUES (?, 0)");
    $walletStmt->execute([$userId]);
    
    // Return the new user data
    $userData = [
        'id' => $userId,
        'name' => $characterName,
        'email' => $email,
        'discord' => $discord,
        'avatar_url' => $avatarUrl,
        'is_dealer' => false,
        'is_staff' => false,
        'staff_role' => null,
        'company_name' => null,
        'gta_world_id' => $gtaWorldId,
        'gta_world_username' => $gtaWorldUsername
    ];
    
    echo json_encode($userData);

} catch (Exception $e) {
    error_log("User authentication error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>
