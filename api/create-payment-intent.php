<?php
/**
 * OnlyBikes - Create Stripe PaymentIntent
 * Test mode only. Server-side amount verification prevents tampering.
 */
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/bedda-stripe-debug.log');
header('Content-Type: application/json');

// Load config
require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/customers-schema.php';
require_once __DIR__ . '/lib/points-ledger.php';
require_once __DIR__ . '/lib/security-helpers.php';

// ============================================================
// LOAD STRIPE SDK
// ============================================================
if (!loadStripe()) {
    error_log('[BEDDA] CRITICAL: Stripe SDK not found');
    http_response_code(500);
    echo json_encode(['error' => 'Payment system configuration error. Contact support.']);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse and validate input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!$data || empty($data['items']) || empty($data['subtotal']) || empty($data['customerEmail'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: items, subtotal, customerEmail']);
    exit;
}

// Re-calculate subtotal server-side (NEVER trust client)
$subtotal = 0;
foreach ($data['items'] as $item) {
    if (!isset($item['price'], $item['quantity'], $item['product'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item format']);
        exit;
    }
    $price = floatval($item['price']);
    $qty = intval($item['quantity']);
    if ($price < 0 || $qty < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid price or quantity']);
        exit;
    }
    $subtotal += $price * $qty;
}
$subtotal = round($subtotal, 2);

// Apply coupon discount to items only (never to shipping or handling)
$discountAmount = 0;
if (!empty($data['coupon']) && isset($data['coupon']['discount'])) {
    $discountAmount = min(floatval($data['coupon']['discount']), $subtotal);
    $subtotal = round($subtotal - $discountAmount, 2);
}

// Add shipping if provided
$shippingCost = 0;
if (!empty($data['shipping_option']) && is_array($data['shipping_option'])) {
    $shippingCost = floatval($data['shipping_option']['total'] ?? 0);
}

// Calculate handling — must match submit-order.php
$handlingCost = onlybikes_handling_cost($data['items'] ?? [], $data['fulfillment_method'] ?? 'shipping');

$grandTotal = $subtotal + $shippingCost + $handlingCost;

// Handle Pay with Points — session only (never trust client customer_id)
$pointsDiscount = 0;
$pointsUsed = 0;
if (!empty($data['use_points'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure'   => beddaSessionCookieSecure(),
            'cookie_samesite' => 'Strict',
        ]);
    }
    $sessionCustomerId = onlybikes_points_session_customer_id();
    if ($sessionCustomerId !== null) {
        $customersDB = getCustomersDatabase();
        onlybikes_ensure_customers_schema($customersDB);
        $availablePoints = onlybikes_customers_fetch_points($customersDB, $sessionCustomerId);
        if ($availablePoints !== null) {
            $redeemPlan = onlybikes_points_checkout_redeem_calc($availablePoints, $grandTotal);
            $pointsDiscount = $redeemPlan['discount_cad'];
            $pointsUsed = $redeemPlan['points_used'];
            if ($pointsDiscount > 0) {
                $grandTotal -= $pointsDiscount;
            }
        }
    }
}

// Convert to cents (Stripe requires integer)
$amountInCents = intval(round($grandTotal * 100));

// Stripe minimum for CAD is $0.50 (50 cents)
if ($amountInCents < 50) {
    http_response_code(400);
    echo json_encode(['error' => 'Order total must be at least $0.50 CAD to process payment.']);
    exit;
}

try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY); // From secure-config.php

    $orderNumber       = $data['orderNumber'] ?? '';
    $customerName      = $data['customerName'] ?? '';
    $customerEmail     = $data['customerEmail'];
    $phone             = $data['phone'] ?? '';
    $street            = $data['street'] ?? '';
    $address2          = $data['address2'] ?? '';
    $city              = $data['city'] ?? '';
    $province          = $data['province'] ?? '';
    $postalCode        = $data['postalCode'] ?? '';
    $shippingCarrier   = $data['shipping_carrier'] ?? '';
    $fulfillmentMethod = $data['fulfillment_method'] ?? 'shipping';
    $pickupLocation    = $data['pickup_location'] ?? '';
    $pickupDate        = $data['pickup_date'] ?? '';
    $subscriptionId    = $data['subscription_id'] ?? '';
    $fullAddress       = trim("$street $address2 $city $province $postalCode");

    $params = [
        'amount'                    => $amountInCents,
        'currency'                  => defined('STRIPE_CURRENCY') ? STRIPE_CURRENCY : 'cad',
        'automatic_payment_methods' => ['enabled' => true],
        'receipt_email'             => $customerEmail,
        'metadata' => [
            'site'                => 'onlybikes.shop',
            'order_number'        => $orderNumber,
            'customer_name'       => $customerName,
            'customer_email'      => $customerEmail,
            'phone'               => $phone,
            'ip'                  => $_SERVER['REMOTE_ADDR'] ?? '',
            'items_json'          => substr(json_encode($data['items'] ?? []), 0, 480),
            'subtotal'            => (string)$subtotal,
            'shipping_cost'       => (string)$shippingCost,
            'handling_cost'       => (string)$handlingCost,
            'shipping_carrier'    => $shippingCarrier,
            'shipping_address'    => substr($fullAddress, 0, 480),
            'fulfillment_method'  => $fulfillmentMethod,
            'pickup_location'     => $pickupLocation,
            'pickup_date'         => $pickupDate,
            'subscription_id'     => $subscriptionId,
            'coupon_code'         => $data['coupon']['code'] ?? '',
            'points_used'         => (string) $pointsUsed,
            'points_discount'     => (string) $pointsDiscount,
            'customer_id'         => (string) (onlybikes_points_session_customer_id() ?? ''),
        ],
    ];

    // Only include shipping for actual shipping orders with a valid address
    if ($fulfillmentMethod === 'shipping' && $street !== '' && $city !== '' && $province !== '') {
        $params['shipping'] = [
            'name'    => $customerName,
            'phone'   => $phone,
            'address' => [
                'line1'       => $street,
                'line2'       => $address2,
                'city'        => $city,
                'state'       => $province,
                'postal_code' => $postalCode,
                'country'     => 'CA',
            ],
        ];
    }

    $pi = \Stripe\PaymentIntent::create($params, [
        'idempotency_key' => uniqid('onlybikes_', true),
    ]);

    echo json_encode([
        'success'      => true,
        'clientSecret' => $pi->client_secret,
        'stripePublishableKey' => defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '',
        'amount'       => $grandTotal,
        'currency'     => 'cad'
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('[BEDDA] Stripe API error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error'   => 'Payment initialization failed: ' . $e->getMessage(),
        'details' => 'Please check your order details and try again.'
    ]);
} catch (Exception $e) {
    error_log('[BEDDA] General error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'   => 'Payment initialization failed',
        'details' => $e->getMessage()
    ]);
}