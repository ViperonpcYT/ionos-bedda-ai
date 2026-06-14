<?php
/**
 * @deprecated Use api/.env + secure-config.php → getNewsletterDatabase() / getDatabase()
 */
require_once __DIR__ . '/secure-config.php';

if (!function_exists('getNewsletterDatabase')) {
    if ($IS_DIRECT_ACCESS ?? false) {
        http_response_code(503);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Configure api/.env and secure-config.php']));
    }
    exit;
}

if (!function_exists('getDatabase')) {
    function getDatabase(): PDO
    {
        return getNewsletterDatabase();
    }
}
