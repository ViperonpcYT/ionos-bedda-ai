<?php
declare(strict_types=1);

/**
 * Agent 4 cloud fallbacks: Groq llama-3.1-8b-instant → OpenRouter Qwen2.5-1.5B.
 */

require_once __DIR__ . '/roast-cloud-api.php';
require_once __DIR__ . '/roast-config.php';
require_once __DIR__ . '/roast-cloud-budget.php';

if (!function_exists('roast_judge_build_prompt')) {
    /** @param array<string, mixed> $identity @param array<string, mixed> $inspect */
    function roast_judge_build_prompt(array $identity, array $inspect, ?int $score): string
    {
        $payload = json_encode([
            'identity' => $identity,
            'inspect' => $inspect,
            'shame_score' => $score,
        ], JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are a cynical e-moto mechanic. Read this JSON about a bike's identity, condition, and possible visual mods.
Write a concise, witty, technical roast of the owner's choices. 2-4 sentences. No pleasantries. No markdown.

JSON:
{$payload}
PROMPT;
    }
}

if (!function_exists('roast_judge_groq')) {
    function roast_judge_groq(string $prompt): array
    {
        $payload = [
            'model' => ROAST_JUDGE_MODEL_GROQ,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You roast electric bike owners with dry mechanic humor. Plain text only.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => ROAST_JUDGE_GROQ_TEMP,
            'max_tokens' => ROAST_JUDGE_GROQ_MAX_TOKENS,
        ];

        $result = roast_groq_chat($payload, ROAST_JUDGE_CLOUD_TIMEOUT_SEC, 'judge', false);
        if ($result['ok']) {
            $result['backend'] = 'groq_judge';
        }
        return $result;
    }
}

if (!function_exists('roast_judge_openrouter')) {
    function roast_judge_openrouter(string $prompt): array
    {
        $payload = [
            'model' => ROAST_JUDGE_MODEL_OR,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You roast electric bike owners with dry mechanic humor. Plain text only.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => ROAST_JUDGE_GROQ_TEMP,
            'max_tokens' => ROAST_JUDGE_GROQ_MAX_TOKENS,
        ];

        $result = roast_openrouter_chat($payload, ROAST_JUDGE_CLOUD_TIMEOUT_SEC, 'judge', false);
        if ($result['ok']) {
            $result['backend'] = 'openrouter_judge';
        }
        return $result;
    }
}

if (!function_exists('roast_judge_cloud_chain')) {
    /**
     * Groq text → OpenRouter on 429 or server/timeout errors.
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     */
    function roast_judge_cloud_chain(array $identity, array $inspect, ?int $score): array
    {
        $prompt = roast_judge_build_prompt($identity, $inspect, $score);

        $skipGroq = defined('ROAST_JUDGE_SKIP_GROQ') && ROAST_JUDGE_SKIP_GROQ;
        $groq = ['ok' => false, 'ms' => 0];

        if (!$skipGroq && roast_groq_budget_available()) {
            $groq = roast_judge_groq($prompt);
            if ($groq['ok']) {
                roast_groq_budget_consume();
                return [
                    'ok' => true,
                    'text' => trim((string) ($groq['text'] ?? '')),
                    'ms' => $groq['ms'] ?? 0,
                    'backend' => 'groq_judge',
                ];
            }
        } elseif ($skipGroq) {
            $groq['error'] = roast_error('GROQ_SKIPPED', 'Groq judge skipped by config.', true, 'judge');
        } else {
            $groq['error'] = roast_error('GROQ_BUDGET', 'Groq daily budget exhausted.', true, 'judge');
        }

        if (ROAST_OPENROUTER_API_KEY !== '') {
            $or = roast_judge_openrouter($prompt);
            if ($or['ok']) {
                return [
                    'ok' => true,
                    'text' => trim((string) ($or['text'] ?? '')),
                    'ms' => ($groq['ms'] ?? 0) + ($or['ms'] ?? 0),
                    'backend' => 'openrouter_judge',
                    'fallback' => true,
                    'fallback_reason' => $groq['error']['code'] ?? 'GROQ_FAILED',
                ];
            }

            return [
                'ok' => false,
                'error' => $or['error'] ?? roast_error('CLOUD_ERROR', 'All judge cloud fallbacks failed.', true, 'judge'),
                'ms' => ($groq['ms'] ?? 0) + ($or['ms'] ?? 0),
            ];
        }

        return [
            'ok' => false,
            'error' => $groq['error'] ?? roast_error('CLOUD_ERROR', 'Groq judge failed.', true, 'judge'),
            'ms' => $groq['ms'] ?? 0,
        ];
    }
}
