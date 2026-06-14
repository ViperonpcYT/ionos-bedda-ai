<?php
/**
 * OnlyBikes newsletter signup — double opt-in.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/php-errors.log');

$origin = rtrim((string) (getenv('SITE_ORIGIN') ?: getenv('SITE_URL') ?: 'https://onlybikes.shop'), '/');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/security-helpers.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/newsletter-subscribe-lib.php';

if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders();
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 10 * 1024) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Request too large.']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit;
}

foreach (['website', 'url', 'company', 'phone2', 'fax'] as $field) {
    if (!empty($data[$field])) {
        echo json_encode(['success' => true, 'message' => 'Subscribed successfully!']);
        exit;
    }
}

$email = trim((string) ($data['email'] ?? ''));
$name = trim((string) ($data['name'] ?? ''));

$result = onlybikes_newsletter_subscribe($email, $name);

if (!$result['success']) {
    $code = $result['code'] ?? '';
    http_response_code($code === 'db_error' || $code === 'no_db' ? 503 : 400);
}

echo json_encode([
    'success' => $result['success'],
    'message' => $result['message'],
    'data' => isset($result['confirmationEmailSent'])
        ? ['confirmationEmailSent' => $result['confirmationEmailSent']]
        : new stdClass(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
