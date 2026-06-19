<?php

declare(strict_types=1);



/**

 * Local llama-cli inference — Agent 4 judge only.

 * Agents 1–3 run on cloud APIs (Groq → OpenRouter) to avoid Ionos OOM.

 */



require_once __DIR__ . '/roast-config.php';

require_once __DIR__ . '/roast-envelope.php';

require_once __DIR__ . '/ai-inference.php';



if (!function_exists('roast_llama_prefix')) {

    function roast_llama_prefix(string $bin): string

    {

        $dir = dirname($bin);

        if (is_file($dir . '/libssl.so.3') || is_file($dir . '/libcrypto.so.3')) {

            return 'LD_LIBRARY_PATH=' . escapeshellarg($dir) . ' ';

        }

        return '';

    }

}



if (!function_exists('roast_extract_cli_output')) {

    function roast_extract_cli_output(string $raw): string

    {

        if (function_exists('aiExtractLlamaOutput')) {

            $t = aiExtractLlamaOutput($raw);

            if ($t !== '') {

                return $t;

            }

        }

        return trim($raw);

    }

}



if (!function_exists('roast_local_shell_failed')) {

    function roast_local_shell_failed(string $raw, int $ms): bool

    {

        if (trim($raw) === '') {

            return true;

        }

        $lower = strtolower($raw);

        if (str_contains($lower, 'glibc') || str_contains($lower, 'killed') || str_contains($lower, 'out of memory')) {
            return true;
        }
        if (roast_is_cli_error_text($raw)) {
            return true;
        }

        return $ms > (ROAST_JUDGE_LOCAL_TIMEOUT_SEC * 1000);

    }

}



if (!function_exists('roast_is_cli_error_text')) {
    function roast_is_cli_error_text(string $text): bool
    {
        $t = strtolower(trim($text));
        if ($t === '') {
            return true;
        }
        return str_contains($t, 'error:')
            || str_contains($t, 'invalid argument')
            || str_starts_with($t, 'usage:')
            || str_contains($t, 'unknown option');
    }
}

if (!function_exists('roast_judge_gguf_size_ok')) {
    function roast_judge_gguf_size_ok(): bool
    {
        if (!is_readable(ROAST_JUDGE_GGUF)) {
            return false;
        }
        $size = @filesize(ROAST_JUDGE_GGUF);
        return is_int($size) && $size >= 800_000_000;
    }
}

if (!function_exists('roast_template_roast')) {
    /**
     * Last-resort roast when all judge backends fail.
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     */
    function roast_template_roast(array $identity, array $inspect, ?int $score): string
    {
        require_once __DIR__ . '/roast-score.php';

        $mods = is_array($inspect['visual_mods'] ?? null) ? count($inspect['visual_mods']) : 0;
        $score = $score ?? roast_compute_shame_score($identity, $inspect);
        $subject = roast_normalize_visible_subject($identity, $inspect);

        if (in_array($subject, ['parts_only', 'not_an_ebike', 'unclear'], true) || $score <= 20) {
            return "Cred {$score}/100 — that's not a build, that's evidence. "
                . ($mods > 0 ? "{$mods} questionable part(s) flagged and we still can't find a whole bike. " : '')
                . 'Upload a side shot of the full frame or keep cosplaying as a parts bin.';
        }

        if ($mods >= 4) {
            return "Cred {$score}/100 — {$mods} aftermarket tells and counting. "
                . 'Your bike looks like a catalog threw up on a frame. At least the anodizing matches your poor life choices.';
        }

        return "Cred {$score}/100 — judgment servers choked, but the photo already told on you. "
            . ($mods > 0 ? "{$mods} mod(s) spotted. " : '')
            . 'Try again in a minute for the full verbal beating.';
    }
}

if (!function_exists('roast_run_judge_local')) {

    /**

     * Agent 4 primary — Qwen2.5-1.5B Q4_K_M on Ionos via llama-cli.

     *

     * @return array{ok: bool, text?: string, error?: array<string,mixed>, ms?: int, backend?: string}

     */

    function roast_run_judge_local(string $prompt): array

    {

        $modelPath = ROAST_JUDGE_GGUF;

        if (!roast_judge_gguf_size_ok()) {

            return [

                'ok' => false,

                'error' => roast_error(
                    'LOCAL_MODEL',
                    'Judge GGUF missing or incomplete on server (need ~1065 MB file).',
                    true,
                    'judge'
                ),

                'backend' => 'local_judge',

            ];

        }

        if (!is_readable($modelPath)) {

            return [

                'ok' => false,

                'error' => roast_error('LOCAL_MODEL', 'Judge GGUF missing on server.', false, 'judge'),

                'backend' => 'local_judge',

            ];

        }



        $bin = aiDetectBinary();

        if (!$bin) {

            return [

                'ok' => false,

                'error' => roast_error('LOCAL_BINARY', 'llama-cli binary not found.', false, 'judge'),

                'backend' => 'local_judge',

            ];

        }



        $cmd = roast_llama_prefix($bin)

            . escapeshellarg($bin)

            . ' -m ' . escapeshellarg($modelPath)

            . ' -p ' . escapeshellarg($prompt)

            . ' -t 1'

            . ' -c ' . ROAST_JUDGE_CTX

            . ' -n ' . ROAST_JUDGE_N_PREDICT

            . ' --temp ' . ROAST_JUDGE_TEMP

            . ' --no-mmap'
            . ' --no-display-prompt 2>&1';



        $start = microtime(true);

        $out = (string) @shell_exec($cmd);

        $ms = (int) round((microtime(true) - $start) * 1000);

        $text = roast_extract_cli_output($out);



        if (roast_local_shell_failed($out, $ms) || $text === '' || roast_is_cli_error_text($text)) {

            roast_log_failure('LOCAL_JUDGE_FAILED', [

                'ms' => $ms,

                'snippet' => substr($out, 0, 200),

            ]);

            return [

                'ok' => false,

                'error' => roast_error(

                    'LOCAL_OOM',

                    'Local judge failed (OOM, GLIBC, timeout, or empty output).',

                    true,

                    'judge'

                ),

                'ms' => $ms,

                'backend' => 'local_judge',

            ];

        }



        return [

            'ok' => true,

            'text' => $text,

            'ms' => $ms,

            'backend' => 'local_judge',

        ];

    }

}



if (!function_exists('roast_local_models_ready')) {

    /** @return array{ready: bool, missing: string[]} */

    function roast_local_models_ready(): array

    {

        $missing = [];

        if (!is_readable(ROAST_JUDGE_GGUF)) {

            $missing[] = 'ROAST_JUDGE_GGUF (Qwen2.5-1.5B judge)';

        } elseif (!roast_judge_gguf_size_ok()) {

            $missing[] = 'ROAST_JUDGE_GGUF incomplete (~1065 MB required, file looks truncated)';

        }

        if (!aiDetectBinary()) {

            $missing[] = 'llama-cli binary';

        }

        return ['ready' => $missing === [], 'missing' => $missing];

    }

}



if (!function_exists('roast_cloud_models_ready')) {

    /** @return array{ready: bool, missing: string[]} */

    function roast_cloud_models_ready(): array

    {

        $missing = [];

        if (roast_groq_api_key() === '') {

            $missing[] = 'ROAST_GROQ_API_KEY (vision primary)';

        }

        if (ROAST_OPENROUTER_API_KEY === '') {

            $missing[] = 'ROAST_OPENROUTER_API_KEY (vision/judge fallbacks)';

        }

        return ['ready' => $missing === [], 'missing' => $missing];

    }

}



if (!function_exists('roast_pipeline_model_catalog')) {

    /** @return list<array<string, mixed>> */

    function roast_pipeline_model_catalog(): array

    {

        return [

            [

                'id' => 1,

                'role' => 'identify',

                'kind' => 'cloud_vision',

                'primary' => ROAST_VISION_MODEL_GROQ . ' @ Groq',

                'fallback_1' => ROAST_VISION_MODEL_OR_LLAMA . ' @ OpenRouter',

                'fallback_2' => ROAST_VISION_MODEL_OR_QWEN . ' @ OpenRouter',

            ],

            [

                'id' => 2,

                'role' => 'condition',

                'kind' => 'cloud_vision',

                'primary' => ROAST_VISION_MODEL_GROQ . ' @ Groq',

                'fallback_1' => ROAST_VISION_MODEL_OR_LLAMA . ' @ OpenRouter',

                'fallback_2' => ROAST_VISION_MODEL_OR_QWEN . ' @ OpenRouter',

            ],

            [

                'id' => 3,

                'role' => 'mods',

                'kind' => 'cloud_vision',

                'primary' => ROAST_VISION_MODEL_GROQ . ' @ Groq',

                'fallback_1' => ROAST_VISION_MODEL_OR_LLAMA . ' @ OpenRouter',

                'fallback_2' => ROAST_VISION_MODEL_OR_QWEN . ' @ OpenRouter',

            ],

            [

                'id' => 4,

                'role' => 'judge',

                'kind' => 'local_text',

                'primary' => basename(ROAST_JUDGE_GGUF) . ' via llama-cli',

                'fallback_1' => ROAST_JUDGE_MODEL_GROQ . ' @ Groq',

                'fallback_2' => ROAST_JUDGE_MODEL_OR . ' @ OpenRouter',

            ],

        ];

    }

}



if (!function_exists('roast_agent_vision_step')) {

    /**

     * Cloud-only vision step (Agents 1–3).

     *

     * @param callable(array<string,mixed>):bool $validator

     */

    function roast_agent_vision_step(

        string $imagePath,

        string $prompt,

        callable $validator,

        string $phase,

        string $modelPath = '',

        string $mmprojPath = '',

        array $ctx = []

    ): array {

        require_once __DIR__ . '/roast-cloud-vision.php';



        $result = roast_cloud_vision($imagePath, $prompt, $validator, $phase, $ctx);

        if ($result['ok']) {

            return [

                'ok' => true,

                'data' => $result['data'] ?? [],

                'ms' => $result['ms'] ?? 0,

                'backend' => $result['backend'] ?? 'groq_vision',

                'fallback' => !empty($result['fallback']),

                'fallback_reason' => $result['fallback_reason'] ?? null,

            ];

        }



        return $result;

    }

}



if (!function_exists('roast_groq_api_key')) {

    function roast_groq_api_key(): string

    {

        if (defined('ROAST_GROQ_API_KEY') && ROAST_GROQ_API_KEY !== '') {

            return ROAST_GROQ_API_KEY;

        }

        return ROAST_VISION_API_KEY;

    }

}


