<?php
declare(strict_types=1);

/**
 * Stock API — products.html + api/data/store-stock.json (no MySQL).
 */
function bedda_stock_json_response(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bedda_stock_require_catalog(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $apiDir = __DIR__;
    $candidates = [
        $apiDir . '/lib/store-catalog.php',
        $apiDir . '/store-catalog.php',
        dirname($apiDir) . '/includes/store-catalog.php',
    ];
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            require_once $path;
            $loaded = true;
            return;
        }
    }
    bedda_stock_json_response([
        'error' => 'Stock module missing on server',
        'hint' => 'Upload api/store-catalog.php (same folder as get-stock.php) or api/lib/store-catalog.php',
    ], 503);
}

try {
    bedda_stock_require_catalog();

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $sku = trim((string) ($_GET['sku'] ?? ''));
    if ($sku === '') {
        bedda_stock_json_response(['error' => 'No SKU provided'], 400);
    }

    bedda_store_seed_stock_file();

    $product = bedda_store_stock_for_sku($sku);
    if ($product === null) {
        bedda_stock_json_response(['error' => 'Product not found: ' . $sku], 404);
    }

    $avail = (int) $product['stock_in_stock'];
    $threshold = (int) $product['low_stock_threshold'];

    if (!headers_sent()) {
        header('Cache-Control: public, max-age=60, stale-while-revalidate=120');
    }

    bedda_stock_json_response([
        'available' => $avail,
        'low' => $avail <= $threshold && $avail > 0,
        'message' => ($avail <= $threshold && $avail > 0) ? "Only {$avail} left!" : null,
        'out' => $avail <= 0,
    ]);
} catch (Throwable $e) {
    error_log('Bedda get-stock: ' . $e->getMessage());
    bedda_stock_json_response(['error' => 'Stock check failed'], 500);
}
