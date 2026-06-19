<?php
declare(strict_types=1);

/**
 * Score all PvP opponent reference images through live_frame pipeline.
 * Run: php api/roast-limited/test-pvp-opponent-images.php
 */

require_once dirname(__DIR__) . '/secure-config.php';
require_once dirname(__DIR__) . '/lib/roast-config.php';
require_once dirname(__DIR__) . '/lib/roast-pvp.php';
require_once dirname(__DIR__) . '/roast-limited/agents/agent1-identify.php';

$root = dirname(__DIR__, 2);
$manifestPath = dirname(__DIR__) . '/data/pvp-opponents.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$opponents = $manifest['opponents'] ?? [];

$imageDir = $root . '/images/pvp-opponents';
$patterns = ['*.jpg', '*.jpeg', '*.webp', '*.JPG', '*.JPEG', '*.WEBP'];
$files = [];
foreach ($patterns as $pat) {
    foreach (glob($imageDir . '/' . $pat) ?: [] as $f) {
        $files[basename($f)] = $f;
    }
}
ksort($files);

$results = [];
foreach ($files as $basename => $path) {
    $manifestEntry = null;
    foreach ($opponents as $opp) {
        $ref = (string) ($opp['reference_image'] ?? '');
        if ($ref !== '' && str_ends_with(str_replace('\\', '/', $ref), $basename)) {
            $manifestEntry = $opp;
            break;
        }
    }

    $a1 = null;
    $a1Err = null;
    try {
        $a1 = roast_agent1_identify($path, ['live_frame' => true]);
    } catch (Throwable $e) {
        $a1Err = $e->getMessage();
        $a1 = ['ok' => false, 'error' => ['code' => 'EXCEPTION', 'message' => $e->getMessage()]];
    }

    $scored = null;
    $scoreErr = null;
    try {
        $scored = roast_pvp_score_frame($path);
    } catch (Throwable $e) {
        $scoreErr = $e->getMessage();
        $scored = ['ok' => false, 'error' => $e->getMessage()];
    }

    $identity = is_array($scored['identity'] ?? null) ? $scored['identity'] : ($a1['data'] ?? []);
    $results[] = [
        'file' => $basename,
        'opponent_id' => $manifestEntry['id'] ?? null,
        'manifest_starting_score' => $manifestEntry['starting_score'] ?? null,
        'agent1_ok' => (bool) ($a1['ok'] ?? false),
        'agent1_backend' => $a1['backend'] ?? null,
        'agent1_error' => $a1Err ?? ($a1['error']['code'] ?? null),
        'visible_subject' => $identity['visible_subject'] ?? null,
        'make' => $identity['make'] ?? null,
        'model' => $identity['model'] ?? null,
        'confidence' => $identity['confidence'] ?? null,
        'degraded' => !empty($identity['degraded']),
        'score_ok' => (bool) ($scored['ok'] ?? false),
        'shame_score' => $scored['score'] ?? null,
        'no_bike' => (bool) ($scored['no_bike'] ?? false),
        'vision_fallback' => (bool) ($scored['vision_fallback'] ?? false),
        'score_error' => $scoreErr ?? ($scored['error'] ?? null),
    ];
}

echo json_encode([
    'ok' => true,
    'image_count' => count($results),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
