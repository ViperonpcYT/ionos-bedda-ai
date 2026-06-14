<?php
declare(strict_types=1);

require_once __DIR__ . '/roast-jobs.php';
require_once __DIR__ . '/roast-envelope.php';
require_once __DIR__ . '/roast-config.php';

if (!function_exists('roast_pvp_ensure_schema')) {
    function roast_pvp_ensure_schema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS roast_pvp_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_token CHAR(64) NOT NULL,
  match_id CHAR(36) NULL,
  face_hash CHAR(64) NULL,
  status ENUM('waiting','matched','cancelled') NOT NULL DEFAULT 'waiting',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roast_pvp_queue_token (queue_token),
  KEY idx_roast_pvp_queue_waiting (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS roast_pvp_matches (
  match_id CHAR(36) NOT NULL,
  player_a_token CHAR(64) NOT NULL,
  player_b_token CHAR(64) NULL,
  player_a_job_id CHAR(36) NULL,
  player_b_job_id CHAR(36) NULL,
  player_a_face_hash CHAR(64) NULL,
  player_b_face_hash CHAR(64) NULL,
  player_a_verified_at TIMESTAMP NULL,
  player_b_verified_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  status ENUM('matched','active','dueling','complete','expired','cancelled') NOT NULL DEFAULT 'matched',
  winner ENUM('a','b','t') NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (match_id),
  KEY idx_roast_pvp_match_status (status, updated_at),
  KEY idx_roast_pvp_match_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS roast_pvp_presence (
  token CHAR(64) NOT NULL,
  last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (token),
  KEY idx_roast_pvp_presence_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS roast_pvp_signals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_id CHAR(36) NOT NULL,
  from_token CHAR(64) NOT NULL,
  signal_type VARCHAR(16) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_roast_pvp_signals_match (match_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        roast_pvp_migrate_schema($pdo);
    }
}

if (!function_exists('roast_pvp_migrate_schema')) {
    function roast_pvp_migrate_schema(PDO $pdo): void
    {
        $alters = [
            'ALTER TABLE roast_pvp_queue ADD COLUMN face_hash CHAR(64) NULL AFTER match_id',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_face_hash CHAR(64) NULL AFTER player_b_job_id',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_face_hash CHAR(64) NULL AFTER player_a_face_hash',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_verified_at TIMESTAMP NULL AFTER player_b_face_hash',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_verified_at TIMESTAMP NULL AFTER player_a_verified_at',
            'ALTER TABLE roast_pvp_matches ADD COLUMN expires_at TIMESTAMP NULL AFTER player_b_verified_at',
            "ALTER TABLE roast_pvp_matches MODIFY status ENUM('matched','active','dueling','complete','expired','cancelled') NOT NULL DEFAULT 'matched'",
            "ALTER TABLE roast_pvp_matches ADD COLUMN player_a_mode ENUM('photo','live') NULL AFTER expires_at",
            "ALTER TABLE roast_pvp_matches ADD COLUMN player_b_mode ENUM('photo','live') NULL AFTER player_a_mode",
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_live_best INT NULL AFTER player_b_mode',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_live_best INT NULL AFTER player_a_live_best',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_live_job_id CHAR(36) NULL AFTER player_b_live_best',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_live_job_id CHAR(36) NULL AFTER player_a_live_job_id',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_live_at TIMESTAMP NULL AFTER player_b_live_job_id',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_live_at TIMESTAMP NULL AFTER player_a_live_at',
            'ALTER TABLE roast_pvp_matches ADD COLUMN round_started_at TIMESTAMP NULL AFTER player_b_live_at',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_live_sum INT UNSIGNED NULL DEFAULT 0 AFTER player_b_live_best',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_live_count INT UNSIGNED NULL DEFAULT 0 AFTER player_a_live_sum',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_live_sum INT UNSIGNED NULL DEFAULT 0 AFTER player_a_live_count',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_live_count INT UNSIGNED NULL DEFAULT 0 AFTER player_b_live_sum',
        ];
        foreach ($alters as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // column/type may already exist
            }
        }
    }
}

if (!function_exists('roast_pvp_pdo')) {
    function roast_pvp_pdo(): ?PDO
    {
        $pdo = roast_jobs_pdo();
        if ($pdo) {
            roast_pvp_ensure_schema($pdo);
        }
        return $pdo;
    }
}

if (!function_exists('roast_pvp_normalize_token')) {
    function roast_pvp_normalize_token(string $token): string
    {
        $token = strtolower(preg_replace('/[^a-f0-9-]/', '', $token) ?? '');
        return strlen($token) >= 32 ? substr($token, 0, 64) : '';
    }
}

if (!function_exists('roast_pvp_normalize_face_hash')) {
    function roast_pvp_normalize_face_hash(string $hash): string
    {
        $hash = strtolower(preg_replace('/[^a-f0-9]/', '', $hash) ?? '');
        return strlen($hash) === 64 ? $hash : '';
    }
}

if (!function_exists('roast_pvp_purge_stale')) {
    function roast_pvp_purge_stale(int $minutes = 10): void
    {
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return;
        }
        $pdo->prepare(
            "UPDATE roast_pvp_queue SET status = 'cancelled'
             WHERE status = 'waiting' AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        )->execute([$minutes]);
        $pdo->prepare(
            "UPDATE roast_pvp_matches SET status = 'expired'
             WHERE status IN ('matched','active') AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        )->execute([$minutes]);
    }
}

if (!function_exists('roast_pvp_activate_match')) {
    function roast_pvp_activate_match(string $matchId): void
    {
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return;
        }
        $sec = ROAST_PVP_ROUND_SEC;
        $pdo->prepare(
            "UPDATE roast_pvp_matches
             SET status = 'active',
                 expires_at = DATE_ADD(NOW(), INTERVAL {$sec} SECOND),
                 player_a_verified_at = COALESCE(player_a_verified_at, NOW()),
                 player_b_verified_at = COALESCE(player_b_verified_at, NOW())
             WHERE match_id = ? AND status = 'matched'"
        )->execute([$matchId]);
    }
}

if (!function_exists('roast_pvp_expire_match')) {
    function roast_pvp_expire_match(string $matchId): void
    {
        $match = roast_pvp_get_match($matchId);
        if (!$match || !in_array($match['status'] ?? '', ['active', 'matched'], true)) {
            return;
        }

        $jobA = trim((string) ($match['player_a_job_id'] ?? ''));
        $jobB = trim((string) ($match['player_b_job_id'] ?? ''));

        if ($jobA !== '' && $jobB !== '') {
            roast_pvp_finalize_match($matchId);
            return;
        }

        $modeA = (string) ($match['player_a_mode'] ?? '');
        $modeB = (string) ($match['player_b_mode'] ?? '');
        if ($modeA === 'live' && $modeB === 'live') {
            roast_pvp_finalize_live_duel($matchId);
            return;
        }
        if ($modeA === 'live' || $modeB === 'live') {
            roast_pvp_finalize_mixed($matchId);
            return;
        }

        $winner = 't';
        if ($jobA !== '' && $jobB === '') {
            $winner = 'a';
        } elseif ($jobB !== '' && $jobA === '') {
            $winner = 'b';
        }

        $pdo = roast_pvp_pdo();
        if ($pdo) {
            $pdo->prepare(
                'UPDATE roast_pvp_matches SET status = ?, winner = ? WHERE match_id = ?'
            )->execute(['expired', $winner, $matchId]);
        }
    }
}

if (!function_exists('roast_pvp_check_expiry')) {
    /** @param array<string, mixed>|null $match */
    function roast_pvp_check_expiry(?array $match): ?array
    {
        if (!$match) {
            return null;
        }
        $status = (string) ($match['status'] ?? '');
        if ($status !== 'active') {
            return $match;
        }
        $exp = $match['expires_at'] ?? null;
        if (!$exp) {
            return $match;
        }
        $ts = strtotime((string) $exp);
        if ($ts !== false && time() >= $ts) {
            roast_pvp_expire_match((string) $match['match_id']);
            return roast_pvp_get_match((string) $match['match_id']);
        }
        return $match;
    }
}

if (!function_exists('roast_pvp_seconds_remaining')) {
    function roast_pvp_seconds_remaining(array $match): ?int
    {
        if (($match['status'] ?? '') !== 'active' || empty($match['expires_at'])) {
            return null;
        }
        $ts = strtotime((string) $match['expires_at']);
        if ($ts === false) {
            return null;
        }
        return max(0, $ts - time());
    }
}

if (!function_exists('roast_pvp_join')) {
    /** @return array<string, mixed> */
    function roast_pvp_join(string $token, string $faceHash = ''): array
    {
        $token = roast_pvp_normalize_token($token);
        $faceHash = roast_pvp_normalize_face_hash($faceHash);
        if ($token === '') {
            return ['ok' => false, 'error' => roast_error('TOKEN', 'Invalid session token.', false)];
        }
        if ($faceHash === '') {
            return ['ok' => false, 'error' => roast_error('FACE', 'Face verification required before matchmaking.', false)];
        }

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Matchmaking unavailable.', true)];
        }

        roast_pvp_purge_stale();

        try {
            $pdo->beginTransaction();

            $existing = roast_pvp_get_by_token($token);
            if ($existing) {
                $existing = roast_pvp_check_expiry($existing);
            }
            if ($existing && ($existing['status'] ?? '') === 'complete') {
                $pdo->commit();
                return roast_pvp_build_status($existing, $token);
            }
            if ($existing && in_array($existing['status'] ?? '', ['matched', 'active', 'dueling'], true)) {
                $pdo->commit();
                return roast_pvp_build_status($existing, $token);
            }

            $waiter = $pdo->prepare(
                "SELECT queue_token, face_hash FROM roast_pvp_queue
                 WHERE status = 'waiting' AND queue_token <> ? AND face_hash IS NOT NULL
                 ORDER BY created_at ASC LIMIT 1 FOR UPDATE"
            );
            $waiter->execute([$token]);
            $waiterRow = $waiter->fetch(PDO::FETCH_ASSOC);

            if (is_array($waiterRow) && !empty($waiterRow['queue_token'])) {
                $opponentToken = (string) $waiterRow['queue_token'];
                $oppFace = (string) ($waiterRow['face_hash'] ?? '');
                $matchId = roast_jobs_new_id();

                $sec = ROAST_PVP_ROUND_SEC;
                $ins = $pdo->prepare(
                    'INSERT INTO roast_pvp_matches
                     (match_id, player_a_token, player_b_token, player_a_face_hash, player_b_face_hash,
                      player_a_verified_at, player_b_verified_at, status, expires_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?, DATE_ADD(NOW(), INTERVAL ' . (int) $sec . ' SECOND))'
                );
                $ins->execute([
                    $matchId,
                    $opponentToken,
                    $token,
                    $oppFace,
                    $faceHash,
                    'active',
                ]);

                $pdo->prepare(
                    "UPDATE roast_pvp_queue SET status = 'matched', match_id = ?
                     WHERE queue_token IN (?, ?) AND status = 'waiting'"
                )->execute([$matchId, $opponentToken, $token]);

                $pdo->prepare(
                    'INSERT INTO roast_pvp_queue (queue_token, match_id, face_hash, status) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE match_id = VALUES(match_id), face_hash = VALUES(face_hash), status = VALUES(status)'
                )->execute([$token, $matchId, $faceHash, 'matched']);

                $pdo->commit();
                $match = roast_pvp_get_match($matchId);
                return roast_pvp_build_status($match ?: ['match_id' => $matchId, 'status' => 'active'], $token);
            }

            $pdo->prepare(
                'INSERT INTO roast_pvp_queue (queue_token, face_hash, status) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE face_hash = VALUES(face_hash),
                   status = IF(status = "cancelled", "waiting", status),
                   created_at = IF(status = "cancelled", CURRENT_TIMESTAMP, created_at)'
            )->execute([$token, $faceHash, 'waiting']);

            $pdo->commit();
            return [
                'ok' => true,
                'status' => 'waiting',
                'message' => 'Looking for a verified stranger…',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Roast PvP] join failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => roast_error('JOIN', 'Could not join queue.', true)];
        }
    }
}

if (!function_exists('roast_pvp_get_match')) {
    /** @return array<string, mixed>|null */
    function roast_pvp_get_match(string $matchId): ?array
    {
        $pdo = roast_pvp_pdo();
        if (!$pdo || $matchId === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM roast_pvp_matches WHERE match_id = ? LIMIT 1');
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('roast_pvp_get_by_token')) {
    /** @return array<string, mixed>|null */
    function roast_pvp_get_by_token(string $token): ?array
    {
        $token = roast_pvp_normalize_token($token);
        if ($token === '') {
            return null;
        }
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT m.* FROM roast_pvp_matches m
             WHERE m.player_a_token = ? OR m.player_b_token = ?
             ORDER BY m.updated_at DESC LIMIT 1'
        );
        $stmt->execute([$token, $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('roast_pvp_role_for_token')) {
    function roast_pvp_role_for_token(array $match, string $token): string
    {
        if (($match['player_a_token'] ?? '') === $token) {
            return 'a';
        }
        if (($match['player_b_token'] ?? '') === $token) {
            return 'b';
        }
        return '';
    }
}

if (!function_exists('roast_pvp_validate_participant')) {
    function roast_pvp_validate_participant(string $matchId, string $token): bool
    {
        $match = roast_pvp_get_match($matchId);
        $token = roast_pvp_normalize_token($token);
        if (!$match || $token === '') {
            return false;
        }
        if (!in_array($match['status'] ?? '', ['matched', 'active', 'dueling'], true)) {
            return false;
        }
        return roast_pvp_role_for_token($match, $token) !== '';
    }
}

if (!function_exists('roast_pvp_link_job')) {
    /** @return array<string, mixed> */
    function roast_pvp_link_job(string $matchId, string $token, string $jobId): array
    {
        $match = roast_pvp_get_match($matchId);
        $match = roast_pvp_check_expiry($match);
        $token = roast_pvp_normalize_token($token);
        $role = $match ? roast_pvp_role_for_token($match, $token) : '';
        if ($role === '') {
            return ['ok' => false, 'error' => roast_error('PVP', 'Not in this match.', false)];
        }
        if ($match && ($match['status'] ?? '') === 'expired') {
            return ['ok' => false, 'error' => roast_error('EXPIRED', 'Round timer expired.', false)];
        }

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Match update failed.', true)];
        }

        $col = $role === 'a' ? 'player_a_job_id' : 'player_b_job_id';
        $pdo->prepare("UPDATE roast_pvp_matches SET {$col} = ?, status = 'dueling' WHERE match_id = ?")
            ->execute([$jobId, $matchId]);

        $match = roast_pvp_get_match($matchId);
        if (!$match) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Match lost.', false)];
        }

        $jobA = (string) ($match['player_a_job_id'] ?? '');
        $jobB = (string) ($match['player_b_job_id'] ?? '');
        if ($jobA === '' || $jobB === '') {
            $modeA = (string) ($match['player_a_mode'] ?? '');
            $modeB = (string) ($match['player_b_mode'] ?? '');
            if ($modeA === 'live' && $modeB === 'live') {
                return roast_pvp_finalize_live_duel($matchId);
            }
            if ($modeA === 'live' || $modeB === 'live') {
                return roast_pvp_finalize_mixed($matchId);
            }
            return ['ok' => true, 'status' => 'dueling', 'waiting_for' => $jobA === '' ? 'a' : 'b'];
        }

        return roast_pvp_finalize_match($matchId);
    }
}

if (!function_exists('roast_pvp_finalize_match')) {
    /** @return array<string, mixed> */
    function roast_pvp_finalize_match(string $matchId): array
    {
        $match = roast_pvp_get_match($matchId);
        if (!$match) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Match not found.', false)];
        }

        $rowA = roast_jobs_get((string) ($match['player_a_job_id'] ?? ''));
        $rowB = roast_jobs_get((string) ($match['player_b_job_id'] ?? ''));
        if (!$rowA || !$rowB) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Jobs missing.', true)];
        }

        $scoreA = (int) ($rowA['score'] ?? 0);
        $scoreB = (int) ($rowB['score'] ?? 0);
        $winner = 't';
        if ($scoreA > $scoreB) {
            $winner = 'a';
        } elseif ($scoreB > $scoreA) {
            $winner = 'b';
        }

        $pdo = roast_pvp_pdo();
        if ($pdo) {
            $pdo->prepare(
                'UPDATE roast_pvp_matches SET status = ?, winner = ? WHERE match_id = ?'
            )->execute(['complete', $winner, $matchId]);
        }

        $match['status'] = 'complete';
        $match['winner'] = $winner;
        return ['ok' => true, 'status' => 'complete', 'match' => $match];
    }
}

if (!function_exists('roast_pvp_resolve_job_id')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_resolve_job_id(array $match, string $side): ?string
    {
        $mode = (string) ($match["player_{$side}_mode"] ?? '');
        if ($mode === 'live') {
            $liveJob = trim((string) ($match["player_{$side}_live_job_id"] ?? ''));
            if ($liveJob !== '') {
                return $liveJob;
            }
        }
        $job = trim((string) ($match["player_{$side}_job_id"] ?? ''));
        return $job !== '' ? $job : null;
    }
}

if (!function_exists('roast_pvp_live_average')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_live_average(array $match, string $side): ?int
    {
        $count = (int) ($match["player_{$side}_live_count"] ?? 0);
        if ($count > 0) {
            $sum = (int) ($match["player_{$side}_live_sum"] ?? 0);
            return (int) round($sum / $count);
        }
        $best = $match["player_{$side}_live_best"] ?? null;
        return $best !== null && $best !== '' ? (int) $best : null;
    }
}

if (!function_exists('roast_pvp_live_end_roast')) {
    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     */
    function roast_pvp_live_end_roast(array $identity, array $inspect, int $score): string
    {
        require_once __DIR__ . '/roast-score.php';

        $make = trim((string) ($identity['make'] ?? ''));
        $model = trim((string) ($identity['model'] ?? ''));
        $subject = roast_normalize_visible_subject($identity, $inspect);
        $bike = 'that build';
        if ($make !== '' && strtolower($make) !== 'unknown') {
            $bike = trim($make . ' ' . ($model !== '' && strtolower($model) !== 'unknown' ? $model : ''));
        } elseif ($model !== '' && strtolower($model) !== 'unknown') {
            $bike = $model;
        }

        if (in_array($subject, ['parts_only', 'not_an_ebike', 'unclear'], true)) {
            return "Cred {$score}/100 (match average) — we still couldn't see a whole bike live. "
                . 'Point the top camera at the full frame, not floor clutter.';
        }
        if ($score >= 80) {
            return "Cred {$score}/100 (match average) — your {$bike} looked shockingly respectable on a live roast. "
                . "Don't let one good angle go to your helmet.";
        }
        if ($score >= 55) {
            return "Cred {$score}/100 (match average) — the {$bike} held up on camera. "
                . 'A few questionable choices, but you survived the stranger stare-down.';
        }
        if ($score >= 30) {
            return "Cred {$score}/100 (match average) — {$bike} energy: part stock, part chaos. "
                . 'The mods are shy and the dirt is not.';
        }

        return "Cred {$score}/100 (match average) — {$bike} rolled in like a yard sale on two wheels. "
            . 'Fix something visible before the next stranger laughs.';
    }
}

if (!function_exists('roast_pvp_job_snapshot')) {
    /** @return array<string, mixed>|null */
    function roast_pvp_job_snapshot(?string $jobId): ?array
    {
        if ($jobId === null || $jobId === '') {
            return null;
        }
        $row = roast_jobs_get($jobId);
        if (!$row || !in_array($row['status'] ?? '', ['complete', 'partial'], true)) {
            return null;
        }
        $identity = json_decode((string) ($row['identity_json'] ?? '{}'), true) ?: [];
        $inspect = json_decode((string) ($row['inspect_json'] ?? '{}'), true) ?: [];
        return [
            'job_id' => $jobId,
            'score' => isset($row['score']) ? (int) $row['score'] : null,
            'roast' => (string) ($row['roast_text'] ?? ''),
            'identity' => $identity,
            'inspect' => $inspect,
            'status' => (string) ($row['status'] ?? ''),
        ];
    }
}

if (!function_exists('roast_pvp_store_signal')) {
    function roast_pvp_store_signal(string $matchId, string $token, string $type, string $payload): bool
    {
        $pdo = roast_pvp_pdo();
        if (!$pdo || !roast_pvp_validate_participant($matchId, $token)) {
            return false;
        }
        $type = strtolower(trim($type));
        if (!in_array($type, ['offer', 'answer', 'ice'], true)) {
            return false;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO roast_pvp_signals (match_id, from_token, signal_type, payload) VALUES (?, ?, ?, ?)'
        );
        return $stmt->execute([$matchId, roast_pvp_normalize_token($token), $type, $payload]);
    }
}

if (!function_exists('roast_pvp_poll_signals')) {
    /** @return array<string, mixed> */
    function roast_pvp_poll_signals(string $matchId, string $token, int $sinceId = 0): array
    {
        $token = roast_pvp_normalize_token($token);
        if (!roast_pvp_validate_participant($matchId, $token)) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Not in match.', false)];
        }
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Unavailable.', true)];
        }
        $stmt = $pdo->prepare(
            'SELECT id, from_token, signal_type, payload, created_at
             FROM roast_pvp_signals
             WHERE match_id = ? AND id > ? AND from_token <> ?
             ORDER BY id ASC LIMIT 50'
        );
        $stmt->execute([$matchId, max(0, $sinceId), $token]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['ok' => true, 'signals' => $rows];
    }
}

if (!function_exists('roast_pvp_build_status')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_build_status(array $match, string $token): array
    {
        $match = roast_pvp_check_expiry($match) ?? $match;
        $token = roast_pvp_normalize_token($token);
        $role = roast_pvp_role_for_token($match, $token);
        $matchId = (string) ($match['match_id'] ?? '');
        $status = (string) ($match['status'] ?? 'matched');

        $payload = [
            'ok' => true,
            'status' => $status,
            'match_id' => $matchId,
            'role' => $role,
            'round_sec' => ROAST_PVP_ROUND_SEC,
        ];

        $remaining = roast_pvp_seconds_remaining($match);
        if ($remaining !== null) {
            $payload['seconds_remaining'] = $remaining;
        }

        if ($status === 'waiting') {
            $payload['message'] = 'Looking for a verified stranger…';
            return $payload;
        }

        if ($status === 'matched') {
            $payload['message'] = 'Stranger connected — starting round…';
            return $payload;
        }

        $youSide = $role === 'a' ? 'a' : 'b';
        $oppSide = $role === 'a' ? 'b' : 'a';
        $youJob = roast_pvp_resolve_job_id($match, $youSide);
        $oppJob = roast_pvp_resolve_job_id($match, $oppSide);
        $you = roast_pvp_job_snapshot($youJob);
        $opponent = roast_pvp_job_snapshot($oppJob);

        if ($status === 'active') {
            $modeYou = $role === 'a' ? ($match['player_a_mode'] ?? null) : ($match['player_b_mode'] ?? null);
            $modeOpp = $role === 'a' ? ($match['player_b_mode'] ?? null) : ($match['player_a_mode'] ?? null);
            $liveYou = roast_pvp_live_average($match, $youSide);
            $liveOpp = roast_pvp_live_average($match, $oppSide);
            $liveYouCount = (int) ($match["player_{$youSide}_live_count"] ?? 0);
            $liveOppCount = (int) ($match["player_{$oppSide}_live_count"] ?? 0);

            $payload['your_mode'] = $modeYou;
            $payload['opponent_mode'] = $modeOpp;
            $payload['round_type'] = roast_pvp_round_type($match);
            $payload['both_modes_ready'] = ($match['player_a_mode'] ?? '') !== '' && ($match['player_b_mode'] ?? '') !== '';

            if ($modeYou === 'live' || $liveYou !== null) {
                $payload['you_score'] = $liveYou !== null ? $liveYou : ($you['score'] ?? null);
                if ($liveYouCount > 0) {
                    $payload['your_frame_count'] = $liveYouCount;
                }
            }
            if ($modeOpp === 'live' || $liveOpp !== null) {
                $payload['opponent_score'] = $liveOpp !== null ? $liveOpp : ($opponent['score'] ?? null);
                if ($liveOppCount > 0) {
                    $payload['opponent_frame_count'] = $liveOppCount;
                }
            }

            if (!$payload['both_modes_ready']) {
                $payload['message'] = 'Waiting for opponent — auto-judging starts for both riders soon.';
            } else {
                $payload['message'] = 'Highest average cred wins. Auto-judging from your top camera.';
            }
            $payload['you'] = $you;
            $payload['opponent'] = $opponent;
            $payload['you_ready'] = $you !== null || ($modeYou === 'live' && $liveYou !== null);
            $payload['opponent_ready'] = $opponent !== null || ($modeOpp === 'live' && $liveOpp !== null);
            return $payload;
        }

        if ($status === 'dueling') {
            $payload['message'] = $you ? 'Waiting for opponent judgment…' : 'Judging your bike…';
            $payload['you'] = $you;
            $payload['opponent'] = $opponent;
            $payload['you_score'] = $you['score'] ?? null;
            $payload['opponent_score'] = $opponent['score'] ?? null;
            $payload['opponent_ready'] = $opponent !== null;
            $payload['you_ready'] = $you !== null;
            return $payload;
        }

        if ($status === 'complete' || $status === 'expired') {
            $winner = (string) ($match['winner'] ?? 't');
            if ($you && isset($you['score'])) {
                $payload['you_score'] = (int) $you['score'];
            }
            if ($opponent && isset($opponent['score'])) {
                $payload['opponent_score'] = (int) $opponent['score'];
            }
            $payload['you'] = $you;
            $payload['opponent'] = $opponent;
            $payload['winner'] = $winner;
            $payload['you_won'] = ($winner === 't') ? null : ($winner === $role);
            if ($status === 'expired') {
                $payload['message'] = $winner === 't'
                    ? 'Time expired — no winner.'
                    : (($payload['you_won'] ?? false) ? 'Time expired — you win by submission.' : 'Time expired — opponent wins.');
            } else {
                $payload['message'] = $winner === 't'
                    ? 'Draw — equally questionable builds.'
                    : (($payload['you_won'] ?? false) ? 'You win — higher average cred.' : 'You lose — more roast fuel.');
            }
            return $payload;
        }

        $payload['message'] = 'Match ended.';
        return $payload;
    }
}

if (!function_exists('roast_pvp_status')) {
    /** @return array<string, mixed> */
    function roast_pvp_status(string $token): array
    {
        $token = roast_pvp_normalize_token($token);
        if ($token === '') {
            return ['ok' => false, 'error' => roast_error('TOKEN', 'Invalid session token.', false)];
        }

        roast_pvp_purge_stale();

        $match = roast_pvp_get_by_token($token);
        if ($match) {
            $status = (string) ($match['status'] ?? '');
            if ($status === 'matched') {
                roast_pvp_activate_match((string) ($match['match_id'] ?? ''));
                $match = roast_pvp_get_match((string) ($match['match_id'] ?? '')) ?? $match;
            }
            return roast_pvp_build_status($match, $token);
        }

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Matchmaking unavailable.', true)];
        }

        $stmt = $pdo->prepare(
            "SELECT status FROM roast_pvp_queue WHERE queue_token = ? AND status = 'waiting' LIMIT 1"
        );
        $stmt->execute([$token]);
        if ($stmt->fetchColumn()) {
            return ['ok' => true, 'status' => 'waiting', 'message' => 'Looking for a verified stranger…'];
        }

        return ['ok' => true, 'status' => 'idle', 'message' => 'Enable camera and pass face check to start.'];
    }
}

if (!function_exists('roast_pvp_leave')) {
    function roast_pvp_leave(string $token): bool
    {
        $token = roast_pvp_normalize_token($token);
        $pdo = roast_pvp_pdo();
        if (!$pdo || $token === '') {
            return false;
        }
        $pdo->prepare(
            "UPDATE roast_pvp_queue SET status = 'cancelled' WHERE queue_token = ? AND status = 'waiting'"
        )->execute([$token]);
        $match = roast_pvp_get_by_token($token);
        if ($match && in_array($match['status'] ?? '', ['matched', 'active', 'dueling'], true)) {
            $pdo->prepare(
                "UPDATE roast_pvp_matches SET status = 'cancelled' WHERE match_id = ?"
            )->execute([(string) $match['match_id']]);
        }
        return true;
    }
}

if (!function_exists('roast_pvp_round_type')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_round_type(array $match): string
    {
        $modeA = (string) ($match['player_a_mode'] ?? '');
        $modeB = (string) ($match['player_b_mode'] ?? '');
        if ($modeA === 'live' && $modeB === 'live') {
            return 'dual_live';
        }
        if (($modeA === 'live') !== ($modeB === 'live')) {
            return 'mixed';
        }
        return 'photo';
    }
}

if (!function_exists('roast_pvp_sync_round_timer')) {
    function roast_pvp_sync_round_timer(string $matchId): void
    {
        $match = roast_pvp_get_match($matchId);
        if (!$match) {
            return;
        }
        $modeA = (string) ($match['player_a_mode'] ?? '');
        $modeB = (string) ($match['player_b_mode'] ?? '');
        if ($modeA === '' || $modeB === '') {
            return;
        }
        if (!empty($match['round_started_at'])) {
            return;
        }

        $roundType = roast_pvp_round_type($match);
        $sec = ROAST_PVP_ROUND_SEC;
        if ($roundType === 'dual_live') {
            $sec = ROAST_PVP_DUAL_LIVE_SEC;
        } elseif ($roundType === 'mixed') {
            $sec = ROAST_PVP_MIXED_SEC;
        }

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return;
        }
        $pdo->prepare(
            'UPDATE roast_pvp_matches
             SET expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                 round_started_at = NOW()
             WHERE match_id = ? AND round_started_at IS NULL'
        )->execute([$sec, $matchId]);
    }
}

if (!function_exists('roast_pvp_purge_presence')) {
    function roast_pvp_purge_presence(int $minutes = 3): void
    {
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return;
        }
        $pdo->prepare(
            'DELETE FROM roast_pvp_presence WHERE last_seen < DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        )->execute([$minutes]);
    }
}

if (!function_exists('roast_pvp_ping')) {
    function roast_pvp_ping(string $token): void
    {
        $token = roast_pvp_normalize_token($token);
        $pdo = roast_pvp_pdo();
        if (!$pdo || $token === '') {
            return;
        }
        roast_pvp_purge_presence();
        $pdo->prepare(
            'INSERT INTO roast_pvp_presence (token, last_seen) VALUES (?, NOW())
             ON DUPLICATE KEY UPDATE last_seen = NOW()'
        )->execute([$token]);
    }
}

if (!function_exists('roast_pvp_stats')) {
    /** @return array<string, mixed> */
    function roast_pvp_stats(): array
    {
        roast_pvp_purge_stale();
        roast_pvp_purge_presence();
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Stats unavailable.', true)];
        }

        $queue = (int) $pdo->query(
            "SELECT COUNT(*) FROM roast_pvp_queue WHERE status = 'waiting'"
        )->fetchColumn();

        $duels = (int) $pdo->query(
            "SELECT COUNT(*) FROM roast_pvp_matches WHERE status IN ('active','dueling')"
        )->fetchColumn();

        $presence = (int) $pdo->query(
            'SELECT COUNT(*) FROM roast_pvp_presence WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
        )->fetchColumn();

        $online = max($presence, $queue + ($duels * 2));

        return [
            'ok' => true,
            'online' => $online,
            'queue' => $queue,
            'duels' => $duels,
        ];
    }
}

if (!function_exists('roast_pvp_ensure_match_active')) {
    /** @param array<string, mixed>|null $match */
    function roast_pvp_ensure_match_active(?array $match): ?array
    {
        if (!$match) {
            return null;
        }
        if (($match['status'] ?? '') === 'matched') {
            roast_pvp_activate_match((string) ($match['match_id'] ?? ''));
            return roast_pvp_get_match((string) ($match['match_id'] ?? ''));
        }
        return $match;
    }
}

if (!function_exists('roast_pvp_set_mode')) {
    /** @return array<string, mixed> */
    function roast_pvp_set_mode(string $matchId, string $token, string $mode): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['photo', 'live'], true)) {
            return ['ok' => false, 'error' => roast_error('MODE', 'Mode must be photo or live.', false)];
        }
        if (!roast_pvp_validate_participant($matchId, $token)) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Not in this match.', false)];
        }

        $match = roast_pvp_get_match($matchId);
        $match = roast_pvp_ensure_match_active($match);
        if (!$match) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Match not found.', false)];
        }
        if (($match['status'] ?? '') === 'matched') {
            roast_pvp_activate_match($matchId);
            $match = roast_pvp_get_match($matchId);
        }
        if (!$match || ($match['status'] ?? '') !== 'active') {
            return ['ok' => false, 'error' => roast_error('PVP', 'Match not active.', false)];
        }

        $role = roast_pvp_role_for_token($match, $token);
        $col = $role === 'a' ? 'player_a_mode' : 'player_b_mode';
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Could not save mode.', true)];
        }

        $pdo->prepare("UPDATE roast_pvp_matches SET {$col} = ? WHERE match_id = ?")->execute([$mode, $matchId]);
        roast_pvp_sync_round_timer($matchId);
        $match = roast_pvp_get_match($matchId);
        return $match ? roast_pvp_build_status($match, $token) : ['ok' => true];
    }
}

if (!function_exists('roast_pvp_complete_live_job')) {
    function roast_pvp_complete_live_job(string $jobId, ?int $avgScore = null): ?array
    {
        if ($jobId === '') {
            return null;
        }
        $row = roast_jobs_get($jobId);
        if (!$row) {
            return null;
        }
        if (($row['status'] ?? '') === 'complete' && ($row['roast_text'] ?? '') !== ''
            && stripos((string) $row['roast_text'], 'choked') === false) {
            return roast_pvp_job_snapshot($jobId);
        }

        require_once dirname(__DIR__) . '/roast-limited/agents/agent4-roast.php';
        require_once __DIR__ . '/roast-score.php';

        $identity = json_decode((string) ($row['identity_json'] ?? '{}'), true) ?: [];
        $inspect = json_decode((string) ($row['inspect_json'] ?? '{}'), true) ?: [];
        $score = $avgScore ?? (isset($row['score']) ? (int) $row['score'] : roast_compute_shame_score($identity, $inspect));
        $a4 = roast_agent4_roast($identity, $inspect, $score);
        $roastText = $a4['ok'] ? trim((string) ($a4['text'] ?? '')) : '';
        $useFallback = $roastText === ''
            || stripos($roastText, 'choked') !== false
            || stripos($roastText, 'try again in a minute') !== false
            || ($a4['backend'] ?? '') === 'template_fallback';
        if ($useFallback) {
            $roastText = roast_pvp_live_end_roast($identity, $inspect, $score);
        }

        roast_jobs_update($jobId, [
            'status' => 'complete',
            'phase' => 'done',
            'roast_text' => $roastText,
            'score' => $score,
        ]);

        return roast_pvp_job_snapshot($jobId);
    }
}

if (!function_exists('roast_pvp_player_score')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_player_score(array $match, string $side): int
    {
        $mode = (string) ($match["player_{$side}_mode"] ?? '');
        if ($mode === 'live') {
            return roast_pvp_live_average($match, $side) ?? 0;
        }
        $jobId = (string) ($match["player_{$side}_job_id"] ?? '');
        if ($jobId === '') {
            return 0;
        }
        $row = roast_jobs_get($jobId);
        return $row ? (int) ($row['score'] ?? 0) : 0;
    }
}

if (!function_exists('roast_pvp_finalize_with_scores')) {
    /** @return array<string, mixed> */
    function roast_pvp_finalize_with_scores(string $matchId, int $scoreA, int $scoreB, string $jobA, string $jobB): array
    {
        $winner = 't';
        if ($scoreA > $scoreB) {
            $winner = 'a';
        } elseif ($scoreB > $scoreA) {
            $winner = 'b';
        }

        $pdo = roast_pvp_pdo();
        if ($pdo) {
            $pdo->prepare(
                'UPDATE roast_pvp_matches
                 SET status = ?, winner = ?, player_a_job_id = COALESCE(NULLIF(player_a_job_id, ""), ?),
                     player_b_job_id = COALESCE(NULLIF(player_b_job_id, ""), ?)
                 WHERE match_id = ?'
            )->execute(['complete', $winner, $jobA, $jobB, $matchId]);
        }

        return ['ok' => true, 'status' => 'complete', 'winner' => $winner];
    }
}

if (!function_exists('roast_pvp_finalize_live_duel')) {
    /** @return array<string, mixed> */
    function roast_pvp_finalize_live_duel(string $matchId): array
    {
        $match = roast_pvp_get_match($matchId);
        if (!$match) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Match not found.', false)];
        }

        $jobA = (string) ($match['player_a_live_job_id'] ?? '');
        $jobB = (string) ($match['player_b_live_job_id'] ?? '');
        if ($jobA !== '') {
            $avgA = roast_pvp_live_average($match, 'a') ?? 0;
            roast_jobs_update($jobA, ['score' => $avgA]);
            roast_pvp_complete_live_job($jobA, $avgA);
        }
        if ($jobB !== '') {
            $avgB = roast_pvp_live_average($match, 'b') ?? 0;
            roast_jobs_update($jobB, ['score' => $avgB]);
            roast_pvp_complete_live_job($jobB, $avgB);
        }

        $scoreA = roast_pvp_player_score($match, 'a');
        $scoreB = roast_pvp_player_score($match, 'b');
        return roast_pvp_finalize_with_scores($matchId, $scoreA, $scoreB, $jobA, $jobB);
    }
}

if (!function_exists('roast_pvp_finalize_mixed')) {
    /** @return array<string, mixed> */
    function roast_pvp_finalize_mixed(string $matchId): array
    {
        $match = roast_pvp_get_match($matchId);
        if (!$match) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Match not found.', false)];
        }

        $modeA = (string) ($match['player_a_mode'] ?? '');
        $modeB = (string) ($match['player_b_mode'] ?? '');
        $photoSide = $modeA === 'photo' ? 'a' : ($modeB === 'photo' ? 'b' : '');
        $liveSide = $modeA === 'live' ? 'a' : ($modeB === 'live' ? 'b' : '');

        if ($photoSide === '' || $liveSide === '') {
            return roast_pvp_finalize_match($matchId);
        }

        $photoJob = (string) ($match["player_{$photoSide}_job_id"] ?? '');
        if ($photoJob === '') {
            return ['ok' => true, 'status' => 'active'];
        }

        $liveJob = (string) ($match["player_{$liveSide}_live_job_id"] ?? '');
        if ($liveJob !== '') {
            $avgLive = roast_pvp_live_average($match, $liveSide) ?? 0;
            roast_jobs_update($liveJob, ['score' => $avgLive]);
            roast_pvp_complete_live_job($liveJob, $avgLive);
        }

        $match = roast_pvp_get_match($matchId) ?? $match;
        $scorePhoto = roast_pvp_player_score($match, $photoSide);
        $scoreLive = roast_pvp_player_score($match, $liveSide);

        $scoreA = $photoSide === 'a' ? $scorePhoto : $scoreLive;
        $scoreB = $photoSide === 'b' ? $scorePhoto : $scoreLive;
        $jobA = $photoSide === 'a' ? $photoJob : $liveJob;
        $jobB = $photoSide === 'b' ? $photoJob : $liveJob;

        return roast_pvp_finalize_with_scores($matchId, $scoreA, $scoreB, $jobA, $jobB);
    }
}

if (!function_exists('roast_pvp_score_frame')) {
    /** Quick live frame score — Agent 1 only (fast). Full roast runs at round end. */
    function roast_pvp_score_frame(string $imagePath): array
    {
        require_once __DIR__ . '/roast-cloud-vision.php';
        require_once __DIR__ . '/roast-score.php';
        require_once dirname(__DIR__) . '/roast-limited/agents/agent1-identify.php';

        try {
            $a1 = roast_agent1_identify($imagePath, []);
        } catch (Throwable $e) {
            error_log('[Roast PvP] score_frame identify threw: ' . $e->getMessage());
            $a1 = ['ok' => false];
        }
        if (!$a1['ok']) {
            $identity = [
                'make' => 'Unknown',
                'model' => 'Unknown',
                'confidence' => 0.15,
                'visible_subject' => 'unclear',
                'is_complete_ebike' => false,
                'source' => 'live_frame_fallback',
            ];
        } else {
            $identity = $a1['data'];
        }
        $inspect = [
            'frame_visible' => ($identity['visible_subject'] ?? '') !== 'parts_only',
            'visual_mods' => [],
            'missing_parts' => [],
            'condition_notes' => 'live_frame',
        ];
        $score = roast_compute_shame_score($identity, $inspect);
        return [
            'ok' => true,
            'score' => $score,
            'identity' => $identity,
            'inspect' => $inspect,
        ];
    }
}

if (!function_exists('roast_pvp_live_frame')) {
    /** @return array<string, mixed> */
    function roast_pvp_live_frame(string $matchId, string $token, array $file): array
    {
        require_once __DIR__ . '/roast-cloud-vision.php';
        require_once __DIR__ . '/roast-score.php';

        if (!roast_pvp_validate_participant($matchId, $token)) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Not in this match.', false)];
        }

        $match = roast_pvp_get_match($matchId);
        $match = roast_pvp_check_expiry($match);
        $match = roast_pvp_ensure_match_active($match);
        if (!$match) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Match not found.', false)];
        }

        $role = roast_pvp_role_for_token($match, $token);
        if ($role === '') {
            return ['ok' => false, 'error' => roast_error('PVP', 'Not in this match.', false)];
        }
        $modeCol = $role === 'a' ? 'player_a_mode' : 'player_b_mode';
        $yourMode = (string) ($match[$modeCol] ?? '');
        if ($yourMode !== 'live') {
            $pdoFix = roast_pvp_pdo();
            if ($pdoFix) {
                $pdoFix->prepare("UPDATE roast_pvp_matches SET {$modeCol} = 'live' WHERE match_id = ?")
                    ->execute([$matchId]);
                roast_pvp_sync_round_timer($matchId);
                $match = roast_pvp_get_match($matchId) ?? $match;
                $yourMode = 'live';
            }
        }
        $status = (string) ($match['status'] ?? '');
        $statusOk = in_array($status, ['active', 'matched', 'dueling'], true);
        if (!$statusOk) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Match not active (' . $status . ').', false)];
        }
        if ($yourMode !== 'live') {
            return ['ok' => false, 'error' => roast_error('MODE', 'Live frames only in live mode.', false)];
        }

        $atCol = $role === 'a' ? 'player_a_live_at' : 'player_b_live_at';
        $lastAt = $match[$atCol] ?? null;
        if ($lastAt) {
            $elapsed = time() - strtotime((string) $lastAt);
            if ($elapsed >= 0 && $elapsed < 9) {
                return ['ok' => false, 'error' => roast_error('RATE', 'Wait a few seconds between live frames.', false)];
            }
        }

        try {
            $saved = roast_save_uploaded_image($file);
            if (!$saved['ok']) {
                return ['ok' => false, 'error' => roast_error('IMAGE', $saved['error'] ?? 'Frame upload failed.', false)];
            }
            $imagePath = (string) $saved['path'];
            $scored = roast_pvp_score_frame($imagePath);
            roast_delete_image($imagePath);
            if (!$scored['ok']) {
                return $scored;
            }

            $score = (int) ($scored['score'] ?? 0);
            $sumCol = $role === 'a' ? 'player_a_live_sum' : 'player_b_live_sum';
            $countCol = $role === 'a' ? 'player_a_live_count' : 'player_b_live_count';
            $bestCol = $role === 'a' ? 'player_a_live_best' : 'player_b_live_best';
            $jobCol = $role === 'a' ? 'player_a_live_job_id' : 'player_b_live_job_id';
            $currentSum = (int) ($match[$sumCol] ?? 0);
            $currentCount = (int) ($match[$countCol] ?? 0);
            $newSum = $currentSum + $score;
            $newCount = $currentCount + 1;
            $newAvg = (int) round($newSum / $newCount);

            $pdo = roast_pvp_pdo();
            if (!$pdo) {
                return ['ok' => false, 'error' => roast_error('DB', 'Could not save score.', true)];
            }

            $jobId = trim((string) ($match[$jobCol] ?? ''));
            try {
                if ($jobId === '') {
                    $jobId = roast_jobs_new_id();
                    $ipHash = roast_ip_hash();
                    roast_jobs_create($jobId, $ipHash, (string) ($saved['hash'] ?? roast_jobs_new_id()));
                }
                if ($jobId !== '') {
                    roast_jobs_update($jobId, [
                        'status' => 'partial',
                        'phase' => 'live',
                        'identity_json' => $scored['identity'] ?? [],
                        'inspect_json' => $scored['inspect'] ?? [],
                        'score' => $newAvg,
                    ]);
                }
            } catch (Throwable $jobErr) {
                error_log('[Roast PvP] live_frame job save skipped: ' . $jobErr->getMessage());
                $jobId = trim((string) ($match[$jobCol] ?? ''));
            }

            $pdo->prepare(
                "UPDATE roast_pvp_matches
                 SET {$sumCol} = ?, {$countCol} = ?, {$bestCol} = ?, {$jobCol} = ?, {$atCol} = NOW()
                 WHERE match_id = ?"
            )->execute([$newSum, $newCount, $newAvg, $jobId !== '' ? $jobId : null, $matchId]);

            $match = roast_pvp_get_match($matchId);
            $payload = $match ? roast_pvp_build_status($match, $token) : ['ok' => true];
            $payload['frame_score'] = $score;
            $payload['your_average'] = $newAvg;
            $payload['frame_count'] = $newCount;
            $payload['your_best'] = $newAvg;
            return $payload;
        } catch (Throwable $e) {
            error_log('[Roast PvP] live_frame failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => roast_error('LIVE', 'Live frame failed.', true)];
        }
    }
}

if (!function_exists('roast_pvp_http_request')) {
    /** @return array{ok: bool, body: string|false, http_code: int, error: string|null} */
    function roast_pvp_http_request(string $method, string $url, ?array $jsonBody = null, int $timeout = 12): array
    {
        if (function_exists('curl_init')) {
            $attempts = [0];
            if (defined('CURL_IPRESOLVE_V4')) {
                $attempts[] = CURL_IPRESOLVE_V4;
            }
            foreach ($attempts as $ipResolve) {
                $headers = ['Accept: application/json'];
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERAGENT => 'OnlyBikes-RoastPvP/1.0',
                ];
                if ($ipResolve !== 0) {
                    $opts[CURLOPT_IPRESOLVE] = $ipResolve;
                }
                if (strtoupper($method) === 'POST') {
                    $opts[CURLOPT_POST] = true;
                    $payload = json_encode($jsonBody ?? new stdClass(), JSON_UNESCAPED_SLASHES);
                    $headers[] = 'Content-Type: application/json';
                    $opts[CURLOPT_HTTPHEADER] = $headers;
                    $opts[CURLOPT_POSTFIELDS] = $payload;
                }
                $ch = curl_init($url);
                curl_setopt_array($ch, $opts);
                $body = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);
                $resp = [
                    'ok' => $body !== false && $httpCode >= 200 && $httpCode < 300,
                    'body' => $body === false ? false : (string) $body,
                    'http_code' => $httpCode,
                    'error' => $curlErr !== '' ? $curlErr : null,
                ];
                if ($resp['ok'] || $resp['http_code'] > 0) {
                    return $resp;
                }
            }
            return $resp ?? ['ok' => false, 'body' => false, 'http_code' => 0, 'error' => 'curl_failed'];
        }

        if (strtoupper($method) !== 'GET') {
            return ['ok' => false, 'body' => false, 'http_code' => 0, 'error' => 'curl_required_for_post'];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => "Accept: application/json\r\nUser-Agent: OnlyBikes-RoastPvP/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $httpCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', (string) $http_response_header[0], $m)) {
            $httpCode = (int) $m[0];
        }
        return [
            'ok' => $body !== false && $httpCode >= 200 && $httpCode < 300,
            'body' => $body === false ? false : (string) $body,
            'http_code' => $httpCode,
            'error' => $body === false ? 'http_get_failed' : null,
        ];
    }
}

if (!function_exists('roast_pvp_http_get')) {
    /** @return array{ok: bool, body: string|false, http_code: int, error: string|null} */
    function roast_pvp_http_get(string $url, int $timeout = 12): array
    {
        return roast_pvp_http_request('GET', $url, null, $timeout);
    }
}

if (!function_exists('roast_pvp_turn_diag_store')) {
    /** @return array<string, mixed> */
    function &roast_pvp_turn_diag_store(): array
    {
        static $diag = [
            'key_set' => false,
            'app' => '',
            'status' => 'missing_key',
            'detail' => '',
            'last_http_code' => 0,
        ];
        return $diag;
    }

    /** @return array<string, mixed> */
    function roast_pvp_turn_diag(): array
    {
        return roast_pvp_turn_diag_store();
    }

    function roast_pvp_patch_turn_diag(array $patch): void
    {
        $diag = &roast_pvp_turn_diag_store();
        foreach ($patch as $k => $v) {
            $diag[$k] = $v;
        }
    }
}

if (!function_exists('roast_pvp_reset_turn_diag')) {
    function roast_pvp_reset_turn_diag(): void
    {
        $keySet = trim((string) roast_env('PVP_TURN_API_KEY', '')) !== ''
            || trim((string) roast_env('PVP_TURN_SECRET_KEY', '')) !== '';
        roast_pvp_patch_turn_diag([
            'key_set' => $keySet,
            'app' => trim((string) roast_env('PVP_TURN_APP', '')),
            'status' => $keySet ? 'pending' : 'missing_key',
            'detail' => '',
            'last_http_code' => 0,
        ]);
    }
}

if (!function_exists('roast_pvp_turn_setup_hint')) {
    function roast_pvp_turn_setup_hint(): string
    {
        $diag = roast_pvp_turn_diag();
        $status = (string) ($diag['status'] ?? 'missing_key');
        if ($status === 'missing_key') {
            return 'Add PVP_TURN_SECRET_KEY (Developers tab) or PVP_TURN_API_KEY (TURN credential Show API Key) to htdocs/api/.env on the server.';
        }
        if ($status === 'invalid_key') {
            return 'Metered rejected the key. Use Developers → Secret Key as PVP_TURN_SECRET_KEY, or TURN → Add Credential → Show API Key as PVP_TURN_API_KEY.';
        }
        if ($status === 'invalid_secret') {
            return 'PVP_TURN_SECRET_KEY rejected — copy Secret Key from Metered Developers tab (onlybikes.metered.live).';
        }
        if ($status === 'fetch_failed') {
            $code = (int) ($diag['last_http_code'] ?? 0);
            $detail = (string) ($diag['detail'] ?? '');
            if ($code === 0) {
                return 'Ionos cannot reach metered.live (HTTP 0). Enable PHP curl on hosting or contact Ionos re outbound HTTPS.';
            }
            return 'TURN credentials fetch failed (HTTP ' . $code . '). ' . $detail;
        }
        if ($status === 'no_servers') {
            return 'Metered returned empty iceServers — verify PVP_TURN_APP matches your subdomain (onlybikes.metered.live → onlybikes).';
        }
        return 'TURN not active — check api/.env on server and purge Cloudflare cache after upload.';
    }
}

if (!function_exists('roast_pvp_fetch_turn_api')) {
    /** @return list<array<string, mixed>>|null */
    function roast_pvp_fetch_turn_api(string $credentialsUrl, string $cacheKey): ?array
    {
        $cacheDir = trim((string) roast_env('ROAST_TMP_DIR', ''));
        if ($cacheDir === '') {
            $cacheDir = sys_get_temp_dir();
        }
        $cacheFile = rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'roast_pvp_turn_' . preg_replace('/[^a-z0-9_-]/i', '_', $cacheKey) . '.json';
        if (is_readable($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && (int) ($cached['expires'] ?? 0) > time() && is_array($cached['servers'] ?? null)) {
                return $cached['servers'];
            }
        }

        $resp = roast_pvp_http_get($credentialsUrl, 12);
        roast_pvp_patch_turn_diag(['last_http_code' => $resp['http_code']]);

        if (!$resp['ok'] || $resp['body'] === false || $resp['body'] === '') {
            $decodedErr = is_string($resp['body']) ? json_decode($resp['body'], true) : null;
            $errMsg = is_array($decodedErr) ? (string) ($decodedErr['error'] ?? $decodedErr['message'] ?? '') : '';
            if (stripos($errMsg, 'invalid') !== false && stripos($errMsg, 'key') !== false) {
                roast_pvp_patch_turn_diag(['status' => 'invalid_key', 'detail' => $errMsg]);
            } else {
                roast_pvp_patch_turn_diag([
                    'status' => 'fetch_failed',
                    'detail' => $resp['error'] ?: $errMsg ?: 'http_' . $resp['http_code'],
                ]);
            }
            error_log('[Roast PvP] TURN API fetch failed HTTP ' . $resp['http_code'] . ': ' . $credentialsUrl);
            return null;
        }
        $raw = $resp['body'];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            roast_pvp_patch_turn_diag(['status' => 'fetch_failed', 'detail' => 'invalid_json']);
            error_log('[Roast PvP] TURN API returned invalid JSON: ' . $credentialsUrl);
            return null;
        }

        $servers = [];
        if (isset($decoded[0]) && is_array($decoded[0])) {
            $servers = $decoded;
        } elseif (isset($decoded['iceServers']) && is_array($decoded['iceServers'])) {
            $servers = $decoded['iceServers'];
        }
        if ($servers === []) {
            roast_pvp_patch_turn_diag(['status' => 'no_servers', 'detail' => 'empty_ice']);
            error_log('[Roast PvP] TURN API returned no iceServers: ' . $credentialsUrl);
            return null;
        }

        roast_pvp_patch_turn_diag(['status' => 'ok', 'detail' => '', 'provision' => 'credential_api']);

        @file_put_contents($cacheFile, json_encode([
            'expires' => time() + 1800,
            'servers' => $servers,
        ], JSON_UNESCAPED_SLASHES));

        return $servers;
    }
}

if (!function_exists('roast_pvp_metered_turn_cache_file')) {
    function roast_pvp_metered_turn_cache_file(string $suffix): string
    {
        $cacheDir = trim((string) roast_env('ROAST_TMP_DIR', ''));
        if ($cacheDir === '') {
            $cacheDir = sys_get_temp_dir();
        }
        return rtrim($cacheDir, '/\\') . DIRECTORY_SEPARATOR . 'roast_pvp_turn_' . preg_replace('/[^a-z0-9_-]/i', '_', $suffix) . '.json';
    }
}

if (!function_exists('roast_pvp_metered_ice_from_username')) {
    /** @return list<array<string, mixed>> */
    function roast_pvp_metered_ice_from_username(string $app, string $username, string $password): array
    {
        $host = $app . '.metered.live';
        return [
            ['urls' => 'stun:' . $host . ':80'],
            ['urls' => 'turn:' . $host . ':80', 'username' => $username, 'credential' => $password],
            ['urls' => 'turn:' . $host . ':80?transport=tcp', 'username' => $username, 'credential' => $password],
            ['urls' => 'turn:' . $host . ':443', 'username' => $username, 'credential' => $password],
            ['urls' => 'turn:' . $host . ':443?transport=tcp', 'username' => $username, 'credential' => $password],
        ];
    }
}

if (!function_exists('roast_pvp_metered_provision_secret')) {
    /** @return array{username: string, password: string, apiKey: string}|null */
    function roast_pvp_metered_provision_secret(string $app, string $secretKey): ?array
    {
        $cacheFile = roast_pvp_metered_turn_cache_file('secret_' . $app);
        if (is_readable($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && (int) ($cached['expires'] ?? 0) > time() && is_array($cached['credential'] ?? null)) {
                return $cached['credential'];
            }
        }

        $url = 'https://' . $app . '.metered.live/api/v1/turn/credential?secretKey=' . rawurlencode($secretKey);
        $resp = roast_pvp_http_request('POST', $url, [
            'expiryInSeconds' => 86400,
            'label' => 'roast-pvp',
        ], 15);
        roast_pvp_patch_turn_diag(['last_http_code' => $resp['http_code']]);

        if (!$resp['ok'] || $resp['body'] === false || $resp['body'] === '') {
            $decodedErr = is_string($resp['body']) ? json_decode($resp['body'], true) : null;
            $errMsg = is_array($decodedErr) ? (string) ($decodedErr['error'] ?? $decodedErr['message'] ?? '') : '';
            if (stripos($errMsg, 'invalid') !== false || stripos($errMsg, 'secret') !== false) {
                roast_pvp_patch_turn_diag(['status' => 'invalid_secret', 'detail' => $errMsg]);
            } else {
                roast_pvp_patch_turn_diag([
                    'status' => 'fetch_failed',
                    'detail' => $resp['error'] ?: $errMsg ?: 'provision_http_' . $resp['http_code'],
                ]);
            }
            error_log('[Roast PvP] TURN secret provision failed HTTP ' . $resp['http_code']);
            return null;
        }

        $decoded = json_decode($resp['body'], true);
        if (!is_array($decoded)) {
            roast_pvp_patch_turn_diag(['status' => 'fetch_failed', 'detail' => 'provision_invalid_json']);
            return null;
        }

        $username = trim((string) ($decoded['username'] ?? ''));
        $password = trim((string) ($decoded['password'] ?? ''));
        $apiKey = trim((string) ($decoded['apiKey'] ?? ''));
        if ($username === '' || $password === '') {
            roast_pvp_patch_turn_diag(['status' => 'fetch_failed', 'detail' => 'provision_missing_fields']);
            return null;
        }

        $credential = ['username' => $username, 'password' => $password, 'apiKey' => $apiKey];
        @file_put_contents($cacheFile, json_encode([
            'expires' => time() + 3600,
            'credential' => $credential,
        ], JSON_UNESCAPED_SLASHES));

        return $credential;
    }
}

if (!function_exists('roast_pvp_metered_ice_servers')) {
    /** @return list<array<string, mixed>>|null */
    function roast_pvp_metered_ice_servers(): ?array
    {
        $app = trim((string) roast_env('PVP_TURN_APP', ''));
        $credentialApiKey = trim((string) roast_env('PVP_TURN_API_KEY', ''));
        $secretKey = trim((string) roast_env('PVP_TURN_SECRET_KEY', ''));

        if ($app === '' || !preg_match('/^[a-z0-9-]+$/i', $app)) {
            return null;
        }

        // 1) Credential API key (TURN dashboard → Show API Key on a credential)
        if ($credentialApiKey !== '') {
            $url = 'https://' . $app . '.metered.live/api/v1/turn/credentials?apiKey=' . rawurlencode($credentialApiKey);
            $servers = roast_pvp_fetch_turn_api($url, 'metered_' . $app);
            if ($servers !== null) {
                roast_pvp_patch_turn_diag(['provision' => 'credential_api']);
                return $servers;
            }
        }

        // 2) Developers Secret Key — auto-create short-lived credential (no manual credential needed)
        $secret = $secretKey !== '' ? $secretKey : $credentialApiKey;
        if ($secret !== '') {
            $provisioned = roast_pvp_metered_provision_secret($app, $secret);
            if ($provisioned !== null) {
                if ($provisioned['apiKey'] !== '') {
                    $credUrl = 'https://' . $app . '.metered.live/api/v1/turn/credentials?apiKey=' . rawurlencode($provisioned['apiKey']);
                    $servers = roast_pvp_fetch_turn_api($credUrl, 'metered_secret_' . $app);
                    if ($servers !== null) {
                        roast_pvp_patch_turn_diag(['provision' => 'secret_api', 'status' => 'ok']);
                        return $servers;
                    }
                }
                roast_pvp_patch_turn_diag(['provision' => 'secret_username', 'status' => 'ok']);
                return roast_pvp_metered_ice_from_username($app, $provisioned['username'], $provisioned['password']);
            }
        }

        // 3) Open Relay fallback — only without a Metered app subdomain
        if ($credentialApiKey !== '' && $app === '') {
            $openRelayUrl = 'https://openrelayproject.metered.ca/api/v1/turn/credentials?apiKey=' . rawurlencode($credentialApiKey);
            return roast_pvp_fetch_turn_api($openRelayUrl, 'openrelay');
        }

        return null;
    }
}

if (!function_exists('roast_pvp_ice_bundle')) {
    /** @return array{iceServers: list<array<string, mixed>>, turn_source: string, turn_configured: bool, turn_warning: string, turn_key_set: bool, turn_status: string} */
    function roast_pvp_ice_bundle(): array
    {
        roast_pvp_reset_turn_diag();

        $servers = [
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun1.l.google.com:19302'],
            ['urls' => 'stun:stun.cloudflare.com:3478'],
        ];

        $user = trim((string) roast_env('PVP_TURN_USERNAME', ''));
        $cred = trim((string) roast_env('PVP_TURN_CREDENTIAL', ''));
        $urlsRaw = trim((string) roast_env('PVP_TURN_URLS', ''));

        if ($user !== '' && $cred !== '' && $urlsRaw !== '') {
            $urls = [];
            foreach (preg_split('/\s*,\s*/', $urlsRaw) ?: [] as $url) {
                $url = trim($url);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
            if ($urls !== []) {
                $servers[] = [
                    'urls' => count($urls) === 1 ? $urls[0] : $urls,
                    'username' => $user,
                    'credential' => $cred,
                ];
                roast_pvp_patch_turn_diag(['provision' => 'static_env', 'status' => 'ok']);
                return [
                    'iceServers' => $servers,
                    'turn_source' => 'static_env',
                    'turn_configured' => true,
                    'turn_warning' => '',
                    'turn_key_set' => true,
                    'turn_status' => 'ok',
                ];
            }
        }

        $metered = roast_pvp_metered_ice_servers();
        if ($metered !== null) {
            $diag = roast_pvp_turn_diag();
            $provision = (string) ($diag['provision'] ?? '');
            if ($provision === 'secret_api' || $provision === 'secret_username') {
                $source = 'metered_secret';
            } elseif ($provision === 'credential_api') {
                $source = 'metered_api';
            } else {
                $source = trim((string) roast_env('PVP_TURN_APP', '')) !== '' ? 'metered_api' : 'openrelay_api';
            }
            return [
                'iceServers' => array_merge($servers, $metered),
                'turn_source' => $source,
                'turn_configured' => true,
                'turn_warning' => '',
                'turn_key_set' => true,
                'turn_status' => 'ok',
            ];
        }

        $diag = roast_pvp_turn_diag();
        return [
            'iceServers' => $servers,
            'turn_source' => 'stun_only',
            'turn_configured' => false,
            'turn_warning' => roast_pvp_turn_setup_hint(),
            'turn_key_set' => (bool) ($diag['key_set'] ?? false),
            'turn_status' => (string) ($diag['status'] ?? 'missing_key'),
        ];
    }
}

if (!function_exists('roast_pvp_ice_servers')) {
    /** @return list<array<string, mixed>> */
    function roast_pvp_ice_servers(): array
    {
        return roast_pvp_ice_bundle()['iceServers'];
    }
}

if (!function_exists('roast_pvp_clear_signals')) {
    function roast_pvp_clear_signals(string $matchId, string $token): bool
    {
        if (!roast_pvp_validate_participant($matchId, $token)) {
            return false;
        }
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return false;
        }
        $stmt = $pdo->prepare('DELETE FROM roast_pvp_signals WHERE match_id = ?');
        return $stmt->execute([$matchId]);
    }
}

if (!function_exists('roast_pvp_signal_stats')) {
    /** @return array<string, mixed> */
    function roast_pvp_signal_stats(string $matchId, string $token): array
    {
        if (!roast_pvp_validate_participant($matchId, $token)) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Not in match.', false)];
        }
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Unavailable.', true)];
        }
        $stmt = $pdo->prepare(
            'SELECT signal_type, COUNT(*) AS cnt FROM roast_pvp_signals WHERE match_id = ? GROUP BY signal_type'
        );
        $stmt->execute([$matchId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $counts = ['offer' => 0, 'answer' => 0, 'ice' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $type = (string) ($row['signal_type'] ?? '');
            $cnt = (int) ($row['cnt'] ?? 0);
            if (isset($counts[$type])) {
                $counts[$type] = $cnt;
            }
            $counts['total'] += $cnt;
        }
        return ['ok' => true, 'counts' => $counts];
    }
}

if (!function_exists('roast_pvp_verify_turnstile')) {
    /** @return array<string, mixed> */
    function roast_pvp_verify_turnstile(string $responseToken): array
    {
        $secret = roast_env('TURNSTILE_SECRET_KEY', '');
        if ($secret === '') {
            return ['ok' => false, 'error' => roast_error('TURNSTILE', 'Cloudflare Turnstile is not configured.', false)];
        }
        if ($responseToken === '') {
            return ['ok' => false, 'error' => roast_error('TURNSTILE', 'Missing verification token.', false)];
        }

        $remoteIp = '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $remoteIp = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $remoteIp = trim((string) $_SERVER['REMOTE_ADDR']);
        }

        $payload = http_build_query([
            'secret' => $secret,
            'response' => $responseToken,
            'remoteip' => $remoteIp,
        ]);

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$raw) {
            return ['ok' => false, 'error' => roast_error('TURNSTILE', 'Cloudflare verification unavailable.', true)];
        }

        $result = json_decode((string) $raw, true);
        if (!is_array($result) || empty($result['success'])) {
            return ['ok' => false, 'error' => roast_error('TURNSTILE', 'Cloudflare check failed. Try again.', false)];
        }

        return ['ok' => true, 'verified' => true];
    }
}
