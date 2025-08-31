<?php
/**
 * Webhook Event Processor
 * Processes pending webhook events from the database
 * Can be run manually or via cron job
 */

require_once 'config_mysql.php';
require_once 'database_mysql.php';

header('Content-Type: application/json');

try {
    $pdo = getConnection();
    
    // Get pending webhook events
    $stmt = $pdo->prepare("
        SELECT we.*, wc.url, wc.message_template, wc.enabled 
        FROM webhook_events we
        INNER JOIN webhook_configs wc ON we.webhook_id = wc.webhook_id
        WHERE we.status = 'pending' 
        AND wc.enabled = 1
        ORDER BY we.created_at ASC
        LIMIT 10
    ");
    
    $stmt->execute();
    $pendingEvents = $stmt->fetchAll();
    
    if (empty($pendingEvents)) {
        echo json_encode(['message' => 'No pending webhook events found']);
        exit;
    }
    
    $processed = 0;
    $failed = 0;
    
    foreach ($pendingEvents as $event) {
        try {
            // Format message using template
            $message = formatWebhookMessage($event['message_template'], json_decode($event['event_data'], true));
            
            // Send to Discord webhook if URL is set
            if (!empty($event['url'])) {
                $discordResponse = sendDiscordWebhook($event['url'], $message);
                
                if ($discordResponse['http_code'] === 200) {
                    // Mark as sent
                    $updateStmt = $pdo->prepare("
                        UPDATE webhook_events 
                        SET status = 'sent', response_code = ?, response_message = ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$discordResponse['http_code'], $discordResponse['response'], $event['id']]);
                    $processed++;
                    
                    echo "✅ Webhook sent successfully: {$event['webhook_id']} (ID: {$event['id']})\n";
                } else {
                    // Mark as failed
                    $updateStmt = $pdo->prepare("
                        UPDATE webhook_events 
                        SET status = 'failed', response_code = ?, response_message = ?, attempts = attempts + 1, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$discordResponse['http_code'], $discordResponse['response'], $event['id']]);
                    $failed++;
                    
                    echo "❌ Webhook failed: {$event['webhook_id']} (ID: {$event['id']}) - HTTP {$discordResponse['http_code']}\n";
                }
            } else {
                // No URL configured, mark as sent
                $updateStmt = $pdo->prepare("
                    UPDATE webhook_events 
                    SET status = 'sent', response_message = 'No Discord URL configured', updated_at = NOW() 
                    WHERE id = ?
                ");
                $updateStmt->execute([$event['id']]);
                $processed++;
                
                echo "ℹ️ Webhook processed (no URL): {$event['webhook_id']} (ID: {$event['id']})\n";
            }
            
        } catch (Exception $e) {
            // Mark as failed
            $updateStmt = $pdo->prepare("
                UPDATE webhook_events 
                SET status = 'failed', response_message = ?, attempts = attempts + 1, updated_at = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$e->getMessage(), $event['id']]);
            $failed++;
            
            echo "❌ Webhook error: {$event['webhook_id']} (ID: {$event['id']}) - {$e->getMessage()}\n";
        }
    }
    
    echo "\n📊 Processing complete: $processed processed, $failed failed\n";
    
} catch (Exception $e) {
    error_log('Webhook processor error: ' . $e->getMessage());
    echo json_encode(['error' => 'Webhook processor failed: ' . $e->getMessage()]);
}

function formatWebhookMessage($template, $data) {
    $message = $template;
    
    // Replace placeholders with actual data
    foreach ($data as $key => $value) {
        $message = str_replace('{' . $key . '}', $value, $message);
    }
    
    return $message;
}

function sendDiscordWebhook($url, $message) {
    $payload = [
        'content' => $message
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'http_code' => $httpCode,
        'response' => $response
    ];
}
?>
