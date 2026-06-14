<?php
// Test script to debug secure-config.php
header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$results = [];

// Test 1: Check if secure-config.php exists
$results['file_exists'] = file_exists(__DIR__ . '/secure-config.php');

// Test 2: Try to load it with output buffering to catch any errors
ob_start();
try {
    require_once __DIR__ . '/secure-config.php';
    $results['load_success'] = true;
} catch (Exception $e) {
    $results['load_success'] = false;
    $results['error'] = $e->getMessage();
}
$results['output'] = ob_get_clean();

// Test 3: Check if getCustomersDatabase exists
$results['has_getCustomersDatabase'] = function_exists('getCustomersDatabase');

// Test 4: Check constants
if (function_exists('getCustomersDatabase')) {
    $results['CUSTOMERS_DB_HOST'] = defined('CUSTOMERS_DB_HOST') ? CUSTOMERS_DB_HOST : 'NOT DEFINED';
    $results['CUSTOMERS_DB_NAME'] = defined('CUSTOMERS_DB_NAME') ? CUSTOMERS_DB_NAME : 'NOT DEFINED';
}

echo json_encode($results);
