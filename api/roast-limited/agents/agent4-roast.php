<?php

declare(strict_types=1);



require_once dirname(__DIR__, 2) . '/lib/roast-local-inference.php';

require_once dirname(__DIR__, 2) . '/lib/roast-cloud-text.php';



/**

 * Agent 4 — Judge

 * Primary: local Qwen2.5-1.5B Q4_K_M

 * Fallback 1: Groq llama-3.1-8b-instant

 * Fallback 2: OpenRouter qwen/qwen-2.5-1.5b-instruct

 *

 * @param array<string, mixed> $identity

 * @param array<string, mixed> $inspect

 */

function roast_agent4_roast(array $identity, array $inspect, ?int $score): array

{

    require_once dirname(__DIR__, 2) . '/lib/roast-envelope.php';



    $prompt = roast_judge_build_prompt($identity, $inspect, $score);

    $local = roast_run_judge_local($prompt);



    if ($local['ok']) {

        return [

            'ok' => true,

            'text' => trim((string) ($local['text'] ?? '')),

            'ms' => $local['ms'] ?? 0,

            'backend' => 'local_judge',

        ];

    }



    $cloud = roast_judge_cloud_chain($identity, $inspect, $score);

    if ($cloud['ok']) {

        $cloud['fallback'] = true;

        $cloud['fallback_reason'] = $local['error']['code'] ?? 'LOCAL_JUDGE_FAILED';

        return $cloud;

    }

    return [

        'ok' => true,

        'text' => roast_template_roast($identity, $inspect, $score),

        'ms' => ($local['ms'] ?? 0) + ($cloud['ms'] ?? 0),

        'backend' => 'template_fallback',

        'fallback' => true,

        'fallback_reason' => 'ALL_JUDGE_FAILED',

    ];

}

