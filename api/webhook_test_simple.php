<?php
// Simple test - no headers, just basic output
echo "Test 1: Basic PHP output\n";

// Test 2: Database connection
try {
    require_once 'config_mysql.php';
    echo "Test 2: Config loaded\n";
    
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
    echo "Test 3: Database connected\n";
    
    // Test 4: Simple query
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM webhook_configs");
    $count = $stmt->fetch();
    echo "Test 4: Query successful - " . $count['count'] . " webhooks found\n";
    
    // Test 5: Check for empty URLs
    $stmt = $pdo->query("SELECT webhook_id, url FROM webhook_configs LIMIT 3");
    $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Test 5: Sample webhooks:\n";
    foreach ($webhooks as $webhook) {
        echo "  - " . $webhook['webhook_id'] . ": URL length = " . strlen($webhook['url']) . "\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "Test complete!\n";
?>
