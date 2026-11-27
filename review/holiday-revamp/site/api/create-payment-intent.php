<?php
/**
 * Bedda - Create Stripe PaymentIntent
 * Charges the server total. The browser total is not the amount Stripe collects.
 */
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/bedda-stripe-debug.log');
header('Content-Type: application/json');

// Load config
require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/store-catalog.php';
require_once __DIR__ . '/lib/order-totals.php';
require_once __DIR__ . '/lib/profit-log.php';

if (!function_exists('bedda_store_validate_cart_prices')) {
    error_log('[BEDDA] CRITICAL: api/lib/store-catalog.php missing bedda_store_validate_cart_prices()');
    http_response_code(500);
    echo json_encode(['error' => 'Payment system configuration error. Contact support.']);
    exit;
}

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
    bedda_log_profit_block('payment_setup_failed', [
        'email' => $data['customerEmail'] ?? '',
        'order_number' => $data['orderNumber'] ?? '',
        'message' => 'Missing required fields before Stripe',
    ]);
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: items, subtotal, customerEmail']);
    exit;
}

// Re-calculate subtotal server-side from catalog prices (NEVER trust client)
$priceCheck = bedda_store_validate_cart_prices($data['items']);
if (!$priceCheck['ok']) {
    bedda_log_profit_block('payment_setup_failed', [
        'email' => $data['customerEmail'] ?? '',
        'order_number' => $data['orderNumber'] ?? '',
        'message' => $priceCheck['error'] ?? 'Cart price validation failed',
    ]);
    http_response_code(400);
    $payload = ['error' => $priceCheck['error'] ?? 'Cart price validation failed'];
    if (!empty($priceCheck['mismatch'])) {
        $payload['mismatch'] = $priceCheck['mismatch'];
    }
    echo json_encode($payload);
    exit;
}
$data['items'] = $priceCheck['items'];
$subtotal = $priceCheck['subtotal'];

$stockCheck = bedda_store_validate_cart_stock($data['items']);
if (!$stockCheck['ok']) {
    bedda_log_profit_block('payment_setup_failed', [
        'email' => $data['customerEmail'] ?? '',
        'order_number' => $data['orderNumber'] ?? '',
        'message' => $stockCheck['error'] ?? 'One or more items are out of stock',
    ]);
    http_response_code(400);
    $payload = ['error' => $stockCheck['error'] ?? 'One or more items are out of stock'];
    if (!empty($stockCheck['errors'])) {
        $payload['errors'] = $stockCheck['errors'];
    }
    echo json_encode($payload);
    exit;
}
$data['items'] = $stockCheck['items'];

$availablePoints = null;
if (!empty($data['use_points'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure'   => beddaSessionCookieSecure(),
            'cookie_samesite' => 'Strict'
        ]);
    }
    if (!empty($_SESSION['customer_id'])) {
        $customersDB = getCustomersDatabase();
        $stmt = $customersDB->prepare("SELECT points FROM customers WHERE id = ?");
        $stmt->execute([intval($_SESSION['customer_id'])]);
        if ($user = $stmt->fetch()) {
            $availablePoints = (int) $user['points'];
        }
    }
}

$totals = bedda_calculate_order_grand_total($data, $subtotal, $availablePoints);
$shippingCost = $totals['shipping'];
$handlingCost = $totals['handling'];
$grandTotal = $totals['grand_total'];
$pointsDiscount = $totals['points_discount'];
$pointsUsed = $totals['points_used'];

// Convert to cents (Stripe requires integer)
$pickupOnly = bedda_pickup_only_in_cart($data['items'] ?? []);
if (($data['fulfillment_method'] ?? 'shipping') === 'shipping' && $pickupOnly !== null) {
    http_response_code(400);
    echo json_encode(['error' => $pickupOnly . ' is pickup only. We are not shipping baskets or gift bags yet.']);
    exit;
}

if (($data['fulfillment_method'] ?? 'shipping') === 'shipping' && $shippingCost <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'A shipping quote is required before payment.']);
    exit;
}

$amountInCents = intval(round($grandTotal * 100));

// Stripe minimum for CAD is $0.50 (50 cents)
if ($amountInCents < 50) {
    bedda_log_profit_block('payment_setup_failed', [
        'email' => $data['customerEmail'] ?? '',
        'order_number' => $data['orderNumber'] ?? '',
        'amount_cents' => $amountInCents,
        'message' => 'Order total under Stripe minimum',
    ]);
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
        'currency'                  => 'cad',
        'automatic_payment_methods' => ['enabled' => true],
        'receipt_email'             => $customerEmail,
        'metadata' => [
            'order_number'        => $orderNumber,
            'customer_name'       => $customerName,
            'customer_email'      => $customerEmail,
            'phone'               => $phone,
            'ip'                  => $_SERVER['REMOTE_ADDR'] ?? '',
            'items_json'          => substr(json_encode($data['items'] ?? []), 0, 480),
            'subtotal'            => (string)$subtotal,
            'shipping_cost'       => (string)$shippingCost,
            'handling_cost'       => (string)$handlingCost,
            'grand_total'         => (string)$grandTotal,
            'grand_total_cents'   => (string)$amountInCents,
            'shipping_carrier'    => $shippingCarrier,
            'shipping_address'    => substr($fullAddress, 0, 480),
            'fulfillment_method'  => $fulfillmentMethod,
            'pickup_location'     => $pickupLocation,
            'pickup_date'         => $pickupDate,
            'subscription_id'     => $subscriptionId,
            'coupon_code'         => $data['coupon']['code'] ?? '',
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
        'idempotency_key' => uniqid('bedda_', true),
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
    bedda_log_profit_block('payment_setup_failed', [
        'email' => $data['customerEmail'] ?? '',
        'order_number' => $data['orderNumber'] ?? '',
        'amount_cents' => $amountInCents ?? null,
        'code' => $e->getStripeCode(),
        'message' => $e->getMessage(),
    ]);
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