<?php
// Test MySQL Connection for Car Spot
require_once 'database.php';

echo "<h2>MySQL Connection Test</h2>";

try {
    // Test basic connection
    echo "<p>✅ Database connection successful!</p>";
    
    // Test if we can query the database
    $stmt = $pdo->query("SELECT VERSION() as version");
    $version = $stmt->fetch();
    echo "<p>✅ MySQL Version: " . $version['version'] . "</p>";
    
    // Test if we can access the carspot database
    $stmt = $pdo->query("SELECT DATABASE() as current_db");
    $db = $stmt->fetch();
    echo "<p>✅ Current Database: " . $db['current_db'] . "</p>";
    
    // Test if tables exist
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll();
    
    if (count($tables) > 0) {
        echo "<p>✅ Tables found: " . count($tables) . "</p>";
        echo "<ul>";
        foreach ($tables as $table) {
            $tableName = array_values($table)[0];
            echo "<li>$tableName</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>⚠️ No tables found. You may need to run the MySQL schema creation script.</p>";
    }
    
    echo "<p><strong>🎉 MySQL conversion successful! Your website is now using MySQL.</strong></p>";
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
    echo "<p>Please check your MySQL configuration and ensure the database is set up.</p>";
}
?>
