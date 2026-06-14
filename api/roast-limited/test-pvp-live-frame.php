<?php
declare(strict_types=1);

/**
 * Smoke test for live_frame scoring fallback (no DB).
 * Run: php api/roast-limited/test-pvp-live-frame.php
 */

require_once dirname(__DIR__) . '/secure-config.php';
require_once dirname(__DIR__) . '/lib/roast-config.php';
require_once dirname(__DIR__) . '/lib/roast-pvp.php';

header('Content-Type: application/json; charset=utf-8');

$imgPath = dirname(__DIR__, 2) . '/images/products/Baja Headlight.jpg';
if (!is_file($imgPath)) {
    $imgPath = '';
}

$scoreOk = false;
$scoreErr = null;
if ($imgPath && is_file($imgPath)) {
    try {
        $scored = roast_pvp_score_frame($imgPath);
        $scoreOk = !empty($scored['ok']);
        if (!$scoreOk) {
            $scoreErr = $scored['error'] ?? 'unknown';
        }
    } catch (Throwable $e) {
        $scoreErr = $e->getMessage();
    }
}

$ice = roast_pvp_ice_bundle();

echo json_encode([
    'ok' => $scoreOk,
    'tmp_writable' => roast_ensure_tmp_dir(),
    'roast_tmp_dir' => ROAST_TMP_DIR,
    'score_frame_ok' => $scoreOk,
    'score_frame_error' => $scoreErr,
    'turn_configured' => $ice['turn_configured'],
    'turn_source' => $ice['turn_source'],
    'pvp_turn_key_set' => trim((string) roast_env('PVP_TURN_API_KEY', '')) !== ''
        || trim((string) roast_env('PVP_TURN_SECRET_KEY', '')) !== '',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
