<?php
// Secure session settings (path / so /api/* can share admin session when needed)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_path', '/');

// Define API_PATH once, safely
if (!defined('API_PATH')) {
    define('API_PATH', dirname(__DIR__) . '/api');
}

// Load central configuration
if (file_exists(API_PATH . '/secure-config.php')) {
    require_once API_PATH . '/secure-config.php';
} else {
    die('ERROR: secure-config.php not found at ' . API_PATH . '/secure-config.php');
}

// -------------------- SECURITY --------------------
// Regenerate hashes: php dev/generate-admin-hashes.php "your-password" "your-access-key"
// secure-config.php only overrides these when api/.env sets ADMIN_PASSWORD_HASH.
if (!defined('ADMIN_PASSWORD_HASH')) {
    define('ADMIN_PASSWORD_HASH', '$2y$12$GxubCzFzkOJYgUghYdKZVOD34xklut5jLhoyJ7vc8lpcnCL4jCSQi');
}
if (!defined('SECRET_KEY_HASH')) {
    define('SECRET_KEY_HASH', '$2y$12$qTGYsq1WjPU2TYSUf3A8TuJc84OFWQyvmoSEvEZu70UZoQDG1.Hli');
}

// IPs that skip the access-key gate. Add yours from the login page "Your IP" line after Cloudflare proxy.
$IP_WHITELIST = [
    '2607:f2c0:edc2:3c0:ddab:a4b8:b2a4:478f',
    '2607:f2c0:edc2:3c0:54b4:9113:79cd:1c07',
];

define('EMAIL_ADMIN_SESSION', 'bedda_email_admin');

// -------------------- SENDING LIMITS --------------------
define('BATCH_SIZE', 30);
define('BATCH_DELAY', 70);
define('DAILY_CAP', 1800);
define('MAX_RETRIES', 3);