<?php
// Database configuration for Car Spot (MySQL)
// Updated with webhost MySQL credentials

// MySQL connection details
define('DB_HOST', 'localhost');
define('DB_PORT', '3306'); // MySQL default port
define('DB_NAME', 'carsoijc_carspot');
define('DB_USER', 'carsoijc_root');
define('DB_PASSWORD', ')O(I*U&Y0o9i8u7y');

// Optional: SSL configuration
define('DB_SSL', false); // Set to true if your webhost requires SSL

// API configuration
define('API_VERSION', '1.0');
define('MAX_RECORDS_PER_PAGE', 100);

// Security settings
define('ALLOWED_ORIGINS', ['*']); // Restrict this in production
define('RATE_LIMIT_PER_MINUTE', 60);
?>




