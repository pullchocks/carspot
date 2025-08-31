<?php
/**
 * Test New User Webhook
 * Tests the webhook system for new user registrations
 */

require_once 'config_mysql.php';
require_once 'database_mysql.php';

header('Content-Type: application/json');

try {
    $pdo = getConnection();
    
    // Test data for new user
    $testData = [
        'webhook_id' => 'new-user',
        'data' => [
            'username' => 'TestUser_' . time(),
            'gta_world_id' => 99999,
            'gta_world_username' => 'testuser',
            'user_id' => 99999
        ]
    ];
    
    // First, check if webhook config exists
    $stmt = $pdo->prepare("SELECT * FROM webhook_configs WHERE webhook_id = 'new-user'");
    $stmt->execute();
    $webhookConfig = $stmt->fetch();
    
    if (!$webhookConfig) {
        echo json_encode(['error' => 'New user webhook configuration not found']);
        exit;
    }
    
    echo "📋 Webhook Config Found:\n";
    echo "- Name: {$webhookConfig['name']}\n";
    echo "- Enabled: " . ($webhookConfig['enabled'] ? 'Yes' : 'No') . "\n";
    echo "- URL: " . (empty($webhookConfig['url']) ? 'Not configured' : $webhookConfig['url']) . "\n";
    echo "- Template: {$webhookConfig['message_template']}\n\n";
    
    // Test the webhook trigger
    $webhookPayload = json_encode($testData);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://carspot.site/api/webhooks.php?action=trigger');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $webhookPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $webhookResponse = curl_exec($ch);
    $webhookHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "🚀 Webhook Test Results:\n";
    echo "- HTTP Code: $webhookHttpCode\n";
    echo "- Response: $webhookResponse\n\n";
    
    if ($webhookHttpCode === 200) {
        echo "✅ Webhook test successful!\n";
        
        // Check if webhook event was created in database
        $stmt = $pdo->prepare("
            SELECT * FROM webhook_events 
            WHERE webhook_id = 'new-user' 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $webhookEvent = $stmt->fetch();
        
        if ($webhookEvent) {
            echo "📊 Webhook Event Created:\n";
            echo "- ID: {$webhookEvent['id']}\n";
            echo "- Status: {$webhookEvent['status']}\n";
            echo "- Created: {$webhookEvent['created_at']}\n";
            echo "- Data: {$webhookEvent['event_data']}\n";
        }
        
    } else {
        echo "❌ Webhook test failed!\n";
    }
    
} catch (Exception $e) {
    error_log('Webhook test error: ' . $e->getMessage());
    echo json_encode(['error' => 'Webhook test failed: ' . $e->getMessage()]);
}
?>
