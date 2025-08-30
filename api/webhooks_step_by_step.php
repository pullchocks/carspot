<?php
// Step-by-step debug version
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Step 1: Script started\n";
echo "Step 2: Setting headers\n";

// Add CORS headers for cross-origin requests
header('Access-Control-Allow-Origin: https://carspot.site');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

echo "Step 3: CORS headers set\n";

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    echo "Step 3.5: OPTIONS request handled\n";
    exit(0);
}

echo "Step 4: Setting content type\n";
header('Content-Type: application/json');

echo "Step 5: Including database.php\n";
require_once 'database.php';

echo "Step 6: Getting database connection\n";
try {
    $pdo = getDatabaseConnection();
    echo "Step 7: Database connected successfully\n";
} catch (Exception $e) {
    echo "ERROR at step 7: " . $e->getMessage() . "\n";
    exit;
}

echo "Step 8: Getting action parameter\n";
$action = $_GET['action'] ?? '';
echo "Action: " . $action . "\n";

echo "Step 9: Processing request method: " . $_SERVER['REQUEST_METHOD'] . "\n";

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        echo "Step 10: Processing GET request\n";
        if ($action === 'get_configs') {
            echo "Step 11: Calling getWebhookConfigs\n";
            getWebhookConfigs($pdo);
        } else {
            echo "Step 11: Invalid action\n";
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    default:
        echo "Step 10: Method not allowed\n";
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

echo "Step 12: Main switch completed\n";

function getWebhookConfigs($pdo) {
    echo "Step 13: Inside getWebhookConfigs function\n";
    
    try {
        echo "Step 14: Checking if table exists\n";
        // First check if the table exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'webhook_configs'");
        $tableExists = $stmt->fetchColumn();
        
        echo "Step 15: Table exists check result: " . ($tableExists ? 'true' : 'false') . "\n";
        
        if (!$tableExists) {
            echo "Step 16: Creating table (this should not happen)\n";
            // Table creation logic here...
        }
        
        echo "Step 17: Fetching configurations\n";
        // Now fetch the configurations
        $stmt = $pdo->query("SELECT * FROM webhook_configs ORDER BY type, name");
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Step 18: Found " . count($configs) . " configurations\n";
        
        echo "Step 19: Creating safe configs array\n";
        // Ensure all fields are safe for JSON encoding
        $safeConfigs = [];
        foreach ($configs as $config) {
            $safeConfigs[] = [
                'id' => (int)$config['id'],
                'webhook_id' => (string)$config['webhook_id'],
                'name' => (string)$config['name'],
                'description' => (string)$config['description'],
                'type' => (string)$config['type'],
                'url' => (string)$config['url'],
                'enabled' => (bool)$config['enabled'],
                'message_template' => (string)$config['message_template'],
                'example_data' => $config['example_data'] ?: '{}',
                'created_at' => (string)$config['created_at'],
                'updated_at' => (string)$config['updated_at']
            ];
        }
        
        echo "Step 20: Creating response array\n";
        $response = [
            'success' => true,
            'configs' => $safeConfigs
        ];
        
        echo "Step 21: JSON encoding response\n";
        $json = json_encode($response);
        if ($json === false) {
            echo "JSON encoding failed: " . json_last_error_msg() . "\n";
            exit;
        }
        
        echo "Step 22: Outputting JSON\n";
        echo $json;
        
        echo "Step 23: Function completed successfully\n";
        
    } catch (Exception $e) {
        echo "ERROR in getWebhookConfigs: " . $e->getMessage() . "\n";
        echo "Stack trace: " . $e->getTraceAsString() . "\n";
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to fetch webhook configs: ' . $e->getMessage()
        ]);
    }
}

echo "Step 24: Script completed successfully\n";
?>
