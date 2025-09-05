<?php
session_start();
require_once '../database_mysql_clean.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'authenticated' => false,
        'user' => null
    ]);
    exit;
}

try {
    $pdo = getConnection();
    
    // Get user information
    $stmt = $pdo->prepare("SELECT id, name, phone_number, routing_number, avatar_url, is_dealer, staff_role, company_name, gta_world_id, gta_world_username, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // Clear invalid session
        session_unset();
        session_destroy();
        
        echo json_encode([
            'authenticated' => false,
            'user' => null
        ]);
        exit;
    }
    
    // Check if we have GTA World character data in session to verify name is current
    if (isset($_SESSION['gta_world_id']) && $_SESSION['gta_world_id'] == $user['gta_world_id']) {
        // If we have character data in session, use the session name (which should be current)
        $displayName = $_SESSION['user_name'] ?? $user['name'];
        $displayAvatar = $_SESSION['user_avatar'] ?? $user['avatar_url'];
    } else {
        // Fallback to database values
        $displayName = $user['name'];
        $displayAvatar = $user['avatar_url'];
    }
    
    // Check if profile is complete
    $profileComplete = !empty($user['routing_number']) && !empty($user['phone_number']);
    
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'id' => $user['id'],
            'name' => $displayName,

            'phone_number' => $user['phone_number'],
            'routing_number' => $user['routing_number'],
            'avatar_url' => $displayAvatar,
            'is_dealer' => $user['is_dealer'],
            'staff_role' => $user['staff_role'],
            'company_name' => $user['company_name'],
            'gta_world_id' => $user['gta_world_id'],
            'gta_world_username' => $user['gta_world_username'],
            'profile_complete' => $profileComplete,
            'created_at' => $user['created_at']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Session check error: " . $e->getMessage());
    echo json_encode([
        'authenticated' => false,
        'user' => null,
        'error' => 'Database error'
    ]);
}
?>
