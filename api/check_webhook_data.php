<?php
// Check webhook data in database
require_once 'config_mysql.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== Webhook Data Check ===\n";
    
    // Check table structure
    $stmt = $pdo->query("DESCRIBE webhook_configs");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Table structure:\n";
    foreach ($columns as $col) {
        echo "  - " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    
    // Check data
    $stmt = $pdo->query("SELECT webhook_id, name, url, enabled, message_template FROM webhook_configs LIMIT 5");
    $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nSample data:\n";
    foreach ($webhooks as $webhook) {
        echo "  - " . $webhook['webhook_id'] . ":\n";
        echo "    Name: " . $webhook['name'] . "\n";
        echo "    URL: '" . $webhook['url'] . "' (length: " . strlen($webhook['url']) . ")\n";
        echo "    Enabled: " . ($webhook['enabled'] ? 'true' : 'false') . "\n";
        echo "    Template: " . substr($webhook['message_template'], 0, 50) . "...\n";
        echo "\n";
    }
    
    // Check for NULL or empty URLs
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM webhook_configs WHERE url IS NULL OR url = ''");
    $emptyCount = $stmt->fetch();
    echo "Empty URLs: " . $emptyCount['count'] . "\n";
    
    // Check for NULL or empty message templates
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM webhook_configs WHERE message_template IS NULL OR message_template = ''");
    $emptyTemplateCount = $stmt->fetch();
    echo "Empty templates: " . $emptyTemplateCount['count'] . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
