<?php
declare(strict_types=1);

/**
 * Debug session NDJSON logger (Cursor debug mode).
 * Writes to workspace debug-a3b6cc.log when writable; falls back to api/logs/.
 */
if (!function_exists('roast_debug_session_log')) {
    /** @param array<string, mixed> $data */
    function roast_debug_session_log(string $location, string $message, array $data = [], string $hypothesisId = ''): void
    {
        $sessionId = 'a3b6cc';
        $entry = [
            'sessionId' => $sessionId,
            'timestamp' => (int) round(microtime(true) * 1000),
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'hypothesisId' => $hypothesisId,
        ];
        if ($hypothesisId !== '') {
            $entry['hypothesisId'] = $hypothesisId;
        }
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        $line .= "\n";
        $paths = [];
        if (defined('ROAST_TMP_DIR')) {
            $paths[] = rtrim((string) ROAST_TMP_DIR, '/\\') . '/debug-a3b6cc.log';
        }
        $paths[] = dirname(__DIR__) . '/logs/debug-a3b6cc.log';
        $paths[] = dirname(__DIR__, 2) . '/debug-a3b6cc.log';
        foreach ($paths as $path) {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }
            if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false) {
                return;
            }
        }
    }
}

if (!function_exists('roast_debug_session_enabled')) {
    function roast_debug_session_enabled(): bool
    {
        return trim((string) ($_POST['debug_session'] ?? $_GET['debug_session'] ?? '')) === 'a3b6cc';
    }
}
