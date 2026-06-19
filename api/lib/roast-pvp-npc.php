<?php
declare(strict_types=1);

require_once __DIR__ . '/roast-config.php';
require_once __DIR__ . '/roast-jobs.php';

if (!function_exists('roast_pvp_npc_require_pvp')) {
    function roast_pvp_npc_require_pvp(): void
    {
        require_once __DIR__ . '/roast-pvp.php';
    }
}

if (!function_exists('roast_pvp_npc_enabled')) {
    function roast_pvp_npc_enabled(): bool
    {
        return defined('ROAST_PVP_NPC_ENABLED') && ROAST_PVP_NPC_ENABLED;
    }
}

if (!function_exists('roast_pvp_npc_fallback_sec')) {
    function roast_pvp_npc_fallback_sec(): int
    {
        return defined('ROAST_PVP_NPC_FALLBACK_SEC') ? ROAST_PVP_NPC_FALLBACK_SEC : 10;
    }
}

if (!function_exists('roast_pvp_npc_grade_delay_sec')) {
    function roast_pvp_npc_grade_delay_sec(): int
    {
        $raw = defined('ROAST_PVP_NPC_GRADE_DELAY_SEC')
            ? ROAST_PVP_NPC_GRADE_DELAY_SEC
            : (int) roast_env('ROAST_PVP_NPC_GRADE_DELAY_SEC', '11');
        return max(10, min(12, $raw));
    }
}

if (!function_exists('roast_pvp_npc_htdocs_root')) {
    function roast_pvp_npc_htdocs_root(): string
    {
        return dirname(__DIR__, 2);
    }
}

if (!function_exists('roast_pvp_npc_resolve_asset')) {
    function roast_pvp_npc_resolve_asset(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return '';
        }
        $full = roast_pvp_npc_htdocs_root() . '/' . ltrim($path, '/');
        return is_readable($full) ? $full : '';
    }
}

if (!function_exists('roast_pvp_npc_manifest_paths')) {
    /** @return list<string> */
    function roast_pvp_npc_manifest_paths(): array
    {
        return [
            dirname(__DIR__) . '/data/pvp-opponents.json',
            dirname(__DIR__, 2) . '/api/data/pvp-opponents.json',
        ];
    }
}

if (!function_exists('roast_pvp_npc_manifest')) {
    /** @return array<string, mixed> */
    function roast_pvp_npc_manifest(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $cache = ['opponents' => []];
        foreach (roast_pvp_npc_manifest_paths() as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['opponents']) || !is_array($data['opponents'])) {
                continue;
            }
            $cache = $data;
            break;
        }

        return $cache;
    }
}

if (!function_exists('roast_pvp_npc_has_manifest_grade')) {
    /** @param array<string, mixed> $row */
    function roast_pvp_npc_has_manifest_grade(array $row): bool
    {
        $identity = is_array($row['identity'] ?? null) ? $row['identity'] : [];
        $inspect = is_array($row['inspect'] ?? null) ? $row['inspect'] : [];
        $hasIdentity = $identity !== [] && trim((string) ($identity['make'] ?? '')) !== '';
        $hasInspect = $inspect !== [] && isset($inspect['cleanliness_score']);

        return $hasIdentity || $hasInspect;
    }
}

if (!function_exists('roast_pvp_npc_normalize_entry')) {
    /** @param array<string, mixed> $row */
    function roast_pvp_npc_normalize_entry(array $row): ?array
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        if (isset($row['enabled']) && $row['enabled'] === false) {
            return null;
        }

        $video = trim((string) ($row['video'] ?? ''));
        $reference = trim((string) ($row['reference_image'] ?? ''));
        $videoOk = $video !== '' && roast_pvp_npc_resolve_asset($video) !== '';
        if (!$videoOk && !roast_pvp_npc_has_manifest_grade($row)) {
            return null;
        }

        $starting = (int) ($row['starting_score'] ?? 50);
        $starting = max(0, min(100, $starting));

        $identity = is_array($row['identity'] ?? null) ? $row['identity'] : [];
        $inspect = is_array($row['inspect'] ?? null) ? $row['inspect'] : [];

        return [
            'id' => $id,
            'video' => $videoOk ? $video : '',
            'reference_image' => $reference,
            'video_available' => $videoOk,
            'starting_score' => $starting,
            'identity' => $identity,
            'inspect' => $inspect,
        ];
    }
}

if (!function_exists('roast_pvp_npc_entry_for_grade')) {
    /** @return array<string, mixed>|null */
    function roast_pvp_npc_entry_for_grade(string $npcId): ?array
    {
        $npcId = trim($npcId);
        if ($npcId === '') {
            return null;
        }

        $entry = roast_pvp_npc_entry($npcId);
        if ($entry !== null) {
            return $entry;
        }

        foreach (roast_pvp_npc_manifest()['opponents'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['id'] ?? '')) !== $npcId) {
                continue;
            }
            if (isset($row['enabled']) && $row['enabled'] === false) {
                return null;
            }

            return [
                'id' => $npcId,
                'video' => trim((string) ($row['video'] ?? '')),
                'reference_image' => trim((string) ($row['reference_image'] ?? '')),
                'video_available' => false,
                'starting_score' => max(0, min(100, (int) ($row['starting_score'] ?? 50))),
                'identity' => is_array($row['identity'] ?? null) ? $row['identity'] : [],
                'inspect' => is_array($row['inspect'] ?? null) ? $row['inspect'] : [],
            ];
        }

        return null;
    }
}

if (!function_exists('roast_pvp_npc_opponents')) {
    /** @return list<array<string, mixed>> */
    function roast_pvp_npc_opponents(): array
    {
        $manifest = roast_pvp_npc_manifest();
        $out = [];
        foreach ($manifest['opponents'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entry = roast_pvp_npc_normalize_entry($row);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }
        return $out;
    }
}

if (!function_exists('roast_pvp_npc_entry')) {
    /** @return array<string, mixed>|null */
    function roast_pvp_npc_entry(string $npcId): ?array
    {
        $npcId = trim($npcId);
        if ($npcId === '') {
            return null;
        }
        foreach (roast_pvp_npc_opponents() as $entry) {
            if (($entry['id'] ?? '') === $npcId) {
                return $entry;
            }
        }
        return null;
    }
}

if (!function_exists('roast_pvp_npc_video_url')) {
    function roast_pvp_npc_video_url(string $npcId): string
    {
        $entry = roast_pvp_npc_entry($npcId);
        return $entry ? (string) ($entry['video'] ?? '') : '';
    }
}

if (!function_exists('roast_pvp_npc_player_context')) {
    /**
     * Best-effort player history for NPC matching.
     *
     * @return array{
     *   match_count:int,
     *   ledger_entries:int,
     *   avg_live_score:?int,
     *   recent_npc_ids:list<string>
     * }
     */
    function roast_pvp_npc_player_context(string $token): array
    {
        roast_pvp_npc_require_pvp();

        $token = roast_pvp_normalize_token($token);
        $ctx = [
            'match_count' => 0,
            'ledger_entries' => 0,
            'avg_live_score' => null,
            'recent_npc_ids' => [],
        ];
        if ($token === '') {
            return $ctx;
        }

        $pdo = roast_pvp_pdo();
        if ($pdo) {
            try {
                $countStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM roast_pvp_matches
                     WHERE status = 'complete' AND (player_a_token = ? OR player_b_token = ?)"
                );
                $countStmt->execute([$token, $token]);
                $ctx['match_count'] = max(0, (int) $countStmt->fetchColumn());

                $histStmt = $pdo->prepare(
                    "SELECT player_a_token, player_b_token, player_a_live_best, player_b_live_best,
                            opponent_npc_id
                     FROM roast_pvp_matches
                     WHERE (player_a_token = ? OR player_b_token = ?)
                     ORDER BY created_at DESC
                     LIMIT 8"
                );
                $histStmt->execute([$token, $token]);
                $scores = [];
                while ($row = $histStmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $side = roast_pvp_normalize_token((string) ($row['player_a_token'] ?? '')) === $token ? 'a' : 'b';
                    $npcId = trim((string) ($row['opponent_npc_id'] ?? ''));
                    if ($npcId !== '' && count($ctx['recent_npc_ids']) < 3) {
                        $ctx['recent_npc_ids'][] = $npcId;
                    }
                    $liveBest = (int) ($row["player_{$side}_live_best"] ?? 0);
                    if ($liveBest > 0) {
                        $scores[] = $liveBest;
                    }
                }
                if ($scores !== []) {
                    $ctx['avg_live_score'] = (int) round(array_sum($scores) / count($scores));
                }
            } catch (Throwable $e) {
                error_log('[Roast PvP NPC] player_context failed: ' . $e->getMessage());
            }
        }

        if (!function_exists('runtime_credits_customer_id_from_session')) {
            require_once __DIR__ . '/runtime-credits.php';
        }
        $customerId = runtime_credits_customer_id_from_session();
        if ($customerId !== null && $customerId > 0) {
            try {
                $cpdo = runtime_credits_pdo();
                runtime_credits_ensure_schema($cpdo);
                $ledgerStmt = $cpdo->prepare(
                    "SELECT COUNT(*) FROM runtime_ledger
                     WHERE customer_id = ? AND type = 'pvp_spend'"
                );
                $ledgerStmt->execute([$customerId]);
                $ctx['ledger_entries'] = max(0, (int) $ledgerStmt->fetchColumn());
            } catch (Throwable $e) {
                error_log('[Roast PvP NPC] ledger context failed: ' . $e->getMessage());
            }
        }

        return $ctx;
    }
}

if (!function_exists('roast_pvp_npc_weighted_choose')) {
    /**
     * @param list<array<string, mixed>> $pool
     * @param list<float> $weights
     * @return array<string, mixed>
     */
    function roast_pvp_npc_weighted_choose(array $pool, array $weights): array
    {
        $total = 0.0;
        foreach ($weights as $w) {
            $total += max(0.0, (float) $w);
        }
        if ($total <= 0.0) {
            return $pool[array_rand($pool)];
        }

        $roll = (mt_rand() / mt_getrandmax()) * $total;
        foreach ($pool as $i => $entry) {
            $roll -= max(0.0, (float) ($weights[$i] ?? 0));
            if ($roll <= 0.0) {
                return $entry;
            }
        }

        return $pool[array_key_last($pool)];
    }
}

if (!function_exists('roast_pvp_npc_pick_weights')) {
    /**
     * Compute per-opponent draw weights from player context.
     *
     * Shame PvP: higher score wins. New players face high-cred NPCs (manifest
     * starting_score) for a winnable first duel. Veterans get spread + jitter.
     *
     * Signals (all optional):
     *   - Finished PvP match count for this queue token
     *   - Lifetime runtime_ledger pvp_spend rows (signed-in customers)
     *   - Average player_a_live_best from recent NPC duels
     *
     * newbie_factor = clamp(1 - (match_count + ledger_entries) / 6, 0, 1), boosted
     * when avg_live_score < 45. When newbie_factor >= 0.55, weight ∝ starting_score².
     * Otherwise near-uniform with Gaussian spread around historical avg score.
     *
     * @param list<array<string, mixed>> $pickFrom
     * @return list<float>
     */
    function roast_pvp_npc_pick_weights(array $pickFrom, array $ctx): array
    {
        $experience = (int) ($ctx['match_count'] ?? 0) + (int) ($ctx['ledger_entries'] ?? 0);
        $newbieFactor = max(0.0, min(1.0, 1.0 - ($experience / 6.0)));

        $avgScore = $ctx['avg_live_score'] ?? null;
        if ($avgScore !== null && $avgScore < 45) {
            $newbieFactor = min(1.0, $newbieFactor + 0.25);
        }

        $targetScore = $avgScore ?? 55;
        $weights = [];

        foreach ($pickFrom as $i => $entry) {
            $cred = max(0, min(100, (int) ($entry['starting_score'] ?? 50)));

            if ($newbieFactor >= 0.55) {
                $norm = $cred / 100.0;
                $weight = 0.05 + ($norm * $norm) * (0.5 + $newbieFactor);
            } else {
                $weight = 1.0;
                $delta = $cred - $targetScore;
                $weight *= 0.55 + 0.45 * exp(-($delta * $delta) / 350.0);
                $weight += (mt_rand(0, 1000) / 1000.0) * 0.15;
            }

            $weights[$i] = max(0.01, $weight);
        }

        return $weights;
    }
}

if (!function_exists('roast_pvp_npc_bag_cache_dir')) {
    function roast_pvp_npc_bag_cache_dir(): string
    {
        $base = defined('ROAST_TMP_DIR')
            ? ROAST_TMP_DIR
            : (dirname(__DIR__) . '/cache/roast-tmp');
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'pvp-npc-bag';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }
}

if (!function_exists('roast_pvp_npc_bag_scope_key')) {
    /** Per signed-in customer, else per PvP session token; otherwise global. */
    function roast_pvp_npc_bag_scope_key(string $token = '', ?int $customerId = null): string
    {
        if ($customerId !== null && $customerId > 0) {
            return 'cust_' . $customerId;
        }
        $token = trim($token);
        if ($token !== '') {
            return 'user_' . preg_replace('/[^a-f0-9]/', '', strtolower($token));
        }
        return 'global';
    }
}

if (!function_exists('roast_pvp_npc_bag_cache_file')) {
    function roast_pvp_npc_bag_cache_file(string $scopeKey): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '_', $scopeKey) ?? 'global';
        return roast_pvp_npc_bag_cache_dir() . DIRECTORY_SEPARATOR . $safe . '.json';
    }
}

if (!function_exists('roast_pvp_npc_bag_pool_fingerprint')) {
    /** @param list<string> $poolIds */
    function roast_pvp_npc_bag_pool_fingerprint(array $poolIds): string
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $poolIds))));
        sort($ids, SORT_STRING);
        return hash('sha256', implode("\0", $ids));
    }
}

if (!function_exists('roast_pvp_npc_bag_shuffle')) {
    /** @param list<string> $ids */
    function roast_pvp_npc_bag_shuffle(array $ids): array
    {
        $deck = array_values($ids);
        $count = count($deck);
        for ($i = $count - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            if ($j !== $i) {
                [$deck[$i], $deck[$j]] = [$deck[$j], $deck[$i]];
            }
        }
        return $deck;
    }
}

if (!function_exists('roast_pvp_npc_bag_read_state')) {
    /** @return array{deck: list<string>, pool_fp: string}|null */
    function roast_pvp_npc_bag_read_state(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['deck'] ?? null)) {
            return null;
        }
        $deck = [];
        foreach ($data['deck'] as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $deck[] = $id;
            }
        }
        return [
            'deck' => $deck,
            'pool_fp' => trim((string) ($data['pool_fp'] ?? '')),
        ];
    }
}

if (!function_exists('roast_pvp_npc_bag_write_state')) {
    /** @param list<string> $deck */
    function roast_pvp_npc_bag_write_state(string $path, array $deck, string $poolFp): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $payload = json_encode([
            'pool_fp' => $poolFp,
            'deck' => array_values($deck),
            'updated_at' => time(),
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }
        return @file_put_contents($path, $payload, LOCK_EX) !== false;
    }
}

if (!function_exists('roast_pvp_npc_bag_load_db')) {
    /** @return array{deck: list<string>, pool_fp: string}|null */
    function roast_pvp_npc_bag_load_db(string $scopeKey): ?array
    {
        roast_pvp_npc_require_pvp();
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return null;
        }
        roast_pvp_ensure_schema($pdo);
        try {
            $stmt = $pdo->prepare(
                'SELECT pool_fp, deck_json FROM roast_pvp_npc_bag WHERE scope_key = ? LIMIT 1'
            );
            $stmt->execute([$scopeKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            $deck = json_decode((string) ($row['deck_json'] ?? '[]'), true);
            if (!is_array($deck)) {
                return null;
            }
            $out = [];
            foreach ($deck as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $out[] = $id;
                }
            }
            return [
                'deck' => $out,
                'pool_fp' => trim((string) ($row['pool_fp'] ?? '')),
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('roast_pvp_npc_bag_save_db')) {
    /** @param list<string> $deck */
    function roast_pvp_npc_bag_save_db(string $scopeKey, array $deck, string $poolFp): bool
    {
        roast_pvp_npc_require_pvp();
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return false;
        }
        roast_pvp_ensure_schema($pdo);
        try {
            $json = json_encode(array_values($deck), JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return false;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO roast_pvp_npc_bag (scope_key, pool_fp, deck_json, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE pool_fp = VALUES(pool_fp), deck_json = VALUES(deck_json), updated_at = NOW()'
            );
            return $stmt->execute([$scopeKey, $poolFp, $json]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('roast_pvp_npc_bag_draw_id')) {
    /**
     * Draw next NPC id from shuffle bag (no repeat until cycle completes).
     * @param list<string> $poolIds
     */
    function roast_pvp_npc_bag_draw_id(string $scopeKey, array $poolIds): string
    {
        $poolIds = array_values(array_unique(array_filter(array_map(
            static fn($id): string => trim((string) $id),
            $poolIds
        ), static fn(string $id): bool => $id !== '')));
        if ($poolIds === []) {
            return '';
        }

        $poolFp = roast_pvp_npc_bag_pool_fingerprint($poolIds);
        $path = roast_pvp_npc_bag_cache_file($scopeKey);
        $lockPath = $path . '.lock';
        $lockFp = @fopen($lockPath, 'c+');
        if ($lockFp !== false) {
            flock($lockFp, LOCK_EX);
        }

        try {
            $state = roast_pvp_npc_bag_load_db($scopeKey);
            if ($state === null) {
                $state = roast_pvp_npc_bag_read_state($path);
            }

            $valid = array_fill_keys($poolIds, true);
            $deck = [];
            if ($state !== null && ($state['pool_fp'] ?? '') === $poolFp) {
                foreach ($state['deck'] as $id) {
                    if (isset($valid[$id])) {
                        $deck[] = $id;
                    }
                }
            }

            if ($deck === []) {
                $deck = roast_pvp_npc_bag_shuffle($poolIds);
            }

            $chosen = array_shift($deck);
            if ($chosen === null || $chosen === '') {
                $deck = roast_pvp_npc_bag_shuffle($poolIds);
                $chosen = array_shift($deck) ?? $poolIds[0];
            }

            roast_pvp_npc_bag_save_db($scopeKey, $deck, $poolFp);
            roast_pvp_npc_bag_write_state($path, $deck, $poolFp);

            return $chosen;
        } finally {
            if ($lockFp !== false) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
        }
    }
}

if (!function_exists('roast_pvp_npc_pick')) {
    /**
     * Smart NPC opponent selection.
     *
     * Algorithm:
     *   1. Build video pool from manifest (starting_score per opponent).
     *   2. Load player context: finished match count, runtime pvp_spend ledger rows,
     *      and average live shame score from recent duels.
     *   3. Compute newbie_factor = clamp(1 - experience/6, 0, 1), boosted when
     *      avg_live_score < 45 (low-skill signal).
     *   4. Newbie (factor >= 0.55): weighted draw with weight ∝ starting_score² so
     *      high-cred bikes dominate — easier first win in shame PvP (higher score wins).
     *   5. Veteran: per-user shuffle bag for full-roster variety without repeats until
     *      the cycle completes; bag miss falls back to Gaussian-spread weighted draw.
     *
     * @return array<string, mixed>|null
     */
    function roast_pvp_npc_pick(string $token = '', ?int $customerId = null): ?array
    {
        $pool = roast_pvp_npc_opponents();
        if ($pool === []) {
            return null;
        }

        roast_pvp_npc_require_pvp();
        $token = roast_pvp_normalize_token($token);
        if ($customerId === null) {
            $customerId = roast_pvp_npc_customer_id();
        }

        $withVideo = array_values(array_filter(
            $pool,
            static fn(array $entry): bool => !empty($entry['video_available'])
        ));
        $pickFrom = $withVideo !== [] ? $withVideo : $pool;

        $byId = [];
        foreach ($pickFrom as $entry) {
            $id = trim((string) ($entry['id'] ?? ''));
            if ($id !== '') {
                $byId[$id] = $entry;
            }
        }
        if ($byId === []) {
            return null;
        }

        $ctx = $token !== '' ? roast_pvp_npc_player_context($token) : [
            'match_count' => 0,
            'ledger_entries' => 0,
            'avg_live_score' => null,
            'recent_npc_ids' => [],
        ];

        $experience = (int) ($ctx['match_count'] ?? 0) + (int) ($ctx['ledger_entries'] ?? 0);
        $newbieFactor = max(0.0, min(1.0, 1.0 - ($experience / 6.0)));
        $avgScore = $ctx['avg_live_score'] ?? null;
        if ($avgScore !== null && $avgScore < 45) {
            $newbieFactor = min(1.0, $newbieFactor + 0.25);
        }

        if ($newbieFactor >= 0.55) {
            $weights = roast_pvp_npc_pick_weights($pickFrom, $ctx);
            return roast_pvp_npc_weighted_choose($pickFrom, $weights);
        }

        $scopeKey = roast_pvp_npc_bag_scope_key($token, $customerId);
        $chosenId = roast_pvp_npc_bag_draw_id($scopeKey, array_keys($byId));
        if ($chosenId !== '' && isset($byId[$chosenId])) {
            return $byId[$chosenId];
        }

        $weights = roast_pvp_npc_pick_weights($pickFrom, $ctx);
        return roast_pvp_npc_weighted_choose($pickFrom, $weights);
    }
}

if (!function_exists('roast_pvp_npc_customer_id')) {
    function roast_pvp_npc_customer_id(): ?int
    {
        if (!function_exists('runtime_credits_customer_id_from_session')) {
            require_once __DIR__ . '/runtime-credits.php';
        }
        if (!function_exists('runtime_credits_customer_id_from_session')) {
            return null;
        }
        return runtime_credits_customer_id_from_session();
    }
}

if (!function_exists('roast_pvp_npc_token')) {
    /** 64-char hex — survives roast_pvp_normalize_token (no npc- prefix). */
    function roast_pvp_npc_token(string $matchId): string
    {
        return hash('sha256', 'roast_pvp_npc:' . $matchId);
    }
}

if (!function_exists('roast_pvp_npc_is_token')) {
    function roast_pvp_npc_is_token(string $token, ?string $matchId = null): bool
    {
        if ($matchId !== null && $matchId !== '') {
            return hash_equals(roast_pvp_npc_token($matchId), roast_pvp_normalize_token($token));
        }
        $token = roast_pvp_normalize_token($token);
        return strlen($token) === 64 && ctype_xdigit($token);
    }
}

if (!function_exists('roast_pvp_npc_starting_score')) {
    function roast_pvp_npc_starting_score(string $npcId): ?int
    {
        $npcId = trim($npcId);
        if ($npcId === '') {
            return null;
        }
        $entry = roast_pvp_npc_entry_for_grade($npcId);
        if ($entry === null) {
            return null;
        }

        return max(0, min(100, (int) ($entry['starting_score'] ?? 50)));
    }
}

if (!function_exists('roast_pvp_npc_live_job_inspect')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_npc_live_job_inspect(array $match, string $side): array
    {
        $jobId = trim((string) ($match["player_{$side}_live_job_id"] ?? ''));
        if ($jobId === '') {
            return [];
        }
        $job = roast_jobs_get($jobId);
        if (!$job) {
            return [];
        }

        return json_decode((string) ($job['inspect_json'] ?? '{}'), true) ?: [];
    }
}

if (!function_exists('roast_pvp_npc_side_grade_pending')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_npc_side_grade_pending(array $match, string $side): bool
    {
        $inspect = roast_pvp_npc_live_job_inspect($match, $side);

        return !empty($inspect['grade_pending']);
    }
}

if (!function_exists('roast_pvp_npc_effective_score')) {
    /** @param array<string, mixed> $entry */
    function roast_pvp_npc_effective_score(array $entry, int $gradedScore): int
    {
        $starting = max(0, min(100, (int) ($entry['starting_score'] ?? 50)));
        if ($gradedScore <= 0) {
            return $starting;
        }

        return max($starting, min(100, $gradedScore));
    }
}

if (!function_exists('roast_pvp_npc_opponent_display_score')) {
    /**
     * NPC opponent score for live UI: manifest starting_score until AI grade lands.
     *
     * @param array<string, mixed> $match
     */
    function roast_pvp_npc_opponent_display_score(array $match, string $oppSide): ?int
    {
        $npcId = trim((string) ($match['opponent_npc_id'] ?? ''));
        if ($npcId === '') {
            return roast_pvp_player_live_display_score($match, $oppSide);
        }

        $starting = roast_pvp_npc_starting_score($npcId);
        $count = roast_pvp_live_frame_count($match, $oppSide);
        $pending = roast_pvp_npc_side_grade_pending($match, $oppSide);

        if ($count > 0 && !$pending) {
            $avg = roast_pvp_live_average($match, $oppSide);
            if ($avg !== null && $avg > 0) {
                return $avg;
            }
        }

        if ($starting !== null) {
            return $starting;
        }

        $jobId = trim((string) ($match["player_{$oppSide}_live_job_id"] ?? ''));
        if ($jobId !== '') {
            $job = roast_jobs_get($jobId);
            if ($job && isset($job['score'])) {
                $jobScore = (int) $job['score'];
                if ($jobScore > 0) {
                    return $jobScore;
                }
            }
        }

        return $count > 0 ? roast_pvp_live_average($match, $oppSide) : null;
    }
}

if (!function_exists('roast_pvp_npc_create_match')) {
    /** @return array<string, mixed>|null */
    function roast_pvp_npc_create_match(string $token, string $faceHash): ?array
    {
        if (!roast_pvp_npc_enabled()) {
            return null;
        }

        roast_pvp_npc_require_pvp();

        $token = roast_pvp_normalize_token($token);
        $faceHash = roast_pvp_normalize_face_hash($faceHash);
        if ($token === '' || $faceHash === '') {
            return null;
        }

        $opponent = roast_pvp_npc_pick($token);
        if ($opponent === null) {
            return null;
        }

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return null;
        }

        try {
            $pdo->beginTransaction();

            $waiter = $pdo->prepare(
                "SELECT queue_token FROM roast_pvp_queue
                 WHERE status = 'waiting' AND queue_token <> ?
                 ORDER BY created_at ASC LIMIT 1 FOR UPDATE"
            );
            $waiter->execute([$token]);
            if ($waiter->fetchColumn()) {
                $pdo->rollBack();
                return null;
            }

            $self = $pdo->prepare(
                "SELECT status, created_at FROM roast_pvp_queue
                 WHERE queue_token = ? AND status = 'waiting' LIMIT 1 FOR UPDATE"
            );
            $self->execute([$token]);
            $selfRow = $self->fetch(PDO::FETCH_ASSOC);
            if (!is_array($selfRow) || ($selfRow['status'] ?? '') !== 'waiting') {
                $pdo->rollBack();
                return null;
            }
            $createdAt = strtotime((string) ($selfRow['created_at'] ?? ''));
            if ($createdAt === false || (time() - $createdAt) < roast_pvp_npc_fallback_sec()) {
                $pdo->rollBack();
                return null;
            }

            $existing = roast_pvp_get_by_token($token);
            if ($existing && in_array($existing['status'] ?? '', ['matched', 'active', 'dueling'], true)) {
                $pdo->rollBack();
                return $existing;
            }

            $matchId = roast_jobs_new_id();
            $npcToken = roast_pvp_npc_token($matchId);
            $startingScore = (int) ($opponent['starting_score'] ?? 50);
            $liveJobId = roast_jobs_new_id();
            $ipHash = roast_ip_hash();

            roast_jobs_create($liveJobId, $ipHash, 'npc-' . ($opponent['id'] ?? 'opponent'));

            $identity = is_array($opponent['identity'] ?? null) ? $opponent['identity'] : [];
            $inspect = is_array($opponent['inspect'] ?? null) ? $opponent['inspect'] : [];
            if ($inspect === []) {
                $inspect = [
                    'frame_visible' => true,
                    'visual_mods' => [],
                    'missing_parts' => [],
                    'condition_notes' => 'npc_pending',
                    'grade_pending' => true,
                ];
            } else {
                $inspect['grade_pending'] = true;
            }

            roast_jobs_update($liveJobId, [
                'status' => 'partial',
                'phase' => 'live',
                'identity_json' => $identity,
                'inspect_json' => $inspect,
                'score' => $startingScore,
            ]);

            $sec = ROAST_PVP_ROUND_SEC;
            $ins = $pdo->prepare(
                'INSERT INTO roast_pvp_matches
                 (match_id, player_a_token, player_b_token, player_a_face_hash,
                  player_a_verified_at, opponent_npc_id, player_b_mode,
                  player_b_live_sum, player_b_live_count, player_b_live_best,
                  player_b_live_job_id, player_b_live_at, round_started_at, status, expires_at)
                 VALUES (?, ?, ?, ?, NOW(), ?, ?, 0, 0, NULL, ?, NOW(), NOW(), ?, DATE_ADD(NOW(), INTERVAL ' . (int) $sec . ' SECOND))'
            );
            $ins->execute([
                $matchId,
                $token,
                $npcToken,
                $faceHash,
                (string) ($opponent['id'] ?? ''),
                'live',
                $liveJobId,
                'active',
            ]);

            $pdo->prepare(
                "UPDATE roast_pvp_queue SET status = 'matched', match_id = ? WHERE queue_token = ? AND status = 'waiting'"
            )->execute([$matchId, $token]);

            $pdo->prepare(
                'INSERT INTO roast_pvp_queue (queue_token, match_id, face_hash, status) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE match_id = VALUES(match_id), face_hash = VALUES(face_hash), status = VALUES(status)'
            )->execute([$token, $matchId, $faceHash, 'matched']);

            $pdo->commit();

            return roast_pvp_get_match($matchId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Roast PvP NPC] create_match failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('roast_pvp_npc_grade_identity_inspect')) {
    /**
     * @param array<string, mixed> $entry
     * @return array{identity: array<string, mixed>, inspect: array<string, mixed>, score: int}
     */
    function roast_pvp_npc_grade_identity_inspect(array $entry): array
    {
        require_once __DIR__ . '/roast-score.php';
        roast_pvp_npc_require_pvp();

        $identity = is_array($entry['identity'] ?? null) ? $entry['identity'] : [];
        $inspect = is_array($entry['inspect'] ?? null) ? $entry['inspect'] : [];
        $hasIdentity = $identity !== [] && trim((string) ($identity['make'] ?? '')) !== '';
        $hasInspect = $inspect !== [] && isset($inspect['cleanliness_score']);

        if ($hasIdentity && $hasInspect) {
            $inspect['grade_pending'] = false;
            $inspect['condition_notes'] = 'npc_manifest';
            $score = roast_compute_pvp_cred_score($identity, $inspect);

            return [
                'identity' => $identity,
                'inspect' => $inspect,
                'score' => $score,
                'tier1_latency_ms' => null,
                'vision_cache_hit' => false,
                'score_tier' => 0,
            ];
        }

        $imagePath = roast_pvp_npc_resolve_asset((string) ($entry['reference_image'] ?? ''));
        if ($imagePath === '') {
            if ($identity === []) {
                $identity = [
                    'make' => 'Unknown',
                    'model' => 'Unknown',
                    'confidence' => 0.2,
                    'visible_subject' => 'unclear',
                    'is_complete_ebike' => false,
                    'source' => 'npc_manifest',
                ];
            }
            if ($inspect === []) {
                $inspect = [
                    'frame_visible' => true,
                    'visual_mods' => [],
                    'missing_parts' => [],
                    'condition_notes' => 'npc_manifest',
                ];
            }
            $inspect['grade_pending'] = false;
            $inspect['condition_notes'] = 'npc_manifest';
            $manifestScore = roast_compute_pvp_cred_score($identity, $inspect);
            $score = ($hasIdentity || $hasInspect)
                ? $manifestScore
                : (int) ($entry['starting_score'] ?? $manifestScore);

            return [
                'identity' => $identity,
                'inspect' => $inspect,
                'score' => $score,
                'tier1_latency_ms' => null,
                'vision_cache_hit' => false,
                'score_tier' => 0,
            ];
        }

        $tier1LatencyMs = null;
        $visionCacheHit = false;
        $scoreTier = 1;

        if (!$hasIdentity) {
            $scored = roast_pvp_score_frame($imagePath);
            $identity = is_array($scored['identity'] ?? null) ? $scored['identity'] : [];
            $inspect = is_array($scored['inspect'] ?? null) ? $scored['inspect'] : [];
            $tier1LatencyMs = $scored['tier1_latency_ms'] ?? null;
            $visionCacheHit = !empty($scored['vision_cache_hit']);
            $scoreTier = (int) ($scored['score_tier'] ?? 1);
        } elseif (!$hasInspect) {
            require_once dirname(__DIR__) . '/roast-limited/agents/agent2-condition.php';
            try {
                $a2 = roast_agent2_condition($imagePath, $identity);
            } catch (Throwable $e) {
                error_log('[Roast PvP NPC] agent2 failed: ' . $e->getMessage());
                $a2 = ['ok' => false];
            }
            if (!empty($a2['ok']) && is_array($a2['data'] ?? null)) {
                $inspect = $a2['data'];
            } else {
                $inspect = [
                    'frame_visible' => ($identity['visible_subject'] ?? '') !== 'parts_only',
                    'visual_mods' => [],
                    'missing_parts' => [],
                    'condition_notes' => 'npc_grade',
                ];
            }
        }

        $inspect['grade_pending'] = false;
        $inspect['condition_notes'] = 'npc_graded';
        $score = roast_compute_pvp_cred_score($identity, $inspect);

        return [
            'identity' => $identity,
            'inspect' => $inspect,
            'score' => $score,
            'tier1_latency_ms' => $tier1LatencyMs,
            'vision_cache_hit' => $visionCacheHit,
            'score_tier' => $scoreTier,
        ];
    }
}

if (!function_exists('roast_pvp_npc_opponent_side')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_npc_opponent_side(array $match): string
    {
        $npcId = trim((string) ($match['opponent_npc_id'] ?? ''));
        if ($npcId === '') {
            return '';
        }
        $tokenB = roast_pvp_normalize_token((string) ($match['player_b_token'] ?? ''));
        if ($tokenB !== '' && roast_pvp_npc_is_token($tokenB, (string) ($match['match_id'] ?? ''))) {
            return 'b';
        }
        $tokenA = roast_pvp_normalize_token((string) ($match['player_a_token'] ?? ''));
        if ($tokenA !== '' && roast_pvp_npc_is_token($tokenA, (string) ($match['match_id'] ?? ''))) {
            return 'a';
        }
        return 'b';
    }
}

if (!function_exists('roast_pvp_npc_grade_if_due')) {
    function roast_pvp_npc_grade_if_due(string $matchId): bool
    {
        roast_pvp_npc_require_pvp();
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return false;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT * FROM roast_pvp_matches WHERE match_id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$matchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($match)) {
                $pdo->rollBack();
                return false;
            }

            $npcId = trim((string) ($match['opponent_npc_id'] ?? ''));
            if ($npcId === '') {
                $pdo->rollBack();
                return false;
            }

            $status = (string) ($match['status'] ?? '');
            if (!in_array($status, ['matched', 'active', 'dueling'], true)) {
                $pdo->rollBack();
                return false;
            }

            $oppSide = roast_pvp_npc_opponent_side($match);
            if ($oppSide === '') {
                $pdo->rollBack();
                return false;
            }

            $jobId = trim((string) ($match["player_{$oppSide}_live_job_id"] ?? ''));
            if ($jobId === '') {
                $pdo->rollBack();
                return false;
            }

            $job = roast_jobs_get($jobId);
            if (!$job) {
                $pdo->rollBack();
                return false;
            }

            $inspect = json_decode((string) ($job['inspect_json'] ?? '{}'), true) ?: [];
            if (empty($inspect['grade_pending'])) {
                $pdo->rollBack();
                return false;
            }

            $roundStarted = $match['round_started_at'] ?? null;
            $gradeAt = false;
            if ($roundStarted !== null && $roundStarted !== '') {
                $gradeAt = strtotime((string) $roundStarted);
            } else {
                $anchor = $match['player_b_live_at'] ?? $match['created_at'] ?? null;
                if ($anchor !== null && $anchor !== '') {
                    $gradeAt = strtotime((string) $anchor);
                }
            }
            if ($gradeAt === false || time() - $gradeAt < roast_pvp_npc_grade_delay_sec()) {
                $pdo->rollBack();
                return false;
            }

            $entry = roast_pvp_npc_entry_for_grade($npcId);
            if ($entry === null) {
                $pdo->rollBack();
                return false;
            }

            $graded = roast_pvp_npc_grade_identity_inspect($entry);
            $score = roast_pvp_npc_effective_score($entry, (int) ($graded['score'] ?? 0));
            $gradedInspect = $graded['inspect'];
            $gradedInspect['grade_pending'] = false;

            roast_jobs_update($jobId, [
                'status' => 'partial',
                'phase' => 'live',
                'identity_json' => $graded['identity'],
                'inspect_json' => $gradedInspect,
                'score' => $score,
            ]);

            $sumCol = "player_{$oppSide}_live_sum";
            $countCol = "player_{$oppSide}_live_count";
            $bestCol = "player_{$oppSide}_live_best";
            $pdo->prepare(
                "UPDATE roast_pvp_matches
                 SET {$sumCol} = ?, {$countCol} = 1, {$bestCol} = ?
                 WHERE match_id = ?"
            )->execute([$score, $score, $matchId]);

            $pdo->commit();
            roast_log_pvp_metric('npc_grade', array_merge([
                'match_id' => $matchId,
                'npc_id' => $npcId,
                'side' => $oppSide,
                'score' => $score,
                'tier1_latency_ms' => $graded['tier1_latency_ms'] ?? null,
                'vision_cache_hit' => !empty($graded['vision_cache_hit']),
                'score_tier' => $graded['score_tier'] ?? 0,
            ]));
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Roast PvP NPC] grade_if_due failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('roast_pvp_npc_finalize_live_job')) {
    function roast_pvp_npc_finalize_live_job(string $matchId, string $jobId): void
    {
        if ($jobId === '') {
            return;
        }

        roast_pvp_npc_grade_if_due($matchId);
        $match = roast_pvp_get_match($matchId);
        if (!$match) {
            return;
        }

        $npcId = trim((string) ($match['opponent_npc_id'] ?? ''));
        $oppSide = $npcId !== '' ? roast_pvp_npc_opponent_side($match) : 'b';
        $avg = roast_pvp_npc_opponent_display_score($match, $oppSide)
            ?? ($npcId !== '' ? roast_pvp_npc_starting_score($npcId) : null)
            ?? roast_pvp_live_average($match, $oppSide)
            ?? 0;
        roast_jobs_update($jobId, ['score' => $avg]);

        $jobRow = roast_jobs_get($jobId);
        $identity = json_decode((string) ($jobRow['identity_json'] ?? '{}'), true) ?: [];
        $inspect = json_decode((string) ($jobRow['inspect_json'] ?? '{}'), true) ?: [];
        $entry = $npcId !== '' ? roast_pvp_npc_entry_for_grade($npcId) : null;
        if ($entry) {
            if ($identity === [] && is_array($entry['identity'] ?? null)) {
                $identity = $entry['identity'];
            }
            if (($inspect === [] || !empty($inspect['grade_pending']))
                && is_array($entry['inspect'] ?? null) && $entry['inspect'] !== []) {
                $inspect = $entry['inspect'];
            }
        }
        if ($identity === [] || ($inspect === [] && $entry)) {
            $graded = roast_pvp_npc_grade_identity_inspect($entry ?: ['starting_score' => $avg]);
            $identity = $graded['identity'];
            $inspect = $graded['inspect'];
            $avg = (int) ($graded['score'] ?? $avg);
        }
        roast_pvp_complete_npc_live_job($jobId, $avg, $identity, $inspect);
    }
}

if (!function_exists('roast_pvp_complete_npc_live_job')) {
    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     * @return array<string, mixed>|null
     */
    function roast_pvp_complete_npc_live_job(string $jobId, int $avgScore, array $identity, array $inspect): ?array
    {
        if ($jobId === '') {
            return null;
        }

        $inspect['grade_pending'] = false;
        $roastText = roast_pvp_live_end_roast($identity, $inspect, $avgScore);

        roast_jobs_update($jobId, [
            'status' => 'complete',
            'phase' => 'done',
            'roast_text' => $roastText,
            'score' => $avgScore,
            'identity_json' => $identity,
            'inspect_json' => $inspect,
        ]);

        return roast_pvp_job_snapshot($jobId);
    }
}
