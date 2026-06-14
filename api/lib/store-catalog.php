<?php
/**
 * Store catalog from products.html + stock in api/data/store-stock.json (or /data/).
 */
declare(strict_types=1);

function bedda_store_api_dir(): string
{
    $dir = __DIR__;
    return basename($dir) === 'lib' ? dirname($dir) : $dir;
}

function bedda_store_web_root(): string
{
    $candidates = [
        dirname(bedda_store_api_dir()),
        dirname(__DIR__, 2),
        dirname(__DIR__),
    ];
    foreach ($candidates as $root) {
        if (is_readable($root . '/products.html')) {
            return $root;
        }
    }
    return dirname(bedda_store_api_dir());
}

function bedda_store_products_html_path(): string
{
    return bedda_store_web_root() . '/products.html';
}

function bedda_store_stock_json_path(): string
{
    $candidates = [
        bedda_store_api_dir() . '/data/store-stock.json',
        bedda_store_web_root() . '/data/store-stock.json',
    ];
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }
    return $candidates[0];
}

/** @return array<string, array<string, mixed>> */
function bedda_store_load_stock_overrides(): array
{
    $path = bedda_store_stock_json_path();
    if (!is_readable($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/** @param array<string, array<string, mixed>> $stock */
function bedda_store_save_stock_overrides(array $stock): bool
{
    $path = bedda_store_stock_json_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($stock, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

/**
 * @return list<array{sku:string,name:string,price:float,product_id:string,category:string}>
 */
function bedda_store_parse_products_html(?string $htmlPath = null): array
{
    $htmlPath ??= bedda_store_products_html_path();
    if (!is_readable($htmlPath)) {
        return [];
    }

    $html = (string) file_get_contents($htmlPath);
    $products = [];
    $seen = [];

    if (class_exists('DOMDocument')) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR)) {
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' product-card ')][@data-sku]");
            if ($nodes) {
                foreach ($nodes as $node) {
                    if (!$node instanceof DOMElement) {
                        continue;
                    }
                    $sku = trim($node->getAttribute('data-sku'));
                    if ($sku === '' || isset($seen[$sku])) {
                        continue;
                    }
                    $products[] = [
                        'sku' => $sku,
                        'name' => bedda_store_card_name($xpath, $node),
                        'price' => (float) $node->getAttribute('data-price'),
                        'product_id' => trim($node->getAttribute('data-product-id')),
                        'category' => trim($node->getAttribute('data-category')),
                    ];
                    $seen[$sku] = true;
                }
            }
        }
        libxml_clear_errors();
    }

    if ($products !== []) {
        return $products;
    }

    if (preg_match_all(
        '/<div[^>]*class="[^"]*product-card[^"]*"[^>]*>/i',
        $html,
        $divs,
        PREG_OFFSET_CAPTURE
    )) {
        foreach ($divs[0] as $match) {
            $chunk = substr($html, (int) $match[1], 2500);
            if (!preg_match('/data-sku="([^"]+)"/i', $chunk, $skuM)) {
                continue;
            }
            $sku = trim($skuM[1]);
            if ($sku === '' || isset($seen[$sku])) {
                continue;
            }
            preg_match('/data-price="([^"]*)"/i', $chunk, $priceM);
            preg_match('/data-product-id="([^"]*)"/i', $chunk, $idM);
            preg_match('/data-category="([^"]*)"/i', $chunk, $catM);
            preg_match('/<h3[^>]*>(.*?)<\/h3>/is', $chunk, $nameM);
            $name = isset($nameM[1])
                ? trim(html_entity_decode(strip_tags($nameM[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : $sku;
            $products[] = [
                'sku' => $sku,
                'name' => $name,
                'price' => isset($priceM[1]) ? (float) $priceM[1] : 0.0,
                'product_id' => isset($idM[1]) ? trim($idM[1]) : '',
                'category' => isset($catM[1]) ? trim($catM[1]) : '',
            ];
            $seen[$sku] = true;
        }
    }

    return $products;
}

function bedda_store_card_name(DOMXPath $xpath, DOMElement $card): string
{
    $heads = $xpath->query('.//h3', $card);
    if ($heads && $heads->length > 0 && $heads->item(0)) {
        return trim($heads->item(0)->textContent);
    }
    return trim($card->getAttribute('data-product-id')) ?: 'Product';
}

/** @return list<array<string, mixed>> */
function bedda_store_catalog_merged(?string $htmlPath = null): array
{
    $parsed = bedda_store_parse_products_html($htmlPath);
    $overrides = bedda_store_load_stock_overrides();
    $merged = [];

    foreach ($parsed as $row) {
        $sku = $row['sku'];
        $o = $overrides[$sku] ?? [];
        $stock = (int) ($o['stock_in_stock'] ?? $o['stock'] ?? 99);
        $low = (int) ($o['low_stock_threshold'] ?? $o['low'] ?? 10);
        $sold = (int) ($o['stock_sold'] ?? $o['sold'] ?? 0);
        $cost = (float) ($o['cost'] ?? 0);
        $price = (float) ($row['price'] ?? ($o['price'] ?? 0));

        $merged[] = [
            'sku' => $sku,
            'name' => (string) ($o['name'] ?? $row['name']),
            'product_id' => $row['product_id'],
            'category' => $row['category'],
            'price' => $price,
            'cost' => $cost,
            'stock_in_stock' => $stock,
            'low_stock_threshold' => $low,
            'stock_sold' => $sold,
            'revenue' => $price * $sold,
            'profit' => ($price - $cost) * $sold,
            'is_low_stock' => $stock <= $low && $stock > 0 ? 1 : 0,
            'is_out_of_stock' => $stock <= 0 ? 1 : 0,
        ];
    }

    usort($merged, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    return $merged;
}

/** @return array<string, mixed>|null */
function bedda_store_stock_for_sku(string $sku): ?array
{
    $sku = trim($sku);
    if ($sku === '') {
        return null;
    }

    foreach (bedda_store_catalog_merged() as $p) {
        if ($p['sku'] === $sku) {
            return $p;
        }
    }

    return null;
}

function bedda_store_seed_stock_file(): void
{
    $overrides = bedda_store_load_stock_overrides();
    $changed = false;

    foreach (bedda_store_parse_products_html() as $row) {
        $sku = $row['sku'];
        if (!isset($overrides[$sku])) {
            $overrides[$sku] = [
                'name' => $row['name'],
                'price' => $row['price'],
                'stock_in_stock' => 99,
                'low_stock_threshold' => 10,
                'stock_sold' => 0,
                'cost' => 0,
            ];
            $changed = true;
        }
    }

    if ($changed) {
        bedda_store_save_stock_overrides($overrides);
    }
}
