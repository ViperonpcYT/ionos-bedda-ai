<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime-credits-schema.php';
require_once __DIR__ . '/points-ledger.php';

if (!function_exists('runtime_credits_define_constants')) {
    function runtime_credits_define_constants(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $env = static function (string $key, string $default = ''): string {
            if (function_exists('roast_env')) {
                $v = trim(roast_env($key, ''));
                if ($v !== '') {
                    return $v;
                }
            }
            if (function_exists('onlybikes_env')) {
                $v = trim(onlybikes_env($key, ''));
                if ($v !== '') {
                    return $v;
                }
            }
            return $default;
        };

        if (!defined('RUNTIME_SIGNUP_PVP')) {
            define('RUNTIME_SIGNUP_PVP', max(0, (int) $env('RUNTIME_SIGNUP_PVP', '2')));
        }
        if (!defined('RUNTIME_SIGNUP_SOLO')) {
            define('RUNTIME_SIGNUP_SOLO', max(0, (int) $env('RUNTIME_SIGNUP_SOLO', '1')));
        }
        if (!defined('RUNTIME_COST_PVP')) {
            define('RUNTIME_COST_PVP', max(1, (int) $env('RUNTIME_COST_PVP', '1')));
        }
        if (!defined('RUNTIME_COST_SOLO')) {
            define('RUNTIME_COST_SOLO', max(1, (int) $env('RUNTIME_COST_SOLO', '1')));
        }
        if (!defined('RUNTIME_ORDER_PVP_PER_10CAD')) {
            define('RUNTIME_ORDER_PVP_PER_10CAD', max(0, (int) $env('RUNTIME_ORDER_PVP_PER_10CAD', '5')));
        }
        if (!defined('RUNTIME_ORDER_SOLO_PER_10CAD')) {
            define('RUNTIME_ORDER_SOLO_PER_10CAD', max(0, (int) $env('RUNTIME_ORDER_SOLO_PER_10CAD', '2')));
        }
        if (!defined('RUNTIME_AD_MIN_VIEW_SOLO_SEC')) {
            define('RUNTIME_AD_MIN_VIEW_SOLO_SEC', max(5, (int) $env('RUNTIME_AD_MIN_VIEW_SOLO_SEC', '18')));
        }
        if (!defined('RUNTIME_AD_MIN_VIEW_PVP_SEC')) {
            define('RUNTIME_AD_MIN_VIEW_PVP_SEC', max(5, (int) $env('RUNTIME_AD_MIN_VIEW_PVP_SEC', '15')));
        }
        if (!defined('RUNTIME_AD_COOLDOWN_MIN')) {
            define('RUNTIME_AD_COOLDOWN_MIN', max(0, (int) $env('RUNTIME_AD_COOLDOWN_MIN', '0')));
        }
        if (!defined('RUNTIME_GUEST_SOLO_UNLOCKS_PER_IP_DAY')) {
            define('RUNTIME_GUEST_SOLO_UNLOCKS_PER_IP_DAY', max(0, (int) $env('RUNTIME_GUEST_SOLO_UNLOCKS_PER_IP_DAY', '0')));
        }
        if (!defined('RUNTIME_GUEST_PVP_UNLOCKS_PER_IP_DAY')) {
            define('RUNTIME_GUEST_PVP_UNLOCKS_PER_IP_DAY', max(0, (int) $env('RUNTIME_GUEST_PVP_UNLOCKS_PER_IP_DAY', '0')));
        }
        if (!defined('RUNTIME_SIGNED_IN_AD_CLAIMS_PER_DAY')) {
            define('RUNTIME_SIGNED_IN_AD_CLAIMS_PER_DAY', max(0, (int) $env('RUNTIME_SIGNED_IN_AD_CLAIMS_PER_DAY', '0')));
        }
        if (!defined('RUNTIME_AD_VIEWS_PER_SESSION_MAX')) {
            define('RUNTIME_AD_VIEWS_PER_SESSION_MAX', max(0, (int) $env('RUNTIME_AD_VIEWS_PER_SESSION_MAX', '0')));
        }
        if (!defined('RUNTIME_AD_TOKEN_TTL_MIN')) {
            define('RUNTIME_AD_TOKEN_TTL_MIN', max(1, (int) $env('RUNTIME_AD_TOKEN_TTL_MIN', '5')));
        }
        if (!defined('RUNTIME_AD_REWARD_PVP_MIN')) {
            define('RUNTIME_AD_REWARD_PVP_MIN', max(1, (int) $env('RUNTIME_AD_REWARD_PVP_MIN', '2')));
        }
        if (!defined('RUNTIME_AD_REWARD_PVP_MAX')) {
            define('RUNTIME_AD_REWARD_PVP_MAX', max(RUNTIME_AD_REWARD_PVP_MIN, (int) $env('RUNTIME_AD_REWARD_PVP_MAX', '5')));
        }
        if (!defined('RUNTIME_AD_REWARD_SOLO')) {
            define('RUNTIME_AD_REWARD_SOLO', max(1, (int) $env('RUNTIME_AD_REWARD_SOLO', '1')));
        }
    }
}

if (!function_exists('runtime_credits_pdo')) {
    function runtime_credits_pdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        if (function_exists('getRuntimeCreditsDatabase')) {
            $pdo = getRuntimeCreditsDatabase();
            return $pdo;
        }

        $host = function_exists('onlybikes_env') ? onlybikes_env('RUNTIME_CREDITS_DB_HOST', '') : '';
        if ($host === '') {
            if (!function_exists('getCustomersDatabase')) {
                throw new RuntimeException('Runtime credits DB not configured');
            }
            $pdo = getCustomersDatabase();
            return $pdo;
        }

        if (!function_exists('onlybikes_pdo')) {
            throw new RuntimeException('onlybikes_pdo() missing');
        }
        $pdo = onlybikes_pdo('RUNTIME_CREDITS');
        return $pdo;
    }
}

if (!function_exists('runtime_credits_uuid')) {
    function runtime_credits_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('runtime_credits_cookie_opts')) {
    /** @return array{path:string,domain:string,secure:bool,httponly:bool,samesite:string} */
    function runtime_credits_cookie_opts(): array
    {
        $secure = function_exists('beddaSessionCookieSecure') ? beddaSessionCookieSecure() : true;
        return [
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}

if (!function_exists('runtime_credits_set_cookie')) {
    function runtime_credits_set_cookie(string $name, string $value, int $ttlSec): void
    {
        $opts = runtime_credits_cookie_opts();
        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, [
                'expires' => time() + $ttlSec,
                'path' => $opts['path'],
                'domain' => $opts['domain'],
                'secure' => $opts['secure'],
                'httponly' => $opts['httponly'],
                'samesite' => $opts['samesite'],
            ]);
        } else {
            setcookie($name, $value, time() + $ttlSec, $opts['path'], $opts['domain'], $opts['secure'], $opts['httponly']);
        }
        $_COOKIE[$name] = $value;
    }
}

if (!function_exists('runtime_credits_ensure_session_ids')) {
    /**
     * @return array{guest_id:string,session_id:string}
     */
    function runtime_credits_ensure_session_ids(): array
    {
        $guestId = trim((string) ($_COOKIE['ob_roast_guest'] ?? ''));
        if ($guestId === '' || !preg_match('/^[0-9a-f-]{36}$/i', $guestId)) {
            $guestId = runtime_credits_uuid();
            runtime_credits_set_cookie('ob_roast_guest', $guestId, 86400 * 30);
        }

        $sessionId = trim((string) ($_COOKIE['ob_roast_session'] ?? ''));
        if ($sessionId === '' || !preg_match('/^[0-9a-f-]{36}$/i', $sessionId)) {
            $sessionId = runtime_credits_uuid();
            runtime_credits_set_cookie('ob_roast_session', $sessionId, 86400);
        }

        return ['guest_id' => $guestId, 'session_id' => $sessionId];
    }
}

if (!function_exists('runtime_credits_get_balance')) {
    /** @return array{pvp_credits:int,solo_credits:int} */
    function runtime_credits_get_balance(PDO $pdo, int $customerId): array
    {
        runtime_credits_define_constants();
        runtime_credits_ensure_schema($pdo);

        $stmt = $pdo->prepare('SELECT pvp_credits, solo_credits FROM runtime_balances WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['pvp_credits' => 0, 'solo_credits' => 0];
        }

        return [
            'pvp_credits' => max(0, (int) $row['pvp_credits']),
            'solo_credits' => max(0, (int) $row['solo_credits']),
        ];
    }
}

if (!function_exists('runtime_credits_ensure_balance_row')) {
    function runtime_credits_ensure_balance_row(PDO $pdo, int $customerId): void
    {
        runtime_credits_ensure_schema($pdo);
        $stmt = $pdo->prepare('INSERT IGNORE INTO runtime_balances (customer_id, pvp_credits, solo_credits) VALUES (?, 0, 0)');
        $stmt->execute([$customerId]);
    }
}

if (!function_exists('runtime_credits_apply_delta')) {
    /**
     * @param array<string, mixed> $meta
     * @return array{ok:bool,pvp_credits:int,solo_credits:int}
     */
    function runtime_credits_apply_delta(
        PDO $pdo,
        int $customerId,
        int $deltaPvp,
        int $deltaSolo,
        string $type,
        array $meta = []
    ): array {
        runtime_credits_define_constants();
        runtime_credits_ensure_schema($pdo);

        $allowed = [
            'signup_bonus',
            'order_bonus',
            'ad_reward',
            'solo_spend',
            'pvp_spend',
            'refund_failed_job',
            'admin_adjust',
        ];
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Invalid runtime ledger type');
        }

        if ($deltaPvp === 0 && $deltaSolo === 0) {
            $bal = runtime_credits_get_balance($pdo, $customerId);
            return ['ok' => true, 'pvp_credits' => $bal['pvp_credits'], 'solo_credits' => $bal['solo_credits']];
        }

        $pdo->beginTransaction();
        try {
            runtime_credits_ensure_balance_row($pdo, $customerId);
            $lock = $pdo->prepare('SELECT pvp_credits, solo_credits FROM runtime_balances WHERE customer_id = ? FOR UPDATE');
            $lock->execute([$customerId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Runtime balance row missing');
            }

            $pvp = max(0, (int) $row['pvp_credits']);
            $solo = max(0, (int) $row['solo_credits']);
            $newPvp = $pvp + $deltaPvp;
            $newSolo = $solo + $deltaSolo;
            if ($newPvp < 0 || $newSolo < 0) {
                throw new RuntimeException('Insufficient runtime credits');
            }

            $upd = $pdo->prepare('UPDATE runtime_balances SET pvp_credits = ?, solo_credits = ? WHERE customer_id = ?');
            $upd->execute([$newPvp, $newSolo, $customerId]);

            $metaJson = !empty($meta['meta']) && is_array($meta['meta'])
                ? json_encode($meta['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            $ins = $pdo->prepare(
                'INSERT INTO runtime_ledger
                 (customer_id, delta_pvp, delta_solo, balance_pvp_after, balance_solo_after, type,
                  reference_type, reference_id, actor, note, meta_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $customerId,
                $deltaPvp,
                $deltaSolo,
                $newPvp,
                $newSolo,
                $type,
                $meta['reference_type'] ?? null,
                isset($meta['reference_id']) ? (string) $meta['reference_id'] : null,
                $meta['actor'] ?? 'system',
                $meta['note'] ?? null,
                $metaJson,
            ]);

            $pdo->commit();
            error_log("[OnlyBikes][runtime_credits] customer={$customerId} type={$type} d_pvp={$deltaPvp} d_solo={$deltaSolo}");

            return ['ok' => true, 'pvp_credits' => $newPvp, 'solo_credits' => $newSolo];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('runtime_credits_signup_bonus')) {
    function runtime_credits_signup_bonus(int $customerId): array
    {
        try {
            runtime_credits_define_constants();
            $pdo = runtime_credits_pdo();
            runtime_credits_ensure_schema($pdo);

            $chk = $pdo->prepare("SELECT id FROM runtime_ledger WHERE customer_id = ? AND type = 'signup_bonus' LIMIT 1");
            $chk->execute([$customerId]);
            if ($chk->fetch()) {
                return ['ok' => true, 'already_granted' => true] + runtime_credits_get_balance($pdo, $customerId);
            }

            return runtime_credits_apply_delta(
                $pdo,
                $customerId,
                RUNTIME_SIGNUP_PVP,
                RUNTIME_SIGNUP_SOLO,
                'signup_bonus',
                ['note' => 'Welcome roast credits']
            );
        } catch (Throwable $e) {
            error_log('[OnlyBikes][runtime_credits] signup_bonus: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('runtime_credits_order_bonus')) {
    function runtime_credits_order_bonus(int $customerId, float $subtotalCad, string $orderNumber): array
    {
        try {
            runtime_credits_define_constants();
            $pdo = runtime_credits_pdo();
            runtime_credits_ensure_schema($pdo);

            $units = max(0, (int) floor($subtotalCad / 10));
            $pvp = $units * RUNTIME_ORDER_PVP_PER_10CAD;
            $solo = $units * RUNTIME_ORDER_SOLO_PER_10CAD;
            if ($pvp === 0 && $solo === 0) {
                return ['ok' => true, 'granted' => false] + runtime_credits_get_balance($pdo, $customerId);
            }

            return runtime_credits_apply_delta(
                $pdo,
                $customerId,
                $pvp,
                $solo,
                'order_bonus',
                [
                    'reference_type' => 'order',
                    'reference_id' => $orderNumber,
                    'note' => 'Purchase roast credit bonus',
                    'meta' => ['subtotal_cad' => $subtotalCad],
                ]
            ) + ['granted' => true];
        } catch (Throwable $e) {
            error_log('[OnlyBikes][runtime_credits] order_bonus: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('runtime_credits_count_claims')) {
    function runtime_credits_count_claims(
        PDO $pdo,
        string $whereSql,
        array $params
    ): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ad_claim_log WHERE {$whereSql}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('runtime_credits_last_cooldown_at')) {
    /** Last time an ad actually granted play (used unlock or signed-in bonus). */
    function runtime_credits_last_cooldown_at(PDO $pdo, ?int $customerId, ?string $guestId): ?string
    {
        $latest = null;
        if ($customerId) {
            $stmt = $pdo->prepare(
                'SELECT claimed_at AS t FROM ad_claim_log
                 WHERE claim_type = ? AND customer_id = ?
                 ORDER BY claimed_at DESC LIMIT 1'
            );
            $stmt->execute(['bonus', $customerId]);
            $bonus = $stmt->fetchColumn();
            if ($bonus !== false) {
                $latest = (string) $bonus;
            }
            $stmt = $pdo->prepare(
                'SELECT used_at AS t FROM ad_unlock_tokens
                 WHERE customer_id = ? AND used_at IS NOT NULL
                 ORDER BY used_at DESC LIMIT 1'
            );
            $stmt->execute([$customerId]);
            $used = $stmt->fetchColumn();
            if ($used !== false && ($latest === null || strtotime((string) $used) > strtotime($latest))) {
                $latest = (string) $used;
            }
        } elseif ($guestId !== null && $guestId !== '') {
            $stmt = $pdo->prepare(
                'SELECT used_at AS t FROM ad_unlock_tokens
                 WHERE guest_id = ? AND customer_id IS NULL AND used_at IS NOT NULL
                 ORDER BY used_at DESC LIMIT 1'
            );
            $stmt->execute([$guestId]);
            $used = $stmt->fetchColumn();
            if ($used !== false) {
                $latest = (string) $used;
            }
        }
        return $latest;
    }
}

if (!function_exists('runtime_credits_cooldown_remaining_sec')) {
    function runtime_credits_cooldown_remaining_sec(PDO $pdo, ?int $customerId, ?string $guestId): int
    {
        runtime_credits_define_constants();
        if (RUNTIME_AD_COOLDOWN_MIN <= 0) {
            return 0;
        }
        $lastAt = runtime_credits_last_cooldown_at($pdo, $customerId, $guestId);
        if ($lastAt === null) {
            return 0;
        }
        $elapsed = time() - strtotime($lastAt);
        $cooldownSec = RUNTIME_AD_COOLDOWN_MIN * 60;
        return max(0, $cooldownSec - $elapsed);
    }
}

if (!function_exists('runtime_credits_find_reusable_unlock_token')) {
    /** @return array{token:string,expires_at:string}|null */
    function runtime_credits_find_reusable_unlock_token(
        PDO $pdo,
        string $scope,
        ?string $guestId,
        ?int $customerId
    ): ?array {
        if ($customerId) {
            $stmt = $pdo->prepare(
                'SELECT token, expires_at FROM ad_unlock_tokens
                 WHERE scope = ? AND customer_id = ? AND used_at IS NULL AND expires_at > NOW()
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$scope, $customerId]);
        } elseif ($guestId !== null && $guestId !== '') {
            $stmt = $pdo->prepare(
                'SELECT token, expires_at FROM ad_unlock_tokens
                 WHERE scope = ? AND guest_id = ? AND customer_id IS NULL
                   AND used_at IS NULL AND expires_at > NOW()
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$scope, $guestId]);
        } else {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['token'])) {
            return null;
        }
        return ['token' => (string) $row['token'], 'expires_at' => (string) $row['expires_at']];
    }
}

if (!function_exists('runtime_credits_last_claim_at')) {
    function runtime_credits_last_claim_at(PDO $pdo, string $ipHash, ?int $customerId, ?string $guestId): ?string
    {
        $sql = 'SELECT claimed_at FROM ad_claim_log WHERE ip_hash = ?';
        $params = [$ipHash];
        if ($customerId) {
            $sql .= ' OR customer_id = ?';
            $params[] = $customerId;
        }
        if ($guestId) {
            $sql .= ' OR guest_id = ?';
            $params[] = $guestId;
        }
        $sql .= ' ORDER BY claimed_at DESC LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : null;
    }
}

if (!function_exists('runtime_credits_check_ad_limits')) {
    /**
     * Ad gating is token-based (watch ad → unlock token → play). No session/IP/daily caps.
     *
     * @return array{ok:bool,error?:string,code?:string}
     */
    function runtime_credits_check_ad_limits(
        string $scope,
        string $claimType,
        string $ipHash,
        string $sessionId,
        ?string $guestId,
        ?int $customerId
    ): array {
        if (!in_array($scope, ['solo', 'pvp', 'bonus'], true)) {
            return ['ok' => false, 'code' => 'SCOPE', 'error' => 'Invalid scope'];
        }

        return ['ok' => true];
    }
}

if (!function_exists('runtime_credits_log_ad_claim')) {
    function runtime_credits_log_ad_claim(
        string $scope,
        string $claimType,
        string $ipHash,
        string $sessionId,
        ?string $guestId,
        ?int $customerId
    ): void {
        $pdo = runtime_credits_pdo();
        runtime_credits_ensure_schema($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO ad_claim_log (scope, claim_type, ip_hash, session_id, guest_id, customer_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $scope,
            $claimType,
            $ipHash,
            $sessionId,
            $guestId,
            $customerId,
        ]);
    }
}

if (!function_exists('runtime_credits_validate_ad_view_timing')) {
    /**
     * @return array{ok:false,code:string,error:string}|null null when valid
     */
    function runtime_credits_validate_ad_view_timing(int $minViewSec, $viewStartedAtMs): ?array
    {
        $started = is_numeric($viewStartedAtMs) ? (int) $viewStartedAtMs : 0;
        if ($started < 1) {
            return ['ok' => false, 'code' => 'MIN_VIEW', 'error' => 'Ad view timing missing'];
        }
        $elapsedSec = (int) floor((microtime(true) * 1000 - $started) / 1000);
        if ($elapsedSec < max(0, $minViewSec - 2)) {
            return ['ok' => false, 'code' => 'MIN_VIEW', 'error' => 'Minimum ad view time not met'];
        }
        return null;
    }
}

if (!function_exists('runtime_credits_create_unlock_token')) {
    /**
     * @return array{ok:bool,token?:string,error?:string,code?:string}
     */
    function runtime_credits_create_unlock_token(
        string $scope,
        string $ipHash,
        string $sessionId,
        ?string $guestId,
        ?int $customerId,
        int $minViewSec,
        $viewStartedAtMs = null,
        string $adProviderShown = ''
    ): array {
        runtime_credits_define_constants();

        $required = $scope === 'solo' ? RUNTIME_AD_MIN_VIEW_SOLO_SEC : RUNTIME_AD_MIN_VIEW_PVP_SEC;
        if ($minViewSec < $required) {
            return ['ok' => false, 'code' => 'MIN_VIEW', 'error' => 'Minimum ad view time not met'];
        }

        $timing = runtime_credits_validate_ad_view_timing($required, $viewStartedAtMs);
        if ($timing !== null) {
            return $timing;
        }

        $pdo = runtime_credits_pdo();
        runtime_credits_ensure_schema($pdo);

        $reusable = runtime_credits_find_reusable_unlock_token($pdo, $scope, $guestId, $customerId);
        if ($reusable !== null) {
            return [
                'ok' => true,
                'token' => $reusable['token'],
                'expires_at' => $reusable['expires_at'],
                'reused' => true,
            ];
        }

        $limits = runtime_credits_check_ad_limits($scope, 'unlock', $ipHash, $sessionId, $guestId, $customerId);
        if (!$limits['ok']) {
            return $limits;
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + RUNTIME_AD_TOKEN_TTL_MIN * 60);

        $stmt = $pdo->prepare(
            'INSERT INTO ad_unlock_tokens (token, scope, guest_id, customer_id, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$token, $scope, $guestId, $customerId, $expires]);

        runtime_credits_log_ad_claim($scope, 'unlock', $ipHash, $sessionId, $guestId, $customerId);

        return [
            'ok' => true,
            'token' => $token,
            'expires_at' => $expires,
            'ad_provider' => $adProviderShown !== '' ? $adProviderShown : 'unknown',
        ];
    }
}

if (!function_exists('runtime_credits_validate_unlock_token')) {
    /**
     * @return array{ok:bool,error?:string,code?:string}
     */
    function runtime_credits_validate_unlock_token(string $token, string $scope): array
    {
        runtime_credits_define_constants();
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['ok' => false, 'code' => 'TOKEN', 'error' => 'Invalid unlock token'];
        }

        $pdo = runtime_credits_pdo();
        runtime_credits_ensure_schema($pdo);

        $stmt = $pdo->prepare(
            'SELECT scope, expires_at, used_at FROM ad_unlock_tokens WHERE token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'code' => 'TOKEN', 'error' => 'Unlock token not found'];
        }
        if (($row['scope'] ?? '') !== $scope) {
            return ['ok' => false, 'code' => 'SCOPE', 'error' => 'Token scope mismatch'];
        }
        if ($row['used_at'] !== null) {
            return ['ok' => false, 'code' => 'USED', 'error' => 'Unlock token already used'];
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return ['ok' => false, 'code' => 'EXPIRED', 'error' => 'Unlock token expired'];
        }

        return ['ok' => true];
    }
}

if (!function_exists('runtime_credits_consume_unlock_token')) {
    /**
     * @return array{ok:bool,error?:string,code?:string}
     */
    function runtime_credits_consume_unlock_token(string $token, string $scope): array
    {
        runtime_credits_define_constants();
        $token = trim($token);
        $valid = runtime_credits_validate_unlock_token($token, $scope);
        if (!$valid['ok']) {
            return $valid;
        }

        $pdo = runtime_credits_pdo();
        runtime_credits_ensure_schema($pdo);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT id FROM ad_unlock_tokens WHERE token = ? FOR UPDATE"
            );
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'TOKEN', 'error' => 'Unlock token not found'];
            }

            $upd = $pdo->prepare('UPDATE ad_unlock_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
            $upd->execute([(int) $row['id']]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'USED', 'error' => 'Unlock token already used'];
            }
            $pdo->commit();
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('runtime_credits_ad_claim_bonus')) {
    /**
     * @return array{ok:bool,pvp_credits?:int,solo_credits?:int,error?:string,code?:string}
     */
    function runtime_credits_ad_claim_bonus(
        int $customerId,
        string $ipHash,
        string $sessionId,
        int $minViewSec,
        $viewStartedAtMs = null,
        string $adProviderShown = ''
    ): array {
        runtime_credits_define_constants();

        if ($minViewSec < RUNTIME_AD_MIN_VIEW_SOLO_SEC) {
            return ['ok' => false, 'code' => 'MIN_VIEW', 'error' => 'Minimum ad view time not met'];
        }

        $timing = runtime_credits_validate_ad_view_timing(RUNTIME_AD_MIN_VIEW_SOLO_SEC, $viewStartedAtMs);
        if ($timing !== null) {
            return $timing;
        }

        $limits = runtime_credits_check_ad_limits('bonus', 'bonus', $ipHash, $sessionId, null, $customerId);
        if (!$limits['ok']) {
            return $limits;
        }

        $pvpGrant = random_int(RUNTIME_AD_REWARD_PVP_MIN, RUNTIME_AD_REWARD_PVP_MAX);
        $soloGrant = RUNTIME_AD_REWARD_SOLO;

        $pdo = runtime_credits_pdo();
        $result = runtime_credits_apply_delta(
            $pdo,
            $customerId,
            $pvpGrant,
            $soloGrant,
            'ad_reward',
            ['note' => 'Ad watch bonus']
        );

        runtime_credits_log_ad_claim('bonus', 'bonus', $ipHash, $sessionId, null, $customerId);

        return $result + ['pvp_granted' => $pvpGrant, 'solo_granted' => $soloGrant];
    }
}

if (!function_exists('runtime_credits_bypass_active')) {
    function runtime_credits_bypass_active(): bool
    {
        return function_exists('roast_request_bypass_active') && roast_request_bypass_active();
    }
}

if (!function_exists('runtime_credits_require_solo')) {
    /**
     * @return array{ok:bool,method?:string,error?:array<string,mixed>}
     */
    function runtime_credits_require_solo(?int $customerId, string $unlockToken = ''): array
    {
        if (runtime_credits_bypass_active()) {
            return ['ok' => true, 'method' => 'bypass'];
        }

        try {
            runtime_credits_define_constants();
            $pdo = runtime_credits_pdo();
            runtime_credits_ensure_schema($pdo);

            if ($customerId !== null && $customerId > 0) {
                $bal = runtime_credits_get_balance($pdo, $customerId);
                if ($bal['solo_credits'] >= RUNTIME_COST_SOLO) {
                    runtime_credits_apply_delta(
                        $pdo,
                        $customerId,
                        0,
                        -RUNTIME_COST_SOLO,
                        'solo_spend',
                        ['note' => 'Solo roast']
                    );
                    return ['ok' => true, 'method' => 'balance'];
                }
            }

            if ($unlockToken !== '') {
                $consumed = runtime_credits_consume_unlock_token($unlockToken, 'solo');
                if ($consumed['ok']) {
                    return ['ok' => true, 'method' => 'ad_token'];
                }
            }

            require_once __DIR__ . '/roast-envelope.php';
            return [
                'ok' => false,
                'error' => roast_error(
                    'CREDITS',
                    $customerId
                        ? 'Not enough solo credits. Watch an ad for bonus or shop for more.'
                        : 'Watch an ad to unlock solo roast.',
                    false
                ),
            ];
        } catch (Throwable $e) {
            error_log('[OnlyBikes][runtime_credits] require_solo: ' . $e->getMessage());
            require_once __DIR__ . '/roast-envelope.php';
            return [
                'ok' => false,
                'error' => roast_error('CREDITS_DB', 'Credits system unavailable. Try again shortly.', true),
            ];
        }
    }
}

if (!function_exists('runtime_credits_check_pvp')) {
    /**
     * Validate PvP entry without spending credits or consuming ad tokens.
     *
     * @return array{ok:bool,method?:string,error?:array<string,mixed>}
     */
    function runtime_credits_check_pvp(?int $customerId, string $unlockToken = ''): array
    {
        if (runtime_credits_bypass_active()) {
            return ['ok' => true, 'method' => 'bypass'];
        }

        try {
            runtime_credits_define_constants();
            $pdo = runtime_credits_pdo();
            runtime_credits_ensure_schema($pdo);

            if ($customerId !== null && $customerId > 0) {
                $bal = runtime_credits_get_balance($pdo, $customerId);
                if ($bal['pvp_credits'] >= RUNTIME_COST_PVP) {
                    return ['ok' => true, 'method' => 'balance'];
                }
            }

            if ($unlockToken !== '') {
                $valid = runtime_credits_validate_unlock_token($unlockToken, 'pvp');
                if ($valid['ok']) {
                    return ['ok' => true, 'method' => 'ad_token'];
                }
            }

            require_once __DIR__ . '/roast-envelope.php';
            return [
                'ok' => false,
                'error' => roast_error(
                    'CREDITS',
                    $customerId
                        ? 'Not enough PvP credits. Watch an ad for bonus or shop for more.'
                        : 'Watch an ad to join PvP.',
                    false
                ),
            ];
        } catch (Throwable $e) {
            error_log('[OnlyBikes][runtime_credits] check_pvp: ' . $e->getMessage());
            require_once __DIR__ . '/roast-envelope.php';
            return [
                'ok' => false,
                'error' => roast_error('CREDITS_DB', 'Credits system unavailable. Try again shortly.', true),
            ];
        }
    }
}

if (!function_exists('runtime_credits_commit_pvp_entry')) {
  function runtime_credits_commit_pvp_entry(?int $customerId, string $unlockToken, string $method): void
    {
        if ($method === 'bypass') {
            return;
        }

        runtime_credits_define_constants();
        $pdo = runtime_credits_pdo();
        runtime_credits_ensure_schema($pdo);

        if ($method === 'balance' && $customerId !== null && $customerId > 0) {
            runtime_credits_apply_delta(
                $pdo,
                $customerId,
                -RUNTIME_COST_PVP,
                0,
                'pvp_spend',
                ['note' => 'PvP match entry']
            );
            return;
        }

        if ($method === 'ad_token' && $unlockToken !== '') {
            $consumed = runtime_credits_consume_unlock_token($unlockToken, 'pvp');
            if (!$consumed['ok']) {
                throw new RuntimeException($consumed['error'] ?? 'Unlock token consume failed');
            }
        }
    }
}

if (!function_exists('runtime_credits_require_pvp')) {
    /**
     * @return array{ok:bool,method?:string,error?:array<string,mixed>}
     */
    function runtime_credits_require_pvp(?int $customerId, string $unlockToken = ''): array
    {
        $check = runtime_credits_check_pvp($customerId, $unlockToken);
        if (!$check['ok']) {
            return $check;
        }

        try {
            runtime_credits_commit_pvp_entry($customerId, $unlockToken, (string) ($check['method'] ?? ''));
            return $check;
        } catch (Throwable $e) {
            error_log('[OnlyBikes][runtime_credits] require_pvp: ' . $e->getMessage());
            require_once __DIR__ . '/roast-envelope.php';
            return [
                'ok' => false,
                'error' => roast_error('CREDITS_DB', 'Credits system unavailable. Try again shortly.', true),
            ];
        }
    }
}

if (!function_exists('runtime_credits_refund_failed_job')) {
    function runtime_credits_refund_failed_job(int $customerId, string $scope, string $jobId): void
    {
        if ($customerId < 1 || !in_array($scope, ['solo', 'pvp'], true)) {
            return;
        }
        try {
            runtime_credits_define_constants();
            $pdo = runtime_credits_pdo();
            $chk = $pdo->prepare(
                "SELECT id FROM runtime_ledger WHERE customer_id = ? AND type = 'refund_failed_job' AND reference_id = ? LIMIT 1"
            );
            $chk->execute([$customerId, $jobId]);
            if ($chk->fetch()) {
                return;
            }

            $deltaPvp = $scope === 'pvp' ? RUNTIME_COST_PVP : 0;
            $deltaSolo = $scope === 'solo' ? RUNTIME_COST_SOLO : 0;
            runtime_credits_apply_delta(
                $pdo,
                $customerId,
                $deltaPvp,
                $deltaSolo,
                'refund_failed_job',
                [
                    'reference_type' => 'job',
                    'reference_id' => $jobId,
                    'note' => 'Pipeline failure refund',
                ]
            );
        } catch (Throwable $e) {
            error_log('[OnlyBikes][runtime_credits] refund_failed_job: ' . $e->getMessage());
        }
    }
}

if (!function_exists('runtime_credits_customer_id_from_session')) {
    function runtime_credits_customer_id_from_session(): ?int
    {
        if (!function_exists('onlybikes_points_session_customer_id')) {
            return null;
        }
        $id = onlybikes_points_session_customer_id();
        return $id !== null && $id > 0 ? $id : null;
    }
}
