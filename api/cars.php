<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config_mysql.php';
require_once 'database_mysql_clean.php';

try {
    $pdo = getConnection();
} catch (Exception $e) {
    error_log('Cars API: Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'makes') {
    try {
        // Set proper headers FIRST - before any output
        header('Content-Type: application/json');
        
        // Get car makes with all columns
        $query = "SELECT * FROM car_makes WHERE is_active = TRUE ORDER BY sort_order, display_name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $makes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log to error log for debugging
        error_log('Cars API: Found ' . count($makes) . ' makes');
        if (count($makes) > 0) {
            error_log('Cars API: First make: ' . json_encode($makes[0]));
        }
        
        echo json_encode([
            'success' => true,
            'makes' => $makes
        ]);
        
    } catch (Exception $e) {
        error_log('Cars API: Error getting makes: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get car makes: ' . $e->getMessage()
        ]);
    }
} elseif ($action === 'models') {
    try {
        $makeId = $_GET['make_id'] ?? null;
        if (!$makeId) {
            http_response_code(400);
            echo json_encode(['error' => 'Make ID required for models']);
            exit;
        }
        
        // Set proper headers FIRST - before any output
        header('Content-Type: application/json');
        
        // Get car models for the specified make
        $query = "SELECT * FROM car_models WHERE make_id = ? AND is_active = TRUE ORDER BY sort_order, display_name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$makeId]);
        $models = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log to error log for debugging
        error_log('Cars API: Found ' . count($models) . ' models for make_id ' . $makeId);
        if (count($models) > 0) {
            error_log('Cars API: First model: ' . json_encode($models[0]));
        }
        
        echo json_encode([
            'success' => true,
            'models' => $models
        ]);
        
    } catch (Exception $e) {
        error_log('Cars API: Error getting models: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get car models: ' . $e->getMessage()
        ]);
    }
} elseif ($action === 'dealer') {
    try {
        $dealerId = $_GET['dealerId'] ?? null;
        
        if (empty($dealerId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Dealer ID is required']);
            exit;
        }
        
        // Get cars for a specific dealer
        $query = "
            SELECT c.*, 
                   cm.display_name as make_name,
                   cmo.display_name as model_name,
                   u.name as seller_name,
                   u.discord as seller_discord
            FROM cars c
            LEFT JOIN car_makes cm ON c.make_id = cm.id
            LEFT JOIN car_models cmo ON c.model_id = cmo.id
            LEFT JOIN users u ON c.seller_id = u.id
            WHERE c.dealer_id = ? AND c.status != 'removed'
            ORDER BY c.created_at DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$dealerId]);
        $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'cars' => $cars
        ]);
        
    } catch (Exception $e) {
        error_log('Cars API: Error getting dealer cars: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get dealer cars: ' . $e->getMessage()
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action. Use "makes", "models", or "dealer"']);
}
?>


