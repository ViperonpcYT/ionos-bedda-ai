<?php
/**
 * Bedda Coupon Management - Admin Dashboard Endpoint
 * CRUD operations for coupon codes
 */

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/security-helpers.php';
require_once __DIR__ . '/lib/coupons-schema.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Strict');

$knownSessionNames = ['bedda_email_admin', 'bedda_admin_api'];
if (defined('EMAIL_ADMIN_SESSION') && EMAIL_ADMIN_SESSION !== '') {
    $knownSessionNames[] = EMAIL_ADMIN_SESSION;
}

$sessionToUse = 'bedda_admin_api';
foreach (array_unique($knownSessionNames) as $candidate) {
    if (isset($_COOKIE[$candidate])) {
        $sessionToUse = $candidate;
        break;
    }
}

session_name($sessionToUse);
session_start();

// HARD AUTH: session OR X-Admin-Key with brute-force protection + minimum attempts
$sessionOk = (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true)
    || (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)
    || (!empty($_SESSION['admin_authenticated']) && $_SESSION['admin_authenticated'] === true);
$apiKey = $_SERVER['HTTP_X_ADMIN_KEY'] ?? ($_POST['admin_key'] ?? ($_GET['admin_key'] ?? ''));
$VALID_API_KEYS = $GLOBALS['VALID_API_KEYS'] ?? [];
if (!is_array($VALID_API_KEYS)) {
    $VALID_API_KEYS = [];
}

if (!is_readable(__DIR__ . '/lib/coupons-schema.php')) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing api/lib/coupons-schema.php on server.']);
    exit;
}

$bfp = new AdminBruteProtect();
$ip = getClientIP();

// Always check brute force (even for session) - prevents session hijacking abuse
if (!$bfp->check($ip)) {
    http_response_code(429);
    echo json_encode(['success'=>false,'message'=>'Too many attempts. Try again in 15 minutes.']);
    exit;
}

if (!$sessionOk) {
    $keyOk = !empty($apiKey) && is_array($VALID_API_KEYS) && in_array($apiKey, $VALID_API_KEYS, true);
    if (!$keyOk) {
        $bfp->record($ip);
        logSecurityEvent('manage_coupons_unauth', ['ip'=>$ip]);
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Unauthorized']);
        exit;
    }
}

setSecurityHeaders();
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Use correct database function for coupon_codes table
try {
    $pdo = getCouponDatabase();
    ensureCouponSchema($pdo);
} catch (Throwable $e) {
    error_log('[OnlyBikes] Coupon DB connect: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

try {
    switch ($action) {
        case 'list':
            $coupons = fetchCouponCodesList($pdo);
            if (function_exists('couponAttachOrderEconomics')) {
                try {
                    $coupons = couponAttachOrderEconomics($coupons, getOrderDatabase());
                } catch (Throwable $e) {
                    error_log('[OnlyBikes] coupon economics attach: ' . $e->getMessage());
                }
            }
            echo json_encode(['success' => true, 'data' => $coupons]);
            break;

        case 'backfill_economics':
            if (!function_exists('couponBackfillOrderEconomicsFromStripe')) {
                echo json_encode(['success' => false, 'message' => 'Backfill not available']);
                break;
            }
            try {
                $ordersPdo = getOrderDatabase();
                $report = couponBackfillOrderEconomicsFromStripe($ordersPdo, 200);
                if (function_exists('couponSyncUsedCountFromOrders')) {
                    couponSyncUsedCountFromOrders($ordersPdo);
                }
                echo json_encode(['success' => true, 'message' => 'Backfill complete', 'report' => $report]);
            } catch (Throwable $e) {
                error_log('[OnlyBikes] coupon backfill: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Backfill failed']);
            }
            break;

        case 'create':
            $payload = manageCouponsParsePayload();
            if ($payload === null) {
                break;
            }

            $dupSql = couponTableHasColumn($pdo, 'coupon_codes', 'deleted')
                ? 'SELECT id FROM coupon_codes WHERE code = ? AND deleted = 0'
                : 'SELECT id FROM coupon_codes WHERE code = ?';
            $check = $pdo->prepare($dupSql);
            $check->execute([$payload['code']]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Code already exists']);
                break;
            }

            couponInsertRow($pdo, $payload);

            logSecurityEvent('coupon_created', ['code' => $payload['code'], 'admin' => $_SESSION['user_email'] ?? 'unknown']);
            echo json_encode(['success' => true, 'message' => 'Coupon created']);
            break;

        case 'update':
            $id = intval($_POST['id'] ?? 0);
            $payload = manageCouponsParsePayload($id);
            if ($payload === null) {
                break;
            }

            couponUpdateRow($pdo, $id, $payload);

            logSecurityEvent('coupon_updated', ['id' => $id, 'code' => $payload['code'], 'admin' => $_SESSION['user_email'] ?? 'unknown']);
            echo json_encode(['success' => true, 'message' => 'Coupon updated']);
            break;

        case 'delete':
            ensureCouponSchema($pdo);
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid coupon ID']);
                break;
            }

            if (couponTableHasColumn($pdo, 'coupon_codes', 'deleted')) {
                $stmt = $pdo->prepare('UPDATE coupon_codes SET deleted = 1, active = 0 WHERE id = ?');
            } else {
                $stmt = $pdo->prepare('UPDATE coupon_codes SET active = 0 WHERE id = ?');
            }
            $stmt->execute([$id]);

            logSecurityEvent('coupon_deleted', ['id' => $id, 'admin' => $_SESSION['user_email'] ?? 'unknown']);
            echo json_encode(['success' => true, 'message' => 'Coupon deleted']);
            break;

        case 'restore':
            ensureCouponSchema($pdo);
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid coupon ID']);
                break;
            }

            if (couponTableHasColumn($pdo, 'coupon_codes', 'deleted')) {
                $stmt = $pdo->prepare('UPDATE coupon_codes SET deleted = 0 WHERE id = ?');
            } else {
                $stmt = $pdo->prepare('UPDATE coupon_codes SET active = 1 WHERE id = ?');
            }
            $stmt->execute([$id]);

            logSecurityEvent('coupon_restored', ['id' => $id, 'admin' => $_SESSION['user_email'] ?? 'unknown']);
            echo json_encode(['success' => true, 'message' => 'Coupon restored']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }

} catch (Throwable $e) {
    error_log('[OnlyBikes] Coupon management error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

/**
 * @return array{code:string,type:string,value:float,min_total:float,expires_at:?string,usage_limit:?int,active:bool}|null
 */
function manageCouponsParsePayload(?int $requireId = null): ?array
{
    if ($requireId !== null && $requireId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid coupon ID']);
        return null;
    }

    $code = strtoupper(trim($_POST['code'] ?? ''));
    $type = $_POST['type'] ?? '';
    $value = (float) ($_POST['value'] ?? 0);
    $min_total = (float) ($_POST['min_total'] ?? 0);
    $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
    $usage_limit = !empty($_POST['usage_limit']) ? (int) $_POST['usage_limit'] : null;
    $active = !empty($_POST['active']);

    if (empty($code) || !in_array($type, ['percent', 'fixed'], true) || $value <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid coupon data']);
        return null;
    }

    return [
        'code' => $code,
        'type' => $type,
        'value' => $value,
        'min_total' => $min_total,
        'expires_at' => $expires_at,
        'usage_limit' => $usage_limit,
        'active' => $active,
    ];
}