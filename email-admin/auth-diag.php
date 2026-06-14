<?php
/**
 * Diagnose email-admin password hash source (upload, check once, delete).
 * /email-admin/auth-diag.php?key=YOUR_CRON_SECRET
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$expected = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
$provided = trim((string) ($_GET['key'] ?? ''));
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$envHash = trim((string) (getenv('ADMIN_PASSWORD_HASH') ?: ''));
$active = defined('ADMIN_PASSWORD_HASH') ? (string) ADMIN_PASSWORD_HASH : '';

echo json_encode([
    'success' => true,
    'admin_password_hash_configured' => $active !== '',
    'hash_prefix' => $active !== '' ? substr($active, 0, 12) . '…' : null,
    'hash_length' => strlen($active),
    'env_override_present' => $envHash !== '',
    'env_matches_active' => $envHash !== '' && hash_equals($envHash, $active),
    'expected_prefix_for_config_fallback' => '$2y$12$Gxub…',
    'note' => 'Delete auth-diag.php after use. If env_override_present is true with wrong hash, remove ADMIN_PASSWORD_HASH from api/.env.',
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
