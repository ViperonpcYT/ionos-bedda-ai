<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/roast-local-inference.php';
require_once dirname(__DIR__, 2) . '/lib/roast-envelope.php';
require_once dirname(__DIR__, 2) . '/lib/roast-local-agents.php';

/** Agent 1 — Identification */
function roast_agent1_identify(string $imagePath, array $ctx = []): array
{
    $prompt = <<<'PROMPT'
Identify what is in this image for an e-moto roast event.
First decide if the photo shows a complete electric dirt bike / e-moto, partial bike, loose parts only, or not a bike at all.
Then identify make and model if possible (Surron Light Bee X, Ultra Bee, Stark Varg, Talaria Sting/MX4, E-Ride Pro, etc.).

Output strictly JSON:
{"make":"string","model":"string","confidence":0.0,"is_complete_ebike":true,"visible_subject":"full_bike"}

visible_subject must be one of: full_bike, partial_bike, parts_only, not_an_ebike, unclear
is_complete_ebike is false if frame/seat/motor are not all clearly visible.
Use Unknown for make/model and low confidence when you cannot identify a full bike.
PROMPT;

    $result = roast_agent_vision_step(
        $imagePath,
        $prompt,
        'roast_validate_identity',
        'identify'
    );

    if (!$result['ok']) {
        $localText = roast_agent1_identify_local($ctx);
        if ($localText['ok']) {
            $localText['fallback'] = true;
            $localText['fallback_reason'] = $result['error']['code'] ?? 'VISION_FAILED';
            return $localText;
        }
        return $result;
    }

    $data = $result['data'] ?? [];
    $data['make'] = trim((string) ($data['make'] ?? ''));
    $data['model'] = trim((string) ($data['model'] ?? ''));
    $data['confidence'] = round((float) ($data['confidence'] ?? 0.5), 2);
    $data['source'] = $result['backend'] ?? 'groq_vision';
    $subject = strtolower(trim((string) ($data['visible_subject'] ?? 'unclear')));
    $allowed = ['full_bike', 'partial_bike', 'parts_only', 'not_an_ebike', 'unclear'];
    $data['visible_subject'] = in_array($subject, $allowed, true) ? $subject : 'unclear';
    $data['is_complete_ebike'] = !empty($data['is_complete_ebike']);

    if (!empty($ctx['make_override'])) {
        $data['make'] = (string) $ctx['make_override'];
    }
    if (!empty($ctx['model_override'])) {
        $data['model'] = (string) $ctx['model_override'];
    }

    return [
        'ok' => true,
        'data' => $data,
        'ms' => $result['ms'] ?? 0,
        'backend' => $result['backend'] ?? 'groq_vision',
    ];
}
