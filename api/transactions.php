<?php
session_start();
require_once 'config.php';
require_once 'database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'User not authenticated']);
    exit;
}

try {
    $pdo = getConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get query parameters
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(10, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $type = $_GET['type'] ?? null;
        
        // Build query
        $whereClause = "WHERE user_id = ?";
        $params = [$_SESSION['user_id']];
        
        if ($type) {
            $whereClause .= " AND type = ?";
            $params[] = $type;
        }
        
        // Get total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions $whereClause");
        $countStmt->execute($params);
        $totalCount = $countStmt->fetchColumn();
        
        // Get transactions
        $stmt = $pdo->prepare("
            SELECT id, type, amount, reference_id, routing_from, status, timestamp 
            FROM transactions 
            $whereClause 
            ORDER BY timestamp DESC 
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $transactions = $stmt->fetchAll();
        
        // Calculate pagination info
        $totalPages = ceil($totalCount / $limit);
        $hasNext = $page < $totalPages;
        $hasPrev = $page > 1;
        
        echo json_encode([
            'success' => true,
            'transactions' => $transactions,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'has_next' => $hasNext,
                'has_prev' => $hasPrev,
                'limit' => $limit
            ]
        ]);
        
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Transactions error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch transactions']);
}
?>
