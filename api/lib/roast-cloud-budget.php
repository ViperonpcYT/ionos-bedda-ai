<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime-credits.php';

if (!function_exists('roast_groq_budget_cache_path')) {
    function roast_groq_budget_cache_path(): string
    {
        return dirname(__DIR__) . '/cache/roast-groq-daily.json';
    }
}

if (!function_exists('roast_groq_budget_available')) {
    function roast_groq_budget_available(): bool
    {
        if (!defined('ROAST_GROQ_DAILY_MAX')) {
            return true;
        }
        $max = (int) ROAST_GROQ_DAILY_MAX;
        if ($max <= 0) {
            return true;
        }

        $today = date('Y-m-d');
        $count = roast_groq_budget_count($today);
        return $count < $max;
    }
}

if (!function_exists('roast_groq_budget_count')) {
    function roast_groq_budget_count(string $date): int
    {
        try {
            $pdo = runtime_credits_pdo();
            runtime_credits_ensure_schema($pdo);
            $stmt = $pdo->prepare('SELECT groq_calls FROM cloud_usage_daily WHERE usage_date = ?');
            $stmt->execute([$date]);
            $val = $stmt->fetchColumn();
            if ($val !== false) {
                return (int) $val;
            }
        } catch (Throwable $e) {
            // fall through to file cache
        }

        $path = roast_groq_budget_cache_path();
        if (!is_readable($path)) {
            return 0;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return 0;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['date'] ?? '') !== $date) {
            return 0;
        }
        return max(0, (int) ($data['count'] ?? 0));
    }
}

if (!function_exists('roast_groq_budget_consume')) {
    function roast_groq_budget_consume(): void
    {
        $today = date('Y-m-d');

        try {
            $pdo = runtime_credits_pdo();
            runtime_credits_ensure_schema($pdo);
            $stmt = $pdo->prepare(
                'INSERT INTO cloud_usage_daily (usage_date, groq_calls) VALUES (?, 1)
                 ON DUPLICATE KEY UPDATE groq_calls = groq_calls + 1'
            );
            $stmt->execute([$today]);
            return;
        } catch (Throwable $e) {
            error_log('[OnlyBikes][groq_budget] DB consume failed: ' . $e->getMessage());
        }

        $path = roast_groq_budget_cache_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $count = roast_groq_budget_count($today) + 1;
        @file_put_contents($path, json_encode(['date' => $today, 'count' => $count]), LOCK_EX);
    }
}

if (!function_exists('roast_groq_budget_status')) {
    /** @return array<string, mixed> */
    function roast_groq_budget_status(): array
    {
        $max = defined('ROAST_GROQ_DAILY_MAX') ? (int) ROAST_GROQ_DAILY_MAX : 25;
        $today = date('Y-m-d');
        $used = roast_groq_budget_count($today);
        $skipVision = defined('ROAST_VISION_SKIP_GROQ') && ROAST_VISION_SKIP_GROQ;
        $skipJudge = defined('ROAST_JUDGE_SKIP_GROQ') && ROAST_JUDGE_SKIP_GROQ;

        return [
            'daily_max' => $max,
            'used_today' => $used,
            'remaining' => $max > 0 ? max(0, $max - $used) : null,
            'available' => roast_groq_budget_available(),
            'vision_skip_groq' => $skipVision,
            'judge_skip_groq' => $skipJudge,
        ];
    }
}
