<?php
declare(strict_types=1);

/**
 * Runtime credits automated checks — open in browser after deploy.
 * Optional: ?simulate=1 for cooldown/session tests (uses test session prefix).
 */

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/runtime-credits.php';
require_once __DIR__ . '/lib/roast-cloud-budget.php';

header('Content-Type: application/json; charset=utf-8');

$results = [];
$failed = [];

function rc_test(string $name, bool $ok, string $detail = ''): void
{
    global $results, $failed;
    $results[] = ['test' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failed[] = $name;
    }
}

try {
    $pdo = runtime_credits_pdo();
    runtime_credits_ensure_schema($pdo);
    $pdo->query('SELECT 1 FROM runtime_balances LIMIT 1');
    rc_test('db_connect_schema', true, 'PDO + tables OK');
} catch (Throwable $e) {
    rc_test('db_connect_schema', false, $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'results' => $results, 'failed' => $failed], JSON_PRETTY_PRINT);
    exit;
}

$testCustomer = 999001;
$testIp = hash('sha256', 'test-runtime-credits|' . date('Y-m-d'));
$testSession = 'test-session-' . bin2hex(random_bytes(8));
$testGuest = runtime_credits_uuid();

try {
    runtime_credits_ensure_balance_row($pdo, $testCustomer);
    $pdo->prepare('DELETE FROM runtime_ledger WHERE customer_id = ?')->execute([$testCustomer]);
    $pdo->prepare('DELETE FROM runtime_balances WHERE customer_id = ?')->execute([$testCustomer]);

    $bonus1 = runtime_credits_signup_bonus($testCustomer);
    $bonus2 = runtime_credits_signup_bonus($testCustomer);
    rc_test(
        'signup_bonus_idempotent',
        ($bonus1['ok'] ?? false) && !empty($bonus2['already_granted']),
        'first=' . json_encode($bonus1) . ' second=' . json_encode($bonus2)
    );

    $pdo->prepare('DELETE FROM runtime_ledger WHERE customer_id = ?')->execute([$testCustomer]);
    $pdo->prepare('UPDATE runtime_balances SET pvp_credits = 5, solo_credits = 3 WHERE customer_id = ?')->execute([$testCustomer]);

    runtime_credits_apply_delta($pdo, $testCustomer, 0, -1, 'solo_spend', ['note' => 'test']);
    $bal = runtime_credits_get_balance($pdo, $testCustomer);
    rc_test('deduct_solo', $bal['solo_credits'] === 2, 'balance=' . json_encode($bal));

    runtime_credits_apply_delta($pdo, $testCustomer, 0, 1, 'refund_failed_job', ['reference_id' => 'test-job-1']);
    $bal2 = runtime_credits_get_balance($pdo, $testCustomer);
    rc_test('refund_solo', $bal2['solo_credits'] === 3, 'balance=' . json_encode($bal2));

    $pdo->prepare('DELETE FROM ad_unlock_tokens WHERE guest_id = ?')->execute([$testGuest]);
    $pdo->prepare('DELETE FROM ad_claim_log WHERE session_id = ? OR guest_id = ?')->execute([$testSession, $testGuest]);

    $unlock = runtime_credits_create_unlock_token('solo', $testIp, $testSession, $testGuest, null, RUNTIME_AD_MIN_VIEW_SOLO_SEC);
    rc_test('ad_unlock_create', $unlock['ok'] ?? false, json_encode($unlock));

    if (!empty($unlock['token'])) {
        $use1 = runtime_credits_consume_unlock_token($unlock['token'], 'solo');
        $use2 = runtime_credits_consume_unlock_token($unlock['token'], 'solo');
        rc_test('ad_unlock_single_use', ($use1['ok'] ?? false) && !($use2['ok'] ?? true), 'use1=' . json_encode($use1) . ' use2=' . json_encode($use2));
    }

    if (RUNTIME_AD_VIEWS_PER_SESSION_MAX > 0) {
        for ($i = 0; $i < RUNTIME_AD_VIEWS_PER_SESSION_MAX; $i++) {
            runtime_credits_log_ad_claim('solo', 'unlock', $testIp, $testSession, $testGuest, null);
        }
        $sessionCap = runtime_credits_check_ad_limits('solo', 'unlock', $testIp, $testSession, $testGuest, null);
        rc_test('session_cap', !($sessionCap['ok'] ?? true) && ($sessionCap['code'] ?? '') === 'SESSION_CAP', json_encode($sessionCap));
    } else {
        rc_test('session_cap', runtime_credits_check_ad_limits('solo', 'unlock', $testIp, $testSession, $testGuest, null)['ok'] ?? false, 'unlimited');
    }

    $pdo->prepare('DELETE FROM ad_claim_log WHERE ip_hash = ? AND DATE(claimed_at) = CURDATE()')->execute([$testIp]);
    $pdo->prepare('DELETE FROM ad_unlock_tokens WHERE guest_id = ?')->execute([$testGuest]);
    $coolSession = $testSession . '-cool';
    $c1 = runtime_credits_create_unlock_token('pvp', $testIp, $coolSession, $testGuest, null, RUNTIME_AD_MIN_VIEW_PVP_SEC);
    if (($c1['ok'] ?? false) && !empty($c1['token'])) {
        runtime_credits_consume_unlock_token($c1['token'], 'pvp');
    }
    $c2 = runtime_credits_check_ad_limits('pvp', 'unlock', $testIp, $coolSession . '-2', $testGuest, null);
    rc_test('no_cooldown_between_ads', ($c2['ok'] ?? false), 'c2=' . json_encode($c2));

    $pdo->prepare('DELETE FROM ad_claim_log WHERE ip_hash = ?')->execute([$testIp]);
    if (RUNTIME_GUEST_SOLO_UNLOCKS_PER_IP_DAY > 0) {
        for ($i = 0; $i < RUNTIME_GUEST_SOLO_UNLOCKS_PER_IP_DAY; $i++) {
            runtime_credits_log_ad_claim('solo', 'unlock', $testIp, $testSession . '-solo' . $i, $testGuest, null);
        }
        $soloCap = runtime_credits_check_ad_limits('solo', 'unlock', $testIp, $testSession . '-solo-cap', $testGuest, null);
        rc_test('guest_solo_ip_cap', !($soloCap['ok'] ?? true), json_encode($soloCap));
    } else {
        rc_test('guest_solo_ip_cap', runtime_credits_check_ad_limits('solo', 'unlock', $testIp, $testSession . '-solo-cap', $testGuest, null)['ok'] ?? false, 'unlimited');
    }

    $pdo->prepare('DELETE FROM ad_claim_log WHERE ip_hash = ?')->execute([$testIp]);
    if (RUNTIME_GUEST_PVP_UNLOCKS_PER_IP_DAY > 0) {
        for ($i = 0; $i < RUNTIME_GUEST_PVP_UNLOCKS_PER_IP_DAY; $i++) {
            runtime_credits_log_ad_claim('pvp', 'unlock', $testIp, $testSession . '-pvp' . $i, $testGuest, null);
        }
        $pvpCap = runtime_credits_check_ad_limits('pvp', 'unlock', $testIp, $testSession . '-pvp-cap', $testGuest, null);
        rc_test('guest_pvp_ip_cap', !($pvpCap['ok'] ?? true), json_encode($pvpCap));
    } else {
        rc_test('guest_pvp_ip_cap', runtime_credits_check_ad_limits('pvp', 'unlock', $testIp, $testSession . '-pvp-cap', $testGuest, null)['ok'] ?? false, 'unlimited');
    }

    $pdo->prepare('DELETE FROM runtime_ledger WHERE customer_id = ?')->execute([$testCustomer]);
    $pdo->prepare('DELETE FROM runtime_balances WHERE customer_id = ?')->execute([$testCustomer]);
    $pdo->prepare('DELETE FROM ad_unlock_tokens WHERE guest_id = ?')->execute([$testGuest]);
    $pdo->prepare('DELETE FROM ad_claim_log WHERE guest_id = ? OR session_id LIKE ?')->execute([$testGuest, $testSession . '%']);
} catch (Throwable $e) {
    rc_test('runtime_tests_exception', false, $e->getMessage());
}

rc_test('groq_budget_status', true, json_encode(roast_groq_budget_status()));

http_response_code($failed === [] ? 200 : 503);
echo json_encode([
    'ok' => $failed === [],
    'results' => $results,
    'failed' => $failed,
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
