<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/roast-local-inference.php';
require_once dirname(__DIR__, 2) . '/lib/bike-stock-specs.php';
require_once dirname(__DIR__, 2) . '/lib/roast-envelope.php';
require_once dirname(__DIR__, 2) . '/lib/roast-local-agents.php';

/** Agent 3 — Mod spotter (cloud vision + stock RAG; heuristic fallback if all cloud routes fail) */
function roast_agent3_mods(string $imagePath, array $identity): array
{
    $make = (string) ($identity['make'] ?? 'Unknown');
    $model = (string) ($identity['model'] ?? 'Unknown');
    $specs = roast_stock_specs_prompt_block($make, $model);

    $prompt = <<<PROMPT
Compare visible components on this electric bike against stock specifications.
Identify visual deviations (possible aftermarket mods). You must look at the photo.

{$specs}

Output strictly JSON:
{"visual_mods":[{"part":"string","stock_spec":"string","observed_spec":"string"}]}
Max 6 mods.
PROMPT;

    $result = roast_agent_vision_step(
        $imagePath,
        $prompt,
        'roast_validate_mods',
        'mods'
    );

    if ($result['ok']) {
        $data = $result['data'] ?? [];
        $data['source'] = $result['backend'] ?? 'groq_vision';
        return [
            'ok' => true,
            'data' => $data,
            'ms' => $result['ms'] ?? 0,
            'backend' => $result['backend'] ?? 'groq_vision',
            'fallback' => !empty($result['fallback']),
            'fallback_reason' => $result['fallback_reason'] ?? null,
        ];
    }

    $local = roast_agent3_mods_local($identity);
    if ($local['ok']) {
        $local['fallback'] = true;
        $local['fallback_reason'] = $result['error']['code'] ?? 'VISION_FAILED';
    }
    return $local;
}
