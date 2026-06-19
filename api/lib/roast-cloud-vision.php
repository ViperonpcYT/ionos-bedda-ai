<?php
declare(strict_types=1);

/**
 * Cloud vision pipeline (Agents 1–3):
 *   Primary:   Groq llama-3.2-11b-vision-preview
 *   Fallback1: OpenRouter meta-llama/llama-3.2-11b-vision-instruct (Groq 429 / 5xx / timeout)
 *   Fallback2: OpenRouter qwen/qwen-2.5-vl-3b-instruct (OpenRouter FB1 failure)
 *
 * PvP live_frame Tier-1 (roast_pvp_vision_t1) — NO Groq:
 *   Cache → ROAST_VISION_VPS_URL stub → OpenRouter Qwen2.5-VL-3B → OpenRouter Llama backup
 */

require_once __DIR__ . '/roast-config.php';
require_once __DIR__ . '/roast-cloud-api.php';
require_once __DIR__ . '/roast-cloud-budget.php';

if (!function_exists('roast_vision_image_base64')) {
    function roast_vision_image_base64(string $imagePath): ?string
    {
        if (!is_readable($imagePath)) {
            return null;
        }
        $data = @file_get_contents($imagePath);
        if ($data === false || $data === '') {
            return null;
        }
        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $detected = finfo_file($fi, $imagePath);
                finfo_close($fi);
                if (is_string($detected) && str_starts_with($detected, 'image/')) {
                    $mime = $detected;
                }
            }
        }
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}

if (!function_exists('roast_coerce_identity_fields')) {
    /** Normalize LLM identity JSON before schema validation. @param array<string, mixed> $data */
    function roast_coerce_identity_fields(array $data): array
    {
        if (isset($data['make']) && !is_string($data['make'])) {
            $data['make'] = (string) $data['make'];
        }
        if (isset($data['model']) && !is_string($data['model'])) {
            $data['model'] = (string) $data['model'];
        }
        if (isset($data['confidence'])) {
            if (is_string($data['confidence']) && is_numeric(trim($data['confidence']))) {
                $data['confidence'] = (float) trim($data['confidence']);
            } elseif (is_int($data['confidence']) || is_float($data['confidence'])) {
                $data['confidence'] = (float) $data['confidence'];
            }
        }
        if (isset($data['visible_subject']) && !is_string($data['visible_subject'])) {
            $data['visible_subject'] = (string) $data['visible_subject'];
        }
        if (array_key_exists('is_complete_ebike', $data) && !is_bool($data['is_complete_ebike'])) {
            if (is_int($data['is_complete_ebike']) || is_float($data['is_complete_ebike'])) {
                $data['is_complete_ebike'] = ((int) $data['is_complete_ebike']) !== 0;
            } elseif (is_string($data['is_complete_ebike'])) {
                $parsed = filter_var(trim($data['is_complete_ebike']), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $data['is_complete_ebike'] = $parsed ?? false;
            } else {
                unset($data['is_complete_ebike']);
            }
        }
        return $data;
    }
}

if (!function_exists('roast_validate_identity')) {
    /** @param array<string, mixed> $data */
    function roast_validate_identity(array $data): bool
    {
        $data = roast_coerce_identity_fields($data);
        if (!isset($data['make'], $data['model'], $data['confidence'])
            || !is_string($data['make'])
            || !is_string($data['model'])
            || !is_numeric($data['confidence'])) {
            return false;
        }
        if (isset($data['visible_subject']) && !is_string($data['visible_subject'])) {
            return false;
        }
        if (isset($data['is_complete_ebike']) && !is_bool($data['is_complete_ebike'])) {
            return false;
        }
        return true;
    }
}

if (!function_exists('roast_validate_inspect')) {
    /** @param array<string, mixed> $data */
    function roast_validate_inspect(array $data): bool
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

if (!function_exists('roast_build_vision_payload')) {
    /** @return array<string, mixed> */
    function roast_build_vision_payload(string $model, string $prompt, string $b64): array
    {
        return [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $b64]],
                    ],
                ],
            ],
            'max_tokens' => ROAST_VISION_MAX_TOKENS,
            'temperature' => ROAST_VISION_TEMPERATURE,
            'response_format' => ['type' => 'json_object'],
        ];
    }
}

if (!function_exists('roast_vision_backend_label')) {
    function roast_vision_backend_label(string $provider, string $route): string
    {
        if ($provider === 'groq') {
            return 'groq_vision';
        }
        if ($route === 'fallback_2' || $route === 't1_primary') {
            return 'openrouter_vision_qwen';
        }
        return 'openrouter_vision_llama';
    }
}

if (!function_exists('roast_vision_error_code')) {
    /** @param array<string, mixed> $result */
    function roast_vision_error_code(array $result): string
    {
        return (string) ($result['error']['code'] ?? '');
    }
}

if (!function_exists('roast_log_vision_outcome')) {
    /**
     * Structured vision outcome for SLA / fallback monitoring.
     *
     * @param array{match_id_hash?: string, backend?: string, fallback?: bool, ms?: int, code?: string, phase?: string} $outcome
     */
    function roast_log_vision_outcome(array $outcome): void
    {
        roast_log_failure('VISION_OUTCOME', [
            'match_id_hash' => (string) ($outcome['match_id_hash'] ?? ''),
            'backend' => (string) ($outcome['backend'] ?? ''),
            'fallback' => !empty($outcome['fallback']),
            'ms' => (int) ($outcome['ms'] ?? 0),
            'code' => (string) ($outcome['code'] ?? 'OK'),
            'phase' => (string) ($outcome['phase'] ?? ''),
        ]);
    }
}

if (!function_exists('roast_vision_outcome_from_result')) {
    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $ctx
     */
    function roast_vision_outcome_from_result(array $result, string $phase, array $ctx = []): void
    {
        $code = 'OK';
        if (empty($result['ok'])) {
            $code = roast_vision_error_code($result);
            if ($code === '') {
                $code = 'VISION_FAILED';
            }
        }

        roast_log_vision_outcome([
            'match_id_hash' => (string) ($ctx['match_id_hash'] ?? ''),
            'backend' => (string) ($result['backend'] ?? ''),
            'fallback' => !empty($result['fallback']),
            'ms' => (int) ($result['ms'] ?? 0),
            'code' => $code,
            'phase' => $phase,
        ]);
    }
}

if (!function_exists('roast_vision_should_retry')) {
    /** One optional retry for transient provider/network failures. @param array<string, mixed> $result */
    function roast_vision_should_retry(array $result): bool
    {
        if (!empty($result['ok'])) {
            return false;
        }
        if (roast_http_should_fallback($result)) {
            return true;
        }
        $code = roast_vision_error_code($result);
        return in_array($code, ['CLOUD_TIMEOUT', 'CLOUD_ERROR', 'CLOUD_EMPTY'], true);
    }
}

if (!function_exists('roast_vision_should_fallback')) {
    /**
     * Whether to try the next provider in the vision chain.
     *
     * @param array<string, mixed> $result
     */
    function roast_vision_should_fallback(array $result): bool
    {
        if (roast_http_should_fallback($result)) {
            return true;
        }
        $code = roast_vision_error_code($result);
        return in_array($code, [
            'SCHEMA_VIOLATION',
            'CLOUD_EMPTY',
            'CLOUD_TIMEOUT',
            'CLOUD_ERROR',
            'GROQ_SKIPPED',
            'CONFIG',
        ], true);
    }
}

if (!function_exists('roast_vision_call_provider')) {
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    function roast_vision_call_provider(
        string $provider,
        array $payload,
        string $phase,
        int $timeoutSec = 0
    ): array {
        $timeout = $timeoutSec > 0 ? $timeoutSec : ROAST_VISION_TIMEOUT_SEC;

        return $provider === 'groq'
            ? roast_groq_chat($payload, $timeout, $phase, true)
            : roast_openrouter_chat($payload, $timeout, $phase, true);
    }
}

if (!function_exists('roast_vision_try_provider')) {
    /**
     * @param callable(array<string,mixed>):bool $validator
     * @return array<string, mixed>
     */
    function roast_vision_try_provider(
        string $provider,
        string $model,
        string $prompt,
        string $b64,
        callable $validator,
        string $phase,
        string $route,
        int $timeoutSec = 0,
        bool $allowRetry = true
    ): array {
        $payload = roast_build_vision_payload($model, $prompt, $b64);
        $call = roast_vision_call_provider($provider, $payload, $phase, $timeoutSec);

        if ($allowRetry && !$call['ok'] && roast_vision_should_retry($call)) {
            roast_log_failure('VISION_RETRY', [
                'phase' => $phase,
                'provider' => $provider,
                'model' => $model,
                'route' => $route,
                'error' => roast_vision_error_code($call),
                'http_code' => $call['http_code'] ?? 0,
            ]);
            usleep(300000);
            $retryCall = roast_vision_call_provider($provider, $payload, $phase);
            $call['ms'] = ($call['ms'] ?? 0) + ($retryCall['ms'] ?? 0);
            if ($retryCall['ok']) {
                $call = $retryCall;
            } elseif (!empty($retryCall['error'])) {
                $call = $retryCall;
            }
        }

        if (!$call['ok']) {
            $errCode = roast_vision_error_code($call);
            if ($errCode === 'CLOUD_EMPTY' || $errCode === 'SCHEMA_VIOLATION') {
                roast_log_failure($errCode === '' ? 'VISION_FAILED' : $errCode, [
                    'phase' => $phase,
                    'provider' => $provider,
                    'model' => $model,
                    'route' => $route,
                    'http_code' => $call['http_code'] ?? 0,
                    'raw' => isset($call['raw_content']) ? substr((string) $call['raw_content'], 0, 200) : null,
                ]);
            }
            $call['route'] = $route;
            return $call;
        }

        $data = $call['data'] ?? [];
        if ($phase === 'identify' && is_array($data) && $data !== []) {
            $data = roast_coerce_identity_fields($data);
        }
        if ($data === [] || !$validator($data)) {
            if ($data === []) {
                roast_log_failure('CLOUD_EMPTY_DATA', [
                    'phase' => $phase,
                    'provider' => $provider,
                    'model' => $model,
                    'route' => $route,
                ]);
            }

            // Schema violation — one stricter retry on same provider (no backoff)
            $strictPrompt = $prompt . "\n\nCRITICAL: Reply with ONLY valid JSON matching the schema. No markdown. No commentary.";
            $payload['messages'][0]['content'][0]['text'] = $strictPrompt;
            $retry = roast_vision_call_provider($provider, $payload, $phase);

            if ($retry['ok']) {
                $retryData = $retry['data'] ?? [];
                if ($phase === 'identify' && is_array($retryData) && $retryData !== []) {
                    $retryData = roast_coerce_identity_fields($retryData);
                }
                if ($retryData !== [] && $validator($retryData)) {
                    return [
                        'ok' => true,
                        'data' => $retryData,
                        'ms' => ($call['ms'] ?? 0) + ($retry['ms'] ?? 0),
                        'http_code' => $retry['http_code'] ?? 200,
                        'provider' => $provider,
                        'model' => $model,
                        'route' => $route,
                        'backend' => roast_vision_backend_label($provider, $route),
                    ];
                }
            }

            roast_log_failure('SCHEMA_VIOLATION', [
                'phase' => $phase,
                'provider' => $provider,
                'model' => $model,
                'route' => $route,
                'first_keys' => is_array($data) ? array_keys($data) : [],
                'retry_ok' => !empty($retry['ok']),
            ]);

            return [
                'ok' => false,
                'error' => roast_error('SCHEMA_VIOLATION', 'Vision JSON schema violation after retry.', true, $phase),
                'ms' => ($call['ms'] ?? 0) + ($retry['ms'] ?? 0),
                'http_code' => $retry['http_code'] ?? ($call['http_code'] ?? 0),
                'provider' => $provider,
                'route' => $route,
            ];
        }

        return [
            'ok' => true,
            'data' => $data,
            'ms' => $call['ms'] ?? 0,
            'http_code' => $call['http_code'] ?? 200,
            'provider' => $provider,
            'model' => $model,
            'route' => $route,
            'backend' => roast_vision_backend_label($provider, $route),
        ];
    }
}

if (!function_exists('roast_cloud_vision_t1')) {
    /**
     * Tier 1 fast vision — OpenRouter Qwen/Llama only, no Groq, short timeout.
     *
     * @param callable(array<string,mixed>):bool $validator
     * @return array<string, mixed>
     */
    function roast_cloud_vision_t1(
        string $imagePath,
        string $systemPrompt,
        callable $validator,
        string $phase = 'identify',
        array $ctx = []
    ): array {
        require_once __DIR__ . '/roast-envelope.php';
        require_once __DIR__ . '/roast-debug-session.php';

        $b64 = roast_vision_image_base64($imagePath);
        if ($b64 === null) {
            $imageFail = [
                'ok' => false,
                'error' => roast_error('IMAGE', 'Could not read uploaded image.', false, $phase),
            ];
            roast_vision_outcome_from_result($imageFail, $phase, $ctx);
            return $imageFail;
        }

        $prompt = trim($systemPrompt) . "\nOutput ONLY valid JSON.";
        $timeout = max(2, (int) ($ctx['timeout_sec'] ?? (defined('ROAST_PVP_T1_TIMEOUT_SEC') ? ROAST_PVP_T1_TIMEOUT_SEC : 3)));
        $chain = [
            ['openrouter', ROAST_VISION_MODEL_OR_QWEN, 't1_qwen'],
            ['openrouter', ROAST_VISION_MODEL_OR_LLAMA, 't1_llama'],
        ];
        $lastFail = null;

        foreach ($chain as [$provider, $model, $route]) {
            if ($provider === 'openrouter' && ROAST_OPENROUTER_API_KEY === '') {
                continue;
            }
            $result = roast_vision_try_provider(
                $provider,
                $model,
                $prompt,
                $b64,
                $validator,
                $phase,
                $route,
                $timeout,
                false
            );
            if ($result['ok']) {
                $result['backend'] = ($result['backend'] ?? 'openrouter') . '_t1';
                // #region agent log
                roast_debug_session_log('roast-cloud-vision.php:t1', 't1_success', [
                    'route' => $route,
                    'model' => $model,
                    'ms' => $result['ms'] ?? 0,
                ], 'H2');
                // #endregion
                roast_vision_outcome_from_result($result, $phase, $ctx);
                return $result;
            }
            $lastFail = $result;
        }

        if ($lastFail === null) {
            $lastFail = [
                'ok' => false,
                'error' => roast_error('CONFIG', 'OpenRouter not configured for Tier 1.', true, $phase),
            ];
        }

        // #region agent log
        roast_debug_session_log('roast-cloud-vision.php:t1', 't1_chain_failed', [
            'error_code' => roast_vision_error_code($lastFail),
            'or_key_set' => ROAST_OPENROUTER_API_KEY !== '',
            'timeout_sec' => $timeout,
        ], 'H1');
        // #endregion

        roast_vision_outcome_from_result($lastFail, $phase, $ctx);
        return $lastFail;
    }
}

if (!function_exists('roast_pvp_vision_t1')) {
    /**
     * PvP Tier 1 vision entry — Agent 1 live frames, no Groq.
     *
     * @param callable(array<string,mixed>):bool $validator
     * @return array<string, mixed>
     */
    function roast_pvp_vision_t1(
        string $imagePath,
        string $systemPrompt,
        callable $validator,
        string $phase = 'identify',
        array $ctx = []
    ): array {
        $ctx['tier1_fast'] = true;
        if (!isset($ctx['timeout_sec'])) {
            $ctx['timeout_sec'] = defined('ROAST_PVP_T1_TIMEOUT_SEC') ? ROAST_PVP_T1_TIMEOUT_SEC : 3;
        }

        require_once __DIR__ . '/roast-pvp-vision-cache.php';

        $cached = roast_pvp_vision_cache_get($imagePath, $phase);
        if ($cached !== null && ($cached['ok'] ?? false)) {
            roast_vision_outcome_from_result($cached, $phase, $ctx);
            // #region agent log
            require_once __DIR__ . '/roast-debug-session.php';
            roast_debug_session_log('roast-cloud-vision.php:vision_t1', 'cache_hit', [
                'phase' => $phase,
            ], 'H2');
            // #endregion
            return $cached;
        }

        $result = roast_cloud_vision_t1($imagePath, $systemPrompt, $validator, $phase, $ctx);

        if (!empty($result['ok'])) {
            roast_pvp_vision_cache_set($imagePath, $phase, $result);
        }

        return $result;
    }
}

if (!function_exists('roast_cloud_vision')) {
    /**
     * Enterprise vision chain with immediate fallback routing.
     *
     * @param callable(array<string,mixed>):bool $validator
     * @return array<string, mixed>
     */
    function roast_cloud_vision(
        string $imagePath,
        string $systemPrompt,
        callable $validator,
        string $phase = 'identify',
        array $ctx = []
    ): array {
        require_once __DIR__ . '/roast-envelope.php';

        if (!empty($ctx['tier1_fast'])) {
            return roast_cloud_vision_t1($imagePath, $systemPrompt, $validator, $phase, $ctx);
        }

        $b64 = roast_vision_image_base64($imagePath);
        if ($b64 === null) {
            $imageFail = [
                'ok' => false,
                'error' => roast_error('IMAGE', 'Could not read uploaded image.', false, $phase),
            ];
            roast_vision_outcome_from_result($imageFail, $phase, $ctx);
            return $imageFail;
        }

        $prompt = trim($systemPrompt) . "\nOutput ONLY valid JSON.";

        $forceGroq = !empty($ctx['force_groq']);
        $skipGroq = !$forceGroq
            && ((defined('ROAST_VISION_SKIP_GROQ') && ROAST_VISION_SKIP_GROQ)
                || !roast_groq_budget_available());

        if (!$skipGroq) {
            // Primary — Groq
            $primary = roast_vision_try_provider(
                'groq',
                ROAST_VISION_MODEL_GROQ,
                $prompt,
                $b64,
                $validator,
                $phase,
                'primary'
            );
            if ($primary['ok']) {
                roast_groq_budget_consume();
                roast_vision_outcome_from_result($primary, $phase, $ctx);
                return $primary;
            }

            $tryFb1 = roast_vision_should_fallback($primary);
        } else {
            $primary = [
                'ok' => false,
                'error' => roast_error('GROQ_SKIPPED', 'Groq vision skipped (budget or config).', true, $phase),
            ];
            $tryFb1 = true;
        }

        if (!$tryFb1 && ROAST_OPENROUTER_API_KEY === '') {
            roast_log_failure('VISION_CHAIN_STOP', [
                'phase' => $phase,
                'reason' => roast_vision_error_code($primary),
                'route' => 'primary',
            ]);
            roast_vision_outcome_from_result($primary, $phase, $ctx);
            return $primary;
        }

        // Fallback 1 — OpenRouter Llama 3.2 11B vision (or primary when Groq skipped)
        if (ROAST_OPENROUTER_API_KEY !== '') {
            $fb1 = roast_vision_try_provider(
                'openrouter',
                ROAST_VISION_MODEL_OR_LLAMA,
                $prompt,
                $b64,
                $validator,
                $phase,
                $skipGroq ? 'primary_qwen_chain' : 'fallback_1'
            );
            if ($fb1['ok']) {
                if (!$skipGroq) {
                    $fb1['fallback'] = true;
                    $fb1['fallback_reason'] = $primary['error']['code'] ?? 'GROQ_FAILED';
                } else {
                    $fb1['backend'] = ($fb1['backend'] ?? 'openrouter') . '_budget_skip';
                }
                roast_vision_outcome_from_result($fb1, $phase, $ctx);
                return $fb1;
            }

            if (roast_vision_should_fallback($fb1)) {
                // Fallback 2 — OpenRouter Qwen2.5-VL-3B
                $fb2 = roast_vision_try_provider(
                    'openrouter',
                    ROAST_VISION_MODEL_OR_QWEN,
                    $prompt,
                    $b64,
                    $validator,
                    $phase,
                    'fallback_2'
                );
                if ($fb2['ok']) {
                    $fb2['fallback'] = true;
                    $fb2['fallback_reason'] = $fb1['error']['code'] ?? 'OPENROUTER_FB1_FAILED';
                    roast_vision_outcome_from_result($fb2, $phase, $ctx);
                    return $fb2;
                }

                roast_log_failure('VISION_CHAIN_EXHAUSTED', [
                    'phase' => $phase,
                    'primary' => roast_vision_error_code($primary),
                    'fb1' => roast_vision_error_code($fb1),
                    'fb2' => roast_vision_error_code($fb2),
                ]);
                roast_vision_outcome_from_result($fb2, $phase, $ctx);
                return $fb2;
            }

            roast_log_failure('VISION_CHAIN_STOP', [
                'phase' => $phase,
                'reason' => roast_vision_error_code($fb1),
                'route' => 'fallback_1',
            ]);
            roast_vision_outcome_from_result($fb1, $phase, $ctx);
            return $fb1;
        }

        roast_log_failure('VISION_CHAIN_STOP', [
            'phase' => $phase,
            'reason' => roast_vision_error_code($primary),
            'route' => 'primary_no_openrouter',
        ]);
        roast_vision_outcome_from_result($primary, $phase, $ctx);
        return $primary;
    }
}

if (!function_exists('roast_cloud_vision_groq')) {
    /** @deprecated Use roast_cloud_vision() */
    function roast_cloud_vision_groq(string $imagePath, string $systemPrompt, bool $repairRetry = true): array
    {
        return roast_cloud_vision($imagePath, $systemPrompt, 'roast_validate_inspect', 'inspect');
    }
}

if (!function_exists('roast_groq_http')) {
    /** @deprecated Use roast_groq_chat() */
    function roast_groq_http(array $payload): array
    {
        return roast_groq_chat($payload, ROAST_VISION_TIMEOUT_SEC, 'inspect', true);
    }
}

if (!function_exists('roast_upload_err_message')) {
    function roast_upload_err_message(int $uploadErr): string
    {
        return match ($uploadErr) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image too large (max 8MB).',
            UPLOAD_ERR_PARTIAL => 'Upload interrupted — try again.',
            UPLOAD_ERR_NO_FILE => 'No camera frame received — keep the duel open and try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION
                => 'Server could not store upload — try again shortly.',
            default => 'Camera frame upload failed — try again in a few seconds.',
        };
    }
}

if (!function_exists('roast_validate_live_frame_upload')) {
    /**
     * Pre-check $_FILES['image'] before roast_save_uploaded_image().
     *
     * @return array{ok: bool, error?: string}
     */
    function roast_validate_live_frame_upload(?array $file): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($contentType !== '' && !str_contains($contentType, 'multipart/form-data')) {
            return [
                'ok' => false,
                'error' => 'Request must be multipart/form-data with field name "image".',
            ];
        }

        if (!is_array($file)) {
            if (isset($_FILES['file']) && is_array($_FILES['file'])) {
                return [
                    'ok' => false,
                    'error' => 'Upload field must be named "image", not "file".',
                ];
            }
            return [
                'ok' => false,
                'error' => 'No camera frame received — multipart field "image" is missing.',
            ];
        }

        $uploadErr = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadErr !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => roast_upload_err_message($uploadErr)];
        }

        $size = (int) ($file['size'] ?? 0);
        $minBytes = defined('ROAST_MIN_FRAME_BYTES') ? ROAST_MIN_FRAME_BYTES : 2048;
        if ($size < $minBytes) {
            return [
                'ok' => false,
                'error' => 'Camera frame too small or empty ('
                    . $size
                    . ' bytes) — wait for the camera preview, then try again.',
            ];
        }

        return ['ok' => true];
    }
}

if (!function_exists('roast_save_uploaded_image')) {
    /** @return array{ok: bool, path?: string, hash?: string, error?: string} */
    function roast_save_uploaded_image(array $file): array
    {
        if (!roast_ensure_tmp_dir()) {
            return ['ok' => false, 'error' => 'Upload directory not writable.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => roast_upload_err_message((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE))];
        }
        $size = (int) ($file['size'] ?? 0);
        $minBytes = defined('ROAST_MIN_FRAME_BYTES') ? ROAST_MIN_FRAME_BYTES : 2048;
        if ($size < $minBytes) {
            return [
                'ok' => false,
                'error' => 'Camera frame too small or empty ('
                    . $size
                    . ' bytes) — wait for the camera preview, then try again.',
            ];
        }
        if ($size > 8 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'Image too large (max 8MB).'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'Invalid upload.'];
        }

        $info = @getimagesize($tmp);
        if ($info === false) {
            return ['ok' => false, 'error' => 'File must be an image.'];
        }

        $hash = hash_file('sha256', $tmp);
        $dest = rtrim(ROAST_TMP_DIR, '/\\') . '/' . $hash . '.jpg';

        if (!function_exists('imagecreatefromstring')) {
            if (!@move_uploaded_file($tmp, $dest)) {
                return ['ok' => false, 'error' => 'Could not store image.'];
            }
            return ['ok' => true, 'path' => $dest, 'hash' => $hash];
        }

        $src = @imagecreatefromstring((string) file_get_contents($tmp));
        if (!$src) {
            return ['ok' => false, 'error' => 'Could not process image.'];
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $max = 1024;
        if ($w > $max || $h > $max) {
            $ratio = min($max / $w, $max / $h);
            $nw = (int) round($w * $ratio);
            $nh = (int) round($h * $ratio);
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        if (!@imagejpeg($src, $dest, 82)) {
            imagedestroy($src);
            return ['ok' => false, 'error' => 'Could not save resized image.'];
        }
        imagedestroy($src);

        return ['ok' => true, 'path' => $dest, 'hash' => $hash];
    }
}

if (!function_exists('roast_delete_image')) {
    function roast_delete_image(string $path): void
    {
        if ($path === '' || !is_file($path)) {
            return;
        }
        $real = realpath($path);
        $base = realpath(ROAST_TMP_DIR);
        if ($real && $base && str_starts_with($real, $base)) {
            @unlink($real);
        }
    }
}

if (!function_exists('roast_purge_temp_files')) {
    function roast_purge_temp_files(int $maxAgeSec = 3600): int
    {
        if (!is_dir(ROAST_TMP_DIR)) {
            return 0;
        }
        $count = 0;
        $cutoff = time() - $maxAgeSec;
        foreach (glob(ROAST_TMP_DIR . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }
        return $count;
    }
}
