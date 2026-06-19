<?php
declare(strict_types=1);

/**
 * Unit tests for PvP cred scoring (no vision API).
 * Run: php api/roast-limited/test-pvp-cred-scores.php
 *
 * CI: .github/workflows/roast-pvp-ci.yml job pvp-unit — regression bands + assert_deterministic().
 */

require_once dirname(__DIR__) . '/lib/roast-config.php';
require_once dirname(__DIR__) . '/lib/roast-score.php';
require_once dirname(__DIR__) . '/lib/roast-pvp.php';
require_once dirname(__DIR__) . '/lib/roast-pvp-npc.php';

$passed = 0;
$failed = 0;

function t(bool $cond, string $name): void
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

/** @param array<string, mixed> $identity @param array<string, mixed> $inspect */
function assert_deterministic(array $identity, array $inspect, string $label): void
{
    $first = roast_compute_pvp_cred_score($identity, $inspect);
    $second = roast_compute_pvp_cred_score($identity, $inspect);
    t($first === $second, "{$label} same input twice ({$first})");

    $runs = [];
    for ($i = 0; $i < 10; $i++) {
        $runs[] = roast_compute_pvp_cred_score($identity, $inspect);
    }
    t(count(array_unique($runs)) === 1, "{$label} stable across 10 runs ({$first})");
}

$liveShell = [
    'frame_visible' => true,
    'visual_mods' => [],
    'missing_parts' => [],
    'cleanliness_score' => 8,
    'condition_notes' => 'live_frame',
];

// Solo pipeline: harsh on junk uploads
$wheelOnly = roast_compute_shame_score(
    ['make' => 'Unknown', 'model' => 'Unknown', 'confidence' => 0, 'visible_subject' => 'parts_only'],
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
t($wheelOnly <= 25, 'solo wheel-only stays harsh (' . $wheelOnly . ')');

$stockSolo = roast_compute_shame_score(
    ['make' => 'Surron', 'model' => 'LBX', 'confidence' => 0.9, 'visible_subject' => 'full_bike', 'is_complete_ebike' => true],
    ['cleanliness_score' => 6, 'visual_mods' => [], 'missing_parts' => [], 'damage' => []]
);
t($stockSolo >= 55 && $stockSolo <= 100, 'solo full bike reasonable (' . $stockSolo . ')');

// PvP: hero full bike
$heroIdentity = [
    'make' => 'Surron',
    'model' => 'Ultra Bee',
    'confidence' => 0.92,
    'visible_subject' => 'full_bike',
    'is_complete_ebike' => true,
];
$hero = roast_compute_pvp_cred_score($heroIdentity, $liveShell);
t($hero >= 75 && $hero <= 98, 'pvp hero full bike 75-95+ (' . $hero . ')');
t($hero >= roast_compute_pvp_hero_floor(0.92), 'pvp hero meets identity floor (' . $hero . ')');
assert_deterministic($heroIdentity, $liveShell, 'hero full bike');

// PvP: identified partial live frame
$partialIdentity = [
    'make' => 'Stark',
    'model' => 'Varg',
    'confidence' => 0.72,
    'visible_subject' => 'partial_bike',
    'is_complete_ebike' => false,
];
$partial = roast_compute_pvp_cred_score($partialIdentity, $liveShell);
t($partial >= 62 && $partial < 90, 'pvp identified partial 62+ (' . $partial . ')');
assert_deterministic($partialIdentity, $liveShell, 'identified partial');

// PvP: vision fallback with bike signals (partial frame) — provisional, not confirmed
$fallbackIdentity = [
    'make' => 'Unknown',
    'model' => 'Unknown',
    'confidence' => 0.38,
    'visible_subject' => 'partial_bike',
    'is_complete_ebike' => false,
    'source' => 'live_frame_fallback',
];
$fallback = roast_compute_pvp_cred_score($fallbackIdentity, $liveShell);
t($fallback >= 58 && $fallback <= 70, 'pvp vision fallback with bike signals (' . $fallback . ')');
assert_deterministic($fallbackIdentity, $liveShell, 'vision fallback partial');

// PvP: true vision failure without bike signals — lowest provisional floor
$noSignalShell = [
    'frame_visible' => false,
    'visual_mods' => [],
    'missing_parts' => [],
    'cleanliness_score' => 8,
    'condition_notes' => 'live_frame',
];
$fallbackBare = roast_compute_pvp_cred_score(
    [
        'make' => 'Unknown',
        'model' => 'Unknown',
        'confidence' => 0.15,
        'visible_subject' => 'unclear',
        'is_complete_ebike' => false,
        'source' => 'live_frame_fallback',
    ],
    $noSignalShell
);
t($fallbackBare >= 54 && $fallbackBare <= 58, 'pvp vision fallback no bike signals floor 54 (' . $fallbackBare . ')');

// PvP: fallback provider success with known bike — not provisional (no 54 cap)
$degradedKnown = roast_compute_pvp_cred_score(
    [
        'make' => 'Surron',
        'model' => 'LBX',
        'confidence' => 0.48,
        'visible_subject' => 'partial_bike',
        'degraded' => true,
        'degraded_reason' => 'GROQ_FAILED',
        'source' => 'openrouter',
    ],
    $liveShell
);
t($degradedKnown >= 58, 'degraded provider with known bike not provisional (' . $degradedKnown . ')');
assert_deterministic(
    [
        'make' => 'Surron',
        'model' => 'LBX',
        'confidence' => 0.48,
        'visible_subject' => 'partial_bike',
        'degraded' => true,
        'source' => 'openrouter',
    ],
    $liveShell,
    'degraded known bike'
);

// PvP: complete flag promotes to full_bike scoring
$promotedIdentity = [
    'make' => 'Talaria',
    'model' => 'X3',
    'confidence' => 0.88,
    'visible_subject' => 'partial_bike',
    'is_complete_ebike' => true,
];
$promoted = roast_compute_pvp_cred_score($promotedIdentity, $liveShell);
t($promoted >= 75, 'pvp is_complete_ebike promotes hero (' . $promoted . ')');
assert_deterministic($promotedIdentity, $liveShell, 'complete promotes hero');

// NPC grading: max(starting_score, graded) is deterministic
t(
    roast_pvp_npc_effective_score(['starting_score' => 70], 65) === 70,
    'npc effective score keeps starting when graded lower (70)'
);
t(
    roast_pvp_npc_effective_score(['starting_score' => 70], 82) === 82,
    'npc effective score uses graded when higher (82)'
);
t(
    roast_pvp_npc_effective_score(['starting_score' => 70], 0) === 70,
    'npc effective score falls back to starting when graded zero'
);

// Cascade Tier merge — monotonic human display
$upTier = roast_pvp_merge_tier_score(70, 0, 75, 1);
t($upTier['display_score'] === 75 && $upTier['score_tier'] === 1 && $upTier['provisional'] === true, 'merge tier 0→1 takes new score');

$hold = roast_pvp_merge_tier_score(70, 1, 72, 1);
t($hold['display_score'] === 70, 'merge same tier +2 hysteresis holds at 70');

$rise = roast_pvp_merge_tier_score(70, 1, 73, 1);
t($rise['display_score'] === 73, 'merge same tier +3 hysteresis rises to 73');

$downTier = roast_pvp_merge_tier_score(80, 2, 65, 1);
t($downTier['display_score'] === 80 && $downTier['score_tier'] === 2, 'merge tier 2 blocks tier 1 downgrade');

$tier2 = roast_pvp_merge_tier_score(72, 1, 78, 2);
t($tier2['display_score'] === 78 && $tier2['provisional'] === false, 'merge tier 2 clears provisional');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
