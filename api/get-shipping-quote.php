<?php
/**
 * OnlyBikes — live shipping quotes via Chit Chats (Canada + international).
 * Requires CHITCHATS_CLIENT_ID + CHITCHATS_ACCESS_TOKEN in api/.env
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/secure-config.php';
    require_once __DIR__ . '/lib/chitchats-config.php';
    require_once __DIR__ . '/lib/product-shipping-specs.php';
} catch (Throwable $e) {
    error_log('[OnlyBikes] get-shipping-quote bootstrap: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Shipping service configuration error.']);
    exit;
}

define('ENVELOPE_X', 11.0);
define('ENVELOPE_Y', 8.5);
define('ENVELOPE_Z', 1.0);
define('BOX_X', 13.0);
define('BOX_Y', 10.0);
define('BOX_Z', 3.0);
define('ENVELOPE_MAX_WEIGHT', 500);
define('DEFAULT_ITEM_WEIGHT_G', 300);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!onlybikes_chitchats_configured()) {
    error_log('[OnlyBikes] get-shipping-quote: Chit Chats not configured — ' . json_encode(onlybikes_chitchats_config_status()));
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'code' => 'shipping_not_configured',
        'message' => 'Shipping is temporarily unavailable. Please try again shortly.',
        'options' => [],
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput ?: '', true);
if (!is_array($data) || !isset($data['items']) || !isset($data['postal_code'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$items = $data['items'];
$postalCode = strtoupper(trim((string) $data['postal_code']));
$countryCode = strtoupper(trim((string) ($data['country_code'] ?? $data['country'] ?? 'CA')));
if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
    $countryCode = 'CA';
}
$province = strtoupper(trim((string) ($data['province'] ?? '')));
$fulfillmentMethod = $data['fulfillment_method'] ?? 'shipping';

$name = !empty($data['name']) ? trim($data['name']) : 'Customer';
$street = !empty($data['street_address']) ? trim($data['street_address']) : '123 Shipping Street';
$address2 = !empty($data['address2']) ? trim($data['address2']) : '';
$city = !empty($data['city']) ? trim($data['city']) : 'City';
$subtotal = isset($data['subtotal']) ? floatval($data['subtotal']) : 0;

if ($subtotal <= 0 && is_array($items)) {
    foreach ($items as $item) {
        $price = floatval($item['price'] ?? 0);
        $qty = intval($item['quantity'] ?? 1);
        $subtotal += ($price * $qty);
    }
}

if ($fulfillmentMethod === 'pickup') {
    echo json_encode([
        'success' => true,
        'shipment_id' => null,
        'options' => [[
            'id' => 'pickup',
            'carrier' => 'Local Pickup',
            'delivery_time' => 'Pickup at designated location',
            'tracking' => 'N/A',
            'total' => 0.00,
            'breakdown' => ['postage' => 0, 'insurance' => 0, 'tax' => 0],
            'estimated' => false,
        ]],
        'weight' => 0,
        'package_type' => 'pickup',
        'message' => 'Local pickup selected',
    ]);
    exit;
}

if (in_array($countryCode, ['CA', 'US'], true) && $province === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Province or state is required for this country.']);
    exit;
}
if ($postalCode === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Postal or ZIP code is required.']);
    exit;
}

$package = onlybikes_calculate_cart_package(is_array($items) ? $items : []);
$totalWeight = $package['weight'];
$totalItems = $package['total_items'];
$packageType = $package['package_type'];
$sizeX = $package['size_x'];
$sizeY = $package['size_y'];
$sizeZ = $package['size_z'];

if (!empty($package['over_weight_limit'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'shipping_weight_exceeded',
        'message' => 'This cart exceeds the 30 kg single-package shipping limit. Reduce quantities or contact support@onlybikes.shop for a freight quote.',
        'weight' => $totalWeight,
        'max_weight_g' => ONLYBIKES_SHIPPING_MAX_WEIGHT_G,
        'package_type' => $packageType,
        'lines' => $package['lines'] ?? [],
        'options' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$shipmentPayload = [
    'name' => $name,
    'address_1' => $street,
    'address_2' => $address2,
    'city' => $city,
    'province_code' => $province,
    'postal_code' => $postalCode,
    'country_code' => $countryCode,
    'package_contents' => 'merchandise',
    'description' => 'OnlyBikes E-Moto Parts',
    'value' => number_format(max(0, $subtotal), 2, '.', ''),
    'value_currency' => 'cad',
    'package_type' => $packageType,
    'weight_unit' => 'g',
    'weight' => $totalWeight,
    'size_unit' => 'in',
    'size_x' => $sizeX,
    'size_y' => $sizeY,
    'size_z' => $sizeZ,
    'postage_type' => 'unknown',
    'ship_date' => 'today',
];

try {
    $result = onlybikes_chitchats_request('shipments', $shipmentPayload, 'POST');
} catch (Throwable $e) {
    error_log('[OnlyBikes] get-shipping-quote: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'options' => [],
        'message' => 'Unable to fetch live shipping rates. Please check your address and try again.',
    ]);
    exit;
}

if (isset($result['error']) || empty($result['shipment']['rates'])) {
    error_log('[OnlyBikes] get-shipping-quote API: ' . json_encode([
        'http' => $result['http_code'] ?? null,
        'message' => $result['message'] ?? null,
        'weight' => $totalWeight,
        'dims' => [$sizeX, $sizeY, $sizeZ],
        'lines' => $package['lines'] ?? [],
    ]));
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'code' => 'shipping_rates_unavailable',
        'shipment_id' => $result['shipment']['id'] ?? null,
        'options' => [],
        'weight' => $totalWeight,
        'package_type' => $packageType,
        'size_in' => ['x' => $sizeX, 'y' => $sizeY, 'z' => $sizeZ],
        'lines' => $package['lines'] ?? [],
        'message' => 'Unable to fetch live shipping rates. Please check your address and try again.',
        'api_message' => $result['message'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$options = [];
foreach ($result['shipment']['rates'] as $rate) {
    $total = floatval($rate['payment_amount'] ?? $rate['purchase_amount'] ?? 0);
    if ($total <= 0) {
        continue;
    }
    $options[] = [
        'id' => $rate['postage_type'],
        'carrier' => $rate['postage_description'],
        'delivery_time' => $rate['delivery_time_description'],
        'tracking' => $rate['tracking_type_description'],
        'total' => $total,
        'breakdown' => [
            'postage' => floatval($rate['postage_fee'] ?? $rate['purchase_amount'] ?? 0),
            'insurance' => floatval($rate['insurance_fee'] ?? 0),
            'tax' => floatval($rate['federal_tax'] ?? $rate['tax_total'] ?? 0),
        ],
        'estimated' => false,
    ];
}

if ($options === []) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'No shipping services are available for this address.',
        'options' => [],
    ]);
    exit;
}

usort($options, static fn($a, $b) => $a['total'] <=> $b['total']);

echo json_encode([
    'success' => true,
    'shipment_id' => $result['shipment']['id'] ?? null,
    'options' => $options,
    'weight' => $totalWeight,
    'package_type' => $packageType,
    'size_in' => ['x' => $sizeX, 'y' => $sizeY, 'z' => $sizeZ],
    'lines' => $package['lines'] ?? [],
    'country_code' => $countryCode,
    'message' => 'Live shipping rates calculated',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
