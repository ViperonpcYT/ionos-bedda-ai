<?php
/**
 * Cron / WebCron health check for OnlyBikes (IONOS HTTP GET jobs).
 *
 * Dry run (default — no Stripe reconcile, no points fix):
 *   https://onlybikes.shop/api/test-cron-health.php?key=onlybikes-reconcile-YOUR_VALID_API_KEY
 *
 * With points secret check:
 *   ...&points_key=YOUR_CRON_SECRET
 *
 * Live mini-run (hits Stripe + DB — use sparingly):
 *   ...&run=1
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/secure-config.php';

global $VALID_API_KEYS;
if (!is_array($VALID_API_KEYS)) {
    $VALID_API_KEYS = [];
}

$providedPaymentKey = trim((string) ($_GET['key'] ?? ''));
if ($providedPaymentKey === '' || !in_array($providedPaymentKey, $VALID_API_KEYS, true)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized. Use ?key= same value as VALID_API_KEYS in api/.env (payment-reconcile cron).',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$providedPointsKey = trim((string) ($_GET['points_key'] ?? ''));
$cronSecret = getenv('CRON_SECRET') ?: (defined('CRON_SECRET') ? (string) CRON_SECRET : '');
$doRun = isset($_GET['run']) && $_GET['run'] === '1';

$report = [
    'success' => true,
    'checked_at' => gmdate('c'),
    'dry_run' => !$doRun,
    'ionos_cron_jobs' => [
        [
            'name' => 'email-queue',
            'schedule' => '*/5 * * * *',
            'url' => 'https://onlybikes.shop/email-admin/cron-send.php',
        ],
        [
            'name' => 'payment-reconcile',
            'schedule' => '*/15 * * * *',
            'url' => 'https://onlybikes.shop/api/reconcile-payments.php?key=' . $providedPaymentKey,
        ],
        [
            'name' => 'points-audit',
            'schedule' => '0 * * * *',
            'url' => 'https://onlybikes.shop/api/cron-reconcile-points.php?key=[CRON_SECRET]',
        ],
        [
            'name' => 'points-fix-weekly',
            'schedule' => '0 4 * * 0 (or IONOS "weekly")',
            'url' => 'https://onlybikes.shop/api/cron-reconcile-points.php?key=[CRON_SECRET]&fix=1',
        ],
    ],
    'config' => [
        'valid_api_keys_loaded' => count($VALID_API_KEYS),
        'cron_secret_configured' => $cronSecret !== '',
        'site_origin' => defined('SITE_ORIGIN') ? SITE_ORIGIN : null,
    ],
    'auth' => [
        'payment_reconcile_key' => 'ok',
        'points_cron_key' => null,
    ],
    'scripts' => [
        'email_cron_send' => is_readable(dirname(__DIR__) . '/email-admin/cron-send.php'),
        'reconcile_payments' => is_readable(__DIR__ . '/reconcile-payments.php'),
        'cron_reconcile_points' => is_readable(__DIR__ . '/cron-reconcile-points.php'),
    ],
    'database' => [],
    'email_queue' => null,
    'live_run' => null,
];

if ($providedPointsKey !== '') {
    if ($cronSecret === '' || !hash_equals($cronSecret, $providedPointsKey)) {
        $report['auth']['points_cron_key'] = 'fail';
        $report['success'] = false;
        $report['message'] = 'points_key does not match CRON_SECRET in api/.env';
    } else {
        $report['auth']['points_cron_key'] = 'ok';
    }
} else {
    $report['auth']['points_cron_key'] = 'skipped — add &points_key=YOUR_CRON_SECRET to verify points crons';
}

$dbChecks = [
    'orders' => 'getOrderDatabase',
    'newsletter' => 'getNewsletterDatabase',
    'customers' => 'getCustomersDatabase',
    'coupons' => 'getCouponDatabase',
];

foreach ($dbChecks as $label => $fn) {
    try {
        if (!function_exists($fn)) {
            $report['database'][$label] = ['ok' => false, 'error' => "{$fn}() missing"];
            $report['success'] = false;
            continue;
        }
        $pdo = $fn();
        $pdo->query('SELECT 1');
        $report['database'][$label] = ['ok' => true];
    } catch (Throwable $e) {
        $report['database'][$label] = ['ok' => false, 'error' => $e->getMessage()];
        $report['success'] = false;
    }
}

try {
    if (function_exists('getDatabase')) {
        $pdo = getDatabase();
        require_once __DIR__ . '/lib/newsletter-schema.php';
        if (function_exists('ensureNewsletterDatabaseSchema')) {
            ensureNewsletterDatabaseSchema($pdo);
        }
        $pending = (int) $pdo->query(
            "SELECT COUNT(*) FROM email_queue WHERE status='pending' AND send_after <= NOW()"
        )->fetchColumn();
        $report['email_queue'] = ['pending_ready' => $pending];
    }
} catch (Throwable $e) {
    $report['email_queue'] = ['error' => $e->getMessage()];
    $report['success'] = false;
}

if ($doRun && $cronSecret !== '' && $providedPointsKey !== '' && hash_equals($cronSecret, $providedPointsKey)) {
    try {
        require_once __DIR__ . '/lib/points-ledger.php';
        $customersPdo = getCustomersDatabase();
        $ordersPdo = getOrderDatabase();
        onlybikes_ensure_points_ledger_schema($customersPdo);
        $audit = onlybikes_points_audit_all($customersPdo, $ordersPdo, false);
        $report['live_run'] = [
            'points_audit' => [
                'checked' => $audit['checked'],
                'flagged_count' => count($audit['flagged']),
            ],
            'payment_reconcile' => 'hit reconcile-payments URL directly with &hours=1',
        ];
    } catch (Throwable $e) {
        $report['live_run'] = ['error' => $e->getMessage()];
        $report['success'] = false;
    }
} elseif ($doRun) {
    $report['live_run'] = ['note' => 'Add points_key=CRON_SECRET for live points audit; test payment cron via reconcile-payments URL'];
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
