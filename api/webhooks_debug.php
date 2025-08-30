<?php
// Debug version of webhooks.php to isolate the 500 error
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting webhooks debug...\n";

// Add CORS headers for cross-origin requests
header('Access-Control-Allow-Origin: https://carspot.site');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true);

echo "Headers set...\n";

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

echo "Preflight check passed...\n";

header('Content-Type: application/json');

echo "Content-Type header set...\n";

echo "Including database.php...\n";
require_once 'database.php';

echo "Database.php included...\n";

try {
    echo "Getting database connection...\n";
    $pdo = getDatabaseConnection();
    echo "Database connection successful!\n";
} catch (Exception $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

echo "Database connection established...\n";

$action = $_GET['action'] ?? '';
echo "Action: " . $action . "\n";

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        echo "Processing GET request...\n";
        if ($action === 'get_configs') {
            echo "Getting webhook configs...\n";
            getWebhookConfigs($pdo);
        } else {
            echo "Invalid action: " . $action . "\n";
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    case 'POST':
        echo "Processing POST request...\n";
        if ($action === 'save_config') {
            echo "Saving webhook config...\n";
            saveWebhookConfig($pdo);
        } else {
            echo "Invalid action: " . $action . "\n";
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        break;
        
    default:
        echo "Method not allowed: " . $_SERVER['REQUEST_METHOD'] . "\n";
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

function getWebhookConfigs($pdo) {
    echo "Inside getWebhookConfigs function...\n";
    
    try {
        echo "Checking if table exists...\n";
        // First check if the table exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'webhook_configs'");
        $tableExists = $stmt->fetchColumn();
        
        echo "Table exists check result: " . ($tableExists ? 'true' : 'false') . "\n";
        
        if (!$tableExists) {
            echo "Creating table...\n";
            // Create the table if it doesn't exist
            $createTableSQL = "
            CREATE TABLE IF NOT EXISTS webhook_configs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                webhook_id VARCHAR(100) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                type ENUM('public', 'private', 'system') NOT NULL DEFAULT 'public',
                url TEXT NOT NULL,
                enabled BOOLEAN DEFAULT TRUE,
                message_template TEXT NOT NULL,
                example_data JSON DEFAULT '{}',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            
            $pdo->exec($createTableSQL);
            echo "Table created successfully!\n";
            
            // Insert default webhook configurations
            $insertSQL = "
            INSERT INTO webhook_configs (webhook_id, name, description, type, url, enabled, message_template, example_data) VALUES
            ('new-postings', 'New Postings', 'Triggered when a user or dealer posts a new vehicle', 'public', '', TRUE, '🚗 **{username}** posted a new vehicle!\n**{make} {model}** - ${price}\n[View Posting]({posting_url})', '{\"username\": \"JohnDoe\", \"make\": \"BMW\", \"model\": \"M3\", \"price\": \"45,000\", \"posting_url\": \"https://carspot.site/cars/123\"}'),
            ('new-featured', 'New Featured', 'Triggered when a vehicle becomes featured', 'public', '', TRUE, '⭐ **{username}** has a new featured vehicle!\n**{make} {model}** - ${price}\n[View Featured Posting]({posting_url})', '{\"username\": \"PremiumDealer\", \"make\": \"Mercedes\", \"model\": \"AMG GT\", \"price\": \"125,000\", \"posting_url\": \"https://carspot.site/cars/456\"}'),
            ('price-alert', 'Price Changes', 'Triggered when vehicle prices change', 'public', '', TRUE, '💰 **Price Update** for {make} {model}\n**Old Price:** ${old_price} → **New Price:** ${new_price}\n[View Posting]({posting_url})', '{\"username\": \"Audi\", \"model\": \"RS6\", \"old_price\": \"85,000\", \"new_price\": \"79,500\", \"posting_url\": \"https://carspot.site/cars/789\"}'),
            ('sold', 'Vehicle Sold', 'Triggered when a vehicle is marked as sold', 'public', '', TRUE, '✅ **{make} {model}** has been sold!\n**Seller:** {username}\n**Final Price:** ${price}', '{\"make\": \"Porsche\", \"model\": \"911\", \"username\": \"SportsCarDealer\", \"price\": \"95,000\"}'),
            ('new-user', 'New User Registration', 'Triggered when a new user joins CarSpot', 'private', '', TRUE, '👋 **{username}** has joined CarSpot!', '{\"username\": \"NewUser123\"}'),
            ('dealer-application', 'Dealer Application', 'Triggered when someone applies to become a dealer', 'private', '', TRUE, '🏢 **{username}** has applied to become a dealer!\n[Review Application]({application_url})', '{\"username\": \"AspiringDealer\", \"application_url\": \"https://carspot.site/admin/dealer-applications/123\"}'),
            ('dealer-payment', 'Dealer Payment', 'Triggered when a dealer pays their membership dues', 'private', '', TRUE, '💳 **{username}** has paid their dealer membership dues!\n**Amount:** ${amount}\n**Plan:** {plan}', '{\"username\": \"PremiumDealer\", \"amount\": \"$99.99\", \"plan\": \"Premium Monthly\"}'),
            ('tickets', 'Support Tickets', 'Triggered for ticket updates (creation, assignment, resolution)', 'system', '', TRUE, '🎫 **{action}**\n**User:** {username}\n**Subject:** {subject}\n[View Ticket]({ticket_url})', '{\"action\": \"New ticket submitted\", \"username\": \"User123\", \"subject\": \"Payment issue\", \"ticket_url\": \"https://carspot.site/admin/tickets/123\"}'),
            ('reports', 'System Reports', 'Triggered for report updates (creation, investigation, resolution)', 'system', '', TRUE, '🚨 **{action}**\n**Reporter:** {username}\n**Type:** {report_type}\n[View Report]({report_url})', '{\"action\": \"New report submitted\", \"username\": \"Reporter456\", \"report_type\": \"Inappropriate content\", \"report_url\": \"https://carspot.site/admin/reports/456\"}')
            ";
            
            $pdo->exec($insertSQL);
            echo "Default configurations inserted successfully!\n";
        }
        
        echo "Fetching configurations...\n";
        // Now fetch the configurations
        $stmt = $pdo->query("SELECT * FROM webhook_configs ORDER BY type, name");
        $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Found " . count($configs) . " configurations\n";
        
        $response = [
            'success' => true,
            'configs' => $configs
        ];
        
        echo "Sending response...\n";
        echo json_encode($response);
        
    } catch (Exception $e) {
        echo "Error in getWebhookConfigs: " . $e->getMessage() . "\n";
        error_log('Webhook API: Failed to fetch webhook configs: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to fetch webhook configs: ' . $e->getMessage()
        ]);
    }
}

function saveWebhookConfig($pdo) {
    echo "Inside saveWebhookConfig function...\n";
    
    try {
        echo "Reading input...\n";
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Invalid JSON input');
        }
        
        echo "Input received: " . json_encode($input) . "\n";
        
        $webhookId = $input['webhook_id'] ?? '';
        $url = $input['url'] ?? '';
        $enabled = $input['enabled'] ?? true;
        $messageTemplate = $input['message_template'] ?? '';
        
        if (empty($webhookId)) {
            throw new Exception('Webhook ID is required');
        }
        
        echo "Checking if config exists...\n";
        // Check if config exists
        $stmt = $pdo->prepare("SELECT id FROM webhook_configs WHERE webhook_id = ?");
        $stmt->execute([$webhookId]);
        
        if ($stmt->fetch()) {
            echo "Updating existing config...\n";
            // Update existing config
            $stmt = $pdo->prepare("UPDATE webhook_configs SET url = ?, enabled = ?, message_template = ?, updated_at = CURRENT_TIMESTAMP WHERE webhook_id = ?");
            $stmt->execute([$url, $enabled, $messageTemplate, $webhookId]);
        } else {
            echo "Inserting new config...\n";
            // Insert new config
            $stmt = $pdo->prepare("INSERT INTO webhook_configs (webhook_id, url, enabled, message_template) VALUES (?, ?, ?, ?)");
            $stmt->execute([$webhookId, $url, $enabled, $messageTemplate]);
        }
        
        echo "Config saved successfully!\n";
        echo json_encode([
            'success' => true,
            'message' => 'Webhook configuration saved successfully'
        ]);
        
    } catch (Exception $e) {
        echo "Error in saveWebhookConfig: " . $e->getMessage() . "\n";
        error_log('Webhook API: Failed to save webhook config: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to save webhook config: ' . $e->getMessage()
        ]);
    }
}

echo "Script completed successfully!\n";
?>
