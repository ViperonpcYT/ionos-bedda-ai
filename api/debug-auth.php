<?php
// Ultimate debug script
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$result = ['step' => 'start'];

try {
    $result['step1'] = 'About to load secure-config';
    require_once __DIR__ . '/secure-config.php';
    $result['step2'] = 'secure-config loaded successfully';
    
    $result['has_getCustomersDB'] = function_exists('getCustomersDatabase');
    $result['CUSTOMERS_DB_DEFINED'] = defined('CUSTOMERS_DB_HOST');
    
    if (defined('CUSTOMERS_DB_HOST')) {
        $result['CUSTOMERS_DB_HOST'] = CUSTOMERS_DB_HOST;
        $result['CUSTOMERS_DB_NAME'] = CUSTOMERS_DB_NAME;
    }
    
    if (function_exists('getCustomersDatabase')) {
        $result['step3'] = 'About to connect to customers DB';
        try {
            $pdo = getCustomersDatabase();
            $result['step4'] = 'Connected to customers DB successfully';
        } catch (Exception $e) {
            $result['step4_error'] = $e->getMessage();
        }
    }
} catch (Exception $e) {
    $result['error'] = $e->getMessage();
    $result['file'] = $e->getFile();
    $result['line'] = $e->getLine();
}

echo json_encode($result);
