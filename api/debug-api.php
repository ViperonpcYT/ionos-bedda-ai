<?php
declare(strict_types=1);

// Debug endpoint - captures actual errors
try {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    
    $errors = [];
    
    // Test 1: Basic PHP works
    $errors[] = "PHP OK: " . PHP_VERSION;
    
    // Test 2: Can we read .env?
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) {
        $errors[] = "ERROR: .env not found at $envFile";
    } elseif (!is_readable($envFile)) {
        $errors[] = "ERROR: .env exists but not readable";
    } else {
        $errors[] = "ENV OK: .env readable";
    }
    
    // Test 3: Can we load bootstrap?
    try {
        require_once __DIR__ . '/lib/bootstrap.php';
        $errors[] = "BOOTSTRAP OK: loaded";
    } catch (Throwable $e) {
        $errors[] = "BOOTSTRAP FAIL: " . $e->getMessage();
        $errors[] = "File: " . $e->getFile() . ":" . $e->getLine();
    }
    
    // Test 4: Config loading
    try {
        $config = bedda_load_config();
        if ($config) {
            $errors[] = "CONFIG OK: loaded (DB_HOST=" . ($config['DB_HOST'] ?? 'missing') . ")";
        } else {
            $errors[] = "CONFIG EMPTY: null returned";
        }
    } catch (Throwable $e) {
        $errors[] = "CONFIG FAIL: " . $e->getMessage();
    }
    
    // Test 5: Can we check if DB is configured
    try {
        $configured = bedda_db_configured();
        $errors[] = "DB_CONFIG: " . ($configured ? "yes" : "no");
    } catch (Throwable $e) {
        $errors[] = "DB_CONFIG FAIL: " . $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode(['debug' => true, 'results' => $errors], JSON_PRETTY_PRINT);
    
} catch (Throwable $e) {
    header('Content-Type: text/plain');
    echo "FATAL: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString();
}
