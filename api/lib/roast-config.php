<?php
declare(strict_types=1);

/**
 * Bike roast event — enterprise cloud vision + local judge pipeline.
 */

if (!function_exists('roast_env')) {
    function roast_env(string $key, string $default = ''): string
    {
        if (function_exists('onlybikes_env')) {
            $v = trim(onlybikes_env($key, ''));
            if ($v !== '') {
                return $v;
            }
        }
        if (function_exists('cfg')) {
            $v = trim((string) cfg($key, ''));
            if ($v !== '') {
                return $v;
            }
        }
        $g = getenv($key);
        return ($g !== false && $g !== '') ? trim((string) $g) : $default;
    }
}

if (!function_exists('roast_define_constants')) {
    function roast_define_constants(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $apiDir = dirname(__DIR__);
        $htdocs = dirname($apiDir);

        if (!defined('ROAST_PIPELINE_MODE')) {
            define('ROAST_PIPELINE_MODE', roast_env('ROAST_PIPELINE_MODE', 'cloud_first'));
        }
        if (!defined('ROAST_BINARY_PATH')) {
            define(
                'ROAST_BINARY_PATH',
                roast_env('AI_BINARY_PATH', roast_env('ROAST_BINARY_PATH', $htdocs . '/llama-b9285'))
            );
        }
        if (!defined('ROAST_THREADS')) {
            define('ROAST_THREADS', max(1, (int) roast_env('ROAST_THREADS', '1')));
        }

        $modelsDir = roast_env('ROAST_MODELS_DIR', $htdocs . '/models');
        $judgeDefault = $modelsDir . '/qwen2.5-1.5b-instruct-q4_k_m.gguf';

        if (!defined('ROAST_JUDGE_GGUF')) {
            define(
                'ROAST_JUDGE_GGUF',
                roast_env('ROAST_JUDGE_GGUF', roast_env('ROAST_MODEL_AGENT4', $judgeDefault))
            );
        }
        if (!defined('ROAST_MODEL_AGENT4')) {
            define('ROAST_MODEL_AGENT4', ROAST_JUDGE_GGUF);
        }

        // Agent 4 local inference (Ionos Level 2 safe flags)
        if (!defined('ROAST_JUDGE_CTX')) {
            define('ROAST_JUDGE_CTX', max(512, (int) roast_env('ROAST_JUDGE_CTX', '2048')));
        }
        if (!defined('ROAST_JUDGE_N_PREDICT')) {
            define('ROAST_JUDGE_N_PREDICT', max(32, (int) roast_env('ROAST_JUDGE_N_PREDICT', '150')));
        }
        if (!defined('ROAST_JUDGE_TEMP')) {
            define('ROAST_JUDGE_TEMP', (float) roast_env('ROAST_JUDGE_TEMP', '0.7'));
        }
        if (!defined('ROAST_JUDGE_LOCAL_TIMEOUT_SEC')) {
            define('ROAST_JUDGE_LOCAL_TIMEOUT_SEC', max(5, (int) roast_env('ROAST_JUDGE_LOCAL_TIMEOUT_SEC', '15')));
        }
        if (!defined('ROAST_JUDGE_CLOUD_TIMEOUT_SEC')) {
            define('ROAST_JUDGE_CLOUD_TIMEOUT_SEC', max(5, (int) roast_env('ROAST_JUDGE_CLOUD_TIMEOUT_SEC', '12')));
        }
        if (!defined('ROAST_JUDGE_GROQ_TEMP')) {
            define('ROAST_JUDGE_GROQ_TEMP', (float) roast_env('ROAST_JUDGE_GROQ_TEMP', '0.8'));
        }
        if (!defined('ROAST_JUDGE_GROQ_MAX_TOKENS')) {
            define('ROAST_JUDGE_GROQ_MAX_TOKENS', max(64, (int) roast_env('ROAST_JUDGE_GROQ_MAX_TOKENS', '200')));
        }

        // API keys
        if (!defined('ROAST_GROQ_API_KEY')) {
            define('ROAST_GROQ_API_KEY', roast_env('ROAST_GROQ_API_KEY', roast_env('ROAST_VISION_API_KEY', '')));
        }
        if (!defined('ROAST_VISION_API_KEY')) {
            define('ROAST_VISION_API_KEY', roast_env('ROAST_VISION_API_KEY', ROAST_GROQ_API_KEY));
        }
        if (!defined('ROAST_OPENROUTER_API_KEY')) {
            define('ROAST_OPENROUTER_API_KEY', roast_env('ROAST_OPENROUTER_API_KEY', ''));
        }

        // Agents 1–3 vision models (cloud only — prevents Ionos OOM)
        if (!defined('ROAST_VISION_MODEL_GROQ')) {
            define('ROAST_VISION_MODEL_GROQ', roast_env('ROAST_VISION_MODEL', 'llama-3.2-11b-vision-preview'));
        }
        if (!defined('ROAST_VISION_MODEL')) {
            define('ROAST_VISION_MODEL', ROAST_VISION_MODEL_GROQ);
        }
        if (!defined('ROAST_VISION_MODEL_OR_LLAMA')) {
            define(
                'ROAST_VISION_MODEL_OR_LLAMA',
                roast_env('ROAST_VISION_MODEL_OR_LLAMA', 'meta-llama/llama-3.2-11b-vision-instruct')
            );
        }
        if (!defined('ROAST_VISION_MODEL_OR_QWEN')) {
            define(
                'ROAST_VISION_MODEL_OR_QWEN',
                roast_env('ROAST_VISION_MODEL_OR_QWEN', 'qwen/qwen-2.5-vl-3b-instruct')
            );
        }
        if (!defined('ROAST_VISION_TEMPERATURE')) {
            define('ROAST_VISION_TEMPERATURE', (float) roast_env('ROAST_VISION_TEMPERATURE', '0.1'));
        }
        if (!defined('ROAST_VISION_TIMEOUT_SEC')) {
            define('ROAST_VISION_TIMEOUT_SEC', max(5, (int) roast_env('ROAST_VISION_TIMEOUT_SEC', '12')));
        }
        if (!defined('ROAST_VISION_MAX_TOKENS')) {
            define('ROAST_VISION_MAX_TOKENS', max(64, (int) roast_env('ROAST_VISION_MAX_TOKENS', '300')));
        }

        // Agent 4 judge cloud fallbacks
        if (!defined('ROAST_JUDGE_MODEL_GROQ')) {
            define('ROAST_JUDGE_MODEL_GROQ', roast_env('ROAST_JUDGE_MODEL_GROQ', 'llama-3.1-8b-instant'));
        }
        if (!defined('ROAST_JUDGE_MODEL_OR')) {
            define('ROAST_JUDGE_MODEL_OR', roast_env('ROAST_JUDGE_MODEL_OR', 'qwen/qwen-2.5-1.5b-instruct'));
        }

        if (!defined('ROAST_VISION_PROVIDER')) {
            define('ROAST_VISION_PROVIDER', roast_env('ROAST_VISION_PROVIDER', 'groq'));
        }
        if (!defined('ROAST_VISION_VPS_URL')) {
            define('ROAST_VISION_VPS_URL', roast_env('ROAST_VISION_VPS_URL', ''));
        }

        // Dedicated Roast Jobs DB (optional — falls back to ANALYTICS_DB_* if unset)
        if (!defined('ROAST_DB_HOST')) {
            define('ROAST_DB_HOST', roast_env('ROAST_DB_HOST', ''));
        }
        if (!defined('ROAST_DB_NAME')) {
            define('ROAST_DB_NAME', roast_env('ROAST_DB_NAME', ''));
        }
        if (!defined('ROAST_DB_USER')) {
            define('ROAST_DB_USER', roast_env('ROAST_DB_USER', ''));
        }
        if (!defined('ROAST_DB_PASS')) {
            define('ROAST_DB_PASS', roast_env('ROAST_DB_PASS', ''));
        }
        if (!defined('ROAST_DB_PORT')) {
            define('ROAST_DB_PORT', roast_env('ROAST_DB_PORT', '3306'));
        }

        if (!defined('ROAST_EVENT_ENABLED')) {
            define('ROAST_EVENT_ENABLED', roast_env('ROAST_EVENT_ENABLED', '0') === '1');
        }
        if (!defined('ROAST_EVENT_END')) {
            define('ROAST_EVENT_END', roast_env('ROAST_EVENT_END', ''));
        }
        if (!defined('ROAST_RATE_LIMIT_PER_IP_PER_DAY')) {
            define('ROAST_RATE_LIMIT_PER_IP_PER_DAY', (int) roast_env('ROAST_RATE_LIMIT_PER_IP_PER_DAY', '1'));
        }
        if (!defined('ROAST_DAILY_MAX_JOBS')) {
            define('ROAST_DAILY_MAX_JOBS', (int) roast_env('ROAST_DAILY_MAX_JOBS', '15'));
        }
        if (!defined('ROAST_MAX_CONCURRENT')) {
            define('ROAST_MAX_CONCURRENT', max(1, (int) roast_env('ROAST_MAX_CONCURRENT', '1')));
        }
        if (!defined('ROAST_TMP_DIR')) {
            define('ROAST_TMP_DIR', roast_env('ROAST_TMP_DIR', $apiDir . '/cache/roast-tmp'));
        }
        if (!defined('ROAST_LOCK_FILE')) {
            define('ROAST_LOCK_FILE', roast_env('ROAST_LOCK_FILE', $apiDir . '/cache/roast-pipeline.lock'));
        }
        if (!defined('ROAST_FAIL_LOG')) {
            define('ROAST_FAIL_LOG', roast_env('ROAST_FAIL_LOG', $apiDir . '/logs/roast-failures.log'));
        }
        if (!defined('ROAST_PVP_METRICS_LOG')) {
            define('ROAST_PVP_METRICS_LOG', roast_env('ROAST_PVP_METRICS_LOG', $apiDir . '/logs/pvp-metrics.log'));
        }
        if (!defined('ROAST_SHAME_SCORE_ENABLED')) {
            define('ROAST_SHAME_SCORE_ENABLED', roast_env('ROAST_SHAME_SCORE_ENABLED', '1') === '1');
        }
        if (!defined('ROAST_STEP_MIN_MS')) {
            define('ROAST_STEP_MIN_MS', max(800, (int) roast_env('ROAST_STEP_MIN_MS', '1200')));
        }
        if (!defined('ROAST_BYPASS_KEY')) {
            define('ROAST_BYPASS_KEY', roast_env('ROAST_BYPASS_KEY', ''));
        }
        if (!defined('ROAST_PVP_API_VERSION')) {
            define('ROAST_PVP_API_VERSION', roast_env('ROAST_PVP_API_VERSION', 'v1.2.6-pvp-cred'));
        }
        if (!defined('ROAST_PVP_ROUND_SEC')) {
            define('ROAST_PVP_ROUND_SEC', max(60, (int) roast_env('ROAST_PVP_ROUND_SEC', '180')));
        }
        if (!defined('PVP_WEBRTC_MAX_VIDEO_KBPS')) {
            define('PVP_WEBRTC_MAX_VIDEO_KBPS', max(200, (int) roast_env('PVP_WEBRTC_MAX_VIDEO_KBPS', '450')));
        }
        if (!defined('PVP_WEBRTC_MAX_AUDIO_KBPS')) {
            define('PVP_WEBRTC_MAX_AUDIO_KBPS', max(16, (int) roast_env('PVP_WEBRTC_MAX_AUDIO_KBPS', '32')));
        }
        if (!defined('PVP_WEBRTC_MAX_WIDTH')) {
            define('PVP_WEBRTC_MAX_WIDTH', max(320, (int) roast_env('PVP_WEBRTC_MAX_WIDTH', '640')));
        }
        if (!defined('PVP_WEBRTC_MAX_HEIGHT')) {
            define('PVP_WEBRTC_MAX_HEIGHT', max(240, (int) roast_env('PVP_WEBRTC_MAX_HEIGHT', '480')));
        }
        if (!defined('PVP_WEBRTC_MAX_FPS')) {
            define('PVP_WEBRTC_MAX_FPS', max(12, (int) roast_env('PVP_WEBRTC_MAX_FPS', '20')));
        }
        if (!defined('ROAST_PVP_DUAL_LIVE_SEC')) {
            define('ROAST_PVP_DUAL_LIVE_SEC', max(30, (int) roast_env('ROAST_PVP_DUAL_LIVE_SEC', '60')));
        }
        if (!defined('ROAST_PVP_MIXED_SEC')) {
            define('ROAST_PVP_MIXED_SEC', max(60, (int) roast_env('ROAST_PVP_MIXED_SEC', '120')));
        }
        if (!defined('ROAST_PVP_FORFEIT_GRACE_SEC')) {
            define('ROAST_PVP_FORFEIT_GRACE_SEC', max(3, min(30, (int) roast_env('ROAST_PVP_FORFEIT_GRACE_SEC', '5'))));
        }
        if (!defined('ROAST_PVP_NPC_ENABLED')) {
            define('ROAST_PVP_NPC_ENABLED', roast_env('ROAST_PVP_NPC_ENABLED', '1') === '1');
        }
        if (!defined('ROAST_PVP_NPC_FALLBACK_SEC')) {
            define('ROAST_PVP_NPC_FALLBACK_SEC', max(5, (int) roast_env('ROAST_PVP_NPC_FALLBACK_SEC', '10')));
        }
        if (!defined('ROAST_PVP_NPC_GRADE_DELAY_SEC')) {
            define(
                'ROAST_PVP_NPC_GRADE_DELAY_SEC',
                max(10, min(12, (int) roast_env('ROAST_PVP_NPC_GRADE_DELAY_SEC', '11')))
            );
        }
        if (!defined('ROAST_PVP_T1_TIMEOUT_SEC')) {
            define('ROAST_PVP_T1_TIMEOUT_SEC', max(2, min(8, (int) roast_env('ROAST_PVP_T1_TIMEOUT_SEC', '3'))));
        }
        if (!defined('ROAST_PVP_T1_RATE_SEC')) {
            define('ROAST_PVP_T1_RATE_SEC', max(3, min(9, (int) roast_env('ROAST_PVP_T1_RATE_SEC', '4'))));
        }
        if (!defined('ROAST_MIN_FRAME_BYTES')) {
            define('ROAST_MIN_FRAME_BYTES', max(512, (int) roast_env('ROAST_MIN_FRAME_BYTES', '2048')));
        }
        if (!defined('ROAST_INTERPRETATION_NOTICE')) {
            define(
                'ROAST_INTERPRETATION_NOTICE',
                'AI visual interpretation — not a mechanical inspection or certification.'
            );
        }

        if (!defined('ROAST_GROQ_DAILY_MAX')) {
            define('ROAST_GROQ_DAILY_MAX', max(0, (int) roast_env('ROAST_GROQ_DAILY_MAX', '25')));
        }
        if (!defined('ROAST_VISION_SKIP_GROQ')) {
            define('ROAST_VISION_SKIP_GROQ', roast_env('ROAST_VISION_SKIP_GROQ', '0') === '1');
        }
        if (!defined('ROAST_JUDGE_SKIP_GROQ')) {
            define('ROAST_JUDGE_SKIP_GROQ', roast_env('ROAST_JUDGE_SKIP_GROQ', '1') === '1');
        }
    }
}

roast_define_constants();

if (!function_exists('roast_event_active')) {
    function roast_event_active(): bool
    {
        if (!ROAST_EVENT_ENABLED) {
            return false;
        }
        $end = trim(ROAST_EVENT_END);
        if ($end === '') {
            return true;
        }
        $ts = strtotime($end . ' 23:59:59');
        return $ts !== false && time() <= $ts;
    }
}

if (!function_exists('roast_json_headers')) {
    function roast_json_headers(): void
    {
        if (function_exists('setSecurityHeaders')) {
            setSecurityHeaders();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }
}

if (!function_exists('roast_allowed_origin')) {
    function roast_allowed_origin(): bool
    {
        $allowed = function_exists('beddaAllowedHosts')
            ? beddaAllowedHosts()
            : ['onlybikes.shop', 'www.onlybikes.shop', 'localhost', '127.0.0.1'];

        $requestHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        if ($requestHost !== '' && in_array($requestHost, $allowed, true)) {
            return true;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $check = $origin ?: $ref;
        if ($check === '') {
            return true;
        }
        $host = parse_url($check, PHP_URL_HOST);
        return is_string($host) && in_array(strtolower($host), $allowed, true);
    }
}

if (!function_exists('roast_ip_hash')) {
    function roast_ip_hash(): string
    {
        $ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return hash('sha256', $ip . '|roast');
    }
}

if (!function_exists('roast_ensure_tmp_dir')) {
    function roast_ensure_tmp_dir(): bool
    {
        $dir = ROAST_TMP_DIR;
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        return @mkdir($dir, 0750, true) && is_writable($dir);
    }
}

if (!function_exists('roast_acquire_lock')) {
    function roast_acquire_lock(int $waitSec = 3): mixed
    {
        $lockDir = dirname(ROAST_LOCK_FILE);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0750, true);
        }
        $fh = @fopen(ROAST_LOCK_FILE, 'c+');
        if (!$fh) {
            return false;
        }
        $deadline = time() + $waitSec;
        while (!flock($fh, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fclose($fh);
                return false;
            }
            usleep(200000);
        }
        return $fh;
    }
}

if (!function_exists('roast_release_lock')) {
    function roast_release_lock(mixed $fh): void
    {
        if (!is_resource($fh)) {
            return;
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

if (!function_exists('roast_bypass_key_valid')) {
    function roast_bypass_key_valid(?string $submitted): bool
    {
        if (ROAST_BYPASS_KEY === '') {
            return false;
        }
        $submitted = trim((string) $submitted);
        if ($submitted === '') {
            return false;
        }
        return hash_equals(ROAST_BYPASS_KEY, $submitted);
    }
}

if (!function_exists('roast_request_bypass_active')) {
    function roast_request_bypass_active(): bool
    {
        $key = trim((string) ($_POST['bypass_key'] ?? $_GET['bypass_key'] ?? ''));
        return roast_bypass_key_valid($key);
    }
}

if (!function_exists('roast_log_failure')) {
    /** @param array<string, mixed> $context */
    function roast_log_failure(string $code, array $context = []): void
    {
        $dir = dirname(ROAST_FAIL_LOG);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = json_encode([
            'ts' => date('c'),
            'code' => $code,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE);
        if ($line !== false) {
            @file_put_contents(ROAST_FAIL_LOG, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}

if (!function_exists('roast_pvp_metric_groq_budget_remaining')) {
    function roast_pvp_metric_groq_budget_remaining(): ?int
    {
        if (!function_exists('roast_groq_budget_status')) {
            $budgetFile = __DIR__ . '/roast-cloud-budget.php';
            if (is_readable($budgetFile)) {
                require_once $budgetFile;
            }
        }
        if (!function_exists('roast_groq_budget_status')) {
            return null;
        }
        $status = roast_groq_budget_status();
        $remaining = $status['remaining'] ?? null;

        return $remaining === null ? null : max(0, (int) $remaining);
    }
}

if (!function_exists('roast_pvp_metric_cascade_fields')) {
    /**
     * Normalize cascade observability fields for PvP metrics emissions.
     *
     * @param array<string, mixed> $ctx
     * @return array<string, int|bool|null>
     */
    function roast_pvp_metric_cascade_fields(array $ctx = []): array
    {
        $tier1 = $ctx['tier1_latency_ms'] ?? $ctx['ms'] ?? null;
        $tier2 = $ctx['tier2_latency_ms'] ?? null;
        $cacheHit = $ctx['vision_cache_hit'] ?? $ctx['cache_hit'] ?? false;
        $scoreTier = $ctx['score_tier'] ?? null;
        $remaining = array_key_exists('groq_budget_remaining', $ctx)
            ? $ctx['groq_budget_remaining']
            : roast_pvp_metric_groq_budget_remaining();

        return [
            'tier1_latency_ms' => $tier1 === null ? null : max(0, (int) $tier1),
            'tier2_latency_ms' => $tier2 === null ? null : max(0, (int) $tier2),
            'vision_cache_hit' => (bool) $cacheHit,
            'score_tier' => $scoreTier === null ? null : max(0, (int) $scoreTier),
            'groq_budget_remaining' => $remaining === null ? null : max(0, (int) $remaining),
        ];
    }
}

if (!function_exists('roast_pvp_metric_from_scored')) {
    /**
     * Extract cascade fields from roast_pvp_score_frame() (or compatible) results.
     *
     * @param array<string, mixed> $scored
     * @return array<string, int|bool|null>
     */
    function roast_pvp_metric_from_scored(array $scored): array
    {
        $scoreTier = $scored['score_tier'] ?? null;
        if ($scoreTier === null) {
            if (!empty($scored['from_job'])) {
                $scoreTier = 0;
            } elseif (!empty($scored['vision_fallback'])) {
                $scoreTier = 1;
            } elseif (($scored['score_source'] ?? '') === 'vision' || !empty($scored['vision_real'])) {
                $scoreTier = 1;
            } else {
                $scoreTier = 1;
            }
        }

        return roast_pvp_metric_cascade_fields([
            'tier1_latency_ms' => $scored['tier1_latency_ms'] ?? null,
            'tier2_latency_ms' => $scored['tier2_latency_ms'] ?? null,
            'vision_cache_hit' => $scored['vision_cache_hit'] ?? false,
            'score_tier' => $scoreTier,
        ]);
    }
}

if (!function_exists('roast_log_pvp_metric')) {
    /** @param array<string, mixed> $fields */
    function roast_log_pvp_metric(string $event, array $fields = []): void
    {
        static $cascadeEvents = ['live_frame', 'tier2_cron', 'npc_grade'];
        if (in_array($event, $cascadeEvents, true)) {
            $fields = array_merge(roast_pvp_metric_cascade_fields($fields), $fields);
        }

        $dir = dirname(ROAST_PVP_METRICS_LOG);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = json_encode(array_merge([
            'ts' => date('c'),
            'event' => $event,
        ], $fields), JSON_UNESCAPED_UNICODE);
        if ($line !== false) {
            @file_put_contents(ROAST_PVP_METRICS_LOG, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}

