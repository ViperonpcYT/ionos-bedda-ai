<?php
declare(strict_types=1);

/**
 * UserClicks events table — supports IONOS panel schema and legacy Bedda schema.
 *
 * IONOS:  page_url, referrer_url, event_data, created_at, ip_address, user_agent
 * Legacy: page, referrer, data, timestamp
 */

/** @return array{time:string,page:string,referrer:string,data:string,has_ip:bool,has_ua:bool} */
function onlybikes_events_schema(PDO $pdo): array
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }

    $cols = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('created_at', $cols, true)) {
        $schema = [
            'time' => 'created_at',
            'page' => 'page_url',
            'referrer' => 'referrer_url',
            'data' => 'event_data',
            'has_ip' => in_array('ip_address', $cols, true),
            'has_ua' => in_array('user_agent', $cols, true),
        ];
    } else {
        $schema = [
            'time' => 'timestamp',
            'page' => 'page',
            'referrer' => 'referrer',
            'data' => 'data',
            'has_ip' => false,
            'has_ua' => false,
        ];
    }

    return $schema;
}

function onlybikes_events_time_col(array $schema): string
{
    return $schema['time'] === 'timestamp' ? '`timestamp`' : $schema['time'];
}

function onlybikes_events_time_filter(string $period, array $schema): string
{
    if ($period === 'all' || $period === '') {
        return '';
    }

    $col = onlybikes_events_time_col($schema);
    if ($period === '24h') {
        return "AND {$col} >= NOW() - INTERVAL 24 HOUR";
    }
    if ($period === '7d') {
        return "AND {$col} >= NOW() - INTERVAL 7 DAY";
    }
    if ($period === '30d') {
        return "AND {$col} >= NOW() - INTERVAL 30 DAY";
    }

    return '';
}

/** @return array<string, mixed> */
function onlybikes_normalize_event_row(array $row, array $schema): array
{
    $pageCol = $schema['page'];
    $refCol = $schema['referrer'];
    $dataCol = $schema['data'];
    $timeCol = $schema['time'];

    $data = $row[$dataCol] ?? $row['data'] ?? null;
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        $data = is_array($decoded) ? $decoded : $data;
    }

    return [
        'id' => $row['id'] ?? null,
        'session_id' => $row['session_id'] ?? null,
        'user_id' => $row['user_id'] ?? null,
        'event_type' => $row['event_type'] ?? null,
        'page' => $row[$pageCol] ?? $row['page'] ?? null,
        'referrer' => $row[$refCol] ?? $row['referrer'] ?? null,
        'data' => $data,
        'timestamp' => $row[$timeCol] ?? $row['timestamp'] ?? $row['created_at'] ?? null,
    ];
}

function onlybikes_ensure_events_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $exists = (bool) $pdo->query("SHOW TABLES LIKE 'events'")->fetchColumn();
    if ($exists) {
        onlybikes_events_schema($pdo);
        $done = true;
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          session_id VARCHAR(128) NULL,
          user_id VARCHAR(128) NULL,
          event_type VARCHAR(64) NOT NULL,
          page_url VARCHAR(512) NULL,
          referrer_url VARCHAR(512) NULL,
          ip_address VARCHAR(45) NULL,
          user_agent VARCHAR(512) NULL,
          event_data JSON NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_event_type (event_type),
          KEY idx_created_at (created_at),
          KEY idx_session (session_id),
          KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    onlybikes_events_schema($pdo);
    $done = true;
}

function onlybikes_events_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $raw = (string) $_SERVER[$key];
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $raw = trim(explode(',', $raw)[0]);
        }
        if (filter_var($raw, FILTER_VALIDATE_IP)) {
            return substr($raw, 0, 45);
        }
    }
    return 'unknown';
}

function onlybikes_events_user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
}
