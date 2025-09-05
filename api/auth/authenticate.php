<?php
// Configure session to ensure proper sharing
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_path', '/');

session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config_mysql.php';
require_once '../database_mysql.php';

// Ensure database constants are available
if (!defined('DB_HOST') || !defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME') || !defined('DB_PORT')) {
    error_log('Database configuration constants not found in authenticate.php');
    http_response_code(500);
    echo json_encode(['error' => 'Database configuration error']);
    exit;
}

// Validate that we have a working database connection
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('Database connection not available in authenticate.php');
    http_response_code(500);
    echo json_encode(['error' => 'Database connection not available']);
    exit;
}

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
    // Verify database connection is still active and refresh if needed
    try {
        $pdo->query('SELECT 1');
    } catch (PDOException $e) {
        // Connection lost, try to reconnect
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
                PDO::ATTR_TIMEOUT => 30,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        } catch (PDOException $e) {
            throw new Exception('Failed to reconnect to database: ' . $e->getMessage());
        }
    }
    
    $gtaWorldId = $gtaWorldUser['id'];
    $gtaWorldUsername = $gtaWorldUser['username'] ?? '';

    $avatarUrl = $gtaWorldUser['avatar_url'] ?? null;
    
    // Get the character ID from the selected character
    $characterId = $selectedCharacter ? $selectedCharacter['id'] : null;
    if (!$characterId) {
        http_response_code(400);
        echo json_encode(['error' => 'Character selection is required']);
        exit;
    }
    
    error_log("Authentication attempt - GTA World ID: $gtaWorldId, Character ID: $characterId, Username: $gtaWorldUsername");
    
    // Check if this specific character already exists in our system
    // First check by character ID (gta_world_id) - each character should have their own account
    error_log("Checking for existing user with character ID: $characterId");
    $stmt = $pdo->prepare("SELECT * FROM users WHERE gta_world_id = ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare user query: ' . implode(', ', $pdo->errorInfo()));
    }
    $stmt->execute([$characterId]);
    $existingUser = $stmt->fetch();
    
    if ($existingUser) {
        error_log("Found existing user: " . json_encode($existingUser));
    } else {
        error_log("No existing user found with character ID: $characterId");
    }

    if ($existingUser) {
        // User exists, check if character name has changed and update if necessary
        $characterName = $selectedCharacter ? $selectedCharacter['name'] : $gtaWorldUsername;
        $currentName = $existingUser['name'];
        
        // Update user data if character name or avatar has changed
        $updateFields = ['last_login = NOW()'];
        $updateParams = [$existingUser['id']];
        
        if ($currentName !== $characterName) {
            $updateFields[] = 'name = ?';
            $updateParams[] = $characterName;
            error_log("Character name changed from '$currentName' to '$characterName', updating database");
        }
        
        if ($existingUser['avatar_url'] !== $avatarUrl) {
            $updateFields[] = 'avatar_url = ?';
            $updateParams[] = $avatarUrl;
            error_log("Avatar URL changed, updating database");
        }
        
        // Update user record
        $updateStmt = $pdo->prepare("UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?");
        if (!$updateStmt) {
            throw new Exception('Failed to prepare update query: ' . implode(', ', $pdo->errorInfo()));
        }
        $updateStmt->execute($updateParams);
        
        // Check if user has dealer account
        $dealerStmt = $pdo->prepare("
            SELECT da.*, dur.role as user_role 
            FROM dealer_accounts da 
            INNER JOIN dealer_user_roles dur ON da.id = dur.dealer_account_id 
            WHERE dur.user_id = ? AND dur.is_active = TRUE AND da.status = 'active'
        ");
        if (!$dealerStmt) {
            throw new Exception('Failed to prepare dealer query: ' . implode(', ', $pdo->errorInfo()));
        }
        $dealerStmt->execute([$existingUser['id']]);
        $dealerAccount = $dealerStmt->fetch();
        
        // Set session variables for existing user
        $_SESSION['user_id'] = $existingUser['id'];
        $_SESSION['user_name'] = $characterName; // Use updated character name
        $_SESSION['user_avatar'] = $avatarUrl; // Use updated avatar
        $_SESSION['gta_world_id'] = $existingUser['gta_world_id'];
        
        $userData = [
            'id' => $existingUser['id'],
            'name' => $characterName, // Use updated character name

            'phone_number' => $existingUser['phone_number'] ?? null,
            'routing_number' => $existingUser['routing_number'] ?? null,
            'avatar_url' => $avatarUrl, // Use updated avatar
            'is_dealer' => !empty($dealerAccount),
            'staff_role' => $existingUser['staff_role'] ?? null,
            'company_name' => $dealerAccount['company_name'] ?? null,
            'gta_world_id' => $existingUser['gta_world_id'],
            'gta_world_username' => $existingUser['gta_world_username'],
            'created_at' => $existingUser['created_at'] ?? null
        ];
        
        error_log("Returning user data with name: " . $characterName . " (was: " . $currentName . ")");
        echo json_encode($userData);
        exit;
    }
    
    // If no user found by character ID, we can proceed to create a new character account
    // Each character gets their own account with their own gta_world_id
    error_log("No existing character found, proceeding to create new character account");

    // User doesn't exist, create new user
    $characterName = $selectedCharacter ? $selectedCharacter['name'] : $gtaWorldUsername;
    

    
    error_log("Creating new user with character name: $characterName, character ID: $characterId");
    
    $insertStmt = $pdo->prepare("
        INSERT INTO users (
            name, 
            avatar_url, 
            gta_world_id, 
            gta_world_username,
            is_dealer, 
            staff_role,
            created_at, 
            last_login
        ) VALUES (?, ?, ?, ?, false, NULL, NOW(), NOW())
    ");
    
    if (!$insertStmt) {
        throw new Exception('Failed to prepare user insert query: ' . implode(', ', $pdo->errorInfo()));
    }
    
    $insertStmt->execute([
        $characterName,
        $avatarUrl,
        $characterId,  // Use character ID as gta_world_id
        $gtaWorldUsername
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
    
    // Trigger webhook for new user registration
    try {
        // Get webhook configuration from database
        $webhookStmt = $pdo->prepare("SELECT * FROM webhook_configs WHERE webhook_id = 'new-user' AND enabled = 1");
        $webhookStmt->execute();
        $webhook = $webhookStmt->fetch();
        
        if ($webhook && !empty($webhook['url'])) {
            // Format message using template
            $message = $webhook['message_template'];
            
            // Debug logging for placeholder replacement
            error_log("Original template: " . $message);
            error_log("Character name: " . $characterName);
            error_log("Character ID: " . $characterId);
            error_log("GTA World username: " . $gtaWorldUsername);
            error_log("User ID: " . $userId);
            
            // Replace placeholders with actual values
            $message = str_replace('{username}', $characterName, $message);
            $message = str_replace('{gta_world_id}', $characterId, $message);
            $message = str_replace('{gta_world_username}', $gtaWorldUsername, $message);
            $message = str_replace('{user_id}', $userId, $message);
            
            error_log("Final formatted message: " . $message);
            
            // Send webhook notification
            $payload = ['content' => $message];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $webhook['url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                error_log("Webhook sent successfully for new user: $characterName (ID: $userId)");
            } else {
                error_log("Webhook failed for new user ID $userId: HTTP $httpCode");
            }
        } else {
            error_log("Webhook not configured or disabled for new user: $characterName (ID: $userId)");
        }
        
    } catch (Exception $webhookError) {
        error_log("Failed to send webhook for new user ID $userId: " . $webhookError->getMessage());
        // Continue without webhook - user can still function
    }
    
    // Set session variables for new user
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $characterName;
    $_SESSION['gta_world_id'] = $characterId;  // Store character ID as gta_world_id
    
    // Return the new user data
    $userData = [
        'id' => $userId,
        'name' => $characterName,

        'avatar_url' => $avatarUrl,
        'phone_number' => null,
        'routing_number' => null,
        'is_dealer' => false,
        'staff_role' => null,
        'company_name' => null,
        'gta_world_id' => $characterId,  // Store character ID as gta_world_id
        'gta_world_username' => $gtaWorldUsername,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($userData);

} catch (Exception $e) {
    error_log("User authentication error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("Input data: " . print_r($input, true));
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'details' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>
