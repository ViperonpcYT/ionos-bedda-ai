<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security-helpers.php';
bedda_require_admin_api_auth('get_analytics_unauth');

require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

try {
    $pdo = getDatabase();

    $period = $_GET['period'] ?? 'all';
    $limit  = min(max((int) ($_GET['limit'] ?? 1000), 1), 10000);

    $timeFilter = '';
    if ($period === '24h') {
        $timeFilter = 'AND created_at >= NOW() - INTERVAL 24 HOUR';
    } elseif ($period === '7d') {
        $timeFilter = 'AND created_at >= NOW() - INTERVAL 7 DAY';
    } elseif ($period === '30d') {
        $timeFilter = 'AND created_at >= NOW() - INTERVAL 30 DAY';
    }

    // Logger writes analytics_events.payload / created_at. The old queries read events.timestamp.
    $stmt = $pdo->query("
        SELECT id, session_id, user_id, event_type, page, NULL AS referrer, payload AS data, created_at AS timestamp
        FROM analytics_events
        WHERE 1=1 $timeFilter
        ORDER BY created_at DESC
        LIMIT $limit
    ");
    $events = $stmt->fetchAll();

    $stmt = $pdo->query("
        SELECT event_type, COUNT(*) AS count
        FROM analytics_events
        WHERE 1=1 $timeFilter
        GROUP BY event_type
        ORDER BY count DESC
    ");
    $eventTypes = $stmt->fetchAll();

    $stmt = $pdo->query("
        SELECT page, COUNT(*) AS count
        FROM analytics_events
        WHERE page IS NOT NULL AND page != '' $timeFilter
        GROUP BY page
        ORDER BY count DESC
        LIMIT 10
    ");
    $topPages = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT COUNT(*) FROM analytics_events WHERE event_type = 'add_to_cart' $timeFilter");
    $addToCarts = (int) $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM analytics_events WHERE event_type = 'cart_view' $timeFilter");
    $cartViews = (int) $stmt->fetchColumn();

    $pageViews = count(array_filter($events, static fn ($e) => ($e['event_type'] ?? '') === 'page_view'));

    echo json_encode([
        'events' => $events,
        'eventTypes' => $eventTypes,
        'topPages' => $topPages,
        'topReferrers' => [],
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
