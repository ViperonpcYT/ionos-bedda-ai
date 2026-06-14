<?php
declare(strict_types=1);

/**
 * Shared Groq / OpenRouter HTTP client for the roast pipeline.
 * Implements immediate fallback routing (no exponential backoff on 429).
 */

require_once __DIR__ . '/roast-config.php';
require_once __DIR__ . '/roast-envelope.php';

if (!function_exists('roast_parse_json_object')) {
    /** @return array<string, mixed>|null */
    function roast_parse_json_object(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $raw = $m[0];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
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

if (!function_exists('roast_openrouter_headers')) {
    /** @return list<string> */
    function roast_openrouter_headers(): array
    {
        $origin = defined('SITE_ORIGIN') ? SITE_ORIGIN : 'https://onlybikes.shop';
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ROAST_OPENROUTER_API_KEY,
            'HTTP-Referer: ' . $origin,
            'X-Title: OnlyBikes Roast Pipeline',
        ];
    }
}

if (!function_exists('roast_http_is_server_error')) {
    function roast_http_is_server_error(int $code): bool
    {
        return in_array($code, [500, 502, 503, 504], true);
    }
}

if (!function_exists('roast_http_should_fallback')) {
    /** @param array<string, mixed> $result */
    function roast_http_should_fallback(array $result): bool
    {
        $code = (int) ($result['http_code'] ?? 0);
        $errno = (int) ($result['curl_errno'] ?? 0);
        if ($code === 429) {
            return true;
        }
        if (in_array($code, [401, 403, 402], true)) {
            return true;
        }
        if (roast_http_is_server_error($code)) {
            return true;
        }
        if ($errno !== 0) {
            return true;
        }
        if (!empty($result['timeout'])) {
            return true;
        }
        return false;
    }
}

if (!function_exists('roast_api_chat_completion')) {
    /**
     * @param array<string, mixed> $payload OpenAI-compatible chat/completions body
     * @return array{
     *   ok: bool,
     *   data?: array<string,mixed>,
     *   text?: string,
     *   error?: array<string,mixed>,
     *   ms?: int,
     *   http_code?: int,
     *   curl_errno?: int,
     *   provider?: string,
     *   model?: string,
     *   timeout?: bool,
     *   raw_content?: string
     * }
     */
    function roast_api_chat_completion(
        string $provider,
        string $url,
        string $apiKey,
        array $payload,
        int $timeoutSec,
        string $phase,
        bool $expectJson = false
    ): array {
        if ($apiKey === '') {
            return [
                'ok' => false,
                'error' => roast_error('CONFIG', strtoupper($provider) . ' API key not configured.', false, $phase),
                'http_code' => 0,
                'provider' => $provider,
                'model' => (string) ($payload['model'] ?? ''),
            ];
        }

        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
        if ($provider === 'openrouter') {
            $headers = roast_openrouter_headers();
        }

        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => max(5, $timeoutSec),
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = (int) curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);

        $model = (string) ($payload['model'] ?? '');
        $base = [
            'ms' => $ms,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrno,
            'provider' => $provider,
            'model' => $model,
            'timeout' => $curlErrno === CURLE_OPERATION_TIMEDOUT || stripos($curlErr, 'timed out') !== false,
        ];

        if ($body === false || $curlErrno !== 0) {
            roast_log_failure('API_CURL', [
                'provider' => $provider,
                'model' => $model,
                'errno' => $curlErrno,
                'error' => $curlErr,
                'phase' => $phase,
            ]);
            return array_merge($base, [
                'ok' => false,
                'error' => roast_error(
                    $curlErrno === CURLE_OPERATION_TIMEDOUT ? 'CLOUD_TIMEOUT' : 'CLOUD_ERROR',
                    'API request failed: ' . ($curlErr !== '' ? $curlErr : 'network error'),
                    true,
                    $phase
                ),
            ]);
        }

        if ($httpCode === 429) {
            return array_merge($base, [
                'ok' => false,
                'error' => roast_error('PROVIDER_429', 'Rate limit on ' . $provider . '.', false, $phase),
            ]);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            roast_log_failure('API_HTTP', [
                'provider' => $provider,
                'model' => $model,
                'code' => $httpCode,
                'body' => substr((string) $body, 0, 500),
                'phase' => $phase,
            ]);
            return array_merge($base, [
                'ok' => false,
                'error' => roast_error(
                    'CLOUD_AUTH',
                    $httpCode === 401
                        ? 'API key rejected (401) — check Groq/OpenRouter keys in server .env.'
                        : 'API HTTP ' . $httpCode,
                    true,
                    $phase
                ),
            ]);
        }

        $json = json_decode((string) $body, true);
        $content = $json['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            return array_merge($base, [
                'ok' => false,
                'error' => roast_error('CLOUD_EMPTY', 'Empty model response.', true, $phase),
            ]);
        }

        if (!$expectJson) {
            return array_merge($base, [
                'ok' => true,
                'text' => trim($content),
                'raw_content' => $content,
            ]);
        }

        $parsed = roast_parse_json_object($content);
        if ($parsed === null) {
            return array_merge($base, [
                'ok' => false,
                'raw_content' => $content,
                'error' => roast_error('SCHEMA_VIOLATION', 'Response is not valid JSON.', true, $phase),
            ]);
        }

        return array_merge($base, [
            'ok' => true,
            'data' => $parsed,
            'raw_content' => $content,
        ]);
    }
}

if (!function_exists('roast_groq_chat')) {
    /** @param array<string, mixed> $payload */
    function roast_groq_chat(array $payload, int $timeoutSec, string $phase, bool $expectJson = false): array
    {
        return roast_api_chat_completion(
            'groq',
            'https://api.groq.com/openai/v1/chat/completions',
            roast_groq_api_key(),
            $payload,
            $timeoutSec,
            $phase,
            $expectJson
        );
    }
}

if (!function_exists('roast_openrouter_chat')) {
    /** @param array<string, mixed> $payload */
    function roast_openrouter_chat(array $payload, int $timeoutSec, string $phase, bool $expectJson = false): array
    {
        return roast_api_chat_completion(
            'openrouter',
            'https://openrouter.ai/api/v1/chat/completions',
            ROAST_OPENROUTER_API_KEY,
            $payload,
            $timeoutSec,
            $phase,
            $expectJson
        );
    }
}
