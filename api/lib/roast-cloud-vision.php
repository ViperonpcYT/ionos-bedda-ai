<?php
declare(strict_types=1);

/**
 * Cloud vision pipeline (Agents 1–3):
 *   Primary:   Groq llama-3.2-11b-vision-preview
 *   Fallback1: OpenRouter meta-llama/llama-3.2-11b-vision-instruct (Groq 429 / 5xx / timeout)
 *   Fallback2: OpenRouter qwen/qwen-2.5-vl-3b-instruct (OpenRouter FB1 failure)
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

if (!function_exists('roast_validate_identity')) {
    /** @param array<string, mixed> $data */
    function roast_validate_identity(array $data): bool
    {
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
        return $route === 'fallback_2' ? 'openrouter_vision_qwen' : 'openrouter_vision_llama';
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
        string $route
    ): array {
        $payload = roast_build_vision_payload($model, $prompt, $b64);
        $call = $provider === 'groq'
            ? roast_groq_chat($payload, ROAST_VISION_TIMEOUT_SEC, $phase, true)
            : roast_openrouter_chat($payload, ROAST_VISION_TIMEOUT_SEC, $phase, true);

        if (!$call['ok']) {
            $call['route'] = $route;
            return $call;
        }

        $data = $call['data'] ?? [];
        if ($validator($data)) {
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

        // Schema violation — one stricter retry on same provider (no backoff)
        $strictPrompt = $prompt . "\n\nCRITICAL: Reply with ONLY valid JSON matching the schema. No markdown. No commentary.";
        $payload['messages'][0]['content'][0]['text'] = $strictPrompt;
        $retry = $provider === 'groq'
            ? roast_groq_chat($payload, ROAST_VISION_TIMEOUT_SEC, $phase, true)
            : roast_openrouter_chat($payload, ROAST_VISION_TIMEOUT_SEC, $phase, true);

        if ($retry['ok'] && $validator($retry['data'] ?? [])) {
            return [
                'ok' => true,
                'data' => $retry['data'],
                'ms' => ($call['ms'] ?? 0) + ($retry['ms'] ?? 0),
                'http_code' => $retry['http_code'] ?? 200,
                'provider' => $provider,
                'model' => $model,
                'route' => $route,
                'backend' => roast_vision_backend_label($provider, $route),
            ];
        }

        roast_log_failure('SCHEMA_VIOLATION', [
            'phase' => $phase,
            'provider' => $provider,
            'model' => $model,
            'route' => $route,
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
        string $phase = 'identify'
    ): array {
        require_once __DIR__ . '/roast-envelope.php';

        $b64 = roast_vision_image_base64($imagePath);
        if ($b64 === null) {
            return [
                'ok' => false,
                'error' => roast_error('IMAGE', 'Could not read uploaded image.', false, $phase),
            ];
        }

        $prompt = trim($systemPrompt) . "\nOutput ONLY valid JSON.";

        $skipGroq = (defined('ROAST_VISION_SKIP_GROQ') && ROAST_VISION_SKIP_GROQ)
            || !roast_groq_budget_available();

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
                return $primary;
            }

            $tryFb1 = roast_http_should_fallback($primary);
        } else {
            $primary = [
                'ok' => false,
                'error' => roast_error('GROQ_SKIPPED', 'Groq vision skipped (budget or config).', true, $phase),
            ];
            $tryFb1 = true;
        }

        if (!$tryFb1 && ROAST_OPENROUTER_API_KEY === '') {
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
                return $fb1;
            }

            if (roast_http_should_fallback($fb1)) {
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
                    return $fb2;
                }

                return $fb2;
            }

            return $fb1;
        }

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

if (!function_exists('roast_save_uploaded_image')) {
    /** @return array{ok: bool, path?: string, hash?: string, error?: string} */
    function roast_save_uploaded_image(array $file): array
    {
        if (!roast_ensure_tmp_dir()) {
            return ['ok' => false, 'error' => 'Upload directory not writable.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Upload failed.'];
        }
        if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
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
