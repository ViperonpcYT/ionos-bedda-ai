<?php
/**
 * Bedda CAPTCHA Verification
 * Verifies hCaptcha responses
 * 
 * Location: /api/verify-captcha.php
 */

require_once __DIR__ . '/secure-config.php';

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

// Get CAPTCHA token
$captchaToken = $data['captchaToken'] ?? '';

if (empty($captchaToken)) {
    errorResponse('CAPTCHA token is required.');
}

// Verify with hCaptcha
$verifyData = [
    'secret' => CAPTCHA_SECRET_KEY,
    'response' => $captchaToken,
    'remoteip' => getClientIP()
];

$ch = curl_init(CAPTCHA_VERIFY_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($verifyData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    logSecurityEvent('captcha_verification_failed', [
        'http_code' => $httpCode,
        'response' => $response
    ]);
    errorResponse('CAPTCHA verification service unavailable. Please try again.');
}

$result = json_decode($response, true);

if (!$result) {
    errorResponse('Invalid CAPTCHA response.');
}

if ($result['success'] === true) {
    // CAPTCHA verified successfully
    // Store verification in session for order submission
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['captcha_verified'] = true;
    $_SESSION['captcha_verified_at'] = time();
    
    jsonResponse(true, 'CAPTCHA verified successfully!', [
        'verified' => true,
        'timestamp' => time()
    ]);
} else {
    // CAPTCHA failed
    $errorCodes = $result['error-codes'] ?? ['unknown'];
    
    logSecurityEvent('captcha_failed', [
        'error_codes' => $errorCodes,
        'ip' => getClientIP()
    ]);
    
    errorResponse('CAPTCHA verification failed. Please try again.');
}
