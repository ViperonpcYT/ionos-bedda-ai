<?php
declare(strict_types=1);

require_once __DIR__ . '/ai-inference.php';
require_once __DIR__ . '/roast-envelope.php';
require_once __DIR__ . '/bike-stock-specs.php';

if (!function_exists('roast_local_json_agent')) {
    /**
     * Text-only local agent (Qwen via aiRun) — strict JSON output.
     *
     * @param callable(array<string,mixed>): bool $validator
     * @return array{ok: bool, data?: array<string, mixed>, error?: array<string, mixed>, ms?: int, backend?: string}
     */
    function roast_local_json_agent(string $prompt, callable $validator, string $phase): array
    {
        $start = microtime(true);
        $fullPrompt = $prompt . "\n\nReply with ONLY a single JSON object. No markdown, no explanation.";
        $result = aiRun($fullPrompt);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'error' => roast_error('LOCAL_AGENT_FAILED', 'Local analysis step failed.', true, $phase),
                'ms' => $ms,
                'backend' => 'local_text',
            ];
        }

        $raw = (string) ($result['text'] ?? '');
        if (!function_exists('roast_parse_json_object')) {
            require_once __DIR__ . '/roast-cloud-vision.php';
        }
        $data = roast_parse_json_object($raw);
        if ($data === null || !$validator($data)) {
            return [
                'ok' => false,
                'error' => roast_error('SCHEMA_VIOLATION', 'Local agent returned invalid JSON.', true, $phase),
                'ms' => $ms,
                'backend' => 'local_text',
            ];
        }

        return ['ok' => true, 'data' => $data, 'ms' => $ms, 'backend' => 'local_text'];
    }
}

if (!function_exists('roast_agent1_identify_local')) {
    /**
     * @param array{make_override?: string, model_override?: string} $ctx
     */
    function roast_agent1_identify_local(array $ctx): array
    {
        $make = trim((string) ($ctx['make_override'] ?? ''));
        $model = trim((string) ($ctx['model_override'] ?? ''));

        if ($make !== '' && $model !== '') {
            return [
                'ok' => true,
                'data' => [
                    'make' => $make,
                    'model' => $model,
                    'confidence' => 0.85,
                    'source' => 'user_override',
                ],
                'ms' => 0,
                'backend' => 'user_override',
            ];
        }

        $prompt = <<<'PROMPT'
The user uploaded an electric bike photo but cloud vision is unavailable.
Infer the most likely make and model (Surron Light Bee X, Surron Ultra Bee, Talaria Sting MX4, Stark Varg, E-Ride Pro, or Unknown).
You cannot see the photo — be conservative. Use Unknown with confidence 0.1 unless user overrides exist.
Output JSON: {"make":"string","model":"string","confidence":0.1,"is_complete_ebike":false,"visible_subject":"unclear"}
PROMPT;

        $result = roast_local_json_agent($prompt, 'roast_validate_identity', 'identify');
        if ($result['ok'] && isset($result['data'])) {
            $result['data']['source'] = 'local_text_guess';
            $result['data']['confidence'] = min(0.15, (float) ($result['data']['confidence'] ?? 0.1));
            $result['data']['is_complete_ebike'] = false;
            $result['data']['visible_subject'] = 'unclear';
        }
        return $result;
    }
}

if (!function_exists('roast_agent2_condition_local')) {
    /** @param array<string, mixed> $identity */
    function roast_agent2_condition_local(array $identity): array
    {
        $make = (string) ($identity['make'] ?? 'Unknown');
        $model = (string) ($identity['model'] ?? 'Unknown');
        $payload = json_encode($identity, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
You are Agent 2 (Condition Inspector). Cloud vision is unavailable — you cannot see the photo.
Using only typical wear patterns for a {$make} {$model}, produce a conservative speculative JSON assessment.
Use empty arrays if unknown. Do not invent specific damage you cannot verify.

Identity context: {$payload}

Output JSON:
{"damage":["string"],"cleanliness_score":5,"missing_parts":["string"]}
cleanliness_score 0-10. Max 4 items per array.
PROMPT;

        $result = roast_local_json_agent($prompt, 'roast_validate_condition', 'condition');
        if ($result['ok'] && isset($result['data'])) {
            $result['data']['cleanliness_score'] = max(0, min(10, (int) $result['data']['cleanliness_score']));
            $result['data']['source'] = 'local_text_speculative';
        }
        return $result;
    }
}

if (!function_exists('roast_agent3_mods_local')) {
    /** @param array<string, mixed> $identity */
    function roast_agent3_mods_local(array $identity): array
    {
        $make = (string) ($identity['make'] ?? 'Unknown');
        $model = (string) ($identity['model'] ?? 'Unknown');
        $specs = roast_stock_specs_prompt_block($make, $model);

        $prompt = <<<PROMPT
You are Agent 3 (Mod Spotter). Cloud vision is unavailable — you cannot see the photo.
Compare typical aftermarket trends for {$make} {$model} against stock specs below.
Output speculative possible mods only — label as guesses. Empty visual_mods if unknown.

{$specs}

Output JSON:
{"visual_mods":[{"part":"string","stock_spec":"string","observed_spec":"string"}]}
Max 4 mods.
PROMPT;

        $result = roast_local_json_agent($prompt, 'roast_validate_mods', 'mods');
        if ($result['ok'] && isset($result['data'])) {
            $result['data']['source'] = 'local_text_speculative';
        }
        return $result;
    }
}

if (!function_exists('roast_validate_condition')) {
    /** @param array<string, mixed> $data */
    function roast_validate_condition(array $data): bool
    {
        if (!isset($data['cleanliness_score']) || !is_numeric($data['cleanliness_score'])) {
            return false;
        }
        foreach (['damage', 'missing_parts'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('roast_validate_mods')) {
    /** @param array<string, mixed> $data */
    function roast_validate_mods(array $data): bool
    {
        return isset($data['visual_mods']) && is_array($data['visual_mods']);
    }
}

if (!function_exists('roast_merge_inspect')) {
    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $mods
     * @return array<string, mixed>
     */
    function roast_merge_inspect(array $condition, array $mods): array
    {
        return [
            'damage' => $condition['damage'] ?? [],
            'cleanliness_score' => (int) ($condition['cleanliness_score'] ?? 5),
            'missing_parts' => $condition['missing_parts'] ?? [],
            'visual_mods' => $mods['visual_mods'] ?? [],
        ];
    }
}
