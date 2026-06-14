<?php
declare(strict_types=1);

/**
 * Housekeeping cron — purge temp images + stale processing jobs.
 * GET ?key=CRON_SECRET
 */

require_once dirname(__DIR__) . '/secure-config.php';
require_once dirname(__DIR__) . '/lib/roast-config.php';
require_once dirname(__DIR__) . '/lib/roast-cloud-vision.php';
require_once dirname(__DIR__) . '/lib/roast-jobs.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
$cronSecret = defined('CRON_SECRET') ? CRON_SECRET : '';
$validKeys = $GLOBALS['VALID_API_KEYS'] ?? [];

$authorized = ($cronSecret !== '' && hash_equals($cronSecret, $key))
    || (is_array($validKeys) && in_array($key, $validKeys, true));

if (!$authorized) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$purgedFiles = roast_purge_temp_files(3600);
$staleJobs = roast_jobs_purge_stale(1);

echo json_encode([
    'ok' => true,
    'purged_files' => $purgedFiles,
    'stale_jobs' => $staleJobs,
]);
