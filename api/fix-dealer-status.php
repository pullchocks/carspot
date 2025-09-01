<?php
// Fix dealer status for approved dealers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'database_mysql_clean.php';

try {
    $pdo = getConnection();
    
    // Get the user ID from the request
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $data['user_id'] ?? null;
    $companyName = $data['company_name'] ?? null;
    $action = $data['action'] ?? 'check';
    
    if (!$userId && !$companyName) {
        throw new Exception('User ID or company name required');
    }
    
    // Find the user
    if ($userId) {
        $userQuery = "SELECT id, name, discord, is_dealer, company_name FROM users WHERE id = ?";
        $userStmt = $pdo->prepare($userQuery);
        $userStmt->execute([$userId]);
    } else {
        $userQuery = "SELECT id, name, discord, is_dealer, company_name FROM users WHERE company_name = ? OR name = ? OR discord = ?";
        $userStmt = $pdo->prepare($userQuery);
        $userStmt->execute([$companyName, $companyName, $companyName]);
    }
    
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    if ($action === 'fix') {
        // Update user to be a dealer
        $updateUserQuery = "UPDATE users SET is_dealer = 1, company_name = ? WHERE id = ?";
        $updateUserStmt = $pdo->prepare($updateUserQuery);
        $updateUserStmt->execute([$companyName ?: $user['name'], $user['id']]);
        
        // Check if dealer account exists
        $dealerQuery = "SELECT id FROM dealer_accounts WHERE owner_id = ?";
        $dealerStmt = $pdo->prepare($dealerQuery);
        $dealerStmt->execute([$user['id']]);
        $dealerAccount = $dealerStmt->fetch();
        
        if (!$dealerAccount) {
            // Create dealer account
            $createDealerQuery = "
                INSERT INTO dealer_accounts (name, company_name, owner_id, phone, discord, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'active', NOW())
            ";
            $createDealerStmt = $pdo->prepare($createDealerQuery);
            $createDealerStmt->execute([
                $companyName ?: $user['name'],
                $companyName ?: $user['name'],
                $user['id'],
                $user['discord'] ?: 'N/A',
                $user['discord'] ?: 'N/A'
            ]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'User dealer status updated successfully',
            'user_id' => $user['id']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'user' => $user,
            'message' => 'User found'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
