<?php
declare(strict_types=1);

/**
 * SHA-256 file cache for PvP Tier-1 live-frame vision results.
 */

require_once __DIR__ . '/roast-config.php';

if (!function_exists('roast_pvp_vision_cache_dir')) {
    function roast_pvp_vision_cache_dir(): string
    {
        $base = defined('ROAST_TMP_DIR')
            ? ROAST_TMP_DIR
            : (dirname(__DIR__) . '/cache/roast-tmp');
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'pvp-vision-cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }
}

if (!function_exists('roast_pvp_vision_cache_ttl_sec')) {
    function roast_pvp_vision_cache_ttl_sec(): int
    {
        if (defined('ROAST_PVP_VISION_CACHE_TTL_SEC')) {
            return max(60, (int) ROAST_PVP_VISION_CACHE_TTL_SEC);
        }
        return 3600;
    }
}

if (!function_exists('roast_pvp_vision_cache_key')) {
    /** SHA-256 of image bytes + phase (identify, etc.). */
    function roast_pvp_vision_cache_key(string $imagePath, string $phase = 'identify'): string
    {
        $phase = preg_replace('/[^a-z0-9_-]/i', '', $phase) ?: 'identify';
        if (!is_readable($imagePath)) {
            return '';
        }
        $hash = @hash_file('sha256', $imagePath);
        if (!is_string($hash) || strlen($hash) !== 64) {
            return '';
        }
        return $hash . '_' . $phase;
    }
}

if (!function_exists('roast_pvp_vision_cache_file')) {
    function roast_pvp_vision_cache_file(string $cacheKey): string
    {
        $safe = preg_replace('/[^a-f0-9_]/i', '', strtolower($cacheKey)) ?? '';
        if ($safe === '') {
            return '';
        }
        return roast_pvp_vision_cache_dir() . DIRECTORY_SEPARATOR . $safe . '.json';
    }
}

if (!function_exists('roast_pvp_vision_cache_get')) {
    /**
     * @return array<string, mixed>|null Cached vision result envelope (ok/data/backend/ms).
     */
    function roast_pvp_vision_cache_get(string $imagePath, string $phase = 'identify'): ?array
    {
        $key = roast_pvp_vision_cache_key($imagePath, $phase);
        if ($key === '') {
            return null;
        }
        $path = roast_pvp_vision_cache_file($key);
        if ($path === '' || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $entry = json_decode($raw, true);
        if (!is_array($entry) || !is_array($entry['result'] ?? null)) {
            return null;
        }
        $savedAt = (int) ($entry['saved_at'] ?? 0);
        if ($savedAt <= 0 || (time() - $savedAt) > roast_pvp_vision_cache_ttl_sec()) {
            @unlink($path);
            return null;
        }
        $result = $entry['result'];
        $result['cache_hit'] = true;
        $result['cache_key'] = $key;
        return $result;
    }
}

if (!function_exists('roast_pvp_vision_cache_set')) {
    /** @param array<string, mixed> $result */
    function roast_pvp_vision_cache_set(string $imagePath, string $phase, array $result): void
    {
        if (empty($result['ok']) || !is_array($result['data'] ?? null) || ($result['data'] ?? []) === []) {
            return;
        }
        $key = roast_pvp_vision_cache_key($imagePath, $phase);
        if ($key === '') {
            return;
        }
        $path = roast_pvp_vision_cache_file($key);
        if ($path === '') {
            return;
        }
        $payload = json_encode([
            'saved_at' => time(),
            'phase' => $phase,
            'result' => [
                'ok' => true,
                'data' => $result['data'],
                'ms' => (int) ($result['ms'] ?? 0),
                'backend' => (string) ($result['backend'] ?? 'openrouter_vision_qwen'),
                'provider' => (string) ($result['provider'] ?? 'openrouter'),
                'model' => (string) ($result['model'] ?? ''),
                'route' => (string) ($result['route'] ?? 't1_primary'),
                'fallback' => !empty($result['fallback']),
                'fallback_reason' => $result['fallback_reason'] ?? null,
            ],
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        @file_put_contents($path, $payload, LOCK_EX);
    }
}

if (!function_exists('roast_pvp_vision_cache_purge')) {
    function roast_pvp_vision_cache_purge(int $maxAgeSec = 0): int
    {
        $dir = roast_pvp_vision_cache_dir();
        if (!is_dir($dir)) {
            return 0;
        }
        $ttl = $maxAgeSec > 0 ? $maxAgeSec : roast_pvp_vision_cache_ttl_sec();
        $cutoff = time() - $ttl;
        $count = 0;
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $count++;
            }
        }
        return $count;
    }
}
