<?php
// Minimal webhook test - just get one record
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting minimal test...\n";

try {
    require_once 'config_mysql.php';
    echo "Config loaded\n";
    
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connected\n";
    
    // Test 1: Just count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM webhook_configs");
    $count = $stmt->fetch();
    echo "Count: " . $count['count'] . "\n";
    
    // Test 2: Get one record
    $stmt = $pdo->query("SELECT webhook_id, name, url FROM webhook_configs LIMIT 1");
    $webhook = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "First webhook: " . json_encode($webhook) . "\n";
    
    // Test 3: Try to JSON encode it
    $testData = [
        'success' => true,
        'webhook' => $webhook
    ];
    $json = json_encode($testData);
    if ($json === false) {
        echo "JSON error: " . json_last_error_msg() . "\n";
    } else {
        echo "JSON success: " . $json . "\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "Test complete!\n";
?>
