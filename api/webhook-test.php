<?php
// Simple test to see what's working
header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'message' => 'Basic webhook test working',
    'php_version' => PHP_VERSION,
    'time' => date('Y-m-d H:i:s')
]);
?>
