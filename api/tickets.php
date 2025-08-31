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
        error_log('Tickets API: Getting categories...');
        
        $query = "SELECT * FROM ticket_categories WHERE is_active = 1 ORDER BY sort_order, name";
        error_log('Tickets API: Query: ' . $query);
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        error_log('Tickets API: Found ' . count($categories) . ' categories');
        error_log('Tickets API: Categories: ' . json_encode($categories));
        
        echo json_encode([
            'success' => true,
            'categories' => $categories
        ]);
        
    } catch (Exception $e) {
        error_log('Tickets API: Error getting categories: ' . $e->getMessage());
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
