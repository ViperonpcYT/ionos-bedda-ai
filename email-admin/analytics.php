<?php
// Email-admin auth (session + requireLogin)
require_once __DIR__ . '/config.php';
session_name(EMAIL_ADMIN_SESSION);
session_start();
require_once __DIR__ . '/includes/functions.php';
requireLogin();

// Same UserClicks DB as api/log-event.php (ANALYTICS_DB_* in api/.env)
try {
    require_once API_PATH . '/config.php';
    require_once API_PATH . '/lib/analytics-schema.php';
    $pdo = getAnalyticsDatabaseFromConfig();
    onlybikes_ensure_events_table($pdo);
} catch (Throwable $e) {
    error_log('Analytics DB failed: ' . $e->getMessage());
    die('<p style="padding:2rem;color:red;">Could not connect to analytics database. Set ANALYTICS_DB_* in api/.env and run api/sql/01-analytics-userclicks.sql.</p>');
}
$period = $_GET['period'] ?? '24h';
$schema = onlybikes_events_schema($pdo);
$timeFilter = onlybikes_events_time_filter($period, $schema);
$pageCol = $schema['page'];
$refCol = $schema['referrer'];
$dataCol = $schema['data'];
$timeCol = onlybikes_events_time_col($schema);

try {
    // Summary stats
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS totalEvents,
            COUNT(DISTINCT user_id) AS uniqueUsers,
            COUNT(DISTINCT session_id) AS uniqueSessions
        FROM events WHERE 1=1 {$timeFilter}
    ");
    $stmt->execute();
    $summary = $stmt->fetch();

    // Add to cart count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE event_type='add_to_cart' {$timeFilter}");
    $stmt->execute();
    $addToCarts = (int) $stmt->fetchColumn();

    // Page views
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE event_type='page_view' {$timeFilter}");
    $stmt->execute();
    $pageViews = (int) $stmt->fetchColumn();

    // Top pages
    $stmt = $pdo->prepare("
        SELECT {$pageCol} AS page, COUNT(*) AS cnt FROM events
        WHERE {$pageCol} IS NOT NULL AND {$pageCol} != '' {$timeFilter}
        GROUP BY {$pageCol} ORDER BY cnt DESC LIMIT 8
    ");
    $stmt->execute();
    $topPages = $stmt->fetchAll();

    // Event type breakdown
    $stmt = $pdo->prepare("
        SELECT event_type, COUNT(*) AS cnt FROM events
        WHERE 1=1 {$timeFilter}
        GROUP BY event_type ORDER BY cnt DESC LIMIT 12
    ");
    $stmt->execute();
    $eventTypes = $stmt->fetchAll();

    // Top referrers
    $stmt = $pdo->prepare("
        SELECT {$refCol} AS referrer, COUNT(*) AS cnt FROM events
        WHERE {$refCol} IS NOT NULL AND {$refCol} != '' {$timeFilter}
        GROUP BY {$refCol} ORDER BY cnt DESC LIMIT 6
    ");
    $stmt->execute();
    $topReferrers = $stmt->fetchAll();

    // Recent events
    $stmt = $pdo->prepare("
        SELECT id, session_id, user_id, event_type,
               {$pageCol} AS page, {$dataCol} AS data, {$timeCol} AS timestamp
        FROM events WHERE 1=1 {$timeFilter}
        ORDER BY {$timeCol} DESC LIMIT 50
    ");
    $stmt->execute();
    $recentEvents = array_map(
        static fn(array $row): array => onlybikes_normalize_event_row($row, $schema),
        $stmt->fetchAll()
    );

    // Hourly activity (last 24h always for chart)
    $stmt = $pdo->query("
        SELECT HOUR({$timeCol}) AS hr, COUNT(*) AS cnt
        FROM events
        WHERE {$timeCol} >= NOW() - INTERVAL 24 HOUR
        GROUP BY HOUR({$timeCol})
        ORDER BY hr ASC
    ");
    $hourlyData = $stmt->fetchAll();

    $conversionRate = $pageViews > 0 ? round(($addToCarts / $pageViews) * 100, 2) : 0;

} catch (Exception $e) {
    error_log('analytics.php error: ' . $e->getMessage());
    $summary = ['totalEvents'=>0,'uniqueUsers'=>0,'uniqueSessions'=>0];
    $addToCarts = $pageViews = $conversionRate = 0;
    $topPages = $eventTypes = $topReferrers = $recentEvents = $hourlyData = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - OnlyBikes Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        .font-playfair { font-family: 'Playfair Display', serif; }
        .font-inter    { font-family: 'Inter', sans-serif; }
        .stat-card     { transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
        .pill { display:inline-flex; align-items:center; padding:2px 10px; border-radius:9999px; font-size:0.7rem; font-weight:600; }
        .badge-page_view    { background:#F5F3EF; color:#5A4D3F; }
        .badge-click        { background:#F5F3EF; color:#5A4D3F; }
        .badge-add_to_cart  { background:#dcfce7; color:#15803d; }
        .badge-session_start{ background:#f3e8ff; color:#7e22ce; }
        .badge-error        { background:#fee2e2; color:#2C2C2C; }
        .badge-default      { background:#f1f5f9; color:#5A4D3F; }
        .period-btn         { padding:6px 16px; border-radius:6px; font-size:0.85rem; font-weight:500; cursor:pointer; border:1px solid #E8E4DC; transition:all 0.15s; }
        .period-btn.active  { background:#9C8C73; color:#fff; border-color:#9C8C73; }
        .period-btn:not(.active):hover { background:#F5F3EF; border-color:#9C8C73; color:#5A4D3F; }
        .bar { height:8px; border-radius:4px; background:#9C8C73; transition:width 0.6s ease; }
    </style>
</head>
<body class="font-inter bg-stone-50 min-h-screen">

<?php renderNav('analytics'); ?>

<div class="max-w-7xl mx-auto px-4 py-8">

    <!-- Header row -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
            <div class="flex items-center gap-4">
                <h1 class="font-playfair text-2xl font-bold text-stone-800">Analytics</h1>
                <a href="../analytics-backend.html" class="inline-flex items-center text-sm font-medium text-sage-600 bg-sage-50 hover:bg-sage-100 px-3 py-1.5 rounded-lg transition-colors border border-sage-200">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    Advanced AI Analytics
                </a>
            </div>
            <p class="text-stone-500 text-sm mt-1">User behaviour &amp; engagement</p>
        </div>
        <!-- Period selector -->
        <form method="GET" class="flex gap-2">
            <?php foreach (['24h'=>'24 Hours','7d'=>'7 Days','30d'=>'30 Days','all'=>'All Time'] as $val=>$label): ?>
            <button type="submit" name="period" value="<?= $val ?>"
                class="period-btn <?= $period===$val ? 'active' : '' ?>">
                <?= $label ?>
            </button>
            <?php endforeach; ?>
        </form>
    </div>

    <!-- Stat cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <?php
        $cards = [
            ['Total Events',    number_format($summary['totalEvents']),   '#5A4D3F', 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            ['Unique Users',    number_format($summary['uniqueUsers']),   '#5A4D3F', 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
            ['Add to Carts',    number_format($addToCarts),               '#7E6D58', 'M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5 6m0 0h9'],
            ['Conversion Rate', $conversionRate . '%',                    '#B5A183', 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6'],
        ];
        foreach ($cards as [$label, $value, $color, $path]): ?>
        <div class="stat-card bg-white rounded-xl p-5 border border-stone-100 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-xs font-medium text-stone-500 uppercase tracking-wide"><?= $label ?></p>
                    <p class="text-2xl font-bold text-stone-800 mt-1"><?= $value ?></p>
                </div>
                <div class="p-2 rounded-lg" style="background:<?= $color ?>18">
                    <svg class="w-5 h-5" fill="none" stroke="<?= $color ?>" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="<?= $path ?>"/>
                    </svg>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Hourly chart + Event types -->
    <div class="grid lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-2 bg-white rounded-xl border border-stone-100 shadow-sm p-6">
            <h2 class="font-semibold text-stone-700 mb-4">Activity — Last 24 Hours</h2>
            <canvas id="hourlyChart" height="100"></canvas>
        </div>
        <div class="bg-white rounded-xl border border-stone-100 shadow-sm p-6">
            <h2 class="font-semibold text-stone-700 mb-4">Event Breakdown</h2>
            <?php
            $maxEvt = max(1, max(array_column($eventTypes, 'cnt') ?: [1]));
            foreach ($eventTypes as $et):
                $pct = round(($et['cnt'] / $maxEvt) * 100);
            ?>
            <div class="mb-3">
                <div class="flex justify-between text-xs mb-1">
                    <span class="text-stone-600"><?= htmlspecialchars(str_replace('_',' ',$et['event_type'])) ?></span>
                    <span class="font-semibold text-stone-800"><?= number_format($et['cnt']) ?></span>
                </div>
                <div class="bg-stone-100 rounded-full h-2">
                    <div class="bar" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($eventTypes)): ?>
                <p class="text-stone-400 text-sm">No events yet</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Pages + Referrers -->
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-xl border border-stone-100 shadow-sm p-6">
            <h2 class="font-semibold text-stone-700 mb-4">Top Pages</h2>
            <?php
            $maxPg = max(1, max(array_column($topPages, 'cnt') ?: [1]));
            foreach ($topPages as $pg):
                $pct = round(($pg['cnt'] / $maxPg) * 100);
            ?>
            <div class="mb-3">
                <div class="flex justify-between text-xs mb-1">
                    <span class="text-stone-600 truncate max-w-[200px]"><?= htmlspecialchars($pg['page']) ?></span>
                    <span class="font-semibold text-stone-800 ml-2"><?= number_format($pg['cnt']) ?></span>
                </div>
                <div class="bg-stone-100 rounded-full h-2">
                    <div class="bar" style="width:<?= $pct ?>%; background:#5A4D3F"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($topPages)): ?>
                <p class="text-stone-400 text-sm">No page data yet</p>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-xl border border-stone-100 shadow-sm p-6">
            <h2 class="font-semibold text-stone-700 mb-4">Top Referrers</h2>
            <?php if (empty($topReferrers)): ?>
                <p class="text-stone-400 text-sm">No referrer data yet — most visitors are direct</p>
            <?php else:
                $maxRef = max(1, max(array_column($topReferrers,'cnt')));
                foreach ($topReferrers as $ref):
                    $pct = round(($ref['cnt']/$maxRef)*100);
            ?>
            <div class="mb-3">
                <div class="flex justify-between text-xs mb-1">
                    <span class="text-stone-600 truncate max-w-[200px]"><?= htmlspecialchars($ref['referrer']) ?></span>
                    <span class="font-semibold text-stone-800 ml-2"><?= number_format($ref['cnt']) ?></span>
                </div>
                <div class="bg-stone-100 rounded-full h-2">
                    <div class="bar" style="width:<?= $pct ?>%; background:#7E6D58"></div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Recent Events Table -->
    <div class="bg-white rounded-xl border border-stone-100 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-stone-100 flex justify-between items-center">
            <h2 class="font-semibold text-stone-700">Recent Events</h2>
            <span class="text-xs text-stone-400">Last 50</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-stone-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wide">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wide">Event</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wide">Page</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wide">User</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase tracking-wide">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-50">
                <?php if (empty($recentEvents)): ?>
                    <tr><td colspan="5" class="px-4 py-8 text-center text-stone-400">No events recorded yet</td></tr>
                <?php else: foreach ($recentEvents as $ev):
                    $badgeClass = 'badge-' . (in_array($ev['event_type'], ['page_view','click','add_to_cart','session_start','error']) ? $ev['event_type'] : 'default');
                    $rawData = $ev['data'] ?? null;
                    $evData = is_array($rawData)
                        ? $rawData
                        : (json_decode(is_string($rawData) ? $rawData : '{}', true) ?: []);
                    $detail = match($ev['event_type']) {
                        'click'        => 'Text: ' . substr($evData['text'] ?? '—', 0, 30),
                        'add_to_cart'  => 'Product: ' . ($evData['product'] ?? '—'),
                        'page_view'    => 'Load: ' . round($evData['loadTime'] ?? 0) . 'ms',
                        'error'        => substr($evData['message'] ?? '—', 0, 50),
                        default        => substr(json_encode($evData), 0, 60)
                    };
                ?>
                    <tr class="hover:bg-stone-50 transition-colors">
                        <td class="px-4 py-3 text-stone-500 whitespace-nowrap text-xs">
                            <?= date('M j H:i:s', strtotime($ev['timestamp'])) ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="pill <?= $badgeClass ?>"><?= htmlspecialchars($ev['event_type']) ?></span>
                        </td>
                        <td class="px-4 py-3 text-stone-600 text-xs max-w-[120px] truncate">
                            <?= htmlspecialchars($ev['page'] ?? '—') ?>
                        </td>
                        <td class="px-4 py-3 text-stone-500 text-xs font-mono">
                            <?= htmlspecialchars(substr($ev['user_id'] ?? 'anon', 0, 10)) ?>
                        </td>
                        <td class="px-4 py-3 text-stone-500 text-xs max-w-[200px] truncate">
                            <?= htmlspecialchars($detail) ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /max-w -->

<script>
// Build hourly chart from PHP data
const hourlyRaw = <?= json_encode($hourlyData) ?>;
const hours = Array.from({length:24}, (_,i) => i);
const counts = hours.map(h => {
    const found = hourlyRaw.find(r => parseInt(r.hr) === h);
    return found ? parseInt(found.cnt) : 0;
});

const ctx = document.getElementById('hourlyChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: hours.map(h => h + ':00'),
        datasets: [{
            label: 'Events',
            data: counts,
            backgroundColor: 'rgba(181, 161, 131, 0.15)',
            borderColor: '#9C8C73',
            borderWidth: 2,
            borderRadius: 4,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, stepSize: 1 } }
        }
    }
});
</script>
</body>
</html>