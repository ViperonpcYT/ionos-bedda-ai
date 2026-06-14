<?php
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-errors.log');
header('Content-Type: application/json; charset=utf-8');

if (!file_exists(__DIR__ . '/secure-config.php') || !file_exists(__DIR__ . '/rate-limiter.php')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error.']);
    exit;
}

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/security-helpers.php';
require_once __DIR__ . '/rate-limiter.php';
require_once __DIR__ . '/lib/coupons-schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    
    if (!$data || empty($data['code']) || !isset($data['subtotal'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $code = strtoupper(trim($data['code']));
    $subtotal = floatval($data['subtotal']);
    $ip = getClientIP();

    $rateLimiter = new RateLimiter();
    if (!$rateLimiter->canValidateCoupon($ip, $code)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many attempts.']);
        exit;
    }

    $pdo = getCouponDatabase();
    $coupon = fetchActiveCouponByCode($pdo, $code);

    if (!$coupon) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired code']);
        exit;
    }

    if ($coupon['expires_at'] && new DateTime($coupon['expires_at']) < new DateTime()) {
        echo json_encode(['success' => false, 'message' => 'Code has expired']);
        exit;
    }

    if ($subtotal < $coupon['min_total']) {
        echo json_encode(['success' => false, 'message' => 'Minimum order of $' . number_format($coupon['min_total'], 2) . ' required']);
        exit;
    }

    $effectiveUsed = function_exists('couponEffectiveUsedCount')
        ? couponEffectiveUsedCount($code)
        : (int) ($coupon['used_count'] ?? 0);
    if ($coupon['usage_limit'] && $effectiveUsed >= $coupon['usage_limit']) {
        echo json_encode(['success' => false, 'message' => 'Usage limit reached']);
        exit;
    }

    $discount = ($coupon['type'] === 'percent') ? round($subtotal * ($coupon['value'] / 100), 2) : min($coupon['value'], $subtotal);
    $displayValue = ($coupon['type'] === 'percent') ? $coupon['value'] . '%' : '$' . number_format($coupon['value'], 2);
    $newTotal = round(max(0, $subtotal - $discount), 2);

    echo json_encode(['success' => true, 'code' => $coupon['code'], 'discount' => $discount, 'displayValue' => $displayValue, 'newTotal' => $newTotal, 'message' => 'Code applied successfully']);

} catch (PDOException $e) {
    error_log('[BEDDA] Coupon DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
} catch (Throwable $e) {
    error_log('[BEDDA] Coupon error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}