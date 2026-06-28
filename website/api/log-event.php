<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

bedda_cors_preflight();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bedda_send_json(['success' => false, 'message' => 'Method not allowed'], 405);
}

$body = bedda_read_json_body();

// Normalize: logger sends { events: [...] }, bedda-ai sends a single event object
$events = [];
if (isset($body['events']) && is_array($body['events'])) {
    $events = $body['events'];
} elseif ($body !== []) {
    $events = [$body];
}

if ($events === []) {
    bedda_send_json(['success' => true, 'stored' => 0]);
}

// If DB is not configured, accept events silently (analytics must not break the storefront)
if (!bedda_db_configured()) {
    bedda_send_json(['success' => true, 'stored' => 0, 'note' => 'analytics_disabled']);
}

$pdo = bedda_pdo();
bedda_ensure_schema($pdo);

$stmt = $pdo->prepare(
    'INSERT INTO analytics_events (event_type, session_id, user_id, page, payload)
     VALUES (:event_type, :session_id, :user_id, :page, :payload)'
);

$stored = 0;
foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }

    $eventType = (string) ($event['event_type'] ?? $event['type'] ?? 'unknown');
    $sessionId = (string) ($event['session_id'] ?? $event['sessionId'] ?? '');
    $userId = (string) ($event['user_id'] ?? $event['userId'] ?? '');
    $page = (string) ($event['page'] ?? '');
    $payload = $event['data'] ?? $event;
    if (isset($payload['data']) && is_array($payload['data'])) {
        $payload = $payload['data'];
    }

    $stmt->execute([
        ':event_type' => substr($eventType, 0, 64),
        ':session_id' => substr($sessionId, 0, 128),
        ':user_id' => substr($userId, 0, 128),
        ':page' => substr($page, 0, 512),
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $stored++;
}

bedda_send_json(['success' => true, 'stored' => $stored]);
