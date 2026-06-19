<?php
declare(strict_types=1);

/** CLI: agent1 live_frame degraded + score_frame integration (no DB). */
require_once dirname(__DIR__) . '/lib/roast-config.php';
require_once dirname(__DIR__) . '/lib/roast-pvp.php';
require_once dirname(__DIR__) . '/roast-limited/agents/agent1-identify.php';

$passed = 0;
$failed = 0;

function t(bool $ok, string $name): void
{
    global $passed, $failed;
    if ($ok) {
        echo "PASS: {$name}\n";
        $passed++;
    } else {
        echo "FAIL: {$name}\n";
        $failed++;
    }
}

$deg = roast_agent1_degraded_live_identity('UNIT_TEST');
t(($deg['ok'] ?? false) === true, 'degraded_live returns ok');
t(($deg['data']['visible_subject'] ?? '') === 'partial_bike', 'degraded subject partial_bike');
t(!empty($deg['data']['degraded']), 'degraded flag set');

$norm = roast_agent1_normalize_identity(['make' => '', 'model' => '', 'visible_subject' => 'bogus']);
t($norm['make'] === 'Unknown' && $norm['visible_subject'] === 'unclear', 'normalize empty make + bad subject');

$img = dirname(__DIR__, 2) . '/images/products/Baja Headlight.jpg';
if (is_file($img)) {
    $scored = roast_pvp_score_frame($img);
    t(($scored['ok'] ?? false) === true, 'score_frame returns ok');
    t(isset($scored['score']) && is_int($scored['score']), 'score_frame has int score');
    t(empty($scored['api_failed']), 'score_frame never hard-fails api_failed');
} else {
    echo "SKIP: no sample image at {$img}\n";
}

echo "\n---\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
