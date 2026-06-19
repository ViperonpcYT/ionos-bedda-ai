<?php
declare(strict_types=1);

/**
 * Tier 2 PvP vision refinement cron — batch-process tier2_pending matches.
 * Agents 1 (Groq 11B) + 2 + 3 on best frame; merge via roast_pvp_merge_tier_score.
 *
 * CLI:  php /path/to/api/roast-limited/cron-tier2.php
 * HTTP: https://onlybikes.shop/api/roast-limited/cron-tier2.php?key=YOUR_CRON_SECRET
 */

require_once dirname(__DIR__) . '/secure-config.php';
require_once dirname(__DIR__) . '/lib/roast-config.php';
require_once dirname(__DIR__) . '/lib/roast-pvp.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(120);

$isCli = PHP_SAPI === 'cli';
$secret = getenv('CRON_SECRET') ?: (defined('CRON_SECRET') ? (string) CRON_SECRET : '');
$provided = $isCli ? '' : trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $validKeys = $GLOBALS['VALID_API_KEYS'] ?? [];
    $authorized = ($secret !== '' && hash_equals($secret, $provided))
        || (is_array($validKeys) && in_array($provided, $validKeys, true));
    if (!$authorized) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

$batchStarted = microtime(true);
$limit = roast_pvp_tier2_batch_limit();
if (!$isCli && isset($_GET['limit'])) {
    $limit = max(1, min(10, (int) $_GET['limit']));
}

try {
    $report = roast_pvp_tier2_cron_run($limit);
    $batchMs = max(0, (int) round((microtime(true) - $batchStarted) * 1000));

    $payload = array_merge($report, [
        'batch_ms' => $batchMs,
        'limit' => $limit,
        'ran_at' => gmdate('c'),
    ]);

    if (($report['processed'] ?? 0) === 0 && ($report['failed'] ?? 0) === 0) {
        roast_log_pvp_metric('tier2_cron', [
            'outcome' => 'idle',
            'processed' => 0,
            'failed' => 0,
            'tier2_latency_ms' => $batchMs,
            'score_tier' => 2,
        ]);
    }

    error_log('[Roast PvP][tier2_cron] ' . json_encode([
        'outcome' => $report['outcome'] ?? 'unknown',
        'processed' => $report['processed'] ?? 0,
        'failed' => $report['failed'] ?? 0,
        'batch_ms' => $batchMs,
    ]));

    if ($isCli) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    error_log('[Roast PvP][tier2_cron] ' . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Tier 2 cron failed']);
}
