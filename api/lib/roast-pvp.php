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

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS roast_pvp_npc_bag (
  scope_key VARCHAR(80) NOT NULL,
  pool_fp CHAR(64) NOT NULL,
  deck_json MEDIUMTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (scope_key)
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
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_left_at TIMESTAMP NULL AFTER round_started_at',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_left_at TIMESTAMP NULL AFTER player_a_left_at',
            'ALTER TABLE roast_pvp_matches ADD COLUMN opponent_npc_id VARCHAR(64) NULL AFTER player_b_left_at',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_live_seed INT UNSIGNED NULL AFTER opponent_npc_id',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_live_seed INT UNSIGNED NULL AFTER player_a_live_seed',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_score_tier TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_b_live_seed',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_score_tier TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_a_score_tier',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_display_score INT UNSIGNED NULL AFTER player_b_score_tier',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_display_score INT UNSIGNED NULL AFTER player_a_display_score',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_tier2_pending TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_b_display_score',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_tier2_pending TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_a_tier2_pending',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_tier2_frame_path VARCHAR(255) NULL AFTER player_b_tier2_pending',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_tier2_frame_path VARCHAR(255) NULL AFTER player_a_tier2_frame_path',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_tier2_requested_at TIMESTAMP NULL AFTER player_b_tier2_frame_path',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_tier2_requested_at TIMESTAMP NULL AFTER player_a_tier2_requested_at',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_a_tier2_ready TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_b_tier2_requested_at',
            'ALTER TABLE roast_pvp_matches ADD COLUMN player_b_tier2_ready TINYINT UNSIGNED NULL DEFAULT 0 AFTER player_a_tier2_ready',
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
            if ($existing && in_array($existing['status'] ?? '', ['complete', 'expired', 'cancelled'], true)) {
                $existing = null;
            }
            if ($existing && in_array($existing['status'] ?? '', ['matched', 'active', 'dueling'], true)) {
                $pdo->commit();
                return array_merge(roast_pvp_build_status($existing, $token), [
                    'fresh_entry' => false,
                    'billing_reference' => (string) ($existing['match_id'] ?? ''),
                ]);
            }

            $queueCheck = $pdo->prepare(
                "SELECT status, created_at FROM roast_pvp_queue WHERE queue_token = ? LIMIT 1"
            );
            $queueCheck->execute([$token]);
            $queueRow = $queueCheck->fetch(PDO::FETCH_ASSOC);
            $wasWaiting = is_array($queueRow) && ($queueRow['status'] ?? '') === 'waiting';

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
                return array_merge(
                    roast_pvp_build_status($match ?: ['match_id' => $matchId, 'status' => 'active'], $token),
                    [
                        'fresh_entry' => true,
                        'billing_reference' => $matchId,
                    ]
                );
            }

            $pdo->prepare(
                'INSERT INTO roast_pvp_queue (queue_token, face_hash, status) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE face_hash = VALUES(face_hash),
                   status = IF(status IN ("cancelled", "matched"), "waiting", status),
                   match_id = IF(status IN ("cancelled", "matched"), NULL, match_id),
                   created_at = IF(status IN ("cancelled", "matched"), CURRENT_TIMESTAMP, created_at)'
            )->execute([$token, $faceHash, 'waiting']);

            $pdo->commit();

            $queueAfter = $pdo->prepare(
                'SELECT created_at FROM roast_pvp_queue WHERE queue_token = ? LIMIT 1'
            );
            $queueAfter->execute([$token]);
            $queueAfterRow = $queueAfter->fetch(PDO::FETCH_ASSOC);
            $createdTs = is_array($queueAfterRow)
                ? strtotime((string) ($queueAfterRow['created_at'] ?? ''))
                : false;
            $billingReference = 'pvp:' . $token . ':' . ($createdTs !== false ? $createdTs : time());

            return [
                'ok' => true,
                'status' => 'waiting',
                'message' => 'Looking for a verified stranger…',
                'fresh_entry' => !$wasWaiting,
                'billing_reference' => $billingReference,
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
        $token = roast_pvp_normalize_token($token);
        if ($token === '') {
            return '';
        }
        if (roast_pvp_normalize_token((string) ($match['player_a_token'] ?? '')) === $token) {
            return 'a';
        }
        if (roast_pvp_normalize_token((string) ($match['player_b_token'] ?? '')) === $token) {
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
        if (($match['status'] ?? '') === 'complete') {
            return ['ok' => true, 'status' => 'complete', 'match' => $match];
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
                'UPDATE roast_pvp_matches SET status = ?, winner = ? WHERE match_id = ? AND status <> ?'
            )->execute(['complete', $winner, $matchId, 'complete']);
        }

        roast_log_pvp_metric('finalize', [
            'match_id' => $matchId,
            'score_a' => $scoreA,
            'score_b' => $scoreB,
            'winner' => $winner,
            'mode' => 'photo',
        ]);

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
        if ($count <= 0) {
            return null;
        }
        $sum = (int) ($match["player_{$side}_live_sum"] ?? 0);

        return (int) round($sum / $count);
    }
}

if (!function_exists('roast_pvp_live_frame_count')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_live_frame_count(array $match, string $side): int
    {
        return max(0, (int) ($match["player_{$side}_live_count"] ?? 0));
    }
}

if (!function_exists('roast_pvp_side_score_tier')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_side_score_tier(array $match, string $side): int
    {
        return max(0, min(2, (int) ($match["player_{$side}_score_tier"] ?? 0)));
    }
}

if (!function_exists('roast_pvp_merge_tier_score')) {
    /**
     * Monotonic human display merge — higher tier wins; same-tier needs +3pt to move up.
     *
     * @return array{display_score: int, score_tier: int, provisional: bool}
     */
    function roast_pvp_merge_tier_score(
        int $lastDisplay,
        int $lastTier,
        int $newScore,
        int $newTier
    ): array {
        $lastDisplay = max(0, min(100, $lastDisplay));
        $newScore = max(0, min(100, $newScore));
        $lastTier = max(0, min(2, $lastTier));
        $newTier = max(0, min(2, $newTier));

        if ($newTier > $lastTier) {
            return [
                'display_score' => $newScore,
                'score_tier' => $newTier,
                'provisional' => $newTier < 2,
            ];
        }
        if ($newTier < $lastTier) {
            return [
                'display_score' => $lastDisplay,
                'score_tier' => $lastTier,
                'provisional' => $lastTier < 2,
            ];
        }

        if ($newScore >= $lastDisplay + 3) {
            return [
                'display_score' => max($lastDisplay, $newScore),
                'score_tier' => $newTier,
                'provisional' => $newTier < 2,
            ];
        }

        return [
            'display_score' => $lastDisplay > 0 ? $lastDisplay : $newScore,
            'score_tier' => $newTier,
            'provisional' => $newTier < 2,
        ];
    }
}

if (!function_exists('roast_pvp_side_has_live_score')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_side_has_live_score(array $match, string $side): bool
    {
        if ((int) ($match["player_{$side}_live_seed"] ?? 0) > 0) {
            return true;
        }
        if ((int) ($match["player_{$side}_display_score"] ?? 0) > 0) {
            return true;
        }

        return roast_pvp_live_frame_count($match, $side) > 0;
    }
}

if (!function_exists('roast_pvp_player_live_display_score')) {
    /**
     * Human live score for UI — uses monotonic display_score, then average, then Tier 0 seed.
     *
     * @param array<string, mixed> $match
     */
    function roast_pvp_player_live_display_score(array $match, string $side): ?int
    {
        $display = (int) ($match["player_{$side}_display_score"] ?? 0);
        if ($display > 0) {
            return $display;
        }

        if (roast_pvp_live_frame_count($match, $side) > 0) {
            return roast_pvp_live_average($match, $side);
        }

        $seed = (int) ($match["player_{$side}_live_seed"] ?? 0);

        return $seed > 0 ? $seed : null;
    }
}

if (!function_exists('roast_pvp_identity_provisional_for_match')) {
    /**
     * @param array<string, mixed> $match
     * @param array<string, mixed>|null $identity
     */
    function roast_pvp_identity_provisional_for_match(array $match, string $side, ?array $identity = null): bool
    {
        $tier = roast_pvp_side_score_tier($match, $side);
        if ($tier >= 2) {
            return false;
        }
        if ($identity !== null && $identity !== []) {
            require_once __DIR__ . '/roast-score.php';

            return roast_pvp_identity_is_provisional($identity);
        }

        return $tier < 2;
    }
}

if (!function_exists('roast_pvp_apply_tier0_seed_score')) {
    /**
     * Tier 0 instant seed — does not increment live_count (split from Tier 1 frames).
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     * @return array<string, mixed>
     */
    function roast_pvp_apply_tier0_seed_score(
        string $matchId,
        string $role,
        int $score,
        array $identity,
        array $inspect,
        ?string $imageHash = null
    ): array {
        if ($role !== 'a' && $role !== 'b') {
            return ['ok' => false, 'error' => roast_error('PVP', 'Invalid role.', false)];
        }

        $seedCol = $role === 'a' ? 'player_a_live_seed' : 'player_b_live_seed';
        $tierCol = $role === 'a' ? 'player_a_score_tier' : 'player_b_score_tier';
        $displayCol = $role === 'a' ? 'player_a_display_score' : 'player_b_display_score';
        $bestCol = $role === 'a' ? 'player_a_live_best' : 'player_b_live_best';
        $jobCol = $role === 'a' ? 'player_a_live_job_id' : 'player_b_live_job_id';
        $atCol = $role === 'a' ? 'player_a_live_at' : 'player_b_live_at';
        $modeCol = $role === 'a' ? 'player_a_mode' : 'player_b_mode';

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Could not save score.', true)];
        }

        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare(
                "SELECT {$seedCol} AS live_seed, {$tierCol} AS score_tier, {$displayCol} AS display_score,
                        {$jobCol} AS live_job_id, {$modeCol} AS player_mode
                 FROM roast_pvp_matches WHERE match_id = ? LIMIT 1 FOR UPDATE"
            );
            $lock->execute([$matchId]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($locked)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => roast_error('PVP', 'Match not found.', false)];
            }

            if ((int) ($locked['live_seed'] ?? 0) > 0) {
                $pdo->commit();
                return ['ok' => true, 'skipped' => true, 'reason' => 'already_seeded'];
            }

            $lastDisplay = (int) ($locked['display_score'] ?? 0);
            $lastTier = (int) ($locked['score_tier'] ?? 0);
            $merged = roast_pvp_merge_tier_score($lastDisplay, $lastTier, $score, 0);

            $jobId = trim((string) ($locked['live_job_id'] ?? ''));
            try {
                if ($jobId === '') {
                    $jobId = roast_jobs_new_id();
                    $ipHash = roast_ip_hash();
                    roast_jobs_create($jobId, $ipHash, $imageHash !== null && $imageHash !== '' ? $imageHash : roast_jobs_new_id());
                }
                if ($jobId !== '') {
                    roast_jobs_update($jobId, [
                        'status' => 'partial',
                        'phase' => 'live_seed',
                        'identity_json' => $identity,
                        'inspect_json' => $inspect,
                        'score' => $merged['display_score'],
                    ]);
                }
            } catch (Throwable $jobErr) {
                error_log('[Roast PvP] apply_tier0_seed_score job save skipped: ' . $jobErr->getMessage());
                $jobId = trim((string) ($locked['live_job_id'] ?? ''));
            }

            $modeSql = (string) ($locked['player_mode'] ?? '') === '' ? ", {$modeCol} = 'live'" : '';
            $pdo->prepare(
                "UPDATE roast_pvp_matches
                 SET {$seedCol} = ?, {$tierCol} = ?, {$displayCol} = ?, {$bestCol} = ?, {$jobCol} = ?, {$atCol} = NOW(){$modeSql}
                 WHERE match_id = ?"
            )->execute([
                $score,
                $merged['score_tier'],
                $merged['display_score'],
                $merged['display_score'],
                $jobId !== '' ? $jobId : null,
                $matchId,
            ]);

            $pdo->commit();
        } catch (Throwable $dbErr) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Roast PvP] apply_tier0_seed_score failed: ' . $dbErr->getMessage());
            return ['ok' => false, 'error' => roast_error('DB', 'Could not save score.', true)];
        }

        roast_pvp_sync_round_timer($matchId);

        return [
            'ok' => true,
            'score' => $score,
            'display_score' => $merged['display_score'],
            'score_tier' => $merged['score_tier'],
            'provisional' => $merged['provisional'],
            'your_average' => $merged['display_score'],
            'frame_count' => 0,
            'tier0_seed' => true,
        ];
    }
}

if (!function_exists('roast_pvp_enqueue_tier2_pending')) {
    /** Stage best Tier 1 frame for background Tier 2 cron processing. */
    function roast_pvp_enqueue_tier2_pending(
        string $matchId,
        string $role,
        string $imagePath,
        int $frameScore
    ): void {
        if ($role !== 'a' && $role !== 'b' || $matchId === '' || !is_readable($imagePath)) {
            // #region agent log
            require_once __DIR__ . '/roast-debug-session.php';
            roast_debug_session_log('roast-pvp.php:enqueue_tier2', 'enqueue_skip', [
                'role' => $role,
                'readable' => is_readable($imagePath),
            ], 'H3');
            // #endregion
            return;
        }

        $pendingCol = $role === 'a' ? 'player_a_tier2_pending' : 'player_b_tier2_pending';
        $pathCol = $role === 'a' ? 'player_a_tier2_frame_path' : 'player_b_tier2_frame_path';
        $atCol = $role === 'a' ? 'player_a_tier2_requested_at' : 'player_b_tier2_requested_at';
        $readyCol = $role === 'a' ? 'player_a_tier2_ready' : 'player_b_tier2_ready';
        $bestCol = $role === 'a' ? 'player_a_live_best' : 'player_b_live_best';

        $tier2Dir = rtrim(ROAST_TMP_DIR, '/\\') . '/tier2-pending';
        if (!is_dir($tier2Dir) && !@mkdir($tier2Dir, 0755, true) && !is_dir($tier2Dir)) {
            error_log('[Roast PvP] tier2_pending dir missing: ' . $tier2Dir);
            // #region agent log
            require_once __DIR__ . '/roast-debug-session.php';
            roast_debug_session_log('roast-pvp.php:enqueue_tier2', 'dir_missing', ['dir' => $tier2Dir], 'H3');
            // #endregion
            return;
        }

        $dest = $tier2Dir . '/' . $matchId . '_' . $role . '_' . time() . '.jpg';
        if (!@copy($imagePath, $dest)) {
            error_log('[Roast PvP] tier2_pending copy failed for match ' . $matchId);
            // #region agent log
            require_once __DIR__ . '/roast-debug-session.php';
            roast_debug_session_log('roast-pvp.php:enqueue_tier2', 'copy_failed', [
                'match_id_hash' => substr(hash('sha256', $matchId), 0, 12),
            ], 'H3');
            // #endregion
            return;
        }

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            @unlink($dest);
            return;
        }

        try {
            $pdo->prepare(
                "UPDATE roast_pvp_matches
                 SET {$pendingCol} = 1, {$pathCol} = ?, {$atCol} = NOW(), {$readyCol} = 0,
                     {$bestCol} = GREATEST(COALESCE({$bestCol}, 0), ?)
                 WHERE match_id = ?"
            )->execute([$dest, max(0, min(100, $frameScore)), $matchId]);
            // #region agent log
            require_once __DIR__ . '/roast-debug-session.php';
            roast_debug_session_log('roast-pvp.php:enqueue_tier2', 'enqueued', [
                'match_id_hash' => substr(hash('sha256', $matchId), 0, 12),
                'frame_score' => $frameScore,
            ], 'H3');
            // #endregion
        } catch (Throwable $e) {
            @unlink($dest);
            error_log('[Roast PvP] tier2_pending enqueue failed: ' . $e->getMessage());
            // #region agent log
            require_once __DIR__ . '/roast-debug-session.php';
            roast_debug_session_log('roast-pvp.php:enqueue_tier2', 'db_failed', [
                'error' => $e->getMessage(),
            ], 'H3');
            // #endregion
        }
    }
}

if (!function_exists('roast_pvp_deterministic_cred_from_arrays')) {
    /**
     * Deterministic cred from identity + inspect (no jitter).
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     */
    function roast_pvp_deterministic_cred_from_arrays(array $identity, array $inspect): ?int
    {
        require_once __DIR__ . '/roast-score.php';
        if ($identity === []) {
            return null;
        }
        if ($inspect === []) {
            $inspect = roast_pvp_frame_inspect_shell($identity);
        }
        if (roast_pvp_frame_is_no_bike($identity, $inspect)) {
            return null;
        }

        return roast_compute_pvp_cred_score($identity, $inspect);
    }
}

if (!function_exists('roast_pvp_deterministic_cred_from_job')) {
    /**
     * @return array{score: int, identity: array<string, mixed>, inspect: array<string, mixed>, job_id: string}|null
     */
    function roast_pvp_deterministic_cred_from_job(?string $jobId): ?array
    {
        if ($jobId === null || trim($jobId) === '') {
            return null;
        }
        $row = roast_jobs_get(trim($jobId));
        if (!$row || !in_array($row['status'] ?? '', ['complete', 'partial'], true)) {
            return null;
        }
        $identity = json_decode((string) ($row['identity_json'] ?? '{}'), true) ?: [];
        $inspect = json_decode((string) ($row['inspect_json'] ?? '{}'), true) ?: [];
        $score = roast_pvp_deterministic_cred_from_arrays($identity, $inspect);
        if ($score === null) {
            return null;
        }

        return [
            'score' => $score,
            'identity' => $identity,
            'inspect' => $inspect,
            'job_id' => trim($jobId),
        ];
    }
}

if (!function_exists('roast_pvp_apply_live_frame_score')) {
    /**
     * Persist one live frame score (initial or rolling average).
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     * @return array<string, mixed>
     */
    function roast_pvp_apply_live_frame_score(
        string $matchId,
        string $role,
        int $score,
        array $identity,
        array $inspect,
        ?string $imageHash = null
    ): array {
        if ($role !== 'a' && $role !== 'b') {
            return ['ok' => false, 'error' => roast_error('PVP', 'Invalid role.', false)];
        }

        $sumCol = $role === 'a' ? 'player_a_live_sum' : 'player_b_live_sum';
        $countCol = $role === 'a' ? 'player_a_live_count' : 'player_b_live_count';
        $bestCol = $role === 'a' ? 'player_a_live_best' : 'player_b_live_best';
        $jobCol = $role === 'a' ? 'player_a_live_job_id' : 'player_b_live_job_id';
        $atCol = $role === 'a' ? 'player_a_live_at' : 'player_b_live_at';
        $modeCol = $role === 'a' ? 'player_a_mode' : 'player_b_mode';
        $tierCol = $role === 'a' ? 'player_a_score_tier' : 'player_b_score_tier';
        $displayCol = $role === 'a' ? 'player_a_display_score' : 'player_b_display_score';

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Could not save score.', true)];
        }

        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare(
                "SELECT {$sumCol} AS live_sum, {$countCol} AS live_count, {$jobCol} AS live_job_id,
                        {$modeCol} AS player_mode, {$tierCol} AS score_tier, {$displayCol} AS display_score
                 FROM roast_pvp_matches WHERE match_id = ? LIMIT 1 FOR UPDATE"
            );
            $lock->execute([$matchId]);
            $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($locked)) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => roast_error('PVP', 'Match not found.', false)];
            }

            $currentSum = (int) ($locked['live_sum'] ?? 0);
            $currentCount = (int) ($locked['live_count'] ?? 0);
            $newSum = $currentSum + $score;
            $newCount = $currentCount + 1;
            $newAvg = (int) round($newSum / $newCount);
            $lastDisplay = (int) ($locked['display_score'] ?? 0);
            $lastTier = (int) ($locked['score_tier'] ?? 0);
            $merged = roast_pvp_merge_tier_score($lastDisplay, $lastTier, $newAvg, 1);

            $jobId = trim((string) ($locked['live_job_id'] ?? ''));
            try {
                if ($jobId === '') {
                    $jobId = roast_jobs_new_id();
                    $ipHash = roast_ip_hash();
                    roast_jobs_create($jobId, $ipHash, $imageHash !== null && $imageHash !== '' ? $imageHash : roast_jobs_new_id());
                }
                if ($jobId !== '') {
                    roast_jobs_update($jobId, [
                        'status' => 'partial',
                        'phase' => 'live',
                        'identity_json' => $identity,
                        'inspect_json' => $inspect,
                        'score' => $merged['display_score'],
                    ]);
                }
            } catch (Throwable $jobErr) {
                error_log('[Roast PvP] apply_live_frame_score job save skipped: ' . $jobErr->getMessage());
                $jobId = trim((string) ($locked['live_job_id'] ?? ''));
            }

            $modeSql = (string) ($locked['player_mode'] ?? '') === '' ? ", {$modeCol} = 'live'" : '';
            $pdo->prepare(
                "UPDATE roast_pvp_matches
                 SET {$sumCol} = ?, {$countCol} = ?, {$bestCol} = ?, {$jobCol} = ?, {$atCol} = NOW(),
                     {$tierCol} = ?, {$displayCol} = ?{$modeSql}
                 WHERE match_id = ?"
            )->execute([
                $newSum,
                $newCount,
                $newAvg,
                $jobId !== '' ? $jobId : null,
                $merged['score_tier'],
                $merged['display_score'],
                $matchId,
            ]);

            $pdo->commit();
        } catch (Throwable $dbErr) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Roast PvP] apply_live_frame_score failed: ' . $dbErr->getMessage());
            return ['ok' => false, 'error' => roast_error('DB', 'Could not save score.', true)];
        }

        roast_pvp_sync_round_timer($matchId);

        return [
            'ok' => true,
            'score' => $score,
            'your_average' => $newAvg,
            'display_score' => $merged['display_score'],
            'score_tier' => $merged['score_tier'],
            'provisional' => $merged['provisional'],
            'frame_count' => $newCount,
            'your_best' => $merged['display_score'],
            'initial' => $currentCount <= 0,
        ];
    }
}

if (!function_exists('roast_pvp_seed_initial_live_score')) {
    /**
     * Quick deterministic score at duel start from optional frame or existing roast job.
     *
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>
     */
    function roast_pvp_seed_initial_live_score(
        string $matchId,
        string $token,
        ?array $file = null,
        ?string $jobId = null
    ): array {
        require_once __DIR__ . '/roast-cloud-vision.php';
        require_once __DIR__ . '/roast-score.php';

        $token = roast_pvp_normalize_token($token);
        if ($matchId === '' || $token === '') {
            return ['ok' => false, 'error' => roast_error('PVP', 'Match and token required.', false)];
        }
        if (!roast_pvp_validate_participant($matchId, $token)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_participant'];
        }

        $match = roast_pvp_get_match($matchId);
        $match = roast_pvp_ensure_match_active($match);
        if (!$match || !in_array($match['status'] ?? '', ['matched', 'active', 'dueling'], true)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'match_inactive'];
        }

        $role = roast_pvp_role_for_token($match, $token);
        if ($role === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_participant'];
        }
        $side = $role === 'a' ? 'a' : 'b';
        if ((int) ($match["player_{$side}_live_seed"] ?? 0) > 0) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'already_seeded'];
        }

        $scored = null;
        $imageHash = null;

        $jobId = trim((string) ($jobId ?? ''));
        $fromJob = roast_pvp_deterministic_cred_from_job($jobId !== '' ? $jobId : null);
        if ($fromJob === null) {
            $linked = trim((string) ($match["player_{$side}_job_id"] ?? ''));
            $fromJob = roast_pvp_deterministic_cred_from_job($linked !== '' ? $linked : null);
        }
        if ($fromJob !== null) {
            $scored = [
                'score' => (int) $fromJob['score'],
                'identity' => $fromJob['identity'],
                'inspect' => $fromJob['inspect'],
                'from_job' => true,
            ];
        }

        if ($scored === null && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                $saved = roast_save_uploaded_image($file);
                if ($saved['ok'] ?? false) {
                    $imagePath = (string) $saved['path'];
                    $imageHash = (string) ($saved['hash'] ?? '');
                    $frame = roast_pvp_score_frame_fast($imagePath, $matchId);
                    roast_delete_image($imagePath);
                    if (($frame['ok'] ?? false) && empty($frame['no_bike'])) {
                        $identity = is_array($frame['identity'] ?? null) ? $frame['identity'] : [];
                        $inspect = is_array($frame['inspect'] ?? null) ? $frame['inspect'] : [];
                        if (!roast_pvp_frame_is_no_bike($identity, $inspect)) {
                            $scored = [
                                'score' => (int) ($frame['score'] ?? 0),
                                'identity' => $identity,
                                'inspect' => $inspect,
                                'vision_fallback' => !empty($frame['vision_fallback']),
                            ];
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('[Roast PvP] seed_initial_live_score frame failed: ' . $e->getMessage());
            }
        }

        if ($scored === null) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'no_source'];
        }

        $applied = roast_pvp_apply_tier0_seed_score(
            $matchId,
            $role,
            (int) $scored['score'],
            $scored['identity'],
            $scored['inspect'],
            $imageHash
        );
        if (!($applied['ok'] ?? false)) {
            if (!empty($applied['skipped'])) {
                return ['ok' => true, 'skipped' => true, 'reason' => $applied['reason'] ?? 'already_seeded'];
            }
            return $applied;
        }

        roast_log_pvp_metric('initial_live_score', [
            'match_id' => $matchId,
            'side' => $role,
            'score' => (int) $scored['score'],
            'score_tier' => 0,
            'from_job' => !empty($scored['from_job']),
            'vision_fallback' => !empty($scored['vision_fallback']),
        ]);

        $match = roast_pvp_get_match($matchId);
        $payload = $match ? roast_pvp_build_status($match, $token) : ['ok' => true];
        $payload['initial_score'] = true;
        $payload['tier0_seed'] = true;
        $payload['frame_score'] = (int) $scored['score'];
        $payload['display_score'] = $applied['display_score'] ?? (int) $scored['score'];
        $payload['score_tier'] = $applied['score_tier'] ?? 0;
        $payload['provisional'] = $applied['provisional'] ?? true;
        $payload['your_average'] = $applied['your_average'] ?? (int) $scored['score'];
        $payload['frame_count'] = 0;
        if (!empty($scored['vision_fallback'])) {
            $payload['frame_fallback'] = true;
        }

        return $payload;
    }
}

if (!function_exists('roast_pvp_attach_live_score_fields')) {
    /**
     * Expose live_score, display_score, opponent_score for poll/join payloads.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $match
     */
    function roast_pvp_attach_live_score_fields(
        array &$payload,
        array $match,
        string $youSide,
        string $oppSide,
        string $npcId = ''
    ): void {
        $youDisplay = roast_pvp_player_live_display_score($match, $youSide);
        $oppDisplay = null;
        $oppStarting = null;

        if ($npcId !== '') {
            require_once __DIR__ . '/roast-pvp-npc.php';
            $oppStarting = roast_pvp_npc_starting_score($npcId);
            if ($oppStarting !== null) {
                $payload['opponent_starting_score'] = $oppStarting;
            }
            $oppDisplay = roast_pvp_npc_opponent_display_score($match, $oppSide);
        } else {
            $oppDisplay = roast_pvp_player_live_display_score($match, $oppSide);
        }

        $liveYouCount = roast_pvp_live_frame_count($match, $youSide);
        $liveOppCount = roast_pvp_live_frame_count($match, $oppSide);

        $youTier = roast_pvp_side_score_tier($match, $youSide);
        $youTier2Pending = !empty($match["player_{$youSide}_tier2_pending"]);
        $youTier2Ready = !empty($match["player_{$youSide}_tier2_ready"]);

        if ($youDisplay !== null) {
            $payload['you_score'] = $youDisplay;
            $payload['live_score'] = $youDisplay;
            $payload['display_score'] = $youDisplay;
            $payload['score_tier'] = $youTier;
            $payload['provisional'] = roast_pvp_identity_provisional_for_match($match, $youSide);
            $payload['tier2_pending'] = $youTier2Pending;
            $payload['tier2_ready'] = $youTier2Ready;
            if ($liveYouCount > 0) {
                $payload['your_frame_count'] = $liveYouCount;
            }
        }
        if ($oppDisplay !== null || $oppStarting !== null) {
            $payload['opponent_score'] = $oppDisplay ?? $oppStarting;
            if ($liveOppCount > 0) {
                $payload['opponent_frame_count'] = $liveOppCount;
            }
        }
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

        if ($subject === 'not_an_ebike') {
            return "Cred {$score}/100 (match average) — live frames didn't show e-moto content. "
                . 'Point your bike camera at your build for the next round.';
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

if (!function_exists('roast_pvp_sanitize_public_identity')) {
    /** @param array<string, mixed> $identity */
    function roast_pvp_sanitize_public_identity(array $identity): array
    {
        $source = strtolower((string) ($identity['source'] ?? ''));
        if ($source !== '' && preg_match('/npc|bot|fake/', $source)) {
            unset($identity['source']);
        }
        return $identity;
    }
}

if (!function_exists('roast_pvp_sanitize_public_inspect')) {
    /** @param array<string, mixed> $inspect */
    function roast_pvp_sanitize_public_inspect(array $inspect): array
    {
        unset($inspect['grade_pending']);
        $notes = strtolower((string) ($inspect['condition_notes'] ?? ''));
        if ($notes !== '' && preg_match('/npc|bot|fake/', $notes)) {
            $inspect['condition_notes'] = 'live_frame';
        }
        return $inspect;
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
            'identity' => roast_pvp_sanitize_public_identity($identity),
            'inspect' => roast_pvp_sanitize_public_inspect($inspect),
            'status' => (string) ($row['status'] ?? ''),
        ];
    }
}

if (!function_exists('roast_pvp_is_npc_match')) {
    function roast_pvp_is_npc_match(string $matchId): bool
    {
        if ($matchId === '') {
            return false;
        }
        $match = roast_pvp_get_match($matchId);
        return $match !== null && trim((string) ($match['opponent_npc_id'] ?? '')) !== '';
    }
}

if (!function_exists('roast_pvp_store_signal')) {
    function roast_pvp_store_signal(string $matchId, string $token, string $type, string $payload): bool
    {
        if (!roast_pvp_validate_participant($matchId, $token)) {
            return false;
        }
        if (roast_pvp_is_npc_match($matchId)) {
            return true;
        }
        $pdo = roast_pvp_pdo();
        if (!$pdo) {
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

if (!function_exists('roast_pvp_forfeit_grace_remaining')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_forfeit_grace_remaining(array $match, string $side): ?int
    {
        $leftAt = $match["player_{$side}_left_at"] ?? null;
        if ($leftAt === null || $leftAt === '') {
            return null;
        }
        $ts = strtotime((string) $leftAt);
        if ($ts === false) {
            return null;
        }
        return max(0, ROAST_PVP_FORFEIT_GRACE_SEC - (time() - $ts));
    }
}

if (!function_exists('roast_pvp_finalize_forfeit')) {
    function roast_pvp_finalize_forfeit(string $matchId, string $winnerSide): void
    {
        $match = roast_pvp_get_match($matchId);
        if (!$match || ($match['status'] ?? '') === 'complete') {
            return;
        }
        $modeA = (string) ($match['player_a_mode'] ?? '');
        $modeB = (string) ($match['player_b_mode'] ?? '');
        if ($modeA === 'live' && $modeB === 'live') {
            roast_pvp_finalize_live_duel($matchId);
        } elseif ($modeA === 'live' || $modeB === 'live') {
            roast_pvp_finalize_mixed($matchId);
        } else {
            $pdo = roast_pvp_pdo();
            if ($pdo) {
                $pdo->prepare(
                    'UPDATE roast_pvp_matches SET status = ?, winner = ? WHERE match_id = ?'
                )->execute(['complete', $winnerSide, $matchId]);
            }
            return;
        }

        $pdo = roast_pvp_pdo();
        if ($pdo) {
            $pdo->prepare(
                'UPDATE roast_pvp_matches SET winner = ? WHERE match_id = ? AND status = ?'
            )->execute([$winnerSide, $matchId, 'complete']);
        }
    }
}

if (!function_exists('roast_pvp_apply_forfeit_grace')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_apply_forfeit_grace(array $match): array
    {
        $status = (string) ($match['status'] ?? '');
        if (!in_array($status, ['matched', 'active', 'dueling'], true)) {
            return $match;
        }
        $matchId = (string) ($match['match_id'] ?? '');
        foreach (['a', 'b'] as $side) {
            $remaining = roast_pvp_forfeit_grace_remaining($match, $side);
            if ($remaining === null) {
                continue;
            }
            if ($remaining <= 0) {
                $winner = $side === 'a' ? 'b' : 'a';
                roast_pvp_finalize_forfeit($matchId, $winner);
                $fresh = roast_pvp_get_match($matchId);
                return $fresh ?? $match;
            }
        }
        return $match;
    }
}

if (!function_exists('roast_pvp_build_status')) {
    /** @param array<string, mixed> $match */
    function roast_pvp_build_status(array $match, string $token): array
    {
        $match = roast_pvp_check_expiry($match) ?? $match;
        $match = roast_pvp_apply_forfeit_grace($match);
        $token = roast_pvp_normalize_token($token);
        $role = roast_pvp_role_for_token($match, $token);
        $matchId = (string) ($match['match_id'] ?? '');
        $status = (string) ($match['status'] ?? 'matched');

        $npcId = trim((string) ($match['opponent_npc_id'] ?? ''));
        if ($npcId !== '' && $matchId !== '' && in_array($status, ['matched', 'active', 'dueling'], true)) {
            require_once __DIR__ . '/roast-pvp-npc.php';
            roast_pvp_npc_grade_if_due($matchId);
            $refreshed = roast_pvp_get_match($matchId);
            if ($refreshed) {
                $match = $refreshed;
            }
        }

        $payload = [
            'ok' => true,
            'status' => $status,
            'match_id' => $matchId,
            'role' => $role,
            'round_sec' => ROAST_PVP_ROUND_SEC,
        ];
        if ($npcId !== '') {
            $payload['opponent_npc'] = true;
            if (!function_exists('roast_pvp_npc_entry')) {
                require_once __DIR__ . '/roast-pvp-npc.php';
            }
            $npcEntry = roast_pvp_npc_entry($npcId);
            if ($npcEntry !== null) {
                $payload['opponent_starting_score'] = (int) ($npcEntry['starting_score'] ?? 50);
            }
        }

        $remaining = roast_pvp_seconds_remaining($match);
        if ($remaining !== null) {
            $payload['seconds_remaining'] = $remaining;
        }

        $youSide = $role === 'a' ? 'a' : 'b';
        $oppSide = $role === 'a' ? 'b' : 'a';
        $oppGrace = roast_pvp_forfeit_grace_remaining($match, $oppSide);
        if ($oppGrace !== null && $oppGrace > 0 && in_array($status, ['matched', 'active', 'dueling'], true)) {
            $payload['opponent_left'] = true;
            $payload['forfeit_grace_sec'] = $oppGrace;
            $payload['message'] = 'Opponent left — match ends in ' . $oppGrace . 's…';
        }

        if ($status === 'waiting') {
            $payload['message'] = 'Looking for a verified stranger…';
            return $payload;
        }

        if ($status === 'matched') {
            $payload['message'] = 'Stranger connected — starting round…';
            if ($npcId !== '') {
                require_once __DIR__ . '/roast-pvp-npc.php';
                $starting = roast_pvp_npc_starting_score($npcId);
                if ($starting !== null) {
                    $payload['opponent_starting_score'] = $starting;
                    $payload['opponent_score'] = $starting;
                }
                $feed = roast_pvp_npc_video_url($npcId);
                if ($feed !== '') {
                    $payload['opponent_feed'] = $feed;
                }
            }
            roast_pvp_attach_live_score_fields($payload, $match, $youSide, $oppSide, $npcId);
            return $payload;
        }

        $youJob = roast_pvp_resolve_job_id($match, $youSide);
        $oppJob = roast_pvp_resolve_job_id($match, $oppSide);
        $you = roast_pvp_job_snapshot($youJob);
        $opponent = roast_pvp_job_snapshot($oppJob);

        if ($status === 'active') {
            $modeYou = $role === 'a' ? ($match['player_a_mode'] ?? null) : ($match['player_b_mode'] ?? null);
            $modeOpp = $role === 'a' ? ($match['player_b_mode'] ?? null) : ($match['player_a_mode'] ?? null);
            roast_pvp_attach_live_score_fields($payload, $match, $youSide, $oppSide, $npcId);
            $youDisplay = $payload['live_score'] ?? null;
            $oppDisplay = $payload['opponent_score'] ?? null;
            $oppStarting = $payload['opponent_starting_score'] ?? null;

            $payload['your_mode'] = $modeYou;
            $payload['opponent_mode'] = $modeOpp;
            $payload['round_type'] = roast_pvp_round_type($match);
            $payload['both_modes_ready'] = ($match['player_a_mode'] ?? '') !== '' && ($match['player_b_mode'] ?? '') !== '';

            if (empty($payload['opponent_left'])) {
                if (!$payload['both_modes_ready']) {
                    $payload['message'] = 'Waiting for opponent — auto-judging starts for both riders soon.';
                } else {
                    $payload['message'] = 'Highest average cred wins. Auto-judging from your top camera.';
                }
            }
            $payload['you'] = $you;
            $payload['opponent'] = $opponent;
            $payload['you_ready'] = $you !== null || ($modeYou === 'live' && $youDisplay !== null);
            $payload['opponent_ready'] = $opponent !== null
                || ($modeOpp === 'live' && ($oppDisplay !== null || $oppStarting !== null));
            if ($npcId !== '') {
                require_once __DIR__ . '/roast-pvp-npc.php';
                $feed = roast_pvp_npc_video_url($npcId);
                if ($feed !== '') {
                    $payload['opponent_feed'] = $feed;
                }
            }
            return $payload;
        }

        if ($status === 'dueling') {
            if (empty($payload['opponent_left'])) {
                $payload['message'] = $you ? 'Waiting for opponent judgment…' : 'Judging your bike…';
            }
            roast_pvp_attach_live_score_fields($payload, $match, $youSide, $oppSide, $npcId);
            $youDisplay = $payload['live_score'] ?? null;
            $oppDisplay = $payload['opponent_score'] ?? null;
            $oppStarting = $payload['opponent_starting_score'] ?? null;
            $payload['you'] = $you;
            $payload['opponent'] = $opponent;
            if ($youDisplay === null && $you && isset($you['score'])) {
                $payload['you_score'] = (int) $you['score'];
                $payload['live_score'] = (int) $you['score'];
                $payload['display_score'] = (int) $you['score'];
            }
            if ($oppDisplay === null) {
                $payload['opponent_score'] = $oppStarting ?? ($opponent['score'] ?? null);
            }
            $payload['opponent_ready'] = $opponent !== null || $oppDisplay !== null || $oppStarting !== null;
            $payload['you_ready'] = $you !== null || $youDisplay !== null;
            if ($npcId !== '') {
                require_once __DIR__ . '/roast-pvp-npc.php';
                $feed = roast_pvp_npc_video_url($npcId);
                if ($feed !== '') {
                    $payload['opponent_feed'] = $feed;
                }
            }
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
                $forfeit = !empty($match["player_a_left_at"]) || !empty($match["player_b_left_at"]);
                if ($forfeit) {
                    $payload['forfeit'] = true;
                    $payload['message'] = ($payload['you_won'] ?? false)
                        ? 'Opponent left — you win by forfeit.'
                        : 'You left — opponent wins by forfeit.';
                } else {
                    $payload['message'] = $winner === 't'
                        ? 'Draw — equally questionable builds.'
                        : (($payload['you_won'] ?? false) ? 'You win — higher average cred.' : 'You lose — more roast fuel.');
                }
            }
            return $payload;
        }

        if ($status === 'cancelled') {
            $payload['message'] = 'Match cancelled.';
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
            $match = roast_pvp_apply_forfeit_grace($match);
            $matchId = (string) ($match['match_id'] ?? '');
            if ($matchId !== '') {
                $match = roast_pvp_get_match($matchId) ?? $match;
            }
            return roast_pvp_build_status($match, $token);
        }

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Matchmaking unavailable.', true)];
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT status, created_at, face_hash FROM roast_pvp_queue
                 WHERE queue_token = ? AND status = 'waiting' LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$token]);
            $queueRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($queueRow)) {
                $pdo->commit();
                return ['ok' => true, 'status' => 'idle', 'message' => 'Enable camera and pass face check to start.'];
            }

            require_once __DIR__ . '/roast-pvp-npc.php';
            $createdAt = strtotime((string) ($queueRow['created_at'] ?? ''));
            $faceHash = roast_pvp_normalize_face_hash((string) ($queueRow['face_hash'] ?? ''));
            $waitSec = $createdAt !== false ? (time() - $createdAt) : 0;
            $fallbackReady = roast_pvp_npc_enabled()
                && $createdAt !== false
                && $faceHash !== ''
                && $waitSec >= roast_pvp_npc_fallback_sec();

            $pdo->commit();

            if ($fallbackReady) {
                $npcMatch = roast_pvp_npc_create_match($token, $faceHash);
                if ($npcMatch) {
                    return roast_pvp_build_status($npcMatch, $token);
                }
                return ['ok' => true, 'status' => 'waiting', 'message' => 'Looking for a verified stranger…'];
            }

            $remaining = max(0, roast_pvp_npc_fallback_sec() - $waitSec);
            $payload = [
                'ok' => true,
                'status' => 'waiting',
                'message' => 'Looking for a verified stranger…',
            ];
            if ($remaining > 0 && roast_pvp_npc_enabled()) {
                $payload['npc_fallback_sec'] = $remaining;
                if ($remaining <= 5) {
                    $payload['opponent_npc'] = true;
                }
            }
            return $payload;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Roast PvP] status queue lock failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => roast_error('STATUS', 'Matchmaking unavailable.', true)];
        }
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
            $role = roast_pvp_role_for_token($match, $token);
            $matchId = (string) ($match['match_id'] ?? '');
            if (($role === 'a' || $role === 'b') && $matchId !== '') {
                $col = $role === 'a' ? 'player_a_left_at' : 'player_b_left_at';
                $pdo->prepare(
                    "UPDATE roast_pvp_matches SET {$col} = NOW() WHERE match_id = ? AND {$col} IS NULL"
                )->execute([$matchId]);
            }
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
        $resolved = roast_pvp_resolve_participant_match($matchId, $token, true);
        if (!($resolved['ok'] ?? false)) {
            return $resolved;
        }

        $match = $resolved['match'];
        $matchId = (string) $resolved['match_id'];
        $role = (string) $resolved['role'];
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
            $npcId = trim((string) ($match['opponent_npc_id'] ?? ''));
            if ($npcId !== '') {
                require_once __DIR__ . '/roast-pvp-npc.php';
                $oppSide = roast_pvp_npc_opponent_side($match);
                if ($side === $oppSide) {
                    return roast_pvp_npc_opponent_display_score($match, $side)
                        ?? roast_pvp_npc_starting_score($npcId)
                        ?? 0;
                }
            }

            return roast_pvp_player_live_display_score($match, $side) ?? 0;
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
        $existing = roast_pvp_get_match($matchId);
        if ($existing && ($existing['status'] ?? '') === 'complete') {
            return [
                'ok' => true,
                'status' => 'complete',
                'winner' => (string) ($existing['winner'] ?? 't'),
            ];
        }

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
                 WHERE match_id = ? AND status <> ?'
            )->execute(['complete', $winner, $jobA, $jobB, $matchId, 'complete']);
        }

        roast_log_pvp_metric('finalize', [
            'match_id' => $matchId,
            'score_a' => $scoreA,
            'score_b' => $scoreB,
            'winner' => $winner,
        ]);

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
        if (($match['status'] ?? '') === 'complete') {
            return [
                'ok' => true,
                'status' => 'complete',
                'winner' => (string) ($match['winner'] ?? 't'),
            ];
        }

        $jobA = (string) ($match['player_a_live_job_id'] ?? '');
        $jobB = (string) ($match['player_b_live_job_id'] ?? '');
        if ($jobA !== '') {
            $avgA = roast_pvp_live_average($match, 'a') ?? 0;
            roast_jobs_update($jobA, ['score' => $avgA]);
            roast_pvp_complete_live_job($jobA, $avgA);
        }
        $npcId = trim((string) ($match['opponent_npc_id'] ?? ''));
        if ($jobB !== '') {
            if ($npcId !== '') {
                require_once __DIR__ . '/roast-pvp-npc.php';
                roast_pvp_npc_finalize_live_job($matchId, $jobB);
            } else {
                $avgB = roast_pvp_live_average($match, 'b') ?? 0;
                roast_jobs_update($jobB, ['score' => $avgB]);
                roast_pvp_complete_live_job($jobB, $avgB);
            }
        }

        $match = roast_pvp_get_match($matchId) ?? $match;
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
        if (($match['status'] ?? '') === 'complete') {
            return [
                'ok' => true,
                'status' => 'complete',
                'winner' => (string) ($match['winner'] ?? 't'),
            ];
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
        $npcId = trim((string) ($match['opponent_npc_id'] ?? ''));
        if ($liveJob !== '') {
            if ($npcId !== '' && $liveSide === 'b') {
                require_once __DIR__ . '/roast-pvp-npc.php';
                roast_pvp_npc_finalize_live_job($matchId, $liveJob);
            } else {
                $avgLive = roast_pvp_live_average($match, $liveSide) ?? 0;
                roast_jobs_update($liveJob, ['score' => $avgLive]);
                roast_pvp_complete_live_job($liveJob, $avgLive);
            }
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

if (!function_exists('roast_pvp_match_id_hash')) {
    function roast_pvp_match_id_hash(string $matchId): string
    {
        $matchId = trim($matchId);
        if ($matchId === '') {
            return '';
        }
        return substr(hash('sha256', $matchId), 0, 16);
    }
}

if (!function_exists('roast_pvp_log_frame_vision_outcome')) {
    /** @param array<string, mixed>|null $visionResult */
    function roast_pvp_log_frame_vision_outcome(string $matchId, ?array $visionResult, bool $usedFallback = false): void
    {
        require_once __DIR__ . '/roast-cloud-vision.php';

        $code = 'OK';
        $backend = 'openrouter_vision_qwen';
        $ms = 0;
        $fallback = $usedFallback;

        if ($visionResult !== null) {
            $ms = (int) ($visionResult['ms'] ?? 0);
            if (!empty($visionResult['ok'])) {
                $backend = (string) ($visionResult['backend'] ?? 'groq_vision');
                $fallback = $fallback || !empty($visionResult['fallback']);
            } else {
                $code = (string) ($visionResult['error']['code'] ?? 'VISION_FAILED');
                $backend = (string) ($visionResult['backend'] ?? '');
                if ($backend === '') {
                    $backend = $usedFallback ? 'live_frame_fallback' : 'vision_failed';
                }
            }
        } elseif ($usedFallback) {
            $code = 'VISION_FAILED';
            $backend = 'live_frame_fallback';
        }

        roast_log_vision_outcome([
            'match_id_hash' => roast_pvp_match_id_hash($matchId),
            'backend' => $backend,
            'fallback' => $fallback,
            'ms' => $ms,
            'code' => $code,
            'phase' => 'pvp_live_frame',
        ]);
    }
}

if (!function_exists('roast_pvp_agent1_is_stub_identity')) {
    /** @param array<string, mixed> $data @param array<string, mixed>|null $a1 */
    function roast_pvp_agent1_is_stub_identity(array $data, ?array $a1 = null): bool
    {
        if (($a1['backend'] ?? '') === 'degraded_live') {
            return true;
        }
        $source = (string) ($data['source'] ?? '');
        if (in_array($source, ['degraded_live', 'live_frame_fallback', 'degraded_override', 'local_text_guess'], true)) {
            return true;
        }
        if (!empty($data['degraded'])) {
            $make = strtolower(trim((string) ($data['make'] ?? '')));
            $model = strtolower(trim((string) ($data['model'] ?? '')));
            if ($make === '' || $model === '' || $make === 'unknown' || $model === 'unknown') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('roast_pvp_agent1_has_identity')) {
    /** @param array<string, mixed>|null $a1 */
    function roast_pvp_agent1_has_identity(?array $a1): bool
    {
        if (!($a1['ok'] ?? false)
            || !is_array($a1['data'] ?? null)
            || ($a1['data'] ?? []) === []) {
            return false;
        }
        return !roast_pvp_agent1_is_stub_identity($a1['data'], $a1);
    }
}

if (!function_exists('roast_pvp_fallback_frame_identity')) {
    /** Permissive live-frame identity when vision is unavailable (v34). */
    function roast_pvp_fallback_frame_identity(string $reason = 'vision_unavailable'): array
    {
        if (!function_exists('roast_agent1_degraded_live_identity')) {
            require_once dirname(__DIR__) . '/roast-limited/agents/agent1-identify.php';
        }
        $deg = roast_agent1_degraded_live_identity($reason);
        $data = is_array($deg['data'] ?? null) ? $deg['data'] : [];
        $data['source'] = 'live_frame_fallback';
        $data['fallback_reason'] = $reason;
        return $data;
    }
}

if (!function_exists('roast_pvp_frame_inspect_shell')) {
    /** @param array<string, mixed> $identity */
    function roast_pvp_frame_inspect_shell(array $identity): array
    {
        $subject = strtolower(trim((string) ($identity['visible_subject'] ?? 'unclear')));
        return [
            'frame_visible' => $subject !== 'parts_only',
            'visual_mods' => [],
            'missing_parts' => [],
            'cleanliness_score' => 8,
            'condition_notes' => 'live_frame',
        ];
    }
}

if (!function_exists('roast_pvp_vision_error_hint')) {
    /** Actionable user hint when live-frame vision fails. */
    function roast_pvp_vision_error_hint(?array $result): string
    {
        if ($result === null) {
            return 'Hold the bike steady in better light — vision timed out. Next try in ~10s.';
        }
        $code = strtoupper(trim((string) ($result['error']['code'] ?? '')));
        $detail = trim((string) ($result['error']['message'] ?? ''));
        return match ($code) {
            'IMAGE' => 'Frame saved but unreadable — check camera focus and lighting. Next try in ~10s.',
            'GROQ_SKIPPED', 'GROQ_FAILED' => 'Vision service busy — scored conservatively. Hold the full bike in frame; retry in ~10s.',
            'HTTP_429', 'RATE_LIMIT' => 'Too many vision requests — scored this frame anyway. Wait ~10s before the next capture.',
            'SCHEMA_VIOLATION' => 'Could not parse bike details — aim at the full bike, not just wheels or background. Next try in ~10s.',
            'LOCAL_AGENT_FAILED' => 'Backup vision offline — scored conservatively. Steady the camera on your build; retry in ~10s.',
            'EXCEPTION' => $detail !== ''
                ? 'Vision error — scored conservatively. ' . $detail . ' Next try in ~10s.'
                : 'Vision error — scored conservatively. Hold the bike in frame; retry in ~10s.',
            default => $detail !== ''
                ? 'Vision hiccup — scored conservatively. ' . $detail . ' Next try in ~10s.'
                : 'Vision hiccup — hold the bike in frame with good light. Next try in ~10s.',
        };
    }
}

if (!function_exists('roast_pvp_frame_is_no_bike')) {
    /**
     * Live frames: only explicit non-bike subjects are rejected.
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     */
    function roast_pvp_frame_is_no_bike(array $identity, array $inspect): bool
    {
        $raw = strtolower(trim((string) ($identity['visible_subject'] ?? '')));
        if ($raw === 'not_an_ebike') {
            return true;
        }
        if (in_array($raw, ['full_bike', 'partial_bike', 'parts_only'], true)) {
            return false;
        }
        require_once __DIR__ . '/roast-score.php';
        return roast_normalize_visible_subject($identity, $inspect) === 'not_an_ebike';
    }
}

if (!function_exists('roast_pvp_frame_score_client_fields')) {
    /** Client-visible scoring metadata for live frames. @param array<string, mixed> $scored */
    function roast_pvp_frame_score_client_fields(array $scored, ?array $a1 = null): array
    {
        $identity = is_array($scored['identity'] ?? null) ? $scored['identity'] : [];
        $visionFallback = !empty($scored['vision_fallback']);

        return [
            'score_source' => (string) ($scored['score_source'] ?? ($visionFallback ? 'fallback' : 'vision')),
            'vision_real' => array_key_exists('vision_real', $scored)
                ? (bool) $scored['vision_real']
                : !$visionFallback,
            'vision_fallback' => $visionFallback,
            'vision_backend' => $a1['backend'] ?? ($identity['source'] ?? null),
            'vision_provider_fallback' => !$visionFallback && !empty($a1['fallback']),
            'identity_source' => (string) ($identity['source'] ?? ''),
            'identity_confidence' => round((float) ($identity['confidence'] ?? 0), 2),
            'visible_subject' => (string) ($identity['visible_subject'] ?? ''),
            'identified_make' => (string) ($identity['make'] ?? ''),
            'identified_model' => (string) ($identity['model'] ?? ''),
        ];
    }
}

if (!function_exists('roast_pvp_identify_live_frame')) {
    /**
     * Tier-1 fast path for live_frame — cache → VPS → OR-Qwen → OR-Llama (no Groq).
     *
     * @return array<string, mixed>
     */
    function roast_pvp_identify_live_frame(string $imagePath, array $ctx = []): array
    {
        require_once __DIR__ . '/roast-pvp-vision-cache.php';
        require_once dirname(__DIR__) . '/roast-limited/agents/agent1-identify.php';

        return roast_agent1_identify($imagePath, array_merge($ctx, ['live_frame' => true]));
    }
}

if (!function_exists('roast_pvp_score_frame_fast')) {
    /**
     * Tier 1 fast live frame — Agent 1 only, single attempt, short timeout (no Groq).
     */
    function roast_pvp_score_frame_fast(string $imagePath, string $matchId = ''): array
    {
        require_once __DIR__ . '/roast-cloud-vision.php';
        require_once __DIR__ . '/roast-score.php';
        require_once dirname(__DIR__) . '/roast-limited/agents/agent1-identify.php';
        require_once __DIR__ . '/roast-debug-session.php';

        $t0 = microtime(true);
        $timeout = defined('ROAST_PVP_T1_TIMEOUT_SEC') ? ROAST_PVP_T1_TIMEOUT_SEC : 3;
        $visionErr = null;
        $a1 = null;

        try {
            $a1 = roast_agent1_identify($imagePath, [
                'live_frame' => true,
                'tier1_fast' => true,
                'timeout_sec' => $timeout,
            ]);
        } catch (Throwable $e) {
            error_log('[Roast PvP] score_frame_fast identify threw: ' . $e->getMessage());
            $a1 = ['ok' => false, 'error' => ['code' => 'EXCEPTION', 'message' => $e->getMessage()]];
        }

        $tier1Ms = (int) round((microtime(true) - $t0) * 1000);

        if (!roast_pvp_agent1_has_identity($a1)) {
            $visionErr = $a1;
            $reason = (string) ($visionErr['error']['code'] ?? $a1['error']['code'] ?? 'vision_failed');
            error_log('[Roast PvP] score_frame_fast vision failed (' . $reason . ') — using fallback identity');
            roast_pvp_log_frame_vision_outcome($matchId, $visionErr ?? $a1, true);
            $identity = roast_pvp_fallback_frame_identity($reason);
            $inspect = roast_pvp_frame_inspect_shell($identity);
            $score = roast_compute_pvp_cred_score($identity, $inspect);

            // #region agent log
            roast_debug_session_log('roast-pvp.php:score_frame_fast', 'tier1_fallback', [
                'match_id_hash' => $matchId !== '' ? substr(hash('sha256', $matchId), 0, 12) : '',
                'reason' => $reason,
                'score' => $score,
                'or_key_set' => defined('ROAST_OPENROUTER_API_KEY') && ROAST_OPENROUTER_API_KEY !== '',
                'tier1_ms' => $tier1Ms,
            ], 'H1');
            // #endregion

            return array_merge([
                'ok' => true,
                'score' => $score,
                'identity' => $identity,
                'inspect' => $inspect,
                'no_bike' => false,
                'vision_fallback' => true,
                'vision_real' => false,
                'score_source' => 'fallback',
                'score_tier' => 1,
                'provisional' => true,
                'tier1_latency_ms' => $tier1Ms,
                'vision_hint' => roast_pvp_vision_error_hint($visionErr ?? $a1),
            ], roast_pvp_frame_score_client_fields([
                'vision_fallback' => true,
                'score_source' => 'fallback',
                'vision_real' => false,
                'identity' => $identity,
            ], $visionErr ?? $a1));
        }

        roast_pvp_log_frame_vision_outcome($matchId, $a1, false);

        $identity = $a1['data'];
        $inspect = roast_pvp_frame_inspect_shell($identity);
        $noBike = roast_pvp_frame_is_no_bike($identity, $inspect);
        $score = $noBike ? 0 : roast_compute_pvp_cred_score($identity, $inspect);
        require_once __DIR__ . '/roast-score.php';
        $provisional = roast_pvp_identity_is_provisional($identity);

        // #region agent log
        roast_debug_session_log('roast-pvp.php:score_frame_fast', 'tier1_vision_ok', [
            'match_id_hash' => $matchId !== '' ? substr(hash('sha256', $matchId), 0, 12) : '',
            'score' => $score,
            'no_bike' => $noBike,
            'backend' => $a1['backend'] ?? null,
            'model' => $a1['model'] ?? null,
            'tier1_ms' => max($tier1Ms, (int) ($a1['ms'] ?? 0)),
        ], 'H2');
        // #endregion

        return array_merge([
            'ok' => true,
            'score' => $score,
            'identity' => $identity,
            'inspect' => $inspect,
            'no_bike' => $noBike,
            'vision_fallback' => false,
            'vision_real' => true,
            'score_source' => 'vision',
            'score_tier' => 1,
            'provisional' => $provisional,
            'tier1_latency_ms' => max($tier1Ms, (int) ($a1['ms'] ?? 0)),
        ], roast_pvp_frame_score_client_fields([
            'vision_fallback' => false,
            'score_source' => 'vision',
            'vision_real' => true,
            'identity' => $identity,
        ], $a1));
    }
}

if (!function_exists('roast_pvp_score_frame')) {
    /** Quick live frame score — Tier-1 vision (Agent 1, no Groq). Full roast runs at round end. */
    function roast_pvp_score_frame(string $imagePath, string $matchId = ''): array
    {
        require_once __DIR__ . '/roast-cloud-vision.php';
        require_once __DIR__ . '/roast-pvp-vision-cache.php';
        require_once __DIR__ . '/roast-score.php';

        $visionErr = null;
        $a1 = null;
        try {
            $a1 = roast_pvp_identify_live_frame($imagePath);
        } catch (Throwable $e) {
            error_log('[Roast PvP] score_frame identify threw: ' . $e->getMessage());
            $a1 = ['ok' => false, 'error' => ['code' => 'EXCEPTION', 'message' => $e->getMessage()]];
        }

        if (!roast_pvp_agent1_has_identity($a1)) {
            $visionErr = $a1;
            try {
                $retry = roast_pvp_identify_live_frame($imagePath);
                if (roast_pvp_agent1_has_identity($retry)) {
                    $a1 = $retry;
                    $visionErr = null;
                } else {
                    $visionErr = $retry;
                }
            } catch (Throwable $e) {
                error_log('[Roast PvP] score_frame identify retry threw: ' . $e->getMessage());
                if ($visionErr === null) {
                    $visionErr = ['ok' => false, 'error' => ['code' => 'EXCEPTION', 'message' => $e->getMessage()]];
                }
            }
        }

        if (!roast_pvp_agent1_has_identity($a1)) {
            $reason = (string) ($visionErr['error']['code'] ?? $a1['error']['code'] ?? 'vision_failed');
            error_log('[Roast PvP] score_frame vision failed (' . $reason . ') — using fallback identity');
            roast_pvp_log_frame_vision_outcome($matchId, $visionErr ?? $a1, true);
            $identity = roast_pvp_fallback_frame_identity($reason);
            $inspect = roast_pvp_frame_inspect_shell($identity);
            $score = roast_compute_pvp_cred_score($identity, $inspect);
            $cascadeMeta = [
                'tier1_latency_ms' => max(0, (int) (($visionErr ?? $a1)['ms'] ?? 0)),
                'vision_cache_hit' => !empty(($visionErr ?? $a1)['cache_hit']),
                'score_tier' => 1,
            ];
            return array_merge([
                'ok' => true,
                'score' => $score,
                'identity' => $identity,
                'inspect' => $inspect,
                'no_bike' => false,
                'vision_fallback' => true,
                'vision_real' => false,
                'score_source' => 'fallback',
                'vision_hint' => roast_pvp_vision_error_hint($visionErr ?? $a1),
            ], $cascadeMeta, roast_pvp_frame_score_client_fields([
                'vision_fallback' => true,
                'score_source' => 'fallback',
                'vision_real' => false,
                'identity' => $identity,
            ], $visionErr ?? $a1));
        }

        roast_pvp_log_frame_vision_outcome($matchId, $a1, false);

        $identity = $a1['data'];
        $inspect = roast_pvp_frame_inspect_shell($identity);
        $noBike = roast_pvp_frame_is_no_bike($identity, $inspect);
        $score = $noBike ? 0 : roast_compute_pvp_cred_score($identity, $inspect);
        $cascadeMeta = [
            'tier1_latency_ms' => max(0, (int) ($a1['ms'] ?? 0)),
            'vision_cache_hit' => !empty($a1['cache_hit']),
            'score_tier' => 1,
        ];
        return array_merge([
            'ok' => true,
            'score' => $score,
            'identity' => $identity,
            'inspect' => $inspect,
            'no_bike' => $noBike,
            'vision_fallback' => false,
            'vision_real' => true,
            'score_source' => 'vision',
        ], $cascadeMeta, roast_pvp_frame_score_client_fields([
            'vision_fallback' => false,
            'score_source' => 'vision',
            'vision_real' => true,
            'identity' => $identity,
        ], $a1));
    }
}

if (!function_exists('roast_pvp_resolve_participant_match')) {
    /**
     * Resolve match for a participant token, reconciling stale match_id and activating matched rounds.
     *
     * @return array{ok: true, match: array<string, mixed>, match_id: string, role: string}|array{ok: false, error: array<string, mixed>}
     */
    function roast_pvp_resolve_participant_match(string $matchId, string $token, bool $activate = true): array
    {
        $token = roast_pvp_normalize_token($token);
        if ($token === '') {
            return ['ok' => false, 'error' => roast_error('TOKEN', 'Invalid session token.', false)];
        }

        $match = $matchId !== '' ? roast_pvp_get_match($matchId) : null;
        $role = $match ? roast_pvp_role_for_token($match, $token) : '';
        if ($role === '') {
            $byToken = roast_pvp_get_by_token($token);
            if ($byToken && roast_pvp_role_for_token($byToken, $token) !== '') {
                $match = $byToken;
                $matchId = (string) ($match['match_id'] ?? '');
                $role = roast_pvp_role_for_token($match, $token);
            }
        }

        if (!$match || $role === '') {
            return [
                'ok' => false,
                'error' => roast_error('NOT_READY', 'Match not ready — waiting for opponent…', true),
            ];
        }

        if ($activate) {
            $match = roast_pvp_check_expiry($match);
            if (!$match) {
                return ['ok' => false, 'error' => roast_error('EXPIRED', 'Round timer expired.', false)];
            }
            $match = roast_pvp_ensure_match_active($match);
        }

        if (!$match) {
            return ['ok' => false, 'error' => roast_error('PVP', 'Match not found.', false)];
        }

        $matchId = (string) ($match['match_id'] ?? $matchId);
        $status = (string) ($match['status'] ?? '');
        if (!in_array($status, ['matched', 'active', 'dueling'], true)) {
            if ($status === 'expired') {
                return ['ok' => false, 'error' => roast_error('EXPIRED', 'Round timer expired.', false)];
            }
            if (in_array($status, ['complete', 'cancelled'], true)) {
                return ['ok' => false, 'error' => roast_error('PVP', 'Match ended.', false)];
            }
            return [
                'ok' => false,
                'error' => roast_error('NOT_READY', 'Match starting — try again in a moment.', true),
            ];
        }

        return [
            'ok' => true,
            'match' => $match,
            'match_id' => $matchId,
            'role' => $role,
        ];
    }
}

if (!function_exists('roast_pvp_live_frame')) {
    /** @return array<string, mixed> */
    function roast_pvp_live_frame(string $matchId, string $token, array $file): array
    {
        require_once __DIR__ . '/roast-cloud-vision.php';
        require_once __DIR__ . '/roast-pvp-vision-cache.php';
        require_once __DIR__ . '/roast-score.php';
        require_once __DIR__ . '/roast-debug-session.php';

        $resolved = roast_pvp_resolve_participant_match($matchId, $token, true);
        if (!($resolved['ok'] ?? false)) {
            return $resolved;
        }

        $match = $resolved['match'];
        $matchId = (string) $resolved['match_id'];
        $role = (string) $resolved['role'];

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
        if ($yourMode !== 'live') {
            return ['ok' => false, 'error' => roast_error('MODE', 'Live frames only in live mode.', false)];
        }

        $atCol = $role === 'a' ? 'player_a_live_at' : 'player_b_live_at';
        $countCol = $role === 'a' ? 'player_a_live_count' : 'player_b_live_count';
        $currentCount = (int) ($match[$countCol] ?? 0);
        $isInitialFrame = $currentCount <= 0
            || trim((string) ($_POST['initial'] ?? $_POST['initial_frame'] ?? '')) === '1';
        $lastAt = $match[$atCol] ?? null;
        $rateSec = defined('ROAST_PVP_T1_RATE_SEC') ? ROAST_PVP_T1_RATE_SEC : 4;
        if (!$isInitialFrame && $lastAt) {
            $elapsed = time() - strtotime((string) $lastAt);
            if ($elapsed >= 0 && $elapsed < $rateSec) {
                $retryAfter = max(1, $rateSec - $elapsed);
                return [
                    'ok' => false,
                    'error' => roast_error('RATE', 'Next judgment in a few seconds.', true),
                    'retry_after' => $retryAfter,
                ];
            }
        }

        try {
            $saved = roast_save_uploaded_image($file);
            if (!$saved['ok']) {
                return ['ok' => false, 'error' => roast_error('IMAGE', $saved['error'] ?? 'Frame upload failed.', false)];
            }
            $imagePath = (string) $saved['path'];
            $scored = roast_pvp_score_frame_fast($imagePath, $matchId);
            if (!($scored['ok'] ?? false)) {
                roast_delete_image($imagePath);
                return $scored;
            }

            $identity = is_array($scored['identity'] ?? null) ? $scored['identity'] : [];
            $inspect = is_array($scored['inspect'] ?? null) ? $scored['inspect'] : [];
            $score = (int) ($scored['score'] ?? 0);
            if (!empty($scored['no_bike']) || roast_pvp_frame_is_no_bike($identity, $inspect)) {
                roast_delete_image($imagePath);
                $match = roast_pvp_get_match($matchId);
                roast_log_pvp_metric('live_frame', array_merge([
                    'match_id' => $matchId,
                    'side' => $role,
                    'outcome' => 'skipped_no_bike',
                    'score_tier' => null,
                ], roast_pvp_metric_from_scored($scored)));
                $payload = $match ? roast_pvp_build_status($match, $token) : ['ok' => true];
                $payload['frame_skipped'] = true;
                $payload['frame_skip_reason'] = 'no_bike';
                $payload['no_bike'] = true;
                $payload['message'] = 'Nothing e-moto visible — point your bike camera at your build.';
                return $payload;
            }

            roast_pvp_enqueue_tier2_pending($matchId, $role, $imagePath, $score);
            roast_delete_image($imagePath);

            $applied = roast_pvp_apply_live_frame_score(
                $matchId,
                $role,
                $score,
                $identity,
                $inspect,
                (string) ($saved['hash'] ?? '')
            );
            if (!($applied['ok'] ?? false)) {
                return $applied;
            }
            $newAvg = (int) ($applied['your_average'] ?? $score);
            $newCount = (int) ($applied['frame_count'] ?? 1);
            $displayScore = (int) ($applied['display_score'] ?? $newAvg);

            $match = roast_pvp_get_match($matchId);
            $payload = $match ? roast_pvp_build_status($match, $token) : ['ok' => true];
            $payload['frame_score'] = $score;
            $payload['your_average'] = $newAvg;
            $payload['display_score'] = $displayScore;
            $payload['score_tier'] = (int) ($applied['score_tier'] ?? 1);
            $payload['provisional'] = (bool) ($applied['provisional'] ?? true);
            $payload['frame_count'] = $newCount;
            $payload['your_best'] = $displayScore;
            if (!empty($applied['initial'])) {
                $payload['initial_score'] = true;
            }
            if (!empty($scored['vision_fallback'])) {
                $payload['frame_fallback'] = true;
                $hint = trim((string) ($scored['vision_hint'] ?? ''));
                if ($hint !== '') {
                    $payload['vision_hint'] = $hint;
                    $payload['message'] = $hint;
                }
            }
            $payload = array_merge($payload, roast_pvp_frame_score_client_fields($scored));
            roast_log_pvp_metric('live_frame', array_merge([
                'match_id' => $matchId,
                'side' => $role,
                'outcome' => 'scored',
                'score' => $score,
                'frame_count' => $newCount,
                'average' => $newAvg,
                'vision_fallback' => !empty($scored['vision_fallback']),
                'initial' => !empty($applied['initial']),
            ], roast_pvp_metric_from_scored($scored)));
            if (roast_debug_session_enabled()) {
                $payload['_vision_debug'] = [
                    'tier1_fallback' => !empty($scored['vision_fallback']),
                    'score_source' => $scored['score_source'] ?? null,
                    'vision_hint' => $scored['vision_hint'] ?? null,
                    'score_tier' => (int) ($applied['score_tier'] ?? 1),
                    'tier2_enqueued' => true,
                    'or_key_set' => defined('ROAST_OPENROUTER_API_KEY') && ROAST_OPENROUTER_API_KEY !== '',
                ];
            }
            return $payload;
        } catch (Throwable $e) {
            error_log('[Roast PvP] live_frame failed: ' . $e->getMessage());
            return [
                'ok' => false,
                'error' => roast_error('LIVE', 'Could not judge that frame — duel continues; try again in a few seconds.', true),
            ];
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
            || trim((string) roast_env('PVP_TURN_SECRET_KEY', '')) !== ''
            || (
                trim((string) roast_env('PVP_TURN_USERNAME', '')) !== ''
                && trim((string) roast_env('PVP_TURN_CREDENTIAL', '')) !== ''
                && trim((string) roast_env('PVP_TURN_URLS', '')) !== ''
            );
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

if (!function_exists('roast_pvp_tier2_batch_limit')) {
    function roast_pvp_tier2_batch_limit(): int
    {
        $raw = roast_env('ROAST_PVP_T2_BATCH', '3');

        return max(1, min(10, (int) $raw));
    }
}

if (!function_exists('roast_pvp_tier2_unlink_frame')) {
    function roast_pvp_tier2_unlink_frame(?string $framePath): void
    {
        if ($framePath === null || trim($framePath) === '') {
            return;
        }
        $framePath = trim($framePath);
        $base = realpath(rtrim(ROAST_TMP_DIR, '/\\') . '/tier2-pending');
        $real = realpath($framePath);
        if ($base !== false && $real !== false && str_starts_with($real, $base) && is_file($real)) {
            @unlink($real);
        }
    }
}

if (!function_exists('roast_pvp_tier2_run_pipeline')) {
    /**
     * Tier 2 full vision — Groq 11B Agent 1 + Agents 2 + 3 on best frame.
     *
     * @return array<string, mixed>
     */
    function roast_pvp_tier2_run_pipeline(string $imagePath, string $matchId = ''): array
    {
        require_once __DIR__ . '/roast-score.php';
        require_once __DIR__ . '/roast-local-agents.php';
        require_once dirname(__DIR__) . '/roast-limited/agents/agent1-identify.php';
        require_once dirname(__DIR__) . '/roast-limited/agents/agent2-condition.php';
        require_once dirname(__DIR__) . '/roast-limited/agents/agent3-mods.php';

        if (!is_readable($imagePath)) {
            return ['ok' => false, 'error' => roast_error('IMAGE', 'Tier 2 frame missing.', false), 'stage' => 'image'];
        }

        $started = microtime(true);
        $ctx = [
            'force_groq' => true,
            'pvp_tier' => 2,
            'match_id_hash' => $matchId !== '' ? substr(hash('sha256', $matchId), 0, 16) : '',
        ];

        try {
            $a1 = roast_agent1_identify($imagePath, $ctx);
        } catch (Throwable $e) {
            error_log('[Roast PvP][tier2] agent1 threw: ' . $e->getMessage());

            return ['ok' => false, 'error' => roast_error('TIER2', 'Agent 1 failed.', true), 'stage' => 'agent1'];
        }

        if (!($a1['ok'] ?? false) || !roast_pvp_agent1_has_identity($a1)) {
            $code = (string) ($a1['error']['code'] ?? 'AGENT1_FAILED');

            return ['ok' => false, 'error' => roast_error('TIER2', 'Agent 1 vision failed (' . $code . ').', true), 'stage' => 'agent1'];
        }

        $identity = is_array($a1['data'] ?? null) ? $a1['data'] : [];
        if (roast_pvp_frame_is_no_bike($identity, roast_pvp_frame_inspect_shell($identity))) {
            return ['ok' => false, 'error' => roast_error('TIER2', 'Frame is not a bike.', false), 'stage' => 'no_bike'];
        }

        try {
            $a2 = roast_agent2_condition($imagePath, $identity, $ctx);
        } catch (Throwable $e) {
            error_log('[Roast PvP][tier2] agent2 threw: ' . $e->getMessage());
            $a2 = ['ok' => false];
        }

        $condition = (!empty($a2['ok']) && is_array($a2['data'] ?? null)) ? $a2['data'] : [
            'damage' => [],
            'cleanliness_score' => 5,
            'missing_parts' => [],
            'frame_visible' => true,
            'condition_notes' => 'tier2_fallback',
        ];

        try {
            $a3 = roast_agent3_mods($imagePath, $identity, $ctx);
        } catch (Throwable $e) {
            error_log('[Roast PvP][tier2] agent3 threw: ' . $e->getMessage());
            $a3 = ['ok' => false];
        }

        $mods = (!empty($a3['ok']) && is_array($a3['data'] ?? null)) ? $a3['data'] : ['visual_mods' => []];
        $inspect = roast_merge_inspect($condition, $mods);
        $inspect['condition_notes'] = 'tier2_cron';
        $score = roast_compute_pvp_cred_score($identity, $inspect);

        return [
            'ok' => true,
            'score' => $score,
            'identity' => $identity,
            'inspect' => $inspect,
            'tier2_latency_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
            'score_tier' => 2,
            'agent1_backend' => $a1['backend'] ?? null,
            'agent2_backend' => $a2['backend'] ?? null,
            'agent3_backend' => $a3['backend'] ?? null,
        ];
    }
}

if (!function_exists('roast_pvp_tier2_apply_result')) {
    /**
     * Merge Tier 2 cred into match row (revises live_sum in place, no live_count bump).
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     * @return array<string, mixed>
     */
    function roast_pvp_tier2_apply_result(
        string $matchId,
        string $role,
        int $score,
        array $identity,
        array $inspect
    ): array {
        if ($role !== 'a' && $role !== 'b') {
            return ['ok' => false, 'error' => roast_error('PVP', 'Invalid role.', false)];
        }

        $tierCol = $role === 'a' ? 'player_a_score_tier' : 'player_b_score_tier';
        $displayCol = $role === 'a' ? 'player_a_display_score' : 'player_b_display_score';
        $sumCol = $role === 'a' ? 'player_a_live_sum' : 'player_b_live_sum';
        $countCol = $role === 'a' ? 'player_a_live_count' : 'player_b_live_count';
        $bestCol = $role === 'a' ? 'player_a_live_best' : 'player_b_live_best';
        $jobCol = $role === 'a' ? 'player_a_live_job_id' : 'player_b_live_job_id';
        $pendingCol = $role === 'a' ? 'player_a_tier2_pending' : 'player_b_tier2_pending';
        $readyCol = $role === 'a' ? 'player_a_tier2_ready' : 'player_b_tier2_ready';
        $pathCol = $role === 'a' ? 'player_a_tier2_frame_path' : 'player_b_tier2_frame_path';

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return ['ok' => false, 'error' => roast_error('DB', 'Could not save Tier 2 score.', true)];
        }

        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                "SELECT {$tierCol} AS score_tier, {$displayCol} AS display_score,
                        {$sumCol} AS live_sum, {$countCol} AS live_count, {$jobCol} AS live_job_id,
                        {$pathCol} AS frame_path
                 FROM roast_pvp_matches WHERE match_id = ? LIMIT 1 FOR UPDATE"
            );
            $lock->execute([$matchId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $pdo->rollBack();

                return ['ok' => false, 'error' => roast_error('PVP', 'Match not found.', false)];
            }

            $lastDisplay = (int) ($row['display_score'] ?? 0);
            $lastTier = (int) ($row['score_tier'] ?? 0);
            $merged = roast_pvp_merge_tier_score($lastDisplay, $lastTier, $score, 2);
            $display = (int) $merged['display_score'];
            $liveCount = max(1, (int) ($row['live_count'] ?? 0));
            $newSum = $display * $liveCount;

            $jobId = trim((string) ($row['live_job_id'] ?? ''));
            if ($jobId !== '') {
                try {
                    roast_jobs_update($jobId, [
                        'status' => 'partial',
                        'phase' => 'tier2',
                        'identity_json' => $identity,
                        'inspect_json' => $inspect,
                        'score' => $display,
                    ]);
                } catch (Throwable $jobErr) {
                    error_log('[Roast PvP][tier2] job update skipped: ' . $jobErr->getMessage());
                }
            }

            $pdo->prepare(
                "UPDATE roast_pvp_matches
                 SET {$tierCol} = ?, {$displayCol} = ?, {$sumCol} = ?, {$bestCol} = ?,
                     {$pendingCol} = 0, {$readyCol} = 1, {$pathCol} = NULL
                 WHERE match_id = ?"
            )->execute([
                $merged['score_tier'],
                $display,
                $newSum,
                $display,
                $matchId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Roast PvP][tier2] apply_result failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => roast_error('DB', 'Could not save Tier 2 score.', true)];
        }

        roast_pvp_tier2_unlink_frame((string) ($row['frame_path'] ?? ''));

        return [
            'ok' => true,
            'display_score' => $display,
            'score_tier' => (int) $merged['score_tier'],
            'provisional' => (bool) $merged['provisional'],
        ];
    }
}

if (!function_exists('roast_pvp_tier2_claim_side')) {
    /**
     * Atomically claim one tier2_pending side for cron processing.
     *
     * @return array{match_id: string, role: string, frame_path: string}|null
     */
    function roast_pvp_tier2_claim_side(string $matchId, string $role): ?array
    {
        if ($role !== 'a' && $role !== 'b') {
            return null;
        }

        $pendingCol = $role === 'a' ? 'player_a_tier2_pending' : 'player_b_tier2_pending';
        $pathCol = $role === 'a' ? 'player_a_tier2_frame_path' : 'player_b_tier2_frame_path';

        $pdo = roast_pvp_pdo();
        if (!$pdo) {
            return null;
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT {$pendingCol} AS pending, {$pathCol} AS frame_path
                 FROM roast_pvp_matches WHERE match_id = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$matchId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int) ($row['pending'] ?? 0) !== 1) {
                $pdo->rollBack();

                return null;
            }

            $framePath = trim((string) ($row['frame_path'] ?? ''));
            if ($framePath === '' || !is_readable($framePath)) {
                $pdo->prepare(
                    "UPDATE roast_pvp_matches SET {$pendingCol} = 0, {$pathCol} = NULL WHERE match_id = ?"
                )->execute([$matchId]);
                $pdo->commit();
                roast_pvp_tier2_unlink_frame($framePath !== '' ? $framePath : null);

                return null;
            }

            $pdo->prepare(
                "UPDATE roast_pvp_matches SET {$pendingCol} = 0 WHERE match_id = ?"
            )->execute([$matchId]);
            $pdo->commit();

            return [
                'match_id' => $matchId,
                'role' => $role,
                'frame_path' => $framePath,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Roast PvP][tier2] claim failed: ' . $e->getMessage());

            return null;
        }
    }
}

if (!function_exists('roast_pvp_tier2_fetch_pending')) {
    /**
     * @return list<array{match_id: string, role: string}>
     */
    function roast_pvp_tier2_fetch_pending(int $limit = 3): array
    {
        $pdo = roast_pvp_pdo();
        if (!$pdo || $limit <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT match_id,
                    player_a_tier2_pending AS a_pending,
                    player_b_tier2_pending AS b_pending,
                    player_a_tier2_requested_at AS a_at,
                    player_b_tier2_requested_at AS b_at
             FROM roast_pvp_matches
             WHERE player_a_tier2_pending = 1 OR player_b_tier2_pending = 1
             ORDER BY LEAST(
                 COALESCE(player_a_tier2_requested_at, '9999-12-31 23:59:59'),
                 COALESCE(player_b_tier2_requested_at, '9999-12-31 23:59:59')
             ) ASC
             LIMIT ?"
        );
        $stmt->execute([max(1, min(50, $limit))]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $queue = [];

        foreach ($rows as $row) {
            $matchId = trim((string) ($row['match_id'] ?? ''));
            if ($matchId === '') {
                continue;
            }
            $candidates = [];
            if ((int) ($row['a_pending'] ?? 0) === 1) {
                $candidates[] = ['match_id' => $matchId, 'role' => 'a', 'at' => (string) ($row['a_at'] ?? '')];
            }
            if ((int) ($row['b_pending'] ?? 0) === 1) {
                $candidates[] = ['match_id' => $matchId, 'role' => 'b', 'at' => (string) ($row['b_at'] ?? '')];
            }
            usort($candidates, static fn(array $x, array $y): int => strcmp($x['at'], $y['at']));
            foreach ($candidates as $item) {
                $queue[] = ['match_id' => $item['match_id'], 'role' => $item['role']];
                if (count($queue) >= $limit) {
                    return $queue;
                }
            }
        }

        return $queue;
    }
}

if (!function_exists('roast_pvp_tier2_process_side')) {
    /** @return array<string, mixed> */
    function roast_pvp_tier2_process_side(string $matchId, string $role): array
    {
        $claimed = roast_pvp_tier2_claim_side($matchId, $role);
        if ($claimed === null) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'not_pending_or_missing_frame'];
        }

        $framePath = $claimed['frame_path'];
        $pipeline = roast_pvp_tier2_run_pipeline($framePath, $matchId);
        if (!($pipeline['ok'] ?? false)) {
            $stage = (string) ($pipeline['stage'] ?? 'pipeline');
            $requeued = false;
            $frameExists = is_readable($framePath);

            if ($stage === 'agent1') {
                if ($frameExists) {
                    $pendingCol = $role === 'a' ? 'player_a_tier2_pending' : 'player_b_tier2_pending';
                    $pdo = roast_pvp_pdo();
                    if ($pdo) {
                        try {
                            $pdo->prepare(
                                "UPDATE roast_pvp_matches SET {$pendingCol} = 1 WHERE match_id = ?"
                            )->execute([$matchId]);
                            $requeued = true;
                        } catch (Throwable $e) {
                            error_log('[Roast PvP][tier2] agent1 requeue failed: ' . $e->getMessage());
                        }
                    }
                } else {
                    error_log(
                        '[Roast PvP][tier2] agent1 failed; frame missing — needs re-enqueue match '
                        . $matchId . ' role ' . $role
                    );
                }
            } else {
                roast_pvp_tier2_unlink_frame($framePath);
            }

            // #region agent log
            require_once __DIR__ . '/roast-debug-session.php';
            roast_debug_session_log('roast-pvp.php:tier2_process', 'pipeline_failed', [
                'match_id_hash' => substr(hash('sha256', $matchId), 0, 12),
                'stage' => $stage,
                'error_code' => (string) ($pipeline['error']['code'] ?? ''),
                'frame_exists' => $frameExists,
                'requeued' => $requeued,
                'needs_re_enqueue' => $stage === 'agent1' && !$frameExists,
            ], 'H5');
            // #endregion

            return [
                'ok' => false,
                'match_id' => $matchId,
                'role' => $role,
                'stage' => $stage,
                'requeued' => $requeued,
                'error' => $pipeline['error'] ?? roast_error('TIER2', 'Tier 2 pipeline failed.', true),
            ];
        }

        $applied = roast_pvp_tier2_apply_result(
            $matchId,
            $role,
            (int) ($pipeline['score'] ?? 0),
            is_array($pipeline['identity'] ?? null) ? $pipeline['identity'] : [],
            is_array($pipeline['inspect'] ?? null) ? $pipeline['inspect'] : []
        );

        if (!($applied['ok'] ?? false)) {
            roast_pvp_tier2_unlink_frame($framePath);

            return array_merge($applied, ['match_id' => $matchId, 'role' => $role]);
        }

        roast_log_pvp_metric('tier2_cron', [
            'match_id' => $matchId,
            'side' => $role,
            'outcome' => 'ready',
            'score' => (int) ($pipeline['score'] ?? 0),
            'display_score' => (int) ($applied['display_score'] ?? 0),
            'tier2_latency_ms' => (int) ($pipeline['tier2_latency_ms'] ?? 0),
            'score_tier' => 2,
            'agent1_backend' => $pipeline['agent1_backend'] ?? null,
        ]);

        // #region agent log
        require_once __DIR__ . '/roast-debug-session.php';
        roast_debug_session_log('roast-pvp.php:tier2_process', 'tier2_ready', [
            'match_id_hash' => substr(hash('sha256', $matchId), 0, 12),
            'score' => (int) ($pipeline['score'] ?? 0),
            'display_score' => (int) ($applied['display_score'] ?? 0),
            'agent1_backend' => $pipeline['agent1_backend'] ?? null,
        ], 'H5');
        // #endregion

        return [
            'ok' => true,
            'match_id' => $matchId,
            'role' => $role,
            'score' => (int) ($pipeline['score'] ?? 0),
            'display_score' => (int) ($applied['display_score'] ?? 0),
            'score_tier' => 2,
            'tier2_latency_ms' => (int) ($pipeline['tier2_latency_ms'] ?? 0),
        ];
    }
}

if (!function_exists('roast_pvp_tier2_cron_run')) {
    /**
     * Batch-process tier2_pending queue (DB + WebCron — no fire-and-forget curl).
     *
     * @return array<string, mixed>
     */
    function roast_pvp_tier2_cron_run(?int $limit = null): array
    {
        $limit = $limit ?? roast_pvp_tier2_batch_limit();
        $limit = max(1, min(10, $limit));
        $pending = roast_pvp_tier2_fetch_pending($limit);

        if ($pending === []) {
            return [
                'ok' => true,
                'outcome' => 'idle',
                'processed' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        $processed = 0;
        $failed = 0;
        $results = [];

        foreach ($pending as $item) {
            $result = roast_pvp_tier2_process_side(
                (string) $item['match_id'],
                (string) $item['role']
            );
            $results[] = $result;
            if (!empty($result['skipped'])) {
                continue;
            }
            if ($result['ok'] ?? false) {
                $processed++;
            } else {
                $failed++;
                roast_log_pvp_metric('tier2_cron', [
                    'match_id' => (string) ($item['match_id'] ?? ''),
                    'side' => (string) ($item['role'] ?? ''),
                    'outcome' => 'failed',
                    'stage' => (string) ($result['stage'] ?? 'unknown'),
                    'score_tier' => 2,
                ]);
            }
        }

        return [
            'ok' => true,
            'outcome' => $processed > 0 ? 'processed' : ($failed > 0 ? 'failed' : 'idle'),
            'processed' => $processed,
            'failed' => $failed,
            'results' => $results,
        ];
    }
}
