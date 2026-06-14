<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/analytics-schema.php';
header('Content-Type: application/json');

try {
    $pdo = getAnalyticsDatabaseFromConfig();
    onlybikes_ensure_events_table($pdo);

    $schema = onlybikes_events_schema($pdo);
    $period = $_GET['period'] ?? 'all';
    $limit  = min((int) ($_GET['limit'] ?? 1000), 10000);
    $timeFilter = onlybikes_events_time_filter($period, $schema);

    $pageCol = $schema['page'];
    $refCol = $schema['referrer'];
    $dataCol = $schema['data'];
    $timeCol = onlybikes_events_time_col($schema);

    $stmt = $pdo->prepare("
        SELECT id, session_id, user_id, event_type,
               {$pageCol} AS page,
               {$refCol} AS referrer,
               {$dataCol} AS data,
               {$timeCol} AS timestamp
        FROM events
        WHERE 1=1 {$timeFilter}
        ORDER BY {$timeCol} DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $events = array_map(
        static fn(array $row): array => onlybikes_normalize_event_row($row, $schema),
        $stmt->fetchAll()
    );

    $stmt = $pdo->prepare("
        SELECT event_type, COUNT(*) AS count
        FROM events
        WHERE 1=1 {$timeFilter}
        GROUP BY event_type
        ORDER BY count DESC
    ");
    $stmt->execute();
    $eventTypes = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT {$pageCol} AS page, COUNT(*) AS count
        FROM events
        WHERE {$pageCol} IS NOT NULL AND {$pageCol} != '' {$timeFilter}
        GROUP BY {$pageCol}
        ORDER BY count DESC
        LIMIT 10
    ");
    $stmt->execute();
    $topPages = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT {$refCol} AS referrer, COUNT(*) AS count
        FROM events
        WHERE {$refCol} IS NOT NULL AND {$refCol} != '' {$timeFilter}
        GROUP BY {$refCol}
        ORDER BY count DESC
        LIMIT 10
    ");
    $stmt->execute();
    $topReferrers = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE event_type = 'add_to_cart' {$timeFilter}");
    $stmt->execute();
    $addToCarts = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE event_type = 'cart_view' {$timeFilter}");
    $stmt->execute();
    $cartViews = (int) $stmt->fetchColumn();

    $pageViews = count(array_filter($events, static fn(array $e): bool => ($e['event_type'] ?? '') === 'page_view'));

    echo json_encode([
        'events' => $events,
        'eventTypes' => $eventTypes,
        'topPages' => $topPages,
        'topReferrers' => $topReferrers,
        'conversionMetrics' => [
            'addToCarts' => $addToCarts,
            'cartViews' => $cartViews,
            'conversionRate' => $pageViews > 0 ? $addToCarts / $pageViews : 0,
            'cartAbandonment' => $addToCarts > 0 ? max(0, 1 - ($cartViews / $addToCarts)) : 0,
        ],
    ]);

} catch (Throwable $e) {
    error_log('get-analytics.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch analytics data']);
}
