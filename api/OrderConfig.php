<?php
/**
 * @deprecated Use api/.env + secure-config.php → getOrderDatabase()
 */
require_once __DIR__ . '/secure-config.php';

if (!function_exists('getOrderDatabase')) {
    http_response_code(503);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Configure api/.env and secure-config.php']));
}
