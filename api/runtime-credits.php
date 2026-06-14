<?php
declare(strict_types=1);

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/security-helpers.php';
require_once __DIR__ . '/lib/roast-config.php';
require_once __DIR__ . '/lib/runtime-credits.php';
require_once __DIR__ . '/lib/roast-ads.php';

setSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

runtime_credits_define_constants();

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

function runtime_credits_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'session' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $ids = runtime_credits_ensure_session_ids();
    $customerId = runtime_credits_customer_id_from_session();
    $balance = null;
    $cooldownRemaining = 0;
    $pendingUnlock = null;
    try {
        $pdo = runtime_credits_pdo();
        runtime_credits_ensure_schema($pdo);
        $cooldownRemaining = runtime_credits_cooldown_remaining_sec(
            $pdo,
            $customerId,
            $customerId ? null : $ids['guest_id']
        );
        foreach (['pvp', 'solo'] as $pendingScope) {
            $row = runtime_credits_find_reusable_unlock_token(
                $pdo,
                $pendingScope,
                $customerId ? null : $ids['guest_id'],
                $customerId
            );
            if ($row) {
                $pendingUnlock[$pendingScope] = [
                    'token' => $row['token'],
                    'expires_at' => $row['expires_at'],
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('[OnlyBikes][runtime-credits] session meta: ' . $e->getMessage());
    }
    if ($customerId) {
        try {
            $balance = runtime_credits_get_balance(runtime_credits_pdo(), $customerId);
        } catch (Throwable $e) {
            error_log('[OnlyBikes][runtime-credits] session balance: ' . $e->getMessage());
        }
    }
    runtime_credits_json([
        'ok' => true,
        'guest_id' => $ids['guest_id'],
        'session_id' => $ids['session_id'],
        'logged_in' => $customerId !== null,
        'customer_id' => $customerId,
        'balance' => $balance,
        'ads' => roast_ads_public_config(),
        'costs' => [
            'solo' => RUNTIME_COST_SOLO,
            'pvp' => RUNTIME_COST_PVP,
        ],
        'ad_cooldown_remaining_sec' => $cooldownRemaining,
        'pending_unlock' => $pendingUnlock ?? new stdClass(),
    ]);
}

if ($action === 'balance' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $customerId = runtime_credits_customer_id_from_session();
    if (!$customerId) {
        runtime_credits_json(['ok' => false, 'error' => 'Not signed in'], 401);
    }
    try {
        $bal = runtime_credits_get_balance(runtime_credits_pdo(), $customerId);
        runtime_credits_json(['ok' => true, 'balance' => $bal]);
    } catch (Throwable $e) {
        runtime_credits_json(['ok' => false, 'error' => 'Credits unavailable'], 503);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    runtime_credits_json(['ok' => false, 'error' => 'Invalid action or method'], 400);
}

$raw = file_get_contents('php://input');
$data = [];
if ($raw !== false && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}
if ($data === []) {
    $data = $_POST;
}

$ipHash = roast_ip_hash();
$ids = runtime_credits_ensure_session_ids();
$sessionId = trim((string) ($data['session_id'] ?? $ids['session_id']));
$guestId = trim((string) ($data['guest_id'] ?? $ids['guest_id']));
$minViewSec = max(0, (int) ($data['min_view_sec'] ?? 0));
$viewStartedAt = $data['view_started_at'] ?? null;
$adProviderShown = trim((string) ($data['ad_provider'] ?? ''));
$customerId = runtime_credits_customer_id_from_session();

if ($action === 'ad_unlock') {
    $scope = trim((string) ($data['scope'] ?? ''));
    if (!in_array($scope, ['solo', 'pvp'], true)) {
        runtime_credits_json(['ok' => false, 'error' => 'Invalid scope'], 400);
    }

    try {
        $result = runtime_credits_create_unlock_token(
            $scope,
            $ipHash,
            $sessionId,
            $customerId ? null : $guestId,
            $customerId,
            $minViewSec,
            $viewStartedAt,
            $adProviderShown
        );
        if (!$result['ok']) {
            runtime_credits_json([
                'ok' => false,
                'code' => $result['code'] ?? 'LIMIT',
                'error' => $result['error'] ?? 'Ad unlock denied',
                'cooldown_remaining_sec' => $result['cooldown_remaining_sec'] ?? null,
            ], 429);
        }
        runtime_credits_json([
            'ok' => true,
            'ad_unlock_token' => $result['token'],
            'expires_at' => $result['expires_at'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[OnlyBikes][runtime-credits] ad_unlock: ' . $e->getMessage());
        runtime_credits_json(['ok' => false, 'error' => 'Credits system unavailable'], 503);
    }
}

if ($action === 'ad_claim') {
    if (!$customerId) {
        runtime_credits_json(['ok' => false, 'error' => 'Sign in required for ad bonus'], 401);
    }

    try {
        $result = runtime_credits_ad_claim_bonus(
            $customerId,
            $ipHash,
            $sessionId,
            $minViewSec,
            $viewStartedAt,
            $adProviderShown
        );
        if (!$result['ok']) {
            runtime_credits_json([
                'ok' => false,
                'code' => $result['code'] ?? 'LIMIT',
                'error' => $result['error'] ?? 'Ad claim denied',
            ], 429);
        }
        runtime_credits_json([
            'ok' => true,
            'balance' => [
                'pvp_credits' => $result['pvp_credits'] ?? 0,
                'solo_credits' => $result['solo_credits'] ?? 0,
            ],
            'granted' => [
                'pvp' => $result['pvp_granted'] ?? 0,
                'solo' => $result['solo_granted'] ?? 0,
            ],
        ]);
    } catch (Throwable $e) {
        error_log('[OnlyBikes][runtime-credits] ad_claim: ' . $e->getMessage());
        runtime_credits_json(['ok' => false, 'error' => 'Credits system unavailable'], 503);
    }
}

runtime_credits_json(['ok' => false, 'error' => 'Invalid action'], 400);
