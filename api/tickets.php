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
        // Output debug info directly to browser console
        echo "<!-- DEBUG: Starting get_categories -->\n";
        
        // First, let's see what columns exist in the table
        $describeQuery = "DESCRIBE ticket_categories";
        $describeStmt = $pdo->prepare($describeQuery);
        $describeStmt->execute();
        $columns = $describeStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<!-- DEBUG: Table columns: " . json_encode($columns) . " -->\n";
        
        // Now get the actual categories with all columns
        $query = "SELECT * FROM ticket_categories ORDER BY sort_order, name";
        echo "<!-- DEBUG: Query: " . $query . " -->\n";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<!-- DEBUG: Found " . count($categories) . " categories -->\n";
        echo "<!-- DEBUG: Raw categories: " . json_encode($categories) . " -->\n";
        
        // Check if we have the expected columns
        if (count($categories) > 0) {
            $firstCategory = $categories[0];
            echo "<!-- DEBUG: First category keys: " . json_encode(array_keys($firstCategory)) . " -->\n";
            echo "<!-- DEBUG: First category data: " . json_encode($firstCategory) . " -->\n";
        }
        
        // Set proper headers for JSON response
        header('Content-Type: application/json');
        
        echo json_encode([
            'success' => true,
            'categories' => $categories,
            'debug' => [
                'count' => count($categories),
                'columns' => $columns,
                'first_category' => $categories[0] ?? null
            ]
        ]);
        
    } catch (Exception $e) {
        echo "<!-- DEBUG: Error: " . $e->getMessage() . " -->\n";
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
