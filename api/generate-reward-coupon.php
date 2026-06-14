<?php
/**
 * Generate a reward coupon based on the logged-in user's points balance.
 */
require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/customers-schema.php';
require_once __DIR__ . '/lib/coupons-schema.php';
require_once __DIR__ . '/lib/points-ledger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Ensure session
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => true,
        'cookie_samesite' => 'Strict'
    ]);
}

if (empty($_SESSION['customer_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in to generate a reward coupon.']);
    exit;
}

try {
    $customersDB = getCustomersDatabase();
    onlybikes_ensure_customers_schema($customersDB);
    $points = onlybikes_customers_fetch_points($customersDB, intval($_SESSION['customer_id']));

    if ($points === null) {
        echo json_encode(['success' => false, 'message' => 'Account not found.']);
        exit;
    }
    if ($points < 10) {
        echo json_encode(['success' => false, 'message' => 'You need at least 10 points to generate a reward coupon. Start shopping to earn more points!']);
        exit;
    }

    // Check if user wants a specific discount amount (for smaller orders)
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? [];
    $targetDiscount = isset($data['target_discount']) ? floatval($data['target_discount']) : null;

    $discountValue = 0;
    $pointsToDeduct = 0;

    if ($targetDiscount && $targetDiscount > 0) {
        // Reverse parabolic formula: points = (discount / 0.03) ^ (1/1.2)
        $pointsNeeded = round(pow($targetDiscount / 0.03, 1 / 1.2));
        
        if ($pointsNeeded > $points) {
            echo json_encode(['success' => false, 'message' => "You need {$pointsNeeded} points for a \${$targetDiscount} discount. You have {$points} points."]);
            exit;
        }
        
        $discountValue = $targetDiscount;
        $pointsToDeduct = $pointsNeeded;
    } else {
        // Use all points with parabolic formula
        $discountValue = round(pow($points, 1.2) * 0.03, 2);
        $pointsToDeduct = $points;
    }

    if ($discountValue < 1.00) {
        echo json_encode(['success' => false, 'message' => 'Not enough points for a minimum $1.00 discount.']);
        exit;
    }

    $couponDB = getCouponDatabase();
    ensureCouponSchema($couponDB);
    $code = null;
    for ($i = 0; $i < 10; $i++) {
        $candidate = 'OB-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        $check = $couponDB->prepare('SELECT id FROM coupon_codes WHERE code = ? LIMIT 1');
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            $code = $candidate;
            break;
        }
    }
    if (!$code) {
        $code = 'OB-' . strtoupper(substr(md5(uniqid('', true)), 0, 10));
    }

    onlybikes_points_coupon_redeem(
        $customersDB,
        (int) $_SESSION['customer_id'],
        $pointsToDeduct,
        $discountValue,
        $code
    );

    $newBalance = onlybikes_customers_fetch_points($customersDB, (int) $_SESSION['customer_id']);

    $expires = date('Y-m-d H:i:s', strtotime('+14 days'));
    $ins = $couponDB->prepare("
        INSERT INTO coupon_codes (code, type, value, min_total, expires_at, usage_limit, used_count, active, deleted)
        VALUES (?, 'fixed', ?, 25.00, ?, 1, 0, 1, 0)
    ");
    $ins->execute([$code, $discountValue, $expires]);

    echo json_encode([
        'success' => true,
        'code' => $code,
        'discount' => $discountValue,
        'displayValue' => '$' . number_format($discountValue, 2),
        'points' => $newBalance ?? 0,
        'expires' => $expires,
        'message' => "Your $" . number_format($discountValue, 2) . " OFF coupon is ready!"
    ]);

} catch (Throwable $e) {
    error_log('[BEDDA] generate-reward-coupon error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to generate coupon right now. Please try again.']);
}
