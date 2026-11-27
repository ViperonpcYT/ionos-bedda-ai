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

/** @return list<string> */
function bedda_store_web_root_candidates(): array
{
    $apiDir = bedda_store_api_dir();
    $candidates = [];

    $envRoot = trim((string) (getenv('BEDDA_WEB_ROOT') ?: ''));
    if ($envRoot !== '') {
        $candidates[] = rtrim(str_replace('\\', '/', $envRoot), '/');
    }

    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($docRoot) && $docRoot !== '') {
        $candidates[] = rtrim(str_replace('\\', '/', $docRoot), '/');
    }

    $candidates[] = dirname($apiDir);
    $candidates[] = dirname(__DIR__, 2);
    $candidates[] = dirname(__DIR__);

    $realApi = realpath($apiDir);
    if ($realApi !== false) {
        $candidates[] = dirname($realApi);
    }

    $deduped = [];
    foreach ($candidates as $root) {
        if ($root === '' || $root === '.' || isset($deduped[$root])) {
            continue;
        }
        $deduped[$root] = true;
    }

    return array_keys($deduped);
}

function bedda_store_web_root(): string
{
    foreach (bedda_store_web_root_candidates() as $root) {
        if (is_readable($root . '/products.html')) {
            return $root;
        }
    }
    return dirname(bedda_store_api_dir());
}

/** @return list<string> */
function bedda_store_products_html_candidates(): array
{
    $apiDir = bedda_store_api_dir();
    $candidates = [];

    $envPath = trim((string) (getenv('BEDDA_PRODUCTS_HTML') ?: ''));
    if ($envPath !== '') {
        $candidates[] = $envPath;
    }

    // IONOS open_basedir often allows api/ but not parent htdocs/ — prefer api copy first.
    $candidates[] = $apiDir . '/products.html';

    foreach (bedda_store_web_root_candidates() as $root) {
        $candidates[] = $root . '/products.html';
    }

    $deduped = [];
    foreach ($candidates as $path) {
        if ($path === '' || isset($deduped[$path])) {
            continue;
        }
        $deduped[$path] = true;
    }

    return array_keys($deduped);
}

function bedda_store_products_html_path(): string
{
    foreach (bedda_store_products_html_candidates() as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }
    $candidates = bedda_store_products_html_candidates();
    return $candidates[0] ?? (bedda_store_api_dir() . '/products.html');
}

/** @return list<string> */
function bedda_store_bundles_html_candidates(): array
{
    $apiDir = bedda_store_api_dir();
    $candidates = [
        $apiDir . '/bundles.html',
    ];
    foreach (bedda_store_web_root_candidates() as $root) {
        $candidates[] = $root . '/bundles.html';
    }

    $deduped = [];
    foreach ($candidates as $path) {
        if ($path !== '' && !isset($deduped[$path])) {
            $deduped[$path] = true;
        }
    }

    return array_keys($deduped);
}

function bedda_store_bundles_html_path(): string
{
    foreach (bedda_store_bundles_html_candidates() as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }
    $candidates = bedda_store_bundles_html_candidates();
    return $candidates[0] ?? (bedda_store_api_dir() . '/bundles.html');
}

function bedda_store_normalize_name(string $name): string
{
    return strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
}

/** Cart display names that map to a catalog SKU (bundle / legacy aliases). */
function bedda_store_manual_sku_aliases(): array
{
    return [
        'plain jane face & body balm' => 'BEDDA-PLAINJANE-BALM',
        'plain jane face balm' => 'BEDDA-PLAINJANE-BALM',
        'plain jane balm' => 'BEDDA-PLAINJANE-BALM',
        'little heart surprise soap' => 'BEDDA-SPEC-HRT',
        'my heart soap' => 'BEDDA-SPEC-LAV',
        'mama 1 - little heart surprise soap' => 'BEDDA-SPEC-HRT',
        'mama 2 - my heart soap' => 'BEDDA-SPEC-LAV',
        'minty lip balm amber jar' => 'BEDDA-MINTY-LIP',
        'uni exfoliating soap' => 'BEDDA-UNI-EXFOL',
        'he-man exfoliating soap' => 'BEDDA-HEMAN-EXFOL',
        'she-ra exfoliating soap' => 'BEDDA-SHERA-EXFOL',
        'the massager soap' => 'BEDDA-MASSAGER',
        'holy grail balm' => 'BEDDA-HOLYGRAIL-BALM',
        'creamsicle balm' => 'BEDDA-CREAMSICLE-BALM',
        'minty lip balm' => 'BEDDA-MINTY-LIP',
        'plain jane soap loaf' => 'BEDDA-PLAINJANE-LOAF',
        'pinky minty lip balm' => 'BEDDA-PINKY-MINTY-LIP',
        'special occasion soap — hidden heart' => 'BEDDA-SPEC-HRT',
        'special occasion soap — lavender heart' => 'BEDDA-SPEC-LAV',
    ];
}

/** @return array{min: float, max: float} */
function bedda_store_custom_loaf_price_range(): array
{
    return ['min' => 38.99, 'max' => 55.79];
}

function bedda_store_is_custom_loaf_name(string $name): bool
{
    return str_starts_with(bedda_store_normalize_name($name), 'custom loaf:');
}

/** True when at least one SKU was parsed from products.html. */
function bedda_store_catalog_is_loaded(): bool
{
    return bedda_store_catalog_index()['sku'] !== [];
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
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('bedda_store_save_stock_overrides: mkdir failed for ' . $dir);
            return false;
        }
    }
    $json = json_encode($stock, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        error_log('bedda_store_save_stock_overrides: json_encode failed for ' . $path);
        return false;
    }
    $ok = @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
    if (!$ok) {
        error_log('bedda_store_save_stock_overrides: write failed for ' . $path);
    }
    return $ok;
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
            $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' product-card ')][@data-product-id]");
            if ($nodes) {
                foreach ($nodes as $node) {
                    if (!$node instanceof DOMElement) {
                        continue;
                    }
                    $sku = trim($node->getAttribute('data-sku'));
                    if ($sku === '') {
                        $productId = trim($node->getAttribute('data-product-id'));
                        $sku = $productId !== '' ? 'BEDDA-' . strtoupper(str_replace('-', '', $productId)) : '';
                    }
                    if ($sku === '' || isset($seen[$sku])) {
                        continue;
                    }
                    $cartNames = bedda_store_card_cart_names($xpath, $node);
                    $products[] = [
                        'sku' => $sku,
                        'name' => bedda_store_card_name($xpath, $node),
                        'cart_names' => $cartNames,
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
            if (!preg_match('/data-product-id="([^"]+)"/i', $chunk, $idProbe)) {
                continue;
            }
            $sku = '';
            if (preg_match('/data-sku="([^"]+)"/i', $chunk, $skuM)) {
                $sku = trim($skuM[1]);
            }
            if ($sku === '') {
                $productId = trim($idProbe[1]);
                $sku = $productId !== '' ? 'BEDDA-' . strtoupper(str_replace('-', '', $productId)) : '';
            }
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
            $cartNames = [];
            if (preg_match_all('/data-product="([^"]+)"/i', $chunk, $productMatches)) {
                foreach ($productMatches[1] as $cartName) {
                    $cartName = trim(html_entity_decode($cartName, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($cartName !== '') {
                        $cartNames[] = $cartName;
                    }
                }
            }
            $cartNames = array_values(array_unique($cartNames));

            $products[] = [
                'sku' => $sku,
                'name' => $name,
                'cart_names' => $cartNames,
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

/** @return list<string> */
function bedda_store_card_cart_names(DOMXPath $xpath, DOMElement $card): array
{
    $names = [];
    $nodes = $xpath->query('.//*[@data-product]', $card);
    if ($nodes) {
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $name = trim($node->getAttribute('data-product'));
            if ($name !== '') {
                $names[] = $name;
            }
        }
    }

    return array_values(array_unique($names));
}

/**
 * Parse bundle JSON item lists from bundles.html.
 *
 * @return array<string, array{display: string, prices: list<float>, sku: ?string}>
 */
function bedda_store_parse_bundle_catalog(?string $htmlPath = null): array
{
    $htmlPath ??= bedda_store_bundles_html_path();
    if (!is_readable($htmlPath)) {
        return [];
    }

    $html = (string) file_get_contents($htmlPath);
    $catalog = [];

    if (!preg_match_all('/data-items(?:-once|-sub)?=(["\'])(.*?)\1/si', $html, $matches)) {
        return [];
    }

    foreach ($matches[2] as $json) {
        $decoded = html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $items = json_decode($decoded, true);
        if (!is_array($items)) {
            continue;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['product'] ?? ''));
            if ($name === '') {
                continue;
            }
            $price = round((float) ($item['price'] ?? 0), 2);
            $key = bedda_store_normalize_name($name);
            if (!isset($catalog[$key])) {
                $catalog[$key] = [
                    'display' => $name,
                    'prices' => [],
                    'sku' => bedda_store_manual_sku_aliases()[$key] ?? null,
                ];
            }
            if (!in_array($price, $catalog[$key]['prices'], true)) {
                $catalog[$key]['prices'][] = $price;
            }
        }
    }

    return $catalog;
}

/** @param list<float> $prices */
function bedda_store_merge_allowed_prices(array $prices, float $catalogPrice): array
{
    $allowed = [round($catalogPrice, 2)];
    foreach ($prices as $price) {
        $rounded = round((float) $price, 2);
        if (!in_array($rounded, $allowed, true)) {
            $allowed[] = $rounded;
        }
    }

    sort($allowed);
    return $allowed;
}


function bedda_holiday_sets(): array
{
    return [
        'holiday basket' => [
            'sku' => 'BEDDA-SET-HOLIDAY-BASKET',
            'name' => 'Holiday Basket',
            'price' => 89.99,
            'components' => [
                'Uni Exfoliating Soap',
                'The Massager Soap',
                'Holiday Ginger Soap',
                'Holy Grail Balm',
                'Minty Lip Balm',
            ],
        ],
        'holiday gift bag' => [
            'sku' => 'BEDDA-SET-HOLIDAY-BAG',
            'name' => 'Holiday Gift Bag',
            'price' => 24.99,
            'components' => [
                'The Massager Soap',
                'She-Ra Exfoliating Soap',
                'Creamsicle Balm',
                'Minty Lip Balm',
            ],
        ],
    ];
}

function bedda_store_set_by_name(string $name): ?array
{
    $key = bedda_store_normalize_name($name);
    $sets = bedda_holiday_sets();
    return $sets[$key] ?? null;
}

function bedda_store_bundle_only_sku(string $name): string
{
    $slug = strtoupper(preg_replace('/[^A-Z0-9]+/i', '-', $name) ?? '');
    $slug = trim($slug, '-');
    return 'BUNDLE-' . ($slug !== '' ? $slug : 'ITEM');
}

/** @return list<array<string, mixed>> */
function bedda_store_catalog_merged(?string $htmlPath = null): array
{
    static $mergedCache = null;
    if ($mergedCache !== null && $htmlPath === null) {
        return $mergedCache;
    }

    $parsed = bedda_store_parse_products_html($htmlPath);
    $overrides = bedda_store_load_stock_overrides();
    $bundleCatalog = bedda_store_parse_bundle_catalog();
    $merged = [];
    $bySku = [];

    foreach ($parsed as $row) {
        $sku = $row['sku'];
        $o = $overrides[$sku] ?? [];
        $stock = (int) ($o['stock_in_stock'] ?? $o['stock'] ?? 99);
        $low = (int) ($o['low_stock_threshold'] ?? $o['low'] ?? 10);
        $sold = (int) ($o['stock_sold'] ?? $o['sold'] ?? 0);
        $cost = (float) ($o['cost'] ?? 0);
        $price = (float) ($row['price'] ?? ($o['price'] ?? 0));
        $displayName = (string) ($o['name'] ?? $row['name']);
        $cartNames = array_values(array_unique(array_merge(
            $row['cart_names'] ?? [],
            [$displayName]
        )));
        $allowedPrices = bedda_store_merge_allowed_prices([], $price);

        foreach ($cartNames as $cartName) {
            $bundleEntry = $bundleCatalog[bedda_store_normalize_name($cartName)] ?? null;
            if ($bundleEntry !== null) {
                $allowedPrices = bedda_store_merge_allowed_prices($bundleEntry['prices'], $price);
            }
        }

        foreach (bedda_store_manual_sku_aliases() as $aliasName => $aliasSku) {
            if ($aliasSku !== $sku) {
                continue;
            }
            $bundleEntry = $bundleCatalog[$aliasName] ?? null;
            if ($bundleEntry !== null) {
                $allowedPrices = bedda_store_merge_allowed_prices($bundleEntry['prices'], $price);
            }
        }

        $entry = [
            'sku' => $sku,
            'name' => $displayName,
            'cart_names' => array_values(array_unique($cartNames)),
            'product_id' => $row['product_id'],
            'category' => $row['category'],
            'price' => $price,
            'allowed_prices' => $allowedPrices,
            'cost' => $cost,
            'stock_in_stock' => $stock,
            'low_stock_threshold' => $low,
            'stock_sold' => $sold,
            'revenue' => $price * $sold,
            'profit' => ($price - $cost) * $sold,
            'is_low_stock' => $stock <= $low && $stock > 0 ? 1 : 0,
            'is_out_of_stock' => $stock <= 0 ? 1 : 0,
        ];
        $merged[] = $entry;
        $bySku[$sku] = $entry;
    }

    foreach ($bundleCatalog as $normalizedName => $bundleEntry) {
        $aliasSku = $bundleEntry['sku'] ?? bedda_store_manual_sku_aliases()[$normalizedName] ?? null;
        if ($aliasSku !== null && isset($bySku[$aliasSku])) {
            $existing = $bySku[$aliasSku];
            $existing['allowed_prices'] = bedda_store_merge_allowed_prices(
                array_merge($existing['allowed_prices'], $bundleEntry['prices']),
                (float) $existing['price']
            );
            $existing['cart_names'] = array_values(array_unique(array_merge(
                $existing['cart_names'],
                [$bundleEntry['display']]
            )));
            $bySku[$aliasSku] = $existing;
            foreach ($merged as $idx => $item) {
                if ($item['sku'] === $aliasSku) {
                    $merged[$idx] = $existing;
                    break;
                }
            }
            continue;
        }

        $bundleSku = bedda_store_bundle_only_sku($bundleEntry['display']);
        if (isset($bySku[$bundleSku])) {
            continue;
        }

        $bundlePrices = $bundleEntry['prices'];
        sort($bundlePrices);
        $defaultPrice = $bundlePrices[0] ?? 0.0;
        $o = $overrides[$bundleSku] ?? [];
        $stock = (int) ($o['stock_in_stock'] ?? $o['stock'] ?? 99);
        $low = (int) ($o['low_stock_threshold'] ?? $o['low'] ?? 10);
        $sold = (int) ($o['stock_sold'] ?? $o['sold'] ?? 0);

        $entry = [
            'sku' => $bundleSku,
            'name' => $bundleEntry['display'],
            'cart_names' => [$bundleEntry['display']],
            'product_id' => '',
            'category' => 'bundle',
            'price' => $defaultPrice,
            'allowed_prices' => $bundlePrices,
            'cost' => 0.0,
            'stock_in_stock' => $stock,
            'low_stock_threshold' => $low,
            'stock_sold' => $sold,
            'revenue' => 0.0,
            'profit' => 0.0,
            'is_low_stock' => $stock <= $low && $stock > 0 ? 1 : 0,
            'is_out_of_stock' => $stock <= 0 ? 1 : 0,
        ];
        $merged[] = $entry;
        $bySku[$bundleSku] = $entry;
    }

    foreach (bedda_holiday_sets() as $set) {
        if (isset($bySku[$set['sku']])) {
            continue;
        }
        $entry = [
            'sku' => $set['sku'],
            'name' => $set['name'],
            'cart_names' => [$set['name']],
            'product_id' => '',
            'category' => 'set',
            'price' => $set['price'],
            'allowed_prices' => [round((float) $set['price'], 2)],
            'cost' => 0.0,
            'stock_in_stock' => 99,
            'low_stock_threshold' => 5,
            'stock_sold' => 0,
            'revenue' => 0.0,
            'profit' => 0.0,
            'is_low_stock' => 0,
            'is_out_of_stock' => 0,
        ];
        $merged[] = $entry;
        $bySku[$set['sku']] = $entry;
    }

    usort($merged, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

    if ($htmlPath === null) {
        $mergedCache = $merged;
    }

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

/** @return array{sku: array<string, array<string, mixed>>, product_id: array<string, array<string, mixed>>, name: array<string, array<string, mixed>>} */
function bedda_store_catalog_index(): array
{
    static $index = null;
    if ($index !== null) {
        return $index;
    }

    $index = ['sku' => [], 'product_id' => [], 'name' => []];
    foreach (bedda_store_catalog_merged() as $row) {
        $index['sku'][$row['sku']] = $row;
        $pid = strtolower(trim((string) ($row['product_id'] ?? '')));
        if ($pid !== '') {
            $index['product_id'][$pid] = $row;
        }

        $names = array_merge(
            [(string) ($row['name'] ?? '')],
            $row['cart_names'] ?? []
        );
        foreach ($names as $name) {
            $normalized = bedda_store_normalize_name((string) $name);
            if ($normalized !== '') {
                $index['name'][$normalized] = $row;
            }
        }
    }

    foreach (bedda_store_manual_sku_aliases() as $aliasName => $sku) {
        if (isset($index['sku'][$sku])) {
            $index['name'][$aliasName] = $index['sku'][$sku];
        }
    }

    return $index;
}

/** @param array<string, mixed> $item */
function bedda_store_lookup_catalog_item(array $item): ?array
{
    if (bedda_store_is_custom_loaf_name((string) ($item['product'] ?? $item['name'] ?? ''))) {
        return [
            'sku' => 'CUSTOM-LOAF',
            'name' => trim((string) ($item['product'] ?? $item['name'] ?? 'Custom Loaf')),
            'product_id' => 'custom-loaf',
            'category' => 'custom',
            'price' => round((float) ($item['price'] ?? 0), 2),
            'allowed_prices' => [],
            'is_custom_loaf' => true,
        ];
    }

    $index = bedda_store_catalog_index();

    $sku = trim((string) ($item['sku'] ?? ''));
    if ($sku !== '' && isset($index['sku'][$sku])) {
        return $index['sku'][$sku];
    }

    $productId = strtolower(trim((string) ($item['product_id'] ?? $item['productId'] ?? '')));
    if ($productId !== '' && isset($index['product_id'][$productId])) {
        return $index['product_id'][$productId];
    }

    $name = bedda_store_normalize_name((string) ($item['product'] ?? $item['name'] ?? ''));
    if ($name !== '' && isset($index['name'][$name])) {
        return $index['name'][$name];
    }

    return null;
}

/** @param array<string, mixed> $catalog */
function bedda_store_price_is_allowed(array $catalog, float $clientPrice, float $tolerance = 0.01): bool
{
    if (!empty($catalog['is_custom_loaf'])) {
        $range = bedda_store_custom_loaf_price_range();
        return $clientPrice + $tolerance >= $range['min'] && $clientPrice - $tolerance <= $range['max'];
    }

    $allowed = $catalog['allowed_prices'] ?? [round((float) ($catalog['price'] ?? 0), 2)];
    foreach ($allowed as $price) {
        if (abs($clientPrice - round((float) $price, 2)) <= $tolerance) {
            return true;
        }
    }

    return false;
}

/** @param array<string, mixed> $catalog */
function bedda_store_resolve_validated_price(array $catalog, float $clientPrice, float $tolerance = 0.01): float
{
    if (!empty($catalog['is_custom_loaf'])) {
        return round($clientPrice, 2);
    }

    $allowed = $catalog['allowed_prices'] ?? [round((float) ($catalog['price'] ?? 0), 2)];
    foreach ($allowed as $price) {
        $rounded = round((float) $price, 2);
        if (abs($clientPrice - $rounded) <= $tolerance) {
            return $rounded;
        }
    }

    return round((float) ($catalog['price'] ?? $clientPrice), 2);
}

/**
 * Validate cart prices against catalog and recalculate subtotal server-side.
 *
 * @param list<array<string, mixed>> $items
 * @return array{ok:bool, subtotal:float, items:list<array<string, mixed>>, error?:string, mismatch?:array<string, mixed>}
 */
function bedda_store_validate_cart_prices(array $items, float $tolerance = 0.01): array
{
    if ($items === []) {
        return ['ok' => false, 'subtotal' => 0.0, 'items' => [], 'error' => 'Cart is empty'];
    }

    if (!bedda_store_catalog_is_loaded()) {
        $path = bedda_store_products_html_path();
        error_log('[BEDDA] Catalog empty or unreadable: ' . $path);
        return [
            'ok' => false,
            'subtotal' => 0.0,
            'items' => [],
            'error' => 'Product catalog unavailable on server',
            'mismatch' => [
                'reason' => 'catalog_unavailable',
                'products_html' => $path,
                'readable' => is_readable($path),
            ],
        ];
    }

    $subtotal = 0.0;
    $validatedItems = [];

    foreach ($items as $item) {
        if (!isset($item['price'], $item['quantity'])) {
            return ['ok' => false, 'subtotal' => 0.0, 'items' => [], 'error' => 'Invalid item format'];
        }

        $qty = (int) $item['quantity'];
        if ($qty < 1) {
            return ['ok' => false, 'subtotal' => 0.0, 'items' => [], 'error' => 'Invalid quantity'];
        }

        $catalog = bedda_store_lookup_catalog_item($item);
        if ($catalog === null) {
            $label = trim((string) ($item['product'] ?? $item['sku'] ?? 'unknown'));
            return [
                'ok' => false,
                'subtotal' => 0.0,
                'items' => [],
                'error' => 'Product not found in catalog: ' . $label,
                'mismatch' => ['product' => $label, 'reason' => 'not_found'],
            ];
        }

        $clientPrice = round((float) $item['price'], 2);

        if (!bedda_store_price_is_allowed($catalog, $clientPrice, $tolerance)) {
            $label = trim((string) ($item['product'] ?? $catalog['name']));
            return [
                'ok' => false,
                'subtotal' => 0.0,
                'items' => [],
                'error' => 'Price mismatch for ' . $label,
                'mismatch' => [
                    'product' => $label,
                    'sku' => $catalog['sku'],
                    'client_price' => $clientPrice,
                    'catalog_price' => round((float) $catalog['price'], 2),
                    'allowed_prices' => $catalog['allowed_prices'] ?? [],
                ],
            ];
        }

        $catalogPrice = bedda_store_resolve_validated_price($catalog, $clientPrice, $tolerance);

        $validatedItems[] = array_merge($item, [
            'price' => $catalogPrice,
            'sku' => $catalog['sku'],
            'product_id' => $catalog['product_id'] ?? '',
        ]);
        $subtotal += $catalogPrice * $qty;
    }

    return [
        'ok' => true,
        'subtotal' => round($subtotal, 2),
        'items' => $validatedItems,
    ];
}

/**
 * Available units for a SKU from stock JSON overrides, else merged catalog.
 * Returns null when stock cannot be determined (caller should skip / allow).
 */
function bedda_store_sku_stock_available(string $sku): ?int
{
    $sku = trim($sku);
    if ($sku === '' || $sku === 'CUSTOM-LOAF') {
        return null;
    }

    $overrides = bedda_store_load_stock_overrides();
    if (isset($overrides[$sku])) {
        return (int) ($overrides[$sku]['stock_in_stock'] ?? $overrides[$sku]['stock'] ?? 0);
    }

    $product = bedda_store_stock_for_sku($sku);
    if ($product !== null) {
        return (int) ($product['stock_in_stock'] ?? 0);
    }

    return null;
}

/**
 * Validate cart quantities against available stock (aggregates demand per SKU).
 *
 * @param list<array<string, mixed>> $items
 * @return array{ok:bool, items:list<array<string, mixed>>, error?:string, errors?:list<string>}
 */
function bedda_store_validate_cart_stock(array $items): array
{
    if ($items === []) {
        return ['ok' => false, 'items' => [], 'error' => 'Cart is empty', 'errors' => ['Cart is empty']];
    }

    /** @var array<string, array{qty:int, label:string}> $demand */
    $demand = [];
    $validatedItems = [];

    foreach ($items as $item) {
        $qty = (int) ($item['quantity'] ?? 0);
        if ($qty < 1) {
            return ['ok' => false, 'items' => [], 'error' => 'Invalid quantity', 'errors' => ['Invalid quantity']];
        }

        $catalog = bedda_store_lookup_catalog_item($item);
        // Unknown / custom loaf: do not block checkout (price validation covers catalog membership).
        if ($catalog === null || !empty($catalog['is_custom_loaf'])) {
            $validatedItems[] = $item;
            continue;
        }

        $sku = (string) $catalog['sku'];
        $label = trim((string) ($item['product'] ?? $catalog['name'] ?? $sku));
        if ($label === '') {
            $label = $sku;
        }

        if (!isset($demand[$sku])) {
            $demand[$sku] = ['qty' => 0, 'label' => $label];
        }
        $demand[$sku]['qty'] += $qty;

        $set = bedda_store_set_by_name($label);
        if ($set !== null) {
            foreach ($set['components'] as $componentName) {
                $component = bedda_store_lookup_catalog_item(['product' => $componentName]);
                if ($component === null || empty($component['sku'])) {
                    $demand['__missing__' . $componentName] = ['qty' => $qty, 'label' => $label . ' includes ' . $componentName . ', which is not in the catalog', 'missing' => true];
                    continue;
                }
                $componentSku = (string) $component['sku'];
                if (!isset($demand[$componentSku])) {
                    $demand[$componentSku] = ['qty' => 0, 'label' => $componentName];
                }
                $demand[$componentSku]['qty'] += $qty;
            }
        }

        $validatedItems[] = array_merge($item, [
            'sku' => $sku,
            'product_id' => $catalog['product_id'] ?? ($item['product_id'] ?? ''),
        ]);
    }

    $errors = [];
    foreach ($demand as $sku => $info) {
        if (!empty($info['missing'])) {
            $errors[] = $info['label'];
            continue;
        }
        $available = bedda_store_sku_stock_available($sku);
        if ($available === null) {
            continue;
        }
        if ($available <= 0) {
            $errors[] = $info['label'] . ' is out of stock';
        } elseif ($info['qty'] > $available) {
            $errors[] = 'Only ' . $available . ' left of ' . $info['label']
                . ' (you requested ' . $info['qty'] . ')';
        }
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'items' => [],
            'error' => $errors[0],
            'errors' => $errors,
        ];
    }

    return ['ok' => true, 'items' => $validatedItems];
}

/** Ensure api/data exists and store-stock.json is seeded from products.html. */
function bedda_store_seed_stock_file(): bool
{
    $path = bedda_store_stock_json_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('bedda_store_seed_stock_file: cannot create directory ' . $dir);
        return false;
    }

    $overrides = bedda_store_load_stock_overrides();
    $changed = !is_file($path);

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
        if (!bedda_store_save_stock_overrides($overrides)) {
            error_log('bedda_store_seed_stock_file: cannot write ' . $path);
            return false;
        }
    }

    return true;
}

/**
 * Adjust stock_in_stock for a SKU in store-stock.json (floors at 0).
 * Reloads the file before save to reduce races; LOCK_EX is used on write.
 *
 * @return array{ok:bool, sku?:string, stock_in_stock?:int, stock_sold?:int, delta?:int, error?:string}
 */
function bedda_store_adjust_stock(string $sku, int $deltaQty, bool $bumpSold = true): array
{
    $sku = trim($sku);
    if ($sku === '') {
        return ['ok' => false, 'error' => 'Empty SKU'];
    }
    if ($deltaQty === 0) {
        $stock = bedda_store_load_stock_overrides();
        $row = $stock[$sku] ?? [];
        return [
            'ok' => true,
            'sku' => $sku,
            'stock_in_stock' => (int) ($row['stock_in_stock'] ?? $row['stock'] ?? 0),
            'stock_sold' => (int) ($row['stock_sold'] ?? $row['sold'] ?? 0),
            'delta' => 0,
        ];
    }

    // Reload immediately before mutate/save to reduce lost updates.
    $stock = bedda_store_load_stock_overrides();

    if (!isset($stock[$sku]) || !is_array($stock[$sku])) {
        $catalog = bedda_store_stock_for_sku($sku);
        if ($catalog !== null) {
            $stock[$sku] = [
                'name' => $catalog['name'] ?? $sku,
                'price' => (float) ($catalog['price'] ?? 0),
                'stock_in_stock' => (int) ($catalog['stock_in_stock'] ?? 0),
                'low_stock_threshold' => (int) ($catalog['low_stock_threshold'] ?? 10),
                'stock_sold' => (int) ($catalog['stock_sold'] ?? 0),
                'cost' => (float) ($catalog['cost'] ?? 0),
            ];
        } else {
            $stock[$sku] = [
                'name' => $sku,
                'stock_in_stock' => 0,
                'low_stock_threshold' => 10,
                'stock_sold' => 0,
                'cost' => 0,
            ];
        }
    }

    $current = (int) ($stock[$sku]['stock_in_stock'] ?? $stock[$sku]['stock'] ?? 0);
    $sold = (int) ($stock[$sku]['stock_sold'] ?? $stock[$sku]['sold'] ?? 0);
    $newStock = max(0, $current + $deltaQty);

    $stock[$sku]['stock_in_stock'] = $newStock;
    if ($bumpSold && $deltaQty < 0) {
        $sold += abs($deltaQty);
        $stock[$sku]['stock_sold'] = $sold;
    }

    if (!bedda_store_save_stock_overrides($stock)) {
        return [
            'ok' => false,
            'sku' => $sku,
            'error' => 'Failed to save stock file',
        ];
    }

    return [
        'ok' => true,
        'sku' => $sku,
        'stock_in_stock' => $newStock,
        'stock_sold' => (int) ($stock[$sku]['stock_sold'] ?? $sold),
        'delta' => $deltaQty,
    ];
}

/**
 * Deduct inventory for cart/order line items from store-stock.json.
 * Maps product names/qty (and sku if present) via catalog helpers.
 *
 * @param list<array<string, mixed>> $items
 * @return array{ok:bool, adjustments:list<array<string, mixed>>, errors:list<string>}
 */
function bedda_store_deduct_order_items(array $items): array
{
    $adjustments = [];
    $errors = [];
    $qtyBySku = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            $errors[] = 'Invalid item format';
            continue;
        }

        $qty = (int) ($item['quantity'] ?? 0);
        if ($qty < 1) {
            $label = trim((string) ($item['product'] ?? $item['name'] ?? $item['sku'] ?? 'unknown'));
            $errors[] = 'Invalid quantity for ' . $label;
            continue;
        }

        if (bedda_store_is_custom_loaf_name((string) ($item['product'] ?? $item['name'] ?? ''))) {
            // Made-to-order — no fixed SKU inventory in store-stock.json.
            continue;
        }

        $catalog = bedda_store_lookup_catalog_item($item);
        if ($catalog === null) {
            $label = trim((string) ($item['product'] ?? $item['name'] ?? $item['sku'] ?? 'unknown'));
            $errors[] = 'Product not found in catalog: ' . $label;
            continue;
        }

        if (!empty($catalog['is_custom_loaf'])) {
            continue;
        }

        $sku = trim((string) ($catalog['sku'] ?? ''));
        if ($sku === '') {
            $label = trim((string) ($item['product'] ?? $item['name'] ?? 'unknown'));
            $errors[] = 'No SKU for ' . $label;
            continue;
        }

        $qtyBySku[$sku] = ($qtyBySku[$sku] ?? 0) + $qty;
        $set = bedda_store_set_by_name((string) ($item['product'] ?? $item['name'] ?? $catalog['name'] ?? ''));
        if ($set !== null) {
            foreach ($set['components'] as $componentName) {
                $component = bedda_store_lookup_catalog_item(['product' => $componentName]);
                $componentSku = trim((string) ($component['sku'] ?? ''));
                if ($componentSku === '') {
                    $errors[] = 'No SKU for set component ' . $componentName;
                    continue;
                }
                $qtyBySku[$componentSku] = ($qtyBySku[$componentSku] ?? 0) + $qty;
            }
        }
    }

    foreach ($qtyBySku as $sku => $qty) {
        $result = bedda_store_adjust_stock($sku, -$qty, true);
        if (!empty($result['ok'])) {
            $adjustments[] = $result;
        } else {
            $errors[] = $result['error'] ?? ('Failed to deduct stock for ' . $sku);
        }
    }

    return [
        'ok' => $errors === [],
        'adjustments' => $adjustments,
        'errors' => $errors,
    ];
}
