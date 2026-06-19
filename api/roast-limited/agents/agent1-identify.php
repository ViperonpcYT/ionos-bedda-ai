<?php

declare(strict_types=1);



require_once dirname(__DIR__, 2) . '/lib/roast-local-inference.php';

require_once dirname(__DIR__, 2) . '/lib/roast-envelope.php';

require_once dirname(__DIR__, 2) . '/lib/roast-local-agents.php';

require_once dirname(__DIR__, 2) . '/lib/roast-cloud-vision.php';



if (!function_exists('roast_agent1_degraded_live_identity')) {

    /**

     * Live PvP frames: always return scorable identity when vision is unavailable.

     *

     * @param array<string, mixed> $ctx

     * @return array<string, mixed>

     */

    function roast_agent1_degraded_live_identity(string $reason, array $ctx = []): array

    {

        $make = trim((string) ($ctx['make_override'] ?? ''));

        $model = trim((string) ($ctx['model_override'] ?? ''));

        $hasOverride = $make !== '' && $model !== '';



        $data = [

            'make' => $hasOverride ? $make : 'Unknown',

            'model' => $hasOverride ? $model : 'Unknown',

            'confidence' => $hasOverride ? 0.62 : 0.42,

            'is_complete_ebike' => false,

            'visible_subject' => 'partial_bike',

            'source' => $hasOverride ? 'degraded_override' : 'degraded_live',

            'degraded' => true,

            'degraded_reason' => $reason,

        ];



        return [

            'ok' => true,

            'data' => $data,

            'ms' => 0,

            'backend' => 'degraded_live',

            'fallback' => true,

            'fallback_reason' => $reason,

        ];

    }

}



if (!function_exists('roast_agent1_normalize_identity')) {

    /**

     * @param array<string, mixed> $data

     * @return array<string, mixed>

     */

    function roast_agent1_normalize_identity(array $data, array $ctx = []): array

    {

        $data['make'] = trim((string) ($data['make'] ?? ''));

        $data['model'] = trim((string) ($data['model'] ?? ''));

        $data['confidence'] = round((float) ($data['confidence'] ?? 0.5), 2);

        $subject = strtolower(trim((string) ($data['visible_subject'] ?? 'unclear')));

        $allowed = ['full_bike', 'partial_bike', 'parts_only', 'not_an_ebike', 'unclear'];

        $data['visible_subject'] = in_array($subject, $allowed, true) ? $subject : 'unclear';

        $data['is_complete_ebike'] = !empty($data['is_complete_ebike']);



        if ($data['visible_subject'] === 'full_bike' || $data['is_complete_ebike']) {
            $data['is_complete_ebike'] = true;
        }

        if (!empty($ctx['make_override'])) {

            $data['make'] = (string) $ctx['make_override'];

        }

        if (!empty($ctx['model_override'])) {

            $data['model'] = (string) $ctx['model_override'];

        }



        if ($data['make'] === '') {

            $data['make'] = 'Unknown';

        }

        if ($data['model'] === '') {

            $data['model'] = 'Unknown';

        }



        return $data;

    }

}



/** Agent 1 — Identification */

function roast_agent1_identify(string $imagePath, array $ctx = []): array

{

    $isLive = !empty($ctx['live_frame']);

    if ($isLive) {

        $prompt = <<<'PROMPT'

Identify e-moto / electric dirt bike content in this live PvP duel camera frame.

Judge ONLY what the camera shows — partial views, cropped sides, and close-ups are valid. Do not require a perfect hero shot or rider face.

First decide visible_subject:
- full_bike when most of the bike is visible (frame plus seat or motor/battery area).
- partial_bike when a clear e-moto section is visible (swingarm, bars, side panel, wheel with frame context) even if cropped.
- parts_only ONLY for isolated components with no bike/frame context (bare tire close-up, loose part on table).
- not_an_ebike ONLY when the frame clearly has no bike and no e-moto parts (face-only, empty room, unrelated object).

Then identify make and model when possible (Surron Light Bee X, Ultra Bee, Stark Varg, Talaria Sting/MX4/X3, YZ450 electric, E-Ride Pro, etc.).
If brand/model is uncertain but bike is visible, use Unknown with moderate confidence and still pick full_bike or partial_bike.

Output strictly JSON:

{"make":"string","model":"string","confidence":0.0,"is_complete_ebike":false,"visible_subject":"partial_bike"}

visible_subject must be one of: full_bike, partial_bike, parts_only, not_an_ebike, unclear

is_complete_ebike is true only when frame, seat, and motor/battery area are all clearly visible together.

PROMPT;

    } else {

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

    }



    if ($isLive) {
        $result = roast_pvp_vision_t1(
            $imagePath,
            $prompt,
            static fn(array $payload): bool => roast_validate_identity($payload),
            'identify',
            $ctx
        );
    } else {
        $result = roast_agent_vision_step(
            $imagePath,
            $prompt,
            static fn(array $payload): bool => roast_validate_identity($payload),
            'identify',
            '',
            '',
            $ctx
        );
    }



    if (!$result['ok']) {

        $reason = (string) ($result['error']['code'] ?? 'VISION_FAILED');

        $localText = roast_agent1_identify_local($ctx);

        if ($localText['ok']) {

            $localText['fallback'] = true;

            $localText['fallback_reason'] = $reason;

            if ($isLive && isset($localText['data']) && is_array($localText['data'])) {

                $localText['data'] = roast_agent1_normalize_identity($localText['data'], $ctx);

                $localText['data']['degraded'] = true;

                $localText['data']['degraded_reason'] = $reason;

                if (($localText['data']['visible_subject'] ?? '') !== 'not_an_ebike'

                    && in_array($localText['data']['visible_subject'] ?? '', ['unclear', 'parts_only'], true)) {

                    $localText['data']['visible_subject'] = 'partial_bike';

                }

            }

            return $localText;

        }

        if ($isLive) {

            error_log('[Roast Agent1] live_frame using degraded identity after vision failure: ' . $reason);

            return roast_agent1_degraded_live_identity($reason, $ctx);

        }

        return $result;

    }



    $rawData = $result['data'] ?? [];

    if (is_array($rawData) && $rawData !== []) {
        $rawData = roast_coerce_identity_fields($rawData);
    }

    if (!is_array($rawData) || $rawData === []) {

        if ($isLive) {

            $reason = 'CLOUD_EMPTY_DATA';

            error_log('[Roast Agent1] live_frame empty vision payload — degraded identity');

            return roast_agent1_degraded_live_identity($reason, $ctx);

        }

        return [

            'ok' => false,

            'error' => roast_error('CLOUD_EMPTY', 'Vision returned empty identity.', true, 'identify'),

            'ms' => $result['ms'] ?? 0,

        ];

    }



    $data = roast_agent1_normalize_identity($rawData, $ctx);

    $defaultBackend = $isLive ? 'openrouter_vision_qwen' : 'groq_vision';

    $data['source'] = $result['backend'] ?? $defaultBackend;



    return [

        'ok' => true,

        'data' => $data,

        'ms' => $result['ms'] ?? 0,

        'backend' => $result['backend'] ?? $defaultBackend,

        'fallback' => !empty($result['fallback']),

        'fallback_reason' => $result['fallback_reason'] ?? null,

    ];

}

