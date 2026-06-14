<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/roast-local-inference.php';
require_once dirname(__DIR__, 2) . '/lib/roast-envelope.php';
require_once dirname(__DIR__, 2) . '/lib/roast-local-agents.php';

/** Agent 2 — Condition inspector */
function roast_agent2_condition(string $imagePath, array $identity): array
{
    $make = (string) ($identity['make'] ?? 'Unknown');
    $model = (string) ($identity['model'] ?? 'Unknown');

    $prompt = <<<PROMPT
Analyze the physical condition of this {$make} {$model} electric bike in the image.
If the photo is not a complete bike (wheels only, partial frame, random parts), say so in missing_parts and set frame_visible false.
Identify cosmetic damage, dirt accumulation, and missing standard components.
Output strictly JSON:
{"damage":["string"],"cleanliness_score":0,"missing_parts":["string"],"frame_visible":true}
cleanliness_score is 0-10 (10 = spotless). frame_visible false if main frame is not clearly shown. Max 6 items per array.
PROMPT;

    $result = roast_agent_vision_step(
        $imagePath,
        $prompt,
        'roast_validate_condition',
        'condition'
    );

    if (!$result['ok']) {
        $local = roast_agent2_condition_local($identity);
        if ($local['ok']) {
            $local['fallback'] = true;
            $local['fallback_reason'] = $result['error']['code'] ?? 'VISION_FAILED';
        }
        return $local;
    }

    $data = $result['data'] ?? [];
    $data['cleanliness_score'] = max(0, min(10, (int) ($data['cleanliness_score'] ?? 5)));
    if (array_key_exists('frame_visible', $data)) {
        $data['frame_visible'] = (bool) $data['frame_visible'];
    }
    $data['source'] = $result['backend'] ?? 'groq_vision';

    return ['ok' => true, 'data' => $data, 'ms' => $result['ms'] ?? 0, 'backend' => $result['backend'] ?? 'groq_vision'];
}
