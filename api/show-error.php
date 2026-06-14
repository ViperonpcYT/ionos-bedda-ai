<?php
// Show the last PHP error
header('Content-Type: text/plain');

// Check all possible error log locations
$locations = [
    __DIR__ . '/../error_log',
    __DIR__ . '/../logs/error.log',
    __DIR__ . '/logs/error.log',
    __DIR__ . '/customer-auth-error.log',
    '/var/log/apache2/error.log',
    '/var/log/httpd/error.log',
    ini_get('error_log')
];

echo "=== PHP Error Log Locations ===\n\n";

foreach ($locations as $loc) {
    echo "Checking: $loc\n";
    if (file_exists($loc)) {
        echo "EXISTS! Size: " . filesize($loc) . " bytes\n";
        if (is_readable($loc)) {
            $content = file_get_contents($loc);
            echo "Last 2000 chars:\n";
            echo substr($content, -2000);
            echo "\n\n";
        } else {
            echo "NOT READABLE\n\n";
        }
    } else {
        echo "NOT FOUND\n\n";
    }
}

// Also try to trigger the error and catch it
echo "=== Testing customer-auth.php ===\n";
ob_start();
try {
    include __DIR__ . '/customer-auth.php';
} catch (Throwable $e) {
    echo "CAUGHT ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
$output = ob_get_clean();
echo $output;
