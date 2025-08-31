<?php
require_once 'config_mysql.php';

function getDatabaseConnection() {
    // Database configuration from config_mysql.php
    $host = DB_HOST;
    $port = DB_PORT;
    $dbname = DB_NAME;
    $user = DB_USER;
    $password = DB_PASSWORD;

    // Create connection string for MySQL
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Set MySQL-specific attributes
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET CHARACTER SET utf8mb4");
        $pdo->exec("SET character_set_connection=utf8mb4");
        
        return $pdo;
    } catch(PDOException $e) {
        // Log the error but don't output anything yet
        error_log('Database connection failed: ' . $e->getMessage());
        throw new Exception('Database connection failed: ' . $e->getMessage());
    }
}
?>
