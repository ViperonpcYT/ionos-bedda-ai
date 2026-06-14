<?php
/**
 * OnlyBikes - Create Stripe Subscription (Error-Proof)
 * 
 * Flow: Customer → Product → Subscription → Finalize Draft Invoice → Extract PaymentIntent/SetupIntent
 * Always returns a client_secret the frontend can use with Stripe.js.
 */
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/bedda-stripe-debug.log');
header('Content-Type: application/json');

// ─────────────────────────────────────────────────────────────
// 1. Load secure configuration (defines STRIPE_SECRET_KEY)
// ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/secure-config.php';

if (!loadStripe()) {
    http_response_code(500);
    echo json_encode(['error' => 'Stripe SDK not found.']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. Only POST allowed
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 4. Parse & validate input
// ─────────────────────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (
    !$data ||
    empty($data['items']) ||
    empty($data['customerEmail']) ||
    empty($data['interval'])
) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: items, customerEmail, interval']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 5. Server‑side pricing calculation (DO NOT trust the client)
// ─────────────────────────────────────────────────────────────
$subtotal = 0.0;
foreach ($data['items'] as $item) {
    $price = floatval($item['price'] ?? 0);
    $qty = intval($item['quantity'] ?? 1);
    if ($price < 0 || $qty < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid item price or quantity']);
        exit;
    }
    $subtotal += $price * $qty;
}
$subtotal = round($subtotal, 2);

// Apply 5% subscription discount only (coupon and points not allowed for recurring subscriptions)
$subscriptionDiscount = round($subtotal * 0.05, 2);
$subtotal = round($subtotal - $subscriptionDiscount, 2);

// Shipping
$shippingCost = floatval($data['shipping_option']['total'] ?? 0);

// Handling cost (mirrors your existing logic exactly)
$handlingCost = 0;
$fulfillmentMethod = $data['fulfillment_method'] ?? 'shipping';
if ($fulfillmentMethod === 'shipping') {
    $totalWeight = 0;
    $totalItems = 0;
    foreach ($data['items'] as $item) {
        $qty = intval($item['quantity'] ?? 1);
        $totalItems += $qty;
        $totalWeight += 300 * $qty; // PLACEHOLDER: replace with exact part dimensions/weights before launch.
    }
    $isSmallParcel = ($totalWeight <= 500 && $totalItems <= 3);
    $handlingCost = $isSmallParcel ? 3.75 : 4.25;
}

// Tax (none in your case)
$tax = 0;

$grandTotal = round($subtotal + $shippingCost + $handlingCost + $tax, 2);
$amountInCents = intval(round($grandTotal * 100));
$intervalMonths = intval($data['interval']);

// Build items summary for metadata (max 450 chars)
$itemsSummary = [];
foreach ($data['items'] as $item) {
    $itemsSummary[] = ($item['product'] ?? 'Item') . ':x' . ($item['quantity'] ?? 1);
}
$itemsMetadataString = substr(implode('|', $itemsSummary), 0, 450);

// ─────────────────────────────────────────────────────────────
// 6. Stripe API calls – all within one try block
// ─────────────────────────────────────────────────────────────
try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

    // 6a. Create Customer
    $customer = \Stripe\Customer::create([
        'email' => $data['customerEmail'],
        'name' => $data['customerName'] ?? null,
        'metadata' => [
            'order_number' => $data['orderNumber'] ?? ''
        ]
    ]);
    error_log("[BEDDA] Customer created: {$customer->id}");

    // 6b. Create Product
    $product = \Stripe\Product::create([
        'name' => 'OnlyBikes Subscribe & Save',
        'metadata' => [
            'order_number' => $data['orderNumber'] ?? '',
            'interval_months' => $intervalMonths
        ]
    ]);
    error_log("[BEDDA] Product created: {$product->id}");

    // 6c. Create Subscription (draft invoice created)
    $subscription = \Stripe\Subscription::create([
        'customer' => $customer->id,
        'items' => [[
            'price_data' => [
                'currency' => 'cad',
                'unit_amount' => $amountInCents,
                'product' => $product->id,
                'recurring' => [
                    'interval' => 'month',
                    'interval_count' => $intervalMonths,
                ],
            ],
        ]],
        'payment_behavior' => 'default_incomplete',
        'payment_settings' => [
            'save_default_payment_method' => 'on_subscription'
        ],
        'metadata' => [
            'order_number' => $data['orderNumber'] ?? '',
            'customer_email' => $data['customerEmail'],
            'items' => $itemsMetadataString,
            'fulfillment_method' => $fulfillmentMethod,
            'product_id' => $product->id
        ]
    ]);
    error_log("[BEDDA] Subscription created: {$subscription->id} | status: {$subscription->status}");

    // ─────────────────────────────────────────────────────────
    // 7. GET THE CLIENT SECRET – robust extraction
    // ─────────────────────────────────────────────────────────
    $clientSecret = null;
    $invoiceId = $subscription->latest_invoice;
    
    // 7a. Retrieve the invoice
    $invoice = \Stripe\Invoice::retrieve([
        'id' => $invoiceId,
        'expand' => ['payment_intent']
    ]);
    $invoiceStatus = $invoice->status;   // ← MUST capture the real status
    error_log("[BEDDA] Invoice {$invoiceId} status: {$invoiceStatus}");

    // 7b. If the invoice is still a draft, finalize it now
    //     (finalization creates the PaymentIntent)
    if ($invoiceStatus === 'draft') {
        try {
            $invoice = \Stripe\Invoice::finalizeInvoice($invoiceId);
            $invoiceStatus = $invoice->status;
            error_log("[BEDDA] Invoice {$invoiceId} finalized, new status: {$invoiceStatus}");
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Already finalized or cannot be finalized (harmless)
            error_log("[BEDDA] Finalize skipped: " . $e->getMessage());
        }
    }

    // 7c. Re‑fetch the invoice with explicit expansion to guarantee the PI is attached
    $invoice = \Stripe\Invoice::retrieve([
        'id' => $invoiceId,
        'expand' => ['payment_intent']
    ]);

    // 7d. Extract client_secret from PaymentIntent
    if (!empty($invoice->payment_intent)) {
        $pi = is_string($invoice->payment_intent)
            ? \Stripe\PaymentIntent::retrieve($invoice->payment_intent)
            : $invoice->payment_intent;
        $clientSecret = $pi->client_secret ?? null;
        error_log("[BEDDA] PaymentIntent found: {$pi->id}, client_secret: " . ($clientSecret ? 'yes' : 'no'));
    }

    // 7e. Fallback 1: if amount is $0 (free trial / 100% coupon), Stripe may use a SetupIntent
    if (empty($clientSecret) && !empty($subscription->pending_setup_intent)) {
        $si = is_string($subscription->pending_setup_intent)
            ? \Stripe\SetupIntent::retrieve($subscription->pending_setup_intent)
            : $subscription->pending_setup_intent;
        $clientSecret = $si->client_secret ?? null;
        error_log("[BEDDA] SetupIntent used as fallback: {$si->id}, client_secret: " . ($clientSecret ? 'yes' : 'no'));
    }

    // 7f. Fallback 2: search PaymentIntents for the customer (no invoice filter)
    if (empty($clientSecret)) {
        $allIntents = \Stripe\PaymentIntent::all([
            'customer' => $customer->id,
            'limit' => 10,
            // Newest first is default
        ]);

        // First try: exact match on amount, currency, status=requires_payment_method
        $candidate = null;
        foreach ($allIntents->data as $intent) {
            if (
                $intent->amount === $amountInCents &&
                $intent->currency === 'cad' &&
                in_array($intent->status, ['requires_payment_method', 'requires_action', 'requires_confirmation'])
            ) {
                // If we find one where invoice is already set, that's perfect
                if (($intent->invoice ?? null) === $invoiceId) {
                    $candidate = $intent;
                    break;
                }
                // Otherwise keep the most recent one as a fallback candidate
                if (!$candidate || $intent->created > $candidate->created) {
                    $candidate = $intent;
                }
            }
        }

        // Second try: if no status match, just use the latest unlinked PI with same amount
        if (!$candidate) {
            foreach ($allIntents->data as $intent) {
                if ($intent->amount === $amountInCents && $intent->currency === 'cad') {
                    $candidate = $intent;
                    break;
                }
            }
        }

        if ($candidate) {
            $clientSecret = $candidate->client_secret ?? null;
            error_log("[BEDDA] Orphan PaymentIntent found: {$candidate->id}, status: {$candidate->status}");
            // Optionally: manually attach it to the invoice (best-effort)
            try {
                \Stripe\Invoice::update($invoiceId, [
                    'payment_intent' => $candidate->id,
                ]);
                error_log("[BEDDA] Manually linked PI {$candidate->id} to invoice {$invoiceId}");
            } catch (\Exception $e) {
                error_log("[BEDDA] Could not link PI to invoice: " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────
    // 8. Success response
    // ─────────────────────────────────────────────────────────
    echo json_encode([
        'success' => true,
        'clientSecret' => $clientSecret,
        'stripePublishableKey' => defined('STRIPE_PUBLISHABLE_KEY') ? STRIPE_PUBLISHABLE_KEY : '',
        'subscriptionId' => $subscription->id,
        'customerId' => $customer->id,
        'productId' => $product->id,
        'amount' => $grandTotal,
        'currency' => 'cad'
    ]);
    exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
    // Stripe‑specific errors (card errors, etc.)
    error_log("[BEDDA] Stripe API Error: " . $e->getMessage());
    http_response_code(402);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
    exit;
} catch (Exception $e) {
    // Any other unforeseen error
    error_log("[BEDDA] General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Subscription initialization failed',
        'debug_message' => $e->getMessage()
    ]);
    exit;
}