<?php
/**
 * One-time lockout reset for email-admin login rate limit.
 * Visit once, then delete this file from the server.
 *
 * URL: /email-admin/clear-lockout.php?key=YOUR_CRON_SECRET
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$expected = '';
if (defined('CRON_SECRET') && CRON_SECRET !== '') {
    $expected = (string) CRON_SECRET;
} elseif (function_exists('cfg')) {
    $expected = (string) cfg('CRON_SECRET', '');
}
if ($expected === '') {
    $expected = trim((string) getenv('CRON_SECRET'));
}

$provided = trim((string) ($_GET['key'] ?? ''));
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$clientIP = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '');
$dir = dirname(__DIR__) . '/api/rate-limits';
$cleared = [];

if ($clientIP !== '' && is_dir($dir)) {
    $file = $dir . '/email-admin-login-' . md5($clientIP) . '.json';
    if (is_file($file)) {
        @unlink($file);
        $cleared[] = basename($file);
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Login lockout cleared for your IP. Try again now.',
    'ip' => $clientIP,
    'cleared' => $cleared,
    'next' => 'Delete clear-lockout.php from email-admin/ after use.',
], JSON_UNESCAPED_SLASHES);
