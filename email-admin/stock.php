<?php
declare(strict_types=1);
// Stock admin — catalog from products.html, quantities in data/store-stock.json

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/api/lib/store-catalog.php';

session_name(EMAIL_ADMIN_SESSION);
session_start();
require_once __DIR__ . '/includes/functions.php';
requireLogin();

bedda_store_seed_stock_file();

$message = '';
$catalogPath = bedda_store_products_html_path();

if (!is_readable($catalogPath)) {
    die('<pre style="color:red;font-family:monospace;padding:2rem;">products.html not found at ' . htmlspecialchars($catalogPath) . '</pre>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['sku'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $sku = trim((string) $_POST['sku']);
    $action = (string) $_POST['action'];
    $stock = bedda_store_load_stock_overrides();

    if ($sku === '' || !isset($stock[$sku])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unknown SKU']);
        exit;
    }

    try {
        if ($action === 'update_cost' && isset($_POST['cost'])) {
            $stock[$sku]['cost'] = (float) $_POST['cost'];
        } elseif ($action === 'update_stock' && isset($_POST['stock_in_stock'])) {
            $stock[$sku]['stock_in_stock'] = max(0, (int) $_POST['stock_in_stock']);
        } elseif ($action === 'update_sold' && isset($_POST['stock_sold'])) {
            $stock[$sku]['stock_sold'] = max(0, (int) $_POST['stock_sold']);
        } elseif ($action === 'update_low' && isset($_POST['low_stock_threshold'])) {
            $stock[$sku]['low_stock_threshold'] = max(0, (int) $_POST['low_stock_threshold']);
        } else {
            throw new InvalidArgumentException('Invalid action');
        }

        bedda_store_save_stock_overrides($stock);

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        $message = 'Updated ' . htmlspecialchars($sku);
    } catch (Throwable $e) {
        error_log('Stock update failed: ' . $e->getMessage());
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Update failed']);
            exit;
        }
        $message = 'Update failed';
    }
}

if (isset($_GET['seed']) && $_GET['seed'] === '1') {
    bedda_store_seed_stock_file();
    $message = 'Synced product list from products.html';
}

$products = bedda_store_catalog_merged();
$parsedCount = count(bedda_store_parse_products_html());

$totalRevenue = array_sum(array_column($products, 'revenue'));
$totalProfit = array_sum(array_column($products, 'profit'));
$totalSold = array_sum(array_column($products, 'stock_sold'));
$avgMargin = $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock & Analytics - OnlyBikes Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
</head>
<body class="bg-stone-50 min-h-screen font-inter">
<?php renderNav('stock'); ?>
<div class="max-w-7xl mx-auto px-4 py-8">

    <div class="flex flex-wrap justify-between items-start gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-stone-800">Stock & Analytics</h1>
            <p class="text-sm text-stone-500 mt-1">
                <?= (int) $parsedCount ?> products from <code class="text-xs bg-stone-100 px-1 rounded">products.html</code>
                — stock saved in <code class="text-xs bg-stone-100 px-1 rounded">data/store-stock.json</code>
            </p>
        </div>
        <a href="?seed=1" class="bg-sage-600 text-white px-4 py-2 rounded hover:bg-sage-700 text-sm">
            Refresh from products.html
        </a>
    </div>

    <?php if ($message): ?>
        <div class="mb-4 p-3 rounded bg-green-100 text-green-700 text-sm"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-lg p-4 border shadow-sm">
            <p class="text-xs text-stone-500 uppercase">Est. Revenue</p>
            <p class="text-2xl font-bold text-green-700">$<?= number_format($totalRevenue, 2) ?></p>
        </div>
        <div class="bg-white rounded-lg p-4 border shadow-sm">
            <p class="text-xs text-stone-500 uppercase">Est. Profit</p>
            <p class="text-2xl font-bold <?= $totalProfit >= 0 ? 'text-green-700' : 'text-red-700' ?>">
                $<?= number_format($totalProfit, 2) ?>
            </p>
        </div>
        <div class="bg-white rounded-lg p-4 border shadow-sm">
            <p class="text-xs text-stone-500 uppercase">Units Sold (manual)</p>
            <p class="text-2xl font-bold text-stone-800"><?= number_format($totalSold) ?></p>
        </div>
        <div class="bg-white rounded-lg p-4 border shadow-sm">
            <p class="text-xs text-stone-500 uppercase">Avg Margin</p>
            <p class="text-2xl font-bold text-sage-700"><?= $avgMargin ?>%</p>
        </div>
    </div>

    <div class="bg-white rounded-lg border shadow-sm p-6 mb-8">
        <h2 class="font-semibold text-stone-700 mb-4">Profit & Revenue by Product</h2>
        <canvas id="profitChart" height="100"></canvas>
    </div>

    <div class="bg-white rounded-lg shadow-sm border overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-stone-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Product</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">SKU</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Cost</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Price</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">In Stock</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Low at</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Sold</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-50">
                <?php foreach ($products as $p): ?>
                    <tr class="hover:bg-stone-50">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($p['name']) ?></td>
                        <td class="px-4 py-3 text-stone-600 font-mono text-xs"><?= htmlspecialchars($p['sku']) ?></td>
                        <td class="px-4 py-3">
                            <form method="POST" class="cost-form" data-sku="<?= htmlspecialchars($p['sku'], ENT_QUOTES) ?>">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="action" value="update_cost">
                                <input type="hidden" name="sku" value="<?= htmlspecialchars($p['sku'], ENT_QUOTES) ?>">
                                <input type="number" name="cost" step="0.01" min="0"
                                    value="<?= htmlspecialchars((string) $p['cost']) ?>"
                                    class="w-20 border border-stone-300 rounded px-2 py-1 text-right editable"
                                    data-original="<?= $p['cost'] ?>">
                            </form>
                        </td>
                        <td class="px-4 py-3 text-stone-600">$<?= number_format((float) $p['price'], 2) ?></td>
                        <td class="px-4 py-3">
                            <form method="POST" class="stock-form" data-sku="<?= htmlspecialchars($p['sku'], ENT_QUOTES) ?>">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="action" value="update_stock">
                                <input type="hidden" name="sku" value="<?= htmlspecialchars($p['sku'], ENT_QUOTES) ?>">
                                <input type="number" name="stock_in_stock" min="0"
                                    value="<?= (int) $p['stock_in_stock'] ?>"
                                    class="w-16 border border-stone-300 rounded px-2 py-1 text-right editable"
                                    data-original="<?= (int) $p['stock_in_stock'] ?>">
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" data-sku="<?= htmlspecialchars($p['sku'], ENT_QUOTES) ?>">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="action" value="update_low">
                                <input type="hidden" name="sku" value="<?= htmlspecialchars($p['sku'], ENT_QUOTES) ?>">
                                <input type="number" name="low_stock_threshold" min="0"
                                    value="<?= (int) $p['low_stock_threshold'] ?>"
                                    class="w-14 border border-stone-300 rounded px-2 py-1 text-right editable"
                                    data-original="<?= (int) $p['low_stock_threshold'] ?>">
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" class="sold-form" data-sku="<?= htmlspecialchars($p['sku'], ENT_QUOTES) ?>">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="action" value="update_sold">
                                <input type="hidden" name="sku" value="<?= htmlspecialchars($p['sku'], ENT_QUOTES) ?>">
                                <input type="number" name="stock_sold" min="0"
                                    value="<?= (int) $p['stock_sold'] ?>"
                                    class="w-16 border border-stone-300 rounded px-2 py-1 text-right editable"
                                    data-original="<?= (int) $p['stock_sold'] ?>">
                            </form>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($p['is_out_of_stock']): ?>
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-semibold bg-red-100 text-red-800">OUT</span>
                            <?php elseif ($p['is_low_stock']): ?>
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-semibold bg-sage-100 text-sage-800">Low</span>
                            <?php else: ?>
                                <span class="text-stone-400 text-xs">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($products === []): ?>
                    <tr><td colspan="8" class="px-4 py-8 text-center text-stone-400">No product cards with data-sku found in products.html</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const chartData = <?= json_encode([
    'labels' => array_column($products, 'name'),
    'revenue' => array_map(static fn ($p) => (float) ($p['revenue'] ?? 0), $products),
    'profit' => array_map(static fn ($p) => (float) ($p['profit'] ?? 0), $products),
    'sold' => array_map(static fn ($p) => (int) $p['stock_sold'], $products),
], JSON_UNESCAPED_UNICODE) ?>;

const ctx = document.getElementById('profitChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: chartData.labels,
        datasets: [
            {
                label: 'Revenue ($)',
                data: chartData.revenue,
                backgroundColor: 'rgba(92, 82, 69, 0.35)',
                borderColor: '#5A4D3F',
                borderWidth: 1,
                yAxisID: 'y'
            },
            {
                label: 'Profit ($)',
                data: chartData.profit,
                backgroundColor: 'rgba(126, 109, 88, 0.35)',
                borderColor: '#7E6D58',
                borderWidth: 1,
                yAxisID: 'y'
            },
            {
                label: 'Units Sold',
                data: chartData.sold,
                type: 'line',
                borderColor: '#B5A183',
                backgroundColor: 'rgba(181, 161, 131, 0.15)',
                borderWidth: 2,
                pointRadius: 3,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: { display: true, text: 'Dollars' },
                grid: { color: 'rgba(0,0,0,0.05)' }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: { display: true, text: 'Units' },
                grid: { drawOnChartArea: false }
            }
        },
        plugins: { legend: { position: 'top', labels: { usePointStyle: true } } }
    }
});

document.querySelectorAll('.editable').forEach(input => {
    input.addEventListener('blur', function() {
        const form = this.closest('form');
        const newVal = this.value;
        const original = this.dataset.original;
        if (newVal === original) return;

        const body = new URLSearchParams(new FormData(form));
        fetch('stock.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                this.dataset.original = newVal;
                location.reload();
            } else {
                alert('Update failed: ' + (data.error || 'Unknown'));
            }
        })
        .catch(() => alert('Network error'));
    });
});
</script>
</body>
</html>
