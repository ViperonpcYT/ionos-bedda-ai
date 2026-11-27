<?php
/**
 * Bedda Real-Time Shipping Quote via Chit Chats API
 * Step 1 of Stripe + Chit Chats Integration
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);        // NEVER output errors to browser (corrupts JSON)
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/bedda-shipping-errors.log');
header('Content-Type: application/json');

// Config
define('CHITCHATS_CLIENT_ID', '610870');
define('CHITCHATS_ACCESS_TOKEN', '86dd5bdd9fde406191b5575ed736d4f6');
define('CHITCHATS_API_URL', 'https://chitchats.com/api/v1/clients');
define('ENVELOPE_X', 11.0); define('ENVELOPE_Y', 8.5); define('ENVELOPE_Z', 1.0);
define('BOX_X', 13.0); define('BOX_Y', 10.0); define('BOX_Z', 3.0);
define('ENVELOPE_MAX_WEIGHT', 500);

$PRODUCT_WEIGHTS = [
    'Be Mine Soap' => 120, 'Uni Exfoliating Soap' => 120, 'He-Man Exfoliating Soap' => 120,
    'The Massager Soap' => 120, 'Mama 1 - Little heart surprise Soap' => 120,
    'Holiday Ginger Soap' => 120, 'Bedda Holiday Sanctuary Basket' => 1100, 'Holiday Gift Bag' => 320, 'She-Ra Exfoliating Soap' => 120, 'Holy Grail Balm' => 37,
    'Plain Jane Face & Body Balm' => 37, 'Creamsicle Balm' => 37,
    'Minty Lip Balm' => 14, 'Plain Jane Soap Loaf' => 1188
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    http_response_code(405); 
    echo json_encode(['success'=>false,'message'=>'Method not allowed']); 
    exit; 
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!$data || !isset($data['items']) || !isset($data['postal_code']) || !isset($data['province'])) {
    http_response_code(400); 
    echo json_encode(['success'=>false,'message'=>'Missing required fields']); 
    exit;
}

$items = $data['items']; 
$postalCode = strtoupper(trim($data['postal_code'])); 
$province = strtoupper(trim($data['province']));
$fulfillmentMethod = $data['fulfillment_method'] ?? 'shipping';

// FIX 1: Extract optional address fields with sensible fallbacks for quoting
// Chit Chats only needs real postal code + province for rates; address can be dummy data at quote stage
$name       = !empty($data['name']) ? trim($data['name']) : 'Customer';
$street     = !empty($data['street_address']) ? trim($data['street_address']) : '123 Test Street';
$address2   = !empty($data['address2']) ? trim($data['address2']) : '';
$city       = !empty($data['city']) ? trim($data['city']) : 'Test City';
$subtotal   = isset($data['subtotal']) ? floatval($data['subtotal']) : 0;

// FIX 2: Calculate subtotal from items if not provided by frontend
if ($subtotal <= 0 && is_array($items)) {
    foreach ($items as $item) {
        $price = floatval($item['price'] ?? 0);
        $qty   = intval($item['quantity'] ?? 1);
        $subtotal += ($price * $qty);
    }
}

if ($fulfillmentMethod === 'pickup') {
    echo json_encode([
        'success' => true,
        'shipment_id' => null,  // FIX 3: No CC shipment created for pickup
        'options' => [[
            'id' => 'pickup',
            'carrier' => 'Local Pickup',
            'delivery_time' => 'Pickup at designated location',
            'tracking' => 'N/A',
            'total' => 0.00,
            'breakdown' => ['postage' => 0, 'insurance' => 0, 'tax' => 0],
            'estimated' => false
        ]],
        'weight' => 0,
        'package_type' => 'pickup',
        'message' => 'Local pickup selected'
    ]);
    exit;
}

// Calculate weight
$totalWeight = 0; 
$totalItems = 0;
foreach ($items as $item) {
    $productName = $item['product'] ?? ''; 
    $quantity = intval($item['quantity'] ?? 1);
    $weight = 100;
    foreach ($PRODUCT_WEIGHTS as $pname => $pweight) { 
        if (stripos($productName, $pname) !== false || $productName === $pname) { 
            $weight = $pweight; 
            break; 
        } 
    }
    $totalWeight += ($weight * $quantity); 
    $totalItems += $quantity;
}

// Package type
if ($totalWeight <= ENVELOPE_MAX_WEIGHT && $totalItems <= 3) {
    $packageType = 'thick_envelope'; 
    [$sizeX,$sizeY,$sizeZ] = [ENVELOPE_X, ENVELOPE_Y, ENVELOPE_Z];
} else {
    $packageType = 'parcel'; 
    [$sizeX,$sizeY,$sizeZ] = [BOX_X, BOX_Y, BOX_Z];
}

// API call
$apiUrl = CHITCHATS_API_URL . '/' . CHITCHATS_CLIENT_ID . '/shipments';
$shipmentData = [
    'name' => $name,
    'address_1' => $street,
    'address_2' => $address2,
    'city' => $city,
    'province_code' => $province,
    'postal_code' => $postalCode,
    'country_code' => 'CA',
    'package_contents' => 'merchandise',
    'description' => 'Bedda Products',
    'value' => number_format($subtotal, 2),
    'value_currency' => 'cad',
    'package_type' => $packageType,
    'weight_unit' => 'g',
    'weight' => $totalWeight,
    'size_unit' => 'in',
    'size_x' => $sizeX,
    'size_y' => $sizeY,
    'size_z' => $sizeZ,
    'postage_type' => 'unknown',
    'ship_date' => 'today'
];

function callChitChatsAPI($url, $data, $maxRetries=2) {
    $retries = 0;
    while ($retries <= $maxRetries) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . CHITCHATS_ACCESS_TOKEN,  // ← Fixed: No "Bearer "
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Log error for debugging
        if ($httpCode !== 201) {
            error_log('[BEDDA] ChitChats API error: HTTP ' . $httpCode . 
                     ' - ' . ($curlError ?: 'No curl error') . 
                     ' - Response: ' . substr($response ?? '', 0, 500));
        }
        
        if ($httpCode === 201 && $response) {
            $decoded = json_decode($response, true);
            return $decoded ?: ['error'=>true, 'message'=>'Invalid API response'];
        }
        
        if ($httpCode >= 500 && $retries < $maxRetries) {
            $retries++;
            sleep(pow(2, $retries));
            continue;
        }
        
        return [
            'error' => true,
            'http_code' => $httpCode,
            'message' => $curlError ?: "API request failed (HTTP $httpCode)",
            'response' => substr($response ?? '', 0, 500)
        ];
    }
}

$result = callChitChatsAPI($apiUrl, $shipmentData);

// Handle errors
if (isset($result['error']) || !isset($result['shipment']) || empty($result['shipment']['rates'])) {
    $fallback = calculateFallbackRate($totalWeight, $province);
    echo json_encode([
        'success' => true,
        'shipment_id' => $result['shipment']['id'] ?? null,
        'options' => [[
            'id'=>'fallback',
            'carrier'=>'Standard Shipping',
            'delivery_time'=>'2-8 business days',
            'tracking'=>'Tracking included',
            'total'=>$fallback['cost'],
            'breakdown'=>['postage'=>$fallback['cost']*0.9,'insurance'=>0.65,'tax'=>$fallback['cost']*0.1],
            'estimated'=>true
        ]],
        'weight'=>$totalWeight,
        'package_type'=>$packageType,
        'message'=>'Using fallback rates'
    ]);
    exit;
}

// Build options from ALL rates
$rates = $result['shipment']['rates'];
$options = [];
foreach($rates as $rate) {
    $options[] = [
        'id' => $rate['postage_type'],
        'carrier' => $rate['postage_description'],
        'delivery_time' => $rate['delivery_time_description'],
        'tracking' => $rate['tracking_type_description'],
        // Add $3.00 to the total price shown to the customer
        // Pure postage from ChitChats (Handling is applied later)
        'total' => floatval($rate['payment_amount'] ?? $rate['purchase_amount'] ?? 0),
        'breakdown' => [
            'postage' => floatval($rate['postage_fee'] ?? $rate['purchase_amount'] ?? 0),
            'insurance' => floatval($rate['insurance_fee'] ?? 0),
            'tax' => floatval($rate['federal_tax'] ?? $rate['tax_total'] ?? 0)
        ],
        'estimated' => false
    ];
}
usort($options, fn($a,$b)=>$a['total']<=>$b['total']);

echo json_encode([
    'success' => true,
    'shipment_id' => $result['shipment']['id'] ?? null,
    'options' => $options,
    'weight' => $totalWeight,
    'package_type' => $packageType,
    'message' => 'Live shipping rates calculated'
]);

function calculateFallbackRate($weight, $province) {
    $base = 5.00;
    $weightCost = $weight <= 100 ? 4.00 : ($weight <= 250 ? 6.00 : ($weight <= 500 ? 8.00 : ($weight <= 1000 ? 12.00 : 15.00)));
    $provCost = 0;
    switch($province) {
        case 'QC': case 'MB': case 'SK': $provCost = 2.00; break;
        case 'AB': case 'BC': $provCost = 3.00; break;
        case 'NB': case 'NS': case 'PE': case 'NL': $provCost = 5.00; break;
        case 'YT': case 'NT': case 'NU': $provCost = 15.00; break;
        case 'ON': default: $provCost = 0; break;
    }    
    $sub = $base + $weightCost + $provCost;
    return ['cost' => round($sub + ($sub * 0.13), 2), 'estimated' => true];
}
?>