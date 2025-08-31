<?php
// Add CORS headers for cross-origin requests
header('Access-Control-Allow-Origin: https://carspot.site');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed. Only POST requests are accepted.']);
    exit;
}

header('Content-Type: application/json');

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $webhookId = $input['webhookId'] ?? '';
    $webhookUrl = $input['webhookUrl'] ?? '';
    $data = $input['data'] ?? [];
    $template = $input['template'] ?? '';
    
    if (empty($webhookUrl)) {
        throw new Exception('Webhook URL is required');
    }
    
    // If template is empty, get the default template from database
    if (empty($template)) {
        require_once 'database.php';
        try {
            $pdo = getDatabaseConnection();
            $stmt = $pdo->prepare("SELECT message_template FROM webhook_configs WHERE webhook_id = ?");
            $stmt->execute([$webhookId]);
            $result = $stmt->fetch();
            
            if ($result && !empty($result['message_template'])) {
                $template = $result['message_template'];
            } else {
                // Fallback to hardcoded defaults if database lookup fails
                $defaultTemplates = [
                    'new-postings' => '🚗 **{username}** posted a new vehicle!\n**{make} {model}** - ${price}\n[View Posting]({posting_url})',
                    'new-featured' => '⭐ **{username}** has a new featured vehicle!\n**{make} {model}** - ${price}\n[View Featured Posting]({posting_url})',
                    'price-alert' => '💰 **Price Update** for {make} {model}\n**Old Price:** ${old_price} → **New Price:** ${new_price}\n[View Posting]({posting_url})',
                    'sold' => '✅ **{make} {model}** has been sold!\n**Seller:** {username}\n**Final Price:** ${price}',
                    'new-user' => '👋 **{username}** has joined CarSpot!',
                    'dealer-application' => '🏢 **{username}** has applied to become a dealer!\n[Review Application]({application_url})',
                    'dealer-payment' => '💳 **{username}** has paid their dealer membership dues!\n**Amount:** ${amount}\n**Plan:** {plan}',
                    'tickets' => '🎫 **{action}**\n**User:** {username}\n**Subject:** {subject}\n**Status:** {status}\n[View Ticket]({ticket_url})',
                    'reports' => '🚨 **{action}**\n**Reporter:** {username}\n**Type:** {report_type}\n**Content:** {content}\n[View Report]({report_url})'
                ];
                $template = $defaultTemplates[$webhookId] ?? '**Test Message**\nThis is a test webhook for {webhook_id}';
            }
        } catch (Exception $e) {
            // If database lookup fails, use a basic template
            $template = '**Test Message**\nThis is a test webhook for ' . $webhookId;
        }
    }
    
    // Process the message template with the provided data
    $message = processTemplate($template, $data);
    
    // Log for debugging
    error_log("Webhook test - ID: $webhookId, Template: " . substr($template, 0, 100) . "...");
    
    // Send to Discord webhook
    $discordResponse = sendDiscordWebhook($webhookUrl, $message);
    
    echo json_encode([
        'success' => true,
        'message' => 'Webhook sent successfully',
        'webhookId' => $webhookId,
        'processedMessage' => $message,
        'discordResponse' => $discordResponse
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send webhook: ' . $e->getMessage(),
        'webhookId' => $webhookId ?? '',
        'error' => $e->getMessage()
    ]);
}

function processTemplate($template, $data) {
    // Replace {variable} placeholders with actual data
    $message = $template;
    
    foreach ($data as $key => $value) {
        $placeholder = '{' . $key . '}';
        $message = str_replace($placeholder, $value, $message);
    }
    
    // Clean up any remaining placeholders
    $message = preg_replace('/\{[^}]+\}/', '[MISSING DATA]', $message);
    
    return $message;
}

function sendDiscordWebhook($webhookUrl, $message) {
    $payload = [
        'content' => $message,
        'username' => 'CarSpot Bot',
        'avatar_url' => 'https://carspot.site/carspotava.png'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhookUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: CarSpot-Webhook/1.0'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error) {
        throw new Exception('cURL error: ' . $error);
    }
    
    // Discord returns 204 on success, anything else is an error
    if ($httpCode !== 204) {
        $responseData = json_decode($response, true);
        $errorMessage = $responseData['message'] ?? 'Unknown Discord error';
        throw new Exception('Discord API error: ' . $errorMessage . ' (HTTP ' . $httpCode . ')');
    }
    
    return [
        'httpCode' => $httpCode,
        'success' => true,
        'message' => 'Message sent to Discord successfully'
    ];
}
?>
