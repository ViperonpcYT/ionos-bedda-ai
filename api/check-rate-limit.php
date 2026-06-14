<?php
/**
 * Bedda Rate Limit Check
 * Allows frontend to check if CAPTCHA is required before submitting
 * 
 * Location: /api/check-rate-limit.php
 */

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/rate-limiter.php';

// Set security headers
setSecurityHeaders();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Invalid request method. Only POST is allowed.', 405);
}

// Get and decode JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    errorResponse('Invalid JSON data provided.');
}

$email = sanitizeInput($data['email'] ?? '', 'email');
$phone = sanitizeInput($data['phone'] ?? '', 'phone');

$rateLimiter = new RateLimiter();
$status = $rateLimiter->getStatus($email, $phone);
$check = $rateLimiter->canSubmitOrder($email, $phone);

jsonResponse(true, 'Rate limit status retrieved', [
    'status' => $status,
    'canSubmit' => $check['allowed'],
    'requiresCaptcha' => $check['requiresCaptcha'] ?? false,
    'blocked' => $check['blocked'] ?? false,
    'score' => $check['score'] ?? 0,
    'reasons' => $check['reasons'] ?? [],
    'captchaSiteKey' => CAPTCHA_SITE_KEY
]);
