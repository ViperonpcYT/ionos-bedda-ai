<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/analytics-schema.php';

// Do not load bootstrap.php here — secure-config.php on Ionos already defines overlapping helpers.

function onlybikes_log_event_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function onlybikes_log_event_cors_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
        exit;
    }
}

function onlybikes_log_event_read_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function onlybikes_sanitize_analytics_value($value)
{
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $safeKey = is_string($key)
                ? substr(preg_replace('/[^a-zA-Z0-9_\-:.]/', '_', $key), 0, 128)
                : $key;
            $clean[$safeKey] = onlybikes_sanitize_analytics_value($item);
        }
        return $clean;
    }

    if (is_string($value)) {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $value = strip_tags($value);
        return mb_substr($value, 0, 2000);
    }

    return $value;
}

function onlybikes_analytics_db_configured(): bool
{
    if (function_exists('getAnalyticsDatabase')) {
        return true;
    }

    return DB_HOST !== '' && DB_NAME !== '' && DB_USER !== '' && DB_PASS !== '';
}

onlybikes_log_event_cors_preflight();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    onlybikes_log_event_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

$body = onlybikes_log_event_read_body();

$events = [];
if (isset($body['events']) && is_array($body['events'])) {
    $events = $body['events'];
} elseif ($body !== [] && array_keys($body) === range(0, count($body) - 1)) {
    $events = $body;
} elseif ($body !== []) {
    $events = [$body];
}

if ($events === []) {
    onlybikes_log_event_json(['success' => true, 'stored' => 0]);
}

if (!onlybikes_analytics_db_configured()) {
    onlybikes_log_event_json(['success' => true, 'stored' => 0, 'note' => 'analytics_disabled']);
}

$stored = 0;
$dbName = '';

try {
    $pdo = getAnalyticsDatabaseFromConfig();
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    onlybikes_ensure_events_table($pdo);
    $schema = onlybikes_events_schema($pdo);

    if ($schema['page'] === 'page_url') {
        $stmt = $pdo->prepare(
            'INSERT INTO events (session_id, user_id, event_type, page_url, referrer_url, ip_address, user_agent, event_data, created_at)
             VALUES (:session_id, :user_id, :event_type, :page, :referrer, :ip_address, :user_agent, :data, NOW())'
        );
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO events (session_id, user_id, event_type, page, referrer, data, `timestamp`)
             VALUES (:session_id, :user_id, :event_type, :page, :referrer, :data, NOW())'
        );
    }

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $eventType = preg_replace(
            '/[^a-zA-Z0-9_\-:.]/',
            '',
            (string) ($event['event_type'] ?? $event['type'] ?? $event['eventType'] ?? 'unknown')
        );
        $sessionId = (string) ($event['session_id'] ?? $event['sessionId'] ?? '');
        $userId = (string) ($event['user_id'] ?? $event['userId'] ?? '');
        $payload = $event['data'] ?? $event;
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload = onlybikes_sanitize_analytics_value($payload);

        $page = strip_tags((string) (
            $event['page']
            ?? $payload['page']
            ?? $payload['url']
            ?? ''
        ));
        $referrer = strip_tags((string) (
            $event['referrer']
            ?? $payload['referrer']
            ?? $_SERVER['HTTP_REFERER']
            ?? ''
        ));

        $params = [
            ':session_id' => substr($sessionId, 0, 128),
            ':user_id' => substr($userId, 0, 128),
            ':event_type' => substr($eventType, 0, 64),
            ':page' => substr($page, 0, 512),
            ':referrer' => substr($referrer, 0, 512),
            ':data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($schema['page'] === 'page_url') {
            $params[':ip_address'] = onlybikes_events_client_ip();
            $params[':user_agent'] = onlybikes_events_user_agent();
        }

        $stmt->execute($params);
        $stored++;
    }
} catch (Throwable $e) {
    error_log('OnlyBikes log-event: ' . $e->getMessage() . ' db=' . $dbName);
    onlybikes_log_event_json([
        'success' => true,
        'stored' => 0,
        'note' => 'analytics_store_failed',
    ]);
}

onlybikes_log_event_json([
    'success' => true,
    'stored' => $stored,
    'database' => $dbName,
]);
