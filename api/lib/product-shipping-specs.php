<?php
declare(strict_types=1);

/**
 * OnlyBikes product weights & package sizes for ChitChats / checkout.
 * Weights in grams; supplier box sizes in cm (converted to inches for ChitChats).
 */

/** ChitChats / Canada Post single-piece practical limits (grams / inches). */
const ONLYBIKES_SHIPPING_MAX_WEIGHT_G = 30000;
const ONLYBIKES_SHIPPING_MAX_QTY_PER_LINE = 10;
const ONLYBIKES_SHIPPING_MAX_SIDE_IN = 48.0;
const ONLYBIKES_SHIPPING_MAX_LENGTH_PLUS_GIRTH_IN = 118.0;

/**
 * Canonical cart product names → shipping spec key.
 *
 * @return array<string, string>
 */
function onlybikes_shipping_cart_aliases(): array
{
    return [
        'ultra bee brake kit' => 'Ultra Bee Brake Kit',
        'titanium bolt kit' => 'Titanium Bolt Kit',
        '17x1.6 supermoto wheel set for talaria & sur-ron' => '17x1.6 Supermoto Wheel Set for Talaria & Sur-Ron',
        '3-inch baja style led headlight' => '3-Inch Baja Style LED Headlight',
        'front 70/100-19 & 90/100-16 rear tire + tube for off road dirt' => 'Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt',
        'rear brake pads for sur-ron' => 'Rear Brake Pads for Sur-Ron',
        'sur-ron ultra bee front & rear fender set' => 'Sur-Ron Ultra Bee Front & Rear Fender Set',
    ];
}

/**
 * @return array<string, array{weight_g:int,size_cm:array{0:float,1:float,2:float},estimated?:bool}>
 */
function onlybikes_product_shipping_catalog(): array
{
    return [
        '3-Inch Baja Style LED Headlight' => [
            'weight_g' => 500,
            'size_cm' => [10.0, 10.0, 9.0],
        ],
        'Front 70/100-19 & 90/100-16 Rear Tire + Tube for Off Road Dirt' => [
            'weight_g' => 6000,
            'size_cm' => [70.0, 70.0, 10.0],
        ],
        'Ultra Bee Brake Kit' => [
            'weight_g' => 8600,
            'size_cm' => [58.0, 27.0, 21.0],
        ],
        '17x1.6 Supermoto Wheel Set for Talaria & Sur-Ron' => [
            'weight_g' => 8600,
            'size_cm' => [58.0, 27.0, 21.0],
        ],
        'Rear Brake Pads for Sur-Ron' => [
            'weight_g' => 150,
            'size_cm' => [10.0, 8.0, 6.0],
        ],
        'Titanium Bolt Kit' => [
            'weight_g' => 950,
            'size_cm' => [30.0, 24.0, 12.0],
            'estimated' => true,
        ],
        'Sur-Ron Ultra Bee Front & Rear Fender Set' => [
            'weight_g' => 3500,
            'size_cm' => [80.0, 55.0, 20.0],
            'estimated' => true,
        ],
    ];
}

function onlybikes_normalize_cart_product_name(string $productName): string
{
    $productName = trim($productName);
    if ($productName === '') {
        return '';
    }

    $catalog = onlybikes_product_shipping_catalog();
    if (isset($catalog[$productName])) {
        return $productName;
    }

    $key = strtolower($productName);
    $aliases = onlybikes_shipping_cart_aliases();
    if (isset($aliases[$key])) {
        return $aliases[$key];
    }

    return $productName;
}

/** @return array{weight_g:int,size_cm:array{0:float,1:float,2:float},size_in:array{0:float,1:float,2:float},estimated:bool,label:string} */
function onlybikes_lookup_product_shipping(string $productName): array
{
    $canonical = onlybikes_normalize_cart_product_name($productName);
    $catalog = onlybikes_product_shipping_catalog();

    if ($canonical !== '' && isset($catalog[$canonical])) {
        return onlybikes_shipping_spec_row($canonical, $catalog[$canonical]);
    }

    return [
        'weight_g' => 800,
        'size_cm' => [25.0, 20.0, 10.0],
        'size_in' => onlybikes_cm_to_in(25.0, 20.0, 10.0),
        'estimated' => true,
        'label' => 'default',
    ];
}

/** @param array{weight_g:int,size_cm:array{0:float,1:float,2:float},estimated?:bool} $spec */
function onlybikes_shipping_spec_row(string $label, array $spec): array
{
    [$x, $y, $z] = $spec['size_cm'];
    return [
        'weight_g' => (int) $spec['weight_g'],
        'size_cm' => [$x, $y, $z],
        'size_in' => onlybikes_cm_to_in($x, $y, $z),
        'estimated' => !empty($spec['estimated']),
        'label' => $label,
    ];
}

function onlybikes_cm_to_in(float $x, float $y, float $z): array
{
    return [
        round($x / 2.54, 2),
        round($y / 2.54, 2),
        round($z / 2.54, 2),
    ];
}

function onlybikes_shipping_parse_quantity(array $item): int
{
    foreach (['quantity', 'qty'] as $key) {
        if (!isset($item[$key])) {
            continue;
        }
        $raw = $item[$key];
        if (is_numeric($raw)) {
            $qty = (int) round((float) $raw);
            if ($qty >= 1) {
                return min($qty, ONLYBIKES_SHIPPING_MAX_QTY_PER_LINE);
            }
        }
    }
    return 1;
}

/**
 * Normalize cart lines for shipping (merge duplicates, clamp qty).
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array{product:string,quantity:int,spec:array}>
 */
function onlybikes_normalize_cart_lines(array $items): array
{
    $merged = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = (string) ($item['product'] ?? $item['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $canonical = onlybikes_normalize_cart_product_name($name);
        $spec = onlybikes_lookup_product_shipping($canonical);
        $qty = onlybikes_shipping_parse_quantity($item);
        $label = $spec['label'];

        if (!isset($merged[$label])) {
            $merged[$label] = [
                'product' => $canonical !== '' ? $canonical : $name,
                'quantity' => 0,
                'spec' => $spec,
            ];
        }
        $merged[$label]['quantity'] = min(
            ONLYBIKES_SHIPPING_MAX_QTY_PER_LINE,
            $merged[$label]['quantity'] + $qty
        );
    }

    return array_values($merged);
}

function onlybikes_shipping_fit_dimensions(float $x, float $y, float $z): array
{
    $dims = [$x, $y, $z];
    rsort($dims);
    [$length, $width, $height] = $dims;

    $length = min($length, ONLYBIKES_SHIPPING_MAX_SIDE_IN);
    $width = min($width, ONLYBIKES_SHIPPING_MAX_SIDE_IN);
    $height = min($height, ONLYBIKES_SHIPPING_MAX_SIDE_IN);

    $girth = $length + (2 * $width) + (2 * $height);
    if ($girth > ONLYBIKES_SHIPPING_MAX_LENGTH_PLUS_GIRTH_IN) {
        $scale = ONLYBIKES_SHIPPING_MAX_LENGTH_PLUS_GIRTH_IN / max(1.0, $girth);
        $length *= $scale;
        $width *= $scale;
        $height *= $scale;
    }

    return [
        round(max(6.0, $length), 2),
        round(max(4.0, $width), 2),
        round(max(2.0, $height), 2),
    ];
}

/**
 * @param array<int, array<string, mixed>> $items
 * @return array{
 *   weight:int,
 *   package_type:string,
 *   size_x:float,
 *   size_y:float,
 *   size_z:float,
 *   total_items:int,
 *   estimated:bool,
 *   lines:array<int,array{product:string,quantity:int,weight_g:int,label:string}>,
 *   over_weight_limit:bool
 * }
 */
function onlybikes_calculate_cart_package(array $items): array
{
    $lines = onlybikes_normalize_cart_lines($items);
    $totalWeight = 0;
    $totalItems = 0;
    $anyEstimated = false;
    $maxX = 0.0;
    $maxY = 0.0;
    $stackZ = 0.0;
    $breakdown = [];

    foreach ($lines as $line) {
        $spec = $line['spec'];
        $qty = (int) $line['quantity'];
        $unitWeight = (int) $spec['weight_g'];
        $lineWeight = $unitWeight * $qty;
        $totalWeight += $lineWeight;
        $totalItems += $qty;
        $anyEstimated = $anyEstimated || !empty($spec['estimated']);

        $maxX = max($maxX, $spec['size_in'][0]);
        $maxY = max($maxY, $spec['size_in'][1]);
        // Same SKU: one carton footprint + modest extra height per additional unit.
        $stackZ += $spec['size_in'][2] * (1 + max(0, $qty - 1) * 0.25);

        $breakdown[] = [
            'product' => $line['product'],
            'quantity' => $qty,
            'weight_g' => $lineWeight,
            'label' => $spec['label'],
        ];
    }

    if ($totalWeight < 1) {
        $totalWeight = 300;
        $anyEstimated = true;
    }

    if ($lines === []) {
        [$sizeX, $sizeY, $sizeZ] = onlybikes_shipping_fit_dimensions(7.87, 5.91, 3.94);
    } else {
        [$sizeX, $sizeY, $sizeZ] = onlybikes_shipping_fit_dimensions($maxX, $maxY, min(24.0, $stackZ));
    }

    $isEnvelope = $totalWeight <= 500
        && $totalItems <= 3
        && $sizeX <= 11.0
        && $sizeY <= 8.5
        && $sizeZ <= 1.5;

    return [
        'weight' => (int) $totalWeight,
        'package_type' => $isEnvelope ? 'thick_envelope' : 'parcel',
        'size_x' => $sizeX,
        'size_y' => $sizeY,
        'size_z' => $sizeZ,
        'total_items' => $totalItems,
        'estimated' => $anyEstimated,
        'lines' => $breakdown,
        'over_weight_limit' => $totalWeight > ONLYBIKES_SHIPPING_MAX_WEIGHT_G,
    ];
}

/** @return array<string, int> */
function onlybikes_product_weight_map(): array
{
    $map = [];
    foreach (onlybikes_product_shipping_catalog() as $label => $spec) {
        $map[$label] = (int) $spec['weight_g'];
    }
    return $map;
}
