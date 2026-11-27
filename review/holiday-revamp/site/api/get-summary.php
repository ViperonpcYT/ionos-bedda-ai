<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security-helpers.php';
bedda_require_admin_api_auth('get_summary_unauth');

require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

try {
    $pdo = getDatabase();
    $period = $_GET['period'] ?? '24h';

    $timeFilter = '';
    if ($period === '24h') {
        $timeFilter = 'AND created_at >= NOW() - INTERVAL 24 HOUR';
    } elseif ($period === '7d') {
        $timeFilter = 'AND created_at >= NOW() - INTERVAL 7 DAY';
    } elseif ($period === '30d') {
        $timeFilter = 'AND created_at >= NOW() - INTERVAL 30 DAY';
    }

    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS totalEvents,
            COUNT(DISTINCT user_id) AS uniqueUsers,
            COUNT(DISTINCT session_id) AS uniqueSessions
        FROM analytics_events
        WHERE 1=1 $timeFilter
    ");
    $stats = $stmt->fetch();

    $stmt = $pdo->query("
        SELECT COUNT(*) AS addToCarts
        FROM analytics_events
        WHERE event_type = 'add_to_cart' $timeFilter
    ");
    $cartStats = $stmt->fetch();

    echo json_encode([
        'summary' => [
            'totalEvents' => (int) ($stats['totalEvents'] ?? 0),
            'uniqueUsers' => (int) ($stats['uniqueUsers'] ?? 0),
            'uniqueSessions' => (int) ($stats['uniqueSessions'] ?? 0),
            'addToCarts' => (int) ($cartStats['addToCarts'] ?? 0),
        ],
    ]);
} catch (Throwable $e) {
    error_log('get-summary.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch summary data']);
}
