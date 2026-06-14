<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/analytics-schema.php';
header('Content-Type: application/json');

try {
    $pdo = getAnalyticsDatabaseFromConfig();
    onlybikes_ensure_events_table($pdo);

    $schema = onlybikes_events_schema($pdo);
    $period = $_GET['period'] ?? '24h';
    $timeFilter = onlybikes_events_time_filter($period, $schema);

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS totalEvents,
            COUNT(DISTINCT user_id) AS uniqueUsers,
            COUNT(DISTINCT session_id) AS uniqueSessions
        FROM events
        WHERE 1=1 {$timeFilter}
    ");
    $stmt->execute();
    $stats = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS addToCarts
        FROM events
        WHERE event_type = 'add_to_cart' {$timeFilter}
    ");
    $stmt->execute();
    $cartStats = $stmt->fetch();

    echo json_encode([
        'summary' => [
            'totalEvents'    => (int) ($stats['totalEvents'] ?? 0),
            'uniqueUsers'    => (int) ($stats['uniqueUsers'] ?? 0),
            'uniqueSessions' => (int) ($stats['uniqueSessions'] ?? 0),
            'addToCarts'     => (int) ($cartStats['addToCarts'] ?? 0),
        ],
    ]);

} catch (Throwable $e) {
    error_log('get-summary.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch summary data']);
}
