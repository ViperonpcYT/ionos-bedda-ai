<?php
declare(strict_types=1);

require_once __DIR__ . '/roast-config.php';

if (!function_exists('roast_jobs_pdo')) {
    function roast_jobs_pdo(): ?PDO
    {
        static $pdo = null;
        static $failed = false;
        if ($failed) {
            return null;
        }
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        try {
            if (roast_jobs_has_dedicated_db()) {
                $pdo = roast_jobs_connect_dedicated();
            } elseif (is_readable(dirname(__DIR__) . '/config.php')) {
                require_once dirname(__DIR__) . '/config.php';
                if (function_exists('getAnalyticsDatabaseFromConfig')) {
                    $pdo = getAnalyticsDatabaseFromConfig();
                } elseif (function_exists('getAnalyticsDatabase')) {
                    $pdo = getAnalyticsDatabase();
                } else {
                    $failed = true;
                    return null;
                }
            } else {
                $failed = true;
                return null;
            }
            roast_jobs_ensure_schema($pdo);
            return $pdo;
        } catch (Throwable $e) {
            error_log('[Roast] DB unavailable: ' . $e->getMessage());
            $failed = true;
            return null;
        }
    }
}

if (!function_exists('roast_jobs_has_dedicated_db')) {
    function roast_jobs_has_dedicated_db(): bool
    {
        return ROAST_DB_HOST !== '' && ROAST_DB_NAME !== '' && ROAST_DB_USER !== '';
    }
}

if (!function_exists('roast_jobs_connect_dedicated')) {
    function roast_jobs_connect_dedicated(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            ROAST_DB_HOST,
            ROAST_DB_PORT,
            ROAST_DB_NAME
        );
        return new PDO($dsn, ROAST_DB_USER, ROAST_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

if (!function_exists('roast_jobs_ensure_schema')) {
    function roast_jobs_ensure_schema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS roast_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id CHAR(36) NOT NULL,
  status ENUM('processing','partial','complete','failed') NOT NULL DEFAULT 'processing',
  phase VARCHAR(32) NOT NULL DEFAULT 'pending',
  identity_json JSON NULL,
  inspect_json JSON NULL,
  roast_text MEDIUMTEXT NULL,
  score SMALLINT UNSIGNED NULL,
  steps_json JSON NULL,
  error_json JSON NULL,
  image_hash CHAR(64) NULL,
  ip_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roast_job_id (job_id),
  KEY idx_roast_status (status, updated_at),
  KEY idx_roast_ip_day (ip_hash, created_at),
  KEY idx_roast_image_hash (image_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        $pdo->exec($sql);
    }
}

if (!function_exists('roast_jobs_new_id')) {
    function roast_jobs_new_id(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}

if (!function_exists('roast_jobs_count_today')) {
    function roast_jobs_count_today(): int
    {
        $pdo = roast_jobs_pdo();
        if (!$pdo) {
            return 0;
        }
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM roast_jobs WHERE DATE(created_at) = CURDATE() AND status IN ('processing','partial','complete')"
        );
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('roast_jobs_ip_count_today')) {
    function roast_jobs_ip_count_today(string $ipHash): int
    {
        $pdo = roast_jobs_pdo();
        if (!$pdo) {
            return 0;
        }
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM roast_jobs WHERE ip_hash = ? AND DATE(created_at) = CURDATE() AND status IN ('processing','partial','complete')"
        );
        $stmt->execute([$ipHash]);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('roast_jobs_find_by_hash')) {
    /** @return array<string, mixed>|null */
    function roast_jobs_find_complete_by_hash(string $imageHash): ?array
    {
        $pdo = roast_jobs_pdo();
        if (!$pdo || $imageHash === '') {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM roast_jobs WHERE image_hash = ? AND status = 'complete' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$imageHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('roast_jobs_create')) {
    function roast_jobs_create(string $jobId, string $ipHash, string $imageHash = ''): bool
    {
        $pdo = roast_jobs_pdo();
        if (!$pdo) {
            return false;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO roast_jobs (job_id, status, phase, ip_hash, image_hash) VALUES (?, ?, ?, ?, ?)'
        );
        return $stmt->execute([$jobId, 'processing', 'pending', $ipHash, $imageHash !== '' ? $imageHash : null]);
    }
}

if (!function_exists('roast_jobs_get')) {
    /** @return array<string, mixed>|null */
    function roast_jobs_get(string $jobId): ?array
    {
        $pdo = roast_jobs_pdo();
        if (!$pdo) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM roast_jobs WHERE job_id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('roast_jobs_update')) {
    /** @param array<string, mixed> $fields */
    function roast_jobs_update(string $jobId, array $fields): bool
    {
        $pdo = roast_jobs_pdo();
        if (!$pdo || $fields === []) {
            return false;
        }
        $allowed = [
            'status', 'phase', 'identity_json', 'inspect_json', 'roast_text',
            'score', 'steps_json', 'error_json', 'image_hash',
        ];
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            if (in_array($k, ['identity_json', 'inspect_json', 'steps_json', 'error_json'], true) && is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $sets[] = "$k = ?";
            $vals[] = $v;
        }
        if ($sets === []) {
            return false;
        }
        $vals[] = $jobId;
        $sql = 'UPDATE roast_jobs SET ' . implode(', ', $sets) . ' WHERE job_id = ?';
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($vals);
    }
}

if (!function_exists('roast_jobs_row_to_envelope')) {
    /** @param array<string, mixed> $row */
    function roast_jobs_row_to_envelope(array $row): array
    {
        require_once __DIR__ . '/roast-envelope.php';

        $jobId = (string) ($row['job_id'] ?? '');
        $status = (string) ($row['status'] ?? 'processing');
        $steps = [];
        if (!empty($row['steps_json'])) {
            $decoded = json_decode((string) $row['steps_json'], true);
            if (is_array($decoded)) {
                $steps = $decoded;
            }
        }
        $error = null;
        if (!empty($row['error_json'])) {
            $decoded = json_decode((string) $row['error_json'], true);
            if (is_array($decoded)) {
                $error = $decoded;
            }
        }

        $identity = [];
        $inspect = [];
        if (!empty($row['identity_json'])) {
            $d = json_decode((string) $row['identity_json'], true);
            if (is_array($d)) {
                $identity = $d;
            }
        }
        if (!empty($row['inspect_json'])) {
            $d = json_decode((string) $row['inspect_json'], true);
            if (is_array($d)) {
                $inspect = $d;
            }
        }

        $result = null;
        if ($status === 'complete' || $status === 'partial') {
            $result = roast_build_result(
                isset($row['score']) ? (int) $row['score'] : null,
                $identity,
                $inspect,
                isset($row['roast_text']) ? (string) $row['roast_text'] : null,
                $status === 'partial' ? 'Judgment cut short — showing what we got.' : null
            );
        }

        $ok = $status !== 'failed';
        if ($status === 'processing') {
            return roast_envelope($jobId, 'processing', true, null, $steps, null);
        }

        return roast_envelope($jobId, $status, $ok, $result, $steps, $error);
    }
}

if (!function_exists('roast_jobs_purge_stale')) {
    function roast_jobs_purge_stale(int $hours = 24): int
    {
        $pdo = roast_jobs_pdo();
        if (!$pdo) {
            return 0;
        }
        require_once __DIR__ . '/roast-envelope.php';
        $stmt = $pdo->prepare(
            "UPDATE roast_jobs SET status = 'failed', error_json = ? WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)"
        );
        $err = json_encode(roast_error('STALE_JOB', 'Job timed out on server.', true, ''));
        $stmt->execute([$err, $hours]);
        return $stmt->rowCount();
    }
}
