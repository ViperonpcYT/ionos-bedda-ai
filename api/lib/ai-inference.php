<?php
declare(strict_types=1);

/**
 * Local llama-cli inference + AI helpers for ai-engine.php.
 * Reads AI_* from api/.env — does not require Bedda-era secure-config blocks.
 */

require_once __DIR__ . '/chitchats-config.php';

function onlybikes_ai_env(string $key, string $default = ''): string
{
    if (function_exists('onlybikes_env')) {
        $v = trim(onlybikes_env($key, ''));
        if ($v !== '') {
            return $v;
        }
    }
    if (function_exists('cfg')) {
        $v = trim(cfg($key, ''));
        if ($v !== '') {
            return $v;
        }
    }
    onlybikes_chitchats_load_env();
    if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
        return trim((string) $_ENV[$key]);
    }
    $g = getenv($key);
    return ($g !== false && $g !== '') ? trim((string) $g) : $default;
}

function onlybikes_ai_define_constants(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $apiDir = dirname(__DIR__);
    $htdocs = dirname($apiDir);

    if (!defined('AI_BINARY_PATH')) {
        define('AI_BINARY_PATH', onlybikes_ai_env('AI_BINARY_PATH', $htdocs . '/llama-b9285'));
    }
    if (!defined('AI_MODEL_PATH')) {
        define(
            'AI_MODEL_PATH',
            onlybikes_ai_env(
                'AI_MODEL_PATH',
                $htdocs . '/models/qwen2.5-0.5b-instruct-q4_k_m.gguf'
            )
        );
    }
    if (!defined('AI_MAX_TOKENS')) {
        define('AI_MAX_TOKENS', (int) onlybikes_ai_env('AI_MAX_TOKENS', '180'));
    }
    if (!defined('AI_TIMEOUT_SEC')) {
        define('AI_TIMEOUT_SEC', (int) onlybikes_ai_env('AI_TIMEOUT_SEC', '25'));
    }
    if (!defined('AI_CONTEXT_LEN')) {
        define('AI_CONTEXT_LEN', (int) onlybikes_ai_env('AI_CONTEXT_LEN', '1024'));
    }
    if (!defined('AI_THREADS')) {
        define('AI_THREADS', (int) onlybikes_ai_env('AI_THREADS', '1'));
    }
    if (!defined('AI_CACHE_TTL')) {
        define('AI_CACHE_TTL', (int) onlybikes_ai_env('AI_CACHE_TTL', '86400'));
    }
    if (!defined('AI_RATE_LIMIT_PER_IP_PER_HOUR')) {
        define('AI_RATE_LIMIT_PER_IP_PER_HOUR', (int) onlybikes_ai_env('AI_RATE_LIMIT_PER_IP_PER_HOUR', '60'));
    }
    if (!defined('AI_RATE_LIMIT_PER_IP_PER_MINUTE')) {
        define('AI_RATE_LIMIT_PER_IP_PER_MINUTE', (int) onlybikes_ai_env('AI_RATE_LIMIT_PER_IP_PER_MINUTE', '8'));
    }
    if (!defined('AI_CACHE_DIR')) {
        define('AI_CACHE_DIR', onlybikes_ai_env('AI_CACHE_DIR', $apiDir . '/cache/ai'));
    }
    if (!defined('AI_LOG_DIR')) {
        define('AI_LOG_DIR', onlybikes_ai_env('AI_LOG_DIR', $apiDir . '/logs'));
    }
    if (!defined('ADMIN_PASSWORD_HASH')) {
        define('ADMIN_PASSWORD_HASH', onlybikes_ai_env('ADMIN_PASSWORD_HASH', ''));
    }
    if (!isset($GLOBALS['VALID_API_KEYS']) || !is_array($GLOBALS['VALID_API_KEYS'])) {
        $raw = onlybikes_ai_env('VALID_API_KEYS', onlybikes_ai_env('AI_ADMIN_API_KEYS', ''));
        $GLOBALS['VALID_API_KEYS'] = $raw !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $raw))))
            : [];
    }
}

onlybikes_ai_define_constants();

if (!function_exists('beddaAllowedHosts')) {
    function beddaAllowedHosts(): array
    {
        $hosts = ['localhost', '127.0.0.1', 'onlybikes.shop', 'www.onlybikes.shop'];
        foreach (['SITE_ORIGIN', 'SITE_URL'] as $key) {
            $origin = onlybikes_ai_env($key, '');
            if ($origin === '') {
                continue;
            }
            $h = parse_url($origin, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $hosts[] = strtolower($h);
            }
        }
        return array_values(array_unique($hosts));
    }
}

if (!function_exists('aiDetectBinary')) {
    function aiDetectBinary(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached !== '' ? $cached : null;
        }

        $candidates = [];
        $base = rtrim((string) AI_BINARY_PATH, '/\\');
        if ($base !== '') {
            if (is_file($base) && is_executable($base)) {
                $candidates[] = $base;
            }
            foreach (['llama-cli', 'llama', 'main', 'llama-b9285/llama-cli'] as $name) {
                $candidates[] = $base . '/' . $name;
            }
        }

        $htdocs = dirname(dirname(__DIR__));
        $candidates[] = $htdocs . '/llama-b9285/llama-cli';
        $candidates[] = $htdocs . '/llama-cli/llama-cli';

        foreach ($candidates as $path) {
            if (is_file($path) && (is_executable($path) || is_readable($path))) {
                $cached = $path;
                return $path;
            }
        }

        $cached = '';
        return null;
    }
}

if (!function_exists('aiExtractLlamaOutput')) {
    function aiExtractLlamaOutput(string $raw): string
    {
        $lines = preg_split('/\R/', $raw) ?: [];
        $responseLines = [];
        $inGenerate = false;
        foreach ($lines as $line) {
            if (strpos($line, 'generate:') !== false) {
                $inGenerate = true;
                continue;
            }
            if ($inGenerate && strpos($line, 'llama_perf') !== false) {
                break;
            }
            if ($inGenerate && trim($line) !== '') {
                $responseLines[] = trim($line);
            }
        }
        $text = trim(implode(' ', $responseLines));
        if ($text !== '') {
            return $text;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'llama_') || str_starts_with($line, 'load_')) {
                continue;
            }
            if (stripos($line, 'error') !== false || stripos($line, 'warning:') === 0) {
                continue;
            }
            $text = $line;
        }
        return trim($text);
    }
}

if (!function_exists('aiRun')) {
    /** @return array{ok:bool,text?:string,error?:string,elapsed?:float,cached?:bool} */
    function aiRun(string $prompt): array
    {
        $bin = aiDetectBinary();
        if (!$bin || !is_readable(AI_MODEL_PATH)) {
            return ['ok' => false, 'error' => 'AI model or binary unavailable'];
        }

        if (!is_dir(AI_CACHE_DIR)) {
            @mkdir(AI_CACHE_DIR, 0750, true);
        }

        $cacheKey = hash('sha256', $prompt);
        $cacheFile = rtrim(AI_CACHE_DIR, '/\\') . '/' . $cacheKey . '.json';
        if (is_readable($cacheFile) && (time() - (int) @filemtime($cacheFile)) < AI_CACHE_TTL) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached['text'])) {
                $cached['cached'] = true;
                $cached['ok'] = true;
                return $cached;
            }
        }

        $llamaDir = dirname($bin);
        $prefix = '';
        if (is_file($llamaDir . '/libssl.so.3') || is_file($llamaDir . '/libcrypto.so.3')) {
            $prefix = 'LD_LIBRARY_PATH=' . escapeshellarg($llamaDir) . ' ';
        }

        $cmd = $prefix
            . escapeshellarg($bin)
            . ' -m ' . escapeshellarg(AI_MODEL_PATH)
            . ' -p ' . escapeshellarg($prompt)
            . ' -n ' . max(16, (int) AI_MAX_TOKENS)
            . ' -c ' . max(256, (int) AI_CONTEXT_LEN)
            . ' -t ' . max(1, (int) AI_THREADS)
            . ' --temp 0.35 -b 32 --no-display-prompt 2>&1';

        $start = microtime(true);
        $out = trim((string) @shell_exec($cmd));
        $elapsed = round(microtime(true) - $start, 2);

        if ($elapsed > AI_TIMEOUT_SEC) {
            error_log('[OnlyBikes AI] inference slow: ' . $elapsed . 's');
        }

        $text = aiExtractLlamaOutput($out);
        if ($text === '') {
            error_log('[OnlyBikes AI] empty output: ' . substr($out, 0, 400));
            return ['ok' => false, 'error' => 'empty output', 'elapsed' => $elapsed];
        }

        $result = ['ok' => true, 'text' => $text, 'elapsed' => $elapsed, 'cached' => false];
        @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $result;
    }
}

if (!function_exists('chitchatsGetShipment')) {
    function chitchatsGetShipment(string $id): array
    {
        if ($id === '' || !onlybikes_chitchats_configured()) {
            return ['ok' => false, 'error' => 'Chit Chats not configured'];
        }
        $result = onlybikes_chitchats_request('shipments/' . rawurlencode($id), [], 'GET', 0);
        if (isset($result['error'])) {
            return ['ok' => false, 'error' => $result['message'] ?? 'lookup failed'];
        }
        return ['ok' => true, 'data' => $result];
    }
}

if (!function_exists('onlybikes_ai_json_headers')) {
    function onlybikes_ai_json_headers(): void
    {
        if (function_exists('setSecurityHeaders')) {
            setSecurityHeaders();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }
}
