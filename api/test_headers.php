<?php
// Test if headers can be set
header('Content-Type: text/plain');

echo "Headers test successful!\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "Server: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
echo "PHP Handler: " . php_sapi_name() . "\n";
?>
