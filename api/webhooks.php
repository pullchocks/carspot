<?php
// Add CORS headers for cross-origin requests
header('Access-Control-Allow-Origin: https://carspot.site');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header('Content-Type: application/json');

require_once 'database.php';

try {
    $pdo = getDatabaseConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($action === 'get_configs') {
            getWebhookConfigs($pdo);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    case 'POST':
        if ($action === 'save_config') {
            saveWebhookConfig($pdo);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    case 'PUT':
        if ($action === 'update_config') {
            updateWebhookConfig($pdo);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function getWebhookConfigs($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM webhook_configs ORDER BY type, name");
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'configs' => $configs
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to fetch webhook configs: ' . $e->getMessage()
        ]);
    }
}

function saveWebhookConfig($pdo) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Invalid JSON input');
        }
        
        $webhookId = $input['webhook_id'] ?? '';
        $url = $input['url'] ?? '';
        $enabled = $input['enabled'] ?? true;
        $messageTemplate = $input['message_template'] ?? '';
        
        if (empty($webhookId)) {
            throw new Exception('Webhook ID is required');
        }
        
        // Check if config exists
        $stmt = $pdo->prepare("SELECT id FROM webhook_configs WHERE webhook_id = ?");
        $stmt->execute([$webhookId]);
        
        if ($stmt->fetch()) {
            // Update existing config
            $stmt = $pdo->prepare("UPDATE webhook_configs SET url = ?, enabled = ?, message_template = ?, updated_at = CURRENT_TIMESTAMP WHERE webhook_id = ?");
            $stmt->execute([$url, $enabled, $messageTemplate, $webhookId]);
        } else {
            // Insert new config
            $stmt = $pdo->prepare("INSERT INTO webhook_configs (webhook_id, url, enabled, message_template) VALUES (?, ?, ?, ?)");
            $stmt->execute([$webhookId, $url, $enabled, $messageTemplate]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Webhook configuration saved successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to save webhook config: ' . $e->getMessage()
        ]);
    }
}

function updateWebhookConfig($pdo) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Invalid JSON input');
        }
        
        $webhookId = $input['webhook_id'] ?? '';
        $updates = $input['updates'] ?? [];
        
        if (empty($webhookId)) {
            throw new Exception('Webhook ID is required');
        }
        
        if (empty($updates)) {
            throw new Exception('No updates provided');
        }
        
        // Build dynamic update query
        $setClause = [];
        $params = [];
        
        foreach ($updates as $field => $value) {
            if (in_array($field, ['url', 'enabled', 'message_template'])) {
                $setClause[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (empty($setClause)) {
            throw new Exception('No valid fields to update');
        }
        
        $setClause[] = "updated_at = CURRENT_TIMESTAMP";
        $params[] = $webhookId;
        
        $sql = "UPDATE webhook_configs SET " . implode(', ', $setClause) . " WHERE webhook_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode([
            'success' => true,
            'message' => 'Webhook configuration updated successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to update webhook config: ' . $e->getMessage()
        ]);
    }
}
?>
