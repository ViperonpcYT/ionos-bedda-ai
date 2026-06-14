<?php
declare(strict_types=1);

require_once __DIR__ . '/roast-envelope.php';
require_once __DIR__ . '/roast-jobs.php';
require_once __DIR__ . '/roast-score.php';
require_once __DIR__ . '/roast-local-agents.php';

/**
 * Master coordinator — cloud vision (Agents 1–3) + local/cloud judge (Agent 4).
 *
 * Agent 1–3: Groq vision → OpenRouter Llama → OpenRouter Qwen VL
 * Agent 4: Local Qwen2.5-1.5B → Groq 8B → OpenRouter 1.5B
 */
function roast_coordinator_run(
    string $jobId,
    string $imagePath,
    array $ctx,
    callable $agent1,
    callable $agent2,
    callable $agent3,
    callable $agent4
): array {
    $steps = [];
    $pipeline = ['agents' => []];

    // --- Agent 1: Identification ---
    $a1 = $agent1($imagePath, $ctx);
    if (!$a1['ok']) {
        $err = $a1['error'] ?? roast_error('IDENTIFY_FAILED', 'Could not identify bike.', true, 'identify');
        roast_jobs_update($jobId, ['status' => 'failed', 'phase' => 'identify', 'error_json' => $err, 'steps_json' => $steps]);
        return [
            'ok' => false,
            'status' => 'failed',
            'steps' => $steps,
            'error' => $err,
            'pipeline' => $pipeline,
        ];
    }

    $identity = $a1['data'];
    if (!empty($ctx['make_override'])) {
        $identity['make'] = (string) $ctx['make_override'];
    }
    if (!empty($ctx['model_override'])) {
        $identity['model'] = (string) $ctx['model_override'];
    }

    $steps[] = roast_step('identify', 'Reading frame geometry…', 20, (int) ($a1['ms'] ?? 0), true);
    $pipeline['agents']['identify'] = [
        'backend' => $a1['backend'] ?? 'unknown',
        'fallback' => !empty($a1['fallback']),
        'fallback_reason' => $a1['fallback_reason'] ?? null,
    ];
    roast_jobs_update($jobId, [
        'phase' => 'identify',
        'identity_json' => $identity,
        'steps_json' => $steps,
    ]);

    // --- Agent 2: Condition ---
    $a2 = $agent2($imagePath, $identity);
    if (!$a2['ok']) {
        $err = $a2['error'] ?? roast_error('CONDITION_FAILED', 'Condition inspection failed.', true, 'condition');
        roast_jobs_update($jobId, [
            'status' => 'partial',
            'phase' => 'condition',
            'error_json' => $err,
            'steps_json' => $steps,
        ]);
        return [
            'ok' => true,
            'status' => 'partial',
            'steps' => $steps,
            'identity' => $identity,
            'inspect' => [],
            'error' => $err,
            'pipeline' => $pipeline,
        ];
    }

    $condition = $a2['data'];
    $steps[] = roast_step('condition', 'Inspecting scratches and dirt…', 45, (int) ($a2['ms'] ?? 0), true);
    $pipeline['agents']['condition'] = [
        'backend' => $a2['backend'] ?? 'unknown',
        'fallback' => !empty($a2['fallback']),
        'fallback_reason' => $a2['fallback_reason'] ?? null,
    ];
    roast_jobs_update($jobId, ['phase' => 'condition', 'steps_json' => $steps]);

    // --- Agent 3: Mods ---
    $a3 = $agent3($imagePath, $identity);
    if (!$a3['ok']) {
        $err = $a3['error'] ?? roast_error('MODS_FAILED', 'Mod analysis failed.', true, 'mods');
        $inspect = roast_merge_inspect($condition, ['visual_mods' => []]);
        roast_jobs_update($jobId, [
            'status' => 'partial',
            'phase' => 'mods',
            'inspect_json' => $inspect,
            'error_json' => $err,
            'steps_json' => $steps,
        ]);
        return [
            'ok' => true,
            'status' => 'partial',
            'steps' => $steps,
            'identity' => $identity,
            'inspect' => $inspect,
            'error' => $err,
            'pipeline' => $pipeline,
        ];
    }

    $mods = $a3['data'];
    $inspect = roast_merge_inspect($condition, $mods);
    $steps[] = roast_step('mods', 'Scanning for aftermarket crimes…', 70, (int) ($a3['ms'] ?? 0), true);
    $pipeline['agents']['mods'] = [
        'backend' => $a3['backend'] ?? 'unknown',
        'fallback' => !empty($a3['fallback']),
        'fallback_reason' => $a3['fallback_reason'] ?? null,
    ];
    roast_jobs_update($jobId, [
        'phase' => 'mods',
        'inspect_json' => $inspect,
        'steps_json' => $steps,
    ]);

    $score = roast_compute_shame_score($identity, $inspect);
    $pipeline['mode'] = roast_coordinator_pipeline_mode($pipeline);

    // --- Agent 4: Judge (always local) ---
    $a4 = $agent4($identity, $inspect, $score);
    if (!$a4['ok']) {
        $err = $a4['error'] ?? roast_error('LOCAL_ROAST_FAILED', 'Roast generation failed.', true, 'judge');
        roast_jobs_update($jobId, [
            'status' => 'partial',
            'phase' => 'judge',
            'score' => $score,
            'error_json' => $err,
            'steps_json' => $steps,
        ]);
        return [
            'ok' => true,
            'status' => 'partial',
            'steps' => $steps,
            'identity' => $identity,
            'inspect' => $inspect,
            'score' => $score,
            'error' => $err,
            'pipeline' => $pipeline,
        ];
    }

    $steps[] = roast_step('judge', 'Calculating shame score…', 90, (int) ($a4['ms'] ?? 0), true);
    $pipeline['agents']['judge'] = ['backend' => 'local_text', 'fallback' => false];

    roast_jobs_update($jobId, [
        'status' => 'complete',
        'phase' => 'done',
        'roast_text' => $a4['text'],
        'score' => $score,
        'steps_json' => $steps,
        'error_json' => null,
    ]);

    return [
        'ok' => true,
        'status' => 'complete',
        'steps' => $steps,
        'identity' => $identity,
        'inspect' => $inspect,
        'score' => $score,
        'roast' => $a4['text'],
        'pipeline' => $pipeline,
    ];
}

/** @param array<string, mixed> $pipeline */
function roast_coordinator_pipeline_mode(array $pipeline): string
{
    $agents = $pipeline['agents'] ?? [];
    $anyFallback = false;
    $allLocal = true;
    foreach (['identify', 'condition', 'mods'] as $key) {
        $a = $agents[$key] ?? [];
        if (!empty($a['fallback'])) {
            $anyFallback = true;
        }
        $backend = $a['backend'] ?? '';
        if ($backend === 'cloud_vision' || str_ends_with($backend, '_vision')) {
            $allLocal = false;
        }
        if (in_array($backend, ['local_judge', 'groq_judge', 'openrouter_judge', 'local_text', 'user_override'], true)) {
            // expected backends
        }
    }
    if ($allLocal && !$anyFallback) {
        return 'local_primary';
    }
    if ($anyFallback) {
        return 'hybrid';
    }
    return 'cloud_primary';
}

function roast_coordinator_build_result(array $run): array
{
    $pipeline = $run['pipeline'] ?? [];
    $mode = $pipeline['mode'] ?? 'cloud_primary';
    $notice = ROAST_INTERPRETATION_NOTICE;
    if ($mode === 'local_primary') {
        // full local pipeline — no extra notice
    } elseif ($mode === 'local_fallback_pipeline' || $mode === 'hybrid') {
        $notice .= ' Some steps used text-only fallback (vision unavailable) — lower accuracy.';
    }

    $result = roast_build_result(
        isset($run['score']) ? (int) $run['score'] : null,
        $run['identity'] ?? [],
        $run['inspect'] ?? [],
        $run['roast'] ?? null,
        ($run['status'] ?? '') === 'partial' ? 'Judgment cut short — showing what we got.' : null
    );
    $result['interpretation_notice'] = $notice;
    $result['pipeline_mode'] = $mode;
    $result['pipeline'] = $pipeline;

    return $result;
}
