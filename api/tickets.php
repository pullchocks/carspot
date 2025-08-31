<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config_mysql.php';
require_once 'database_mysql.php';

try {
    $pdo = getConnection();
} catch (Exception $e) {
    error_log('Tickets API: Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'get_categories') {
    try {
        // Set proper headers FIRST - before any output
        header('Content-Type: application/json');
        
        // First, let's see what columns exist in the table
        $describeQuery = "DESCRIBE ticket_categories";
        $describeStmt = $pdo->prepare($describeQuery);
        $describeStmt->execute();
        $columns = $describeStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Now get the actual categories with all columns
        $query = "SELECT * FROM ticket_categories ORDER BY sort_order, name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log to error log instead of outputting HTML
        error_log('Tickets API: Found ' . count($categories) . ' categories');
        if (count($categories) > 0) {
            error_log('Tickets API: First category: ' . json_encode($categories[0]));
        }
        
        echo json_encode([
            'success' => true,
            'categories' => $categories
        ]);
        
    } catch (Exception $e) {
        error_log('Tickets API: Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get categories: ' . $e->getMessage()
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}
?>
