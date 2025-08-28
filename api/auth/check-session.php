<?php
session_start();
require_once '../config.php';
require_once '../database.php';

header('Content-Type: application/json');

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
    $stmt = $pdo->prepare("SELECT id, username, forum_id, email, balance, routing_number, phone_number, created_at FROM users WHERE id = ?");
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
    
    // Check if profile is complete
    $profileComplete = !empty($user['routing_number']) && !empty($user['phone_number']);
    
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'forum_id' => $user['forum_id'],
            'email' => $user['email'],
            'balance' => $user['balance'],
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
