<?php
declare(strict_types=1);

/**
 * CLI smoke tests for roast pipeline (run locally: php api/roast-limited/test-roast-pipeline.php)
 */

$root = dirname(__DIR__, 2);
require_once $root . '/api/lib/roast-config.php';
require_once $root . '/api/lib/roast-envelope.php';
require_once $root . '/api/lib/roast-local-agents.php';
require_once $root . '/api/lib/bike-stock-specs.php';
require_once $root . '/api/lib/roast-score.php';
require_once $root . '/api/lib/roast-coordinator.php';
require_once $root . '/api/lib/roast-cloud-vision.php';

$passed = 0;
$failed = 0;

function assert_true(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        echo "PASS: {$name}\n";
        $passed++;
    } else {
        echo "FAIL: {$name}\n";
        $failed++;
    }
}

// Stock specs lookup
$lookup = roast_stock_specs_lookup('Surron', 'Light Bee X');
assert_true($lookup['entry'] !== null, 'stock specs Surron LBX');
assert_true(stripos($lookup['specs_text'], 'handlebars') !== false, 'stock specs contain handlebars');

// Merge inspect
$merged = roast_merge_inspect(
    ['damage' => ['scratch'], 'cleanliness_score' => 6, 'missing_parts' => []],
    ['visual_mods' => [['part' => 'bars', 'stock_spec' => 'black', 'observed_spec' => 'purple']]]
);
assert_true(roast_validate_inspect($merged), 'merge inspect validates');

// Shame score — harsh on unknown / partial uploads
$wheelOnlyScore = roast_compute_shame_score(
    ['make' => 'Unknown', 'model' => 'Unknown', 'confidence' => 0],
    [
        'visual_mods' => [
            ['part' => 'Wheel', 'stock_spec' => 'cast', 'observed_spec' => 'spoked'],
            ['part' => 'Tire', 'stock_spec' => 'street', 'observed_spec' => 'knobby'],
        ],
        'cleanliness_score' => 6,
        'damage' => [],
        'missing_parts' => [],
    ]
);
assert_true($wheelOnlyScore <= 25, 'wheel-only unknown upload scores harshly');

$stockScore = roast_compute_shame_score(
    ['make' => 'Surron', 'model' => 'LBX', 'confidence' => 0.9, 'visible_subject' => 'full_bike', 'is_complete_ebike' => true],
    $merged
);
assert_true($stockScore >= 55 && $stockScore <= 100, 'clean identified bike keeps reasonable cred');

// Agent 1 local override path
$a1 = roast_agent1_identify_local(['make_override' => 'Surron', 'model_override' => 'Light Bee X']);
assert_true($a1['ok'] === true, 'agent1 local override');
assert_true(($a1['data']['make'] ?? '') === 'Surron', 'agent1 override make');

// Envelope shape
$env = roast_envelope('test-uuid', 'complete', true, roast_build_result(50, ['make' => 'X'], $merged, 'roast'), []);
assert_true(isset($env['job_id'], $env['steps'], $env['result']), 'envelope shape');

// Pipeline mode
$mode = roast_coordinator_pipeline_mode([
    'agents' => [
        'identify' => ['backend' => 'local_text', 'fallback' => true],
        'condition' => ['backend' => 'local_text', 'fallback' => true],
        'mods' => ['backend' => 'groq_vision', 'fallback' => false],
    ],
]);
assert_true($mode === 'hybrid', 'pipeline mode hybrid');

// Tmp dir
assert_true(roast_ensure_tmp_dir(), 'tmp dir writable');

// JSON validators
assert_true(roast_validate_condition(['damage' => [], 'cleanliness_score' => 5, 'missing_parts' => []]), 'validate condition');
assert_true(roast_validate_mods(['visual_mods' => []]), 'validate mods');

echo "\n---\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
