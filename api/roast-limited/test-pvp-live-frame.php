<?php
declare(strict_types=1);

/**
 * Smoke test for live_frame scoring fallback (no DB).
 * Run: php api/roast-limited/test-pvp-live-frame.php
 */

require_once dirname(__DIR__) . '/secure-config.php';
require_once dirname(__DIR__) . '/lib/roast-config.php';
require_once dirname(__DIR__) . '/lib/roast-pvp.php';
require_once dirname(__DIR__) . '/lib/roast-cloud-vision.php';
require_once dirname(__DIR__) . '/roast-limited/agents/agent1-identify.php';

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__, 2);
$manifestPath = dirname(__DIR__) . '/data/pvp-opponents.json';
$manifest = is_readable($manifestPath)
    ? (json_decode((string) file_get_contents($manifestPath), true) ?: [])
    : [];
$opponents = $manifest['opponents'] ?? [];

$scoreOne = static function (string $imgPath): array {
    $row = ['path' => $imgPath, 'exists' => is_file($imgPath)];
    if (!is_file($imgPath)) {
        $row['score_ok'] = false;
        $row['error'] = 'missing';
        return $row;
    }
    try {
        $scored = roast_pvp_score_frame($imgPath);
        $identity = is_array($scored['identity'] ?? null) ? $scored['identity'] : [];
        $row['score_ok'] = !empty($scored['ok']);
        $row['shame_score'] = $scored['score'] ?? null;
        $row['visible_subject'] = $identity['visible_subject'] ?? null;
        $row['make'] = $identity['make'] ?? null;
        $row['model'] = $identity['model'] ?? null;
        $row['confidence'] = $identity['confidence'] ?? null;
        $row['no_bike'] = (bool) ($scored['no_bike'] ?? false);
        $row['vision_fallback'] = (bool) ($scored['vision_fallback'] ?? false);
        if (!$row['score_ok']) {
            $row['error'] = $scored['error'] ?? 'unknown';
        }
    } catch (Throwable $e) {
        $row['score_ok'] = false;
        $row['error'] = $e->getMessage();
    }
    return $row;
};

$testOpponents = isset($_GET['opponents']) || (PHP_SAPI === 'cli' && in_array('--opponents', $argv ?? [], true));
$opponentRows = [];
if ($testOpponents) {
    $dir = $root . '/images/pvp-opponents';
    foreach (glob($dir . '/*.{jpg,jpeg,webp,JPG,JPEG,WEBP}', GLOB_BRACE) ?: [] as $path) {
        $basename = basename($path);
        $entry = null;
        foreach ($opponents as $opp) {
            $ref = str_replace('\\', '/', (string) ($opp['reference_image'] ?? ''));
            if ($ref !== '' && str_ends_with($ref, $basename)) {
                $entry = $opp;
                break;
            }
        }
        $row = $scoreOne($path);
        $row['file'] = $basename;
        $row['opponent_id'] = $entry['id'] ?? null;
        $row['manifest_starting_score'] = $entry['starting_score'] ?? null;
        $opponentRows[] = $row;
    }
}

$imgPath = $root . '/images/products/Baja Headlight.jpg';
$smoke = $scoreOne(is_file($imgPath) ? $imgPath : '');
$ice = roast_pvp_ice_bundle();

echo json_encode([
    'ok' => $testOpponents ? ($opponentRows !== [] && !in_array(false, array_column($opponentRows, 'score_ok'), true)) : ($smoke['score_ok'] ?? false),
    'tmp_writable' => roast_ensure_tmp_dir(),
    'roast_tmp_dir' => ROAST_TMP_DIR,
    'score_frame_ok' => $smoke['score_ok'] ?? false,
    'score_frame_error' => $smoke['error'] ?? null,
    'smoke' => $smoke,
    'opponent_results' => $opponentRows,
    'turn_configured' => $ice['turn_configured'],
    'turn_source' => $ice['turn_source'],
    'pvp_turn_key_set' => trim((string) roast_env('PVP_TURN_API_KEY', '')) !== ''
        || trim((string) roast_env('PVP_TURN_SECRET_KEY', '')) !== '',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
