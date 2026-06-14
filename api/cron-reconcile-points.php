<?php
/**
 * Points integrity audit — run via Ionos cron (CLI or HTTPS with secret).
 *
 * CLI:  php /path/to/api/cron-reconcile-points.php
 * HTTP: https://onlybikes.shop/api/cron-reconcile-points.php?key=YOUR_CRON_SECRET&fix=1
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/points-ledger.php';

$isCli = PHP_SAPI === 'cli';
$secret = getenv('CRON_SECRET') ?: (defined('CRON_SECRET') ? (string) CRON_SECRET : '');
$provided = $isCli ? '' : trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    if ($secret === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}

$autoFix = $isCli
    ? in_array('--fix', $argv ?? [], true)
    : isset($_GET['fix']) && $_GET['fix'] === '1';

try {
    $customersPdo = getCustomersDatabase();
    $ordersPdo = getOrderDatabase();
    onlybikes_ensure_points_ledger_schema($customersPdo);

    $report = onlybikes_points_audit_all($customersPdo, $ordersPdo, $autoFix);

    $payload = [
        'success' => true,
        'checked' => $report['checked'],
        'flagged_count' => count($report['flagged']),
        'fixed' => $report['fixed'],
        'auto_fix' => $autoFix,
        'flagged' => $report['flagged'],
        'ran_at' => gmdate('c'),
    ];

    error_log('[OnlyBikes][points][cron] ' . json_encode([
        'checked' => $report['checked'],
        'flagged' => count($report['flagged']),
        'fixed' => $report['fixed'],
    ]));

    if ($isCli) {
        echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    error_log('[OnlyBikes][points][cron] ' . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Cron failed']);
}
