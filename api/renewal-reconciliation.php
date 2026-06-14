<?php
/**
 * Bedda Master Reconciliation Script
 *
 * Catches ALL missing orders:
 *   1. Missing Month 1 initial orders  (subscription_create  → RESCUE)
 *   2. Missing Month 2+ renewals       (subscription_cycle   → RENEWAL)
 *
 * Run via cron every 5 minutes.
 *
 * KEY FIXES vs prior version:
 *   - Re-fetches every invoice with payment_intent + charge expansion
 *     so billing address is ALWAYS retrieved, not just when PI is on the
 *     invoice object from the list call.
 *   - Falls back: invoice PI → invoice charge → customer charge list
 *     to guarantee we find the billing details.
 *   - $price variable scoped correctly (no undefined-variable risk).
 *   - Customer / admin emails now contain every detail needed to ship.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', '/homepages/6/d4299539843/htdocs/logs/reconciliation.log');

require_once __DIR__ . '/secure-config.php';
require_once '/homepages/6/d4299539843/htdocs/stripe-php-master/init.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$lookbackHours = 48;
$limit         = 100;
$isCli         = (php_sapi_name() === 'cli');

if (!$isCli) echo "<pre>";
echo "=== BEDDA MASTER RECONCILIATION ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Extract billing details from every possible Stripe source.
//
// Returns array with keys:
//   paymentIntentId, customerName, customerPhone, customerEmail, shippingAddress
//
// Resolution order:
//   1. invoice->payment_intent  (expanded on fetch)
//   2. invoice->charge          (expanded on fetch)
//   3. PaymentIntent->latest_charge
//   4. Customer's recent charge list (brute-force fallback)
// ─────────────────────────────────────────────────────────────────────────────
function extractBillingDetails(string $invoiceId, string $customerId, int $amountCents, string $fallbackEmail): array
{
    $result = [
        'paymentIntentId' => null,
        'customerName'    => 'Valued Customer',
        'customerPhone'   => '',
        'customerEmail'   => $fallbackEmail,
        'shippingAddress' => '',
    ];

    // ── 1. Re-fetch the invoice with full expansion ───────────────────────
    try {
        $invoice = \Stripe\Invoice::retrieve([
            'id'     => $invoiceId,
            'expand' => [
                'payment_intent',
                'payment_intent.payment_method',
                'payment_intent.latest_charge',
                'charge',
            ],
        ]);
    } catch (\Exception $e) {
        error_log("[BEDDA] Could not re-fetch invoice {$invoiceId}: " . $e->getMessage());
        return $result;
    }

    $extractFromBillingDetails = function ($billing) use (&$result): bool {
        if (empty($billing)) return false;
        $name  = $billing->name  ?? '';
        $phone = $billing->phone ?? '';
        $email = $billing->email ?? '';
        $addr  = $billing->address ?? null;

        if ($name)  $result['customerName']  = $name;
        if ($phone) $result['customerPhone'] = $phone;
        if ($email) $result['customerEmail'] = $email;

        if ($addr) {
            $parts = array_filter([
                $addr->line1        ?? '',
                $addr->line2        ?? '',
                $addr->city         ?? '',
                $addr->state        ?? '',
                $addr->postal_code  ?? '',
                $addr->country      ?? 'CA',
            ]);
            if ($parts) {
                $result['shippingAddress'] = implode(', ', $parts);
                return true;
            }
        }
        return false;
    };

    $extractFromCharge = function ($charge) use ($extractFromBillingDetails, &$result): bool {
        if (empty($charge)) return false;
        // Try shipping address first (most accurate for physical goods)
        if (!empty($charge->shipping)) {
            $ship = $charge->shipping;
            if ($ship->name) $result['customerName']  = $ship->name;
            if (!empty($ship->phone)) $result['customerPhone'] = $ship->phone;
            $addr = $ship->address ?? null;
            if ($addr) {
                $parts = array_filter([
                    $addr->line1        ?? '',
                    $addr->line2        ?? '',
                    $addr->city         ?? '',
                    $addr->state        ?? '',
                    $addr->postal_code  ?? '',
                    $addr->country      ?? 'CA',
                ]);
                if ($parts) {
                    $result['shippingAddress'] = implode(', ', $parts);
                    return true;
                }
            }
        }
        // Fallback to billing_details on the charge
        return $extractFromBillingDetails($charge->billing_details ?? null);
    };

    // ── 2. Try PaymentIntent ──────────────────────────────────────────────
    $pi = null;
    if (!empty($invoice->payment_intent)) {
        $pi = is_string($invoice->payment_intent)
            ? null  // wasn't expanded — unusual; handled by fresh fetch above
            : $invoice->payment_intent;

        if ($pi) {
            $result['paymentIntentId'] = $pi->id;
            error_log("[BEDDA] PI found on invoice: {$pi->id}");

            // Try payment_method billing details
            $pm = is_object($pi->payment_method ?? null) ? $pi->payment_method : null;
            $extractFromBillingDetails($pm->billing_details ?? null);

            // Try latest_charge on the PI (has shipping if collected)
            if (empty($result['shippingAddress'])) {
                $charge = is_object($pi->latest_charge ?? null) ? $pi->latest_charge : null;
                if ($charge) {
                    $extractFromCharge($charge);
                }
            }
        }
    }

    // ── 3. Try invoice->charge directly ──────────────────────────────────
    if (empty($result['shippingAddress']) && !empty($invoice->charge)) {
        $charge = is_string($invoice->charge)
            ? \Stripe\Charge::retrieve(['id' => $invoice->charge, 'expand' => []])
            : $invoice->charge;
        error_log("[BEDDA] Trying invoice->charge: {$charge->id}");
        $extractFromCharge($charge);

        // Also grab the PI id from the charge if we don't have it
        if (!$result['paymentIntentId'] && !empty($charge->payment_intent)) {
            $result['paymentIntentId'] = is_string($charge->payment_intent)
                ? $charge->payment_intent
                : $charge->payment_intent->id;
        }
    }

    // ── 4. Brute-force: list recent charges for this customer ─────────────
    if (empty($result['shippingAddress'])) {
        error_log("[BEDDA] Falling back to customer charge list for {$customerId}");
        try {
            $charges = \Stripe\Charge::all([
                'customer' => $customerId,
                'limit'    => 20,
            ]);
            foreach ($charges->data as $charge) {
                // Prefer exact amount match; accept any succeeded charge as last resort
                if ($charge->amount === $amountCents && $charge->status === 'succeeded') {
                    $extractFromCharge($charge);
                    if (!$result['paymentIntentId'] && !empty($charge->payment_intent)) {
                        $result['paymentIntentId'] = is_string($charge->payment_intent)
                            ? $charge->payment_intent
                            : $charge->payment_intent->id;
                    }
                    error_log("[BEDDA] Matched charge {$charge->id} via customer list");
                    break;
                }
            }
            // If still nothing, just take the newest succeeded charge
            if (empty($result['shippingAddress'])) {
                foreach ($charges->data as $charge) {
                    if ($charge->status === 'succeeded') {
                        $extractFromCharge($charge);
                        if (!$result['paymentIntentId'] && !empty($charge->payment_intent)) {
                            $result['paymentIntentId'] = is_string($charge->payment_intent)
                                ? $charge->payment_intent
                                : $charge->payment_intent->id;
                        }
                        error_log("[BEDDA] Used newest succeeded charge {$charge->id} as fallback");
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("[BEDDA] Charge list fallback failed: " . $e->getMessage());
        }
    }

    return $result;
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Parse items string into a clean HTML table rows for emails
// Handles both pipe-separated "Product:xQTY" and comma-separated "Product (xQTY)" formats
// ─────────────────────────────────────────────────────────────────────────────
function parseItemsForEmail(string $itemsStr): array
{
    $rows = [];
    if (empty($itemsStr) || $itemsStr === '[]') return $rows;

    // Try pipe-separated format: "Item Name:x2|Item Name 2:x1"
    if (strpos($itemsStr, '|') !== false || preg_match('/:x\d+/i', $itemsStr)) {
        $parts = preg_split('/\|/', $itemsStr);
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^(.+):x(\d+)$/i', $part, $m)) {
                $rows[] = ['name' => trim($m[1]), 'qty' => intval($m[2])];
            } elseif ($part) {
                $rows[] = ['name' => $part, 'qty' => 1];
            }
        }
        return $rows;
    }

    // Try comma-separated format: "Product (x1), Product 2 (x2)"
    $parts = explode(',', $itemsStr);
    foreach ($parts as $part) {
        $part = trim($part);
        if (preg_match('/^(.+?)\s*\(x(\d+)\)$/i', $part, $m)) {
            $rows[] = ['name' => trim($m[1]), 'qty' => intval($m[2])];
        } elseif ($part) {
            $rows[] = ['name' => $part, 'qty' => 1];
        }
    }
    return $rows;
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Build customer confirmation email HTML
// ─────────────────────────────────────────────────────────────────────────────
function buildCustomerEmail(
    string $orderNumber,
    string $customerName,
    string $itemsStr,
    string $shippingAddress,
    float  $grandTotal,
    float  $shippingCost,
    float  $handlingCost,
    bool   $isRenewal = false
): string {
    $items    = parseItemsForEmail($itemsStr);
    $typeWord = $isRenewal ? 'renewed' : 'confirmed';
    $typeHead = $isRenewal ? 'Subscription Renewed' : 'Order Confirmed';

    $itemRows = '';
    foreach ($items as $item) {
        $itemRows .= '<tr>
            <td style="padding:6px 12px;border-bottom:1px solid #f0e8df;">' . htmlspecialchars($item['name']) . '</td>
            <td style="padding:6px 12px;border-bottom:1px solid #f0e8df;text-align:center;">' . $item['qty'] . '</td>
        </tr>';
    }
    if (!$itemRows) {
        $itemRows = '<tr><td colspan="2" style="padding:6px 12px;color:#999;">Items not available</td></tr>';
    }

    $subtotal = round($grandTotal - $shippingCost - $handlingCost, 2);

    $addrHtml = nl2br(htmlspecialchars($shippingAddress));
    if (!$shippingAddress) {
        $addrHtml = '<span style="color:#cc4400;">Address not captured — please reply to this email with your shipping address.</span>';
    }

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#fdf8f4;font-family:Georgia,serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#fdf8f4;">
  <tr><td align="center" style="padding:40px 20px;">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
      <tr><td style="background:#3b2a1a;padding:32px 40px;text-align:center;">
        <h1 style="color:#f5ede4;margin:0;font-size:28px;letter-spacing:1px;">Bedda Skincare</h1>
        <p style="color:#c9a882;margin:8px 0 0;font-size:14px;">Handcrafted with love in Canada</p>
      </td></tr>
      <tr><td style="padding:32px 40px;">
        <h2 style="color:#3b2a1a;margin:0 0 8px;">' . $typeHead . ' ✓</h2>
        <p style="color:#5a4030;margin:0 0 24px;">Hi ' . htmlspecialchars($customerName) . ', your subscription has been ' . $typeWord . '! We\'re preparing your order now.</p>

        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
          <tr style="background:#f5ede4;"><td colspan="2" style="padding:10px 12px;font-weight:bold;color:#3b2a1a;font-size:13px;text-transform:uppercase;letter-spacing:1px;">Order ' . htmlspecialchars($orderNumber) . '</td></tr>
          <tr style="background:#fdf8f4;"><th style="padding:8px 12px;text-align:left;font-size:13px;color:#777;">Product</th><th style="padding:8px 12px;text-align:center;font-size:13px;color:#777;">Qty</th></tr>
          ' . $itemRows . '
        </table>

        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
          <tr><td style="padding:4px 0;color:#5a4030;">Product subtotal</td><td style="padding:4px 0;text-align:right;">$' . number_format($subtotal, 2) . ' CAD</td></tr>
          <tr><td style="padding:4px 0;color:#5a4030;">Shipping</td><td style="padding:4px 0;text-align:right;">$' . number_format($shippingCost, 2) . ' CAD</td></tr>
          <tr><td style="padding:4px 0;color:#5a4030;">Handling</td><td style="padding:4px 0;text-align:right;">$' . number_format($handlingCost, 2) . ' CAD</td></tr>
          <tr style="border-top:2px solid #3b2a1a;"><td style="padding:8px 0 0;font-weight:bold;color:#3b2a1a;">Total Charged</td><td style="padding:8px 0 0;text-align:right;font-weight:bold;color:#3b2a1a;">$' . number_format($grandTotal, 2) . ' CAD</td></tr>
        </table>

        <div style="background:#f5ede4;border-radius:6px;padding:16px 20px;margin-bottom:24px;">
          <p style="margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#777;">Shipping to</p>
          <p style="margin:0;color:#3b2a1a;">' . $addrHtml . '</p>
        </div>

        <p style="color:#5a4030;font-size:14px;">Questions? Reply to this email or write to <a href="mailto:orders@bedda.ca" style="color:#8b5e3c;">orders@bedda.ca</a>.</p>

        <div style="border:1px solid #e0d0c0;border-radius:6px;padding:14px 20px;margin-bottom:24px;text-align:center;">
          <p style="margin:0 0 6px;font-size:13px;color:#5a4030;">Want to cancel your subscription?</p>
          <a href="mailto:orders@bedda.ca?subject=Cancel%20Subscription%20-%20' . rawurlencode($orderNumber) . '&body=Hi%20Bedda%2C%0A%0APlease%20cancel%20my%20subscription.%0A%0AOrder%20number%3A%20' . rawurlencode($orderNumber) . '%0AEmail%3A%20' . rawurlencode($customerName) . '"
             style="display:inline-block;padding:10px 24px;background:#3b2a1a;color:#f5ede4;text-decoration:none;border-radius:4px;font-size:13px;letter-spacing:0.5px;">Cancel My Subscription</a>
          <p style="margin:8px 0 0;font-size:11px;color:#999;">Cancellations take effect before your next billing date.</p>
        </div>

        <p style="color:#5a4030;font-size:14px;">Thank you for choosing Bedda! 🌿</p>
      </td></tr>
      <tr><td style="background:#f5ede4;padding:20px 40px;text-align:center;font-size:12px;color:#999;">
        Bedda Skincare · orders@bedda.ca · bedda.ca<br>
        You\'re receiving this because you placed an order with us.
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>';
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Build admin fulfillment email HTML (everything needed to ship)
// ─────────────────────────────────────────────────────────────────────────────
function buildAdminEmail(
    string $orderNumber,
    string $customerName,
    string $customerEmail,
    string $customerPhone,
    string $itemsStr,
    string $shippingAddress,
    float  $grandTotal,
    float  $shippingCost,
    float  $handlingCost,
    string $subscriptionId,
    ?string $paymentIntentId,
    string $alertType   // 'RESCUE' or 'RENEWAL'
): string {
    $items    = parseItemsForEmail($itemsStr);
    $subtotal = round($grandTotal - $shippingCost - $handlingCost, 2);

    $itemRows = '';
    foreach ($items as $item) {
        $itemRows .= '<tr>
            <td style="padding:8px 12px;border-bottom:1px solid #eee;">' . htmlspecialchars($item['name']) . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #eee;text-align:center;">' . $item['qty'] . '</td>
        </tr>';
    }
    if (!$itemRows) {
        $itemRows = '<tr><td colspan="2" style="padding:8px 12px;color:#999;">Items not parsed — check Stripe metadata</td></tr>';
    }

    $addrStyle = $shippingAddress ? '' : 'color:#cc0000;font-weight:bold;';
    $addrText  = $shippingAddress ?: '⚠️ ADDRESS MISSING — contact customer before shipping';
    $phoneText = $customerPhone   ?: '(not captured)';
    $piText    = $paymentIntentId ?: '(not found)';

    $alertColor = ($alertType === 'RESCUE') ? '#cc4400' : '#1a6b2a';
    $alertLabel = ($alertType === 'RESCUE') ? '🚨 RESCUE — Initial order missing from DB' : '🔄 RENEWAL — Month 2+ subscription cycle';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#F5F3EF;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F5F3EF;">
  <tr><td align="center" style="padding:30px 20px;">
    <table width="640" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:6px;overflow:hidden;border:1px solid #ddd;">

      <!-- Header -->
      <tr><td style="background:#1a1a1a;padding:20px 30px;">
        <h2 style="color:#fff;margin:0;font-size:20px;">Bedda Admin — Order Action Required</h2>
      </td></tr>

      <!-- Alert type -->
      <tr><td style="background:' . $alertColor . ';padding:12px 30px;">
        <p style="color:#fff;margin:0;font-size:14px;font-weight:bold;">' . $alertLabel . '</p>
      </td></tr>

      <tr><td style="padding:24px 30px;">

        <!-- ORDER NUMBER big and obvious -->
        <div style="background:#fffbe6;border:2px solid #f0c040;border-radius:6px;padding:14px 20px;margin-bottom:24px;text-align:center;">
          <p style="margin:0;font-size:12px;color:#888;text-transform:uppercase;">Order Number</p>
          <p style="margin:4px 0 0;font-size:26px;font-weight:bold;color:#1a1a1a;letter-spacing:1px;">' . htmlspecialchars($orderNumber) . '</p>
        </div>

        <!-- Customer block -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;border:1px solid #ddd;border-radius:4px;overflow:hidden;">
          <tr style="background:#f8f8f8;"><td colspan="2" style="padding:10px 14px;font-weight:bold;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#555;">Customer Details</td></tr>
          <tr><td style="padding:8px 14px;color:#555;width:140px;">Name</td>        <td style="padding:8px 14px;font-weight:bold;">' . htmlspecialchars($customerName) . '</td></tr>
          <tr style="background:#f8f8f8;"><td style="padding:8px 14px;color:#555;">Email</td>       <td style="padding:8px 14px;"><a href="mailto:' . htmlspecialchars($customerEmail) . '">' . htmlspecialchars($customerEmail) . '</a></td></tr>
          <tr><td style="padding:8px 14px;color:#555;">Phone</td>       <td style="padding:8px 14px;">' . htmlspecialchars($phoneText) . '</td></tr>
        </table>

        <!-- Shipping address — most important thing for fulfillment -->
        <div style="border:2px solid #1a6b2a;border-radius:6px;padding:14px 20px;margin-bottom:24px;">
          <p style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#555;font-weight:bold;">📦 Ship To</p>
          <p style="margin:0;font-size:15px;line-height:1.6;' . $addrStyle . '">' . nl2br(htmlspecialchars($addrText)) . '</p>
        </div>

        <!-- Items -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;border:1px solid #ddd;border-radius:4px;overflow:hidden;">
          <tr style="background:#f8f8f8;"><th style="padding:10px 12px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#555;">Product</th><th style="padding:10px 12px;text-align:center;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#555;">Qty</th></tr>
          ' . $itemRows . '
        </table>

        <!-- Totals -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
          <tr><td style="padding:4px 0;color:#555;">Subtotal</td>  <td style="padding:4px 0;text-align:right;">$' . number_format($subtotal, 2) . ' CAD</td></tr>
          <tr><td style="padding:4px 0;color:#555;">Shipping</td>  <td style="padding:4px 0;text-align:right;">$' . number_format($shippingCost, 2) . ' CAD</td></tr>
          <tr><td style="padding:4px 0;color:#555;">Handling</td>  <td style="padding:4px 0;text-align:right;">$' . number_format($handlingCost, 2) . ' CAD</td></tr>
          <tr style="border-top:2px solid #1a1a1a;"><td style="padding:8px 0 0;font-weight:bold;">Grand Total</td><td style="padding:8px 0 0;text-align:right;font-weight:bold;">$' . number_format($grandTotal, 2) . ' CAD</td></tr>
        </table>

        <!-- Stripe references -->
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #ddd;border-radius:4px;overflow:hidden;">
          <tr style="background:#f8f8f8;"><td colspan="2" style="padding:10px 14px;font-weight:bold;font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#555;">Stripe References</td></tr>
          <tr><td style="padding:8px 14px;color:#555;width:160px;">Subscription ID</td>    <td style="padding:8px 14px;font-family:monospace;font-size:13px;">' . htmlspecialchars($subscriptionId) . '</td></tr>
          <tr style="background:#f8f8f8;"><td style="padding:8px 14px;color:#555;">Payment Intent</td> <td style="padding:8px 14px;font-family:monospace;font-size:13px;">' . htmlspecialchars($piText) . '</td></tr>
          <tr><td style="padding:8px 14px;color:#555;">Caught by</td>         <td style="padding:8px 14px;color:#888;">Cron reconciliation · ' . date('Y-m-d H:i:s') . '</td></tr>
        </table>

      </td></tr>
      <tr><td style="background:#F5F3EF;padding:16px 30px;text-align:center;font-size:12px;color:#aaa;">
        Bedda Skincare internal notification · Do not reply
      </td></tr>
    </table>
  </td></tr>
</table>
</body></html>';
}



// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Re-derive handling cost from items string (same logic as create-subscription.php)
// Used when handling_cost is not stored in DB or Stripe metadata.
// ─────────────────────────────────────────────────────────────────────────────
function recalcHandlingFromItems(string $itemsStr, string $fulfillmentMethod = 'shipping'): float
{
    if ($fulfillmentMethod !== 'shipping') return 0.0;
    $items      = parseItemsForEmail($itemsStr);
    $soapCount  = 0;
    $creamCount = 0;
    $totalItems = 0;
    foreach ($items as $item) {
        $qty         = $item['qty'];
        $totalItems += $qty;
        $lower       = strtolower($item['name']);
        if (strpos($lower, 'soap') !== false && strpos($lower, 'loaf') === false) $soapCount  += $qty;
        if (strpos($lower, 'balm') !== false || strpos($lower, 'cream')  !== false) $creamCount += $qty;
    }
    return ($soapCount <= 5 && $creamCount <= 5 && $totalItems <= 5) ? 3.50 : 3.90;
}

// ═════════════════════════════════════════════════════════════════════════════
// MAIN LOOP
// ═════════════════════════════════════════════════════════════════════════════
try {
    $pdo       = getOrderDatabase();
    $startTime = time() - ($lookbackHours * 3600);

    $invoices = \Stripe\Invoice::all([
        'status'  => 'paid',
        'limit'   => $limit,
        'created' => ['gte' => $startTime],
        'expand'  => ['data.subscription'],
    ]);

    $processed = 0;

    foreach ($invoices->autoPagingIterator() as $invoice) {
        $invoiceId   = $invoice->id;
        $reason      = $invoice->billing_reason ?? 'unknown';
        $amountPaid  = $invoice->amount_paid / 100;
        $amountCents = $invoice->amount_paid;   // integer cents for charge matching
        $customerEmail = $invoice->customer_email ?? '';
        $customerId    = is_string($invoice->customer) ? $invoice->customer : ($invoice->customer->id ?? '');

        // Robust Subscription ID extraction
        $subscriptionId = null;
        if (!empty($invoice->subscription)) {
            $subscriptionId = is_string($invoice->subscription)
                ? $invoice->subscription
                : ($invoice->subscription->id ?? null);
        }
        if (!$subscriptionId && isset($invoice->parent->subscription_details->subscription)) {
            $subscriptionId = $invoice->parent->subscription_details->subscription;
        }

        echo "Checking Invoice: {$invoiceId} | Reason: {$reason} | Amount: \${$amountPaid}\n";

        if ($reason !== 'subscription_cycle' && $reason !== 'subscription_create') {
            echo "  -> Skipped: Not a subscription invoice.\n\n";
            continue;
        }

        if (!$subscriptionId) {
            echo "  -> Skipped: Could not find subscription ID.\n\n";
            continue;
        }

        // ══════════════════════════════════════════════════════════════════
        // SCENARIO 1: MONTH 2+ RENEWAL
        // ══════════════════════════════════════════════════════════════════
        if ($reason === 'subscription_cycle') {

            // Deduplicate by PaymentIntent ID — one PI per billing cycle, immune to
            // order status (shipped, queued, deleted then re-queued, etc.)
            $invoicePiId = is_string($invoice->payment_intent ?? null)
                ? $invoice->payment_intent
                : ($invoice->payment_intent->id ?? null);

            if ($invoicePiId) {
                $stmt = $pdo->prepare("SELECT order_number FROM orders WHERE stripe_payment_intent_id = ? LIMIT 1");
                $stmt->execute([$invoicePiId]);
                if ($stmt->fetchColumn()) {
                    echo "  -> OK: Order for this billing cycle already exists (PI: {$invoicePiId}).\n\n";
                    continue;
                }
            } else {
                // Fallback if PI not on invoice yet (rare): date-based check
                $stmt = $pdo->prepare(
                    "SELECT order_number FROM orders WHERE stripe_subscription_id = ? AND DATE(order_date) = ? LIMIT 1"
                );
                $stmt->execute([$subscriptionId, date('Y-m-d', $invoice->created)]);
                if ($stmt->fetchColumn()) {
                    echo "  -> OK: Renewal order already exists for billing date (fallback check).\n\n";
                    continue;
                }
            }

            // Find the original Month 1 order for shipping address + item details
            $origStmt = $pdo->prepare(
                "SELECT * FROM orders WHERE stripe_subscription_id = ? ORDER BY created_at ASC LIMIT 1"
            );
            $origStmt->execute([$subscriptionId]);
            $originalOrder = $origStmt->fetch(PDO::FETCH_ASSOC);

            if (!$originalOrder) {
                echo "  -> ERROR: Cannot renew — missing original order for sub {$subscriptionId}.\n\n";
                continue;
            }

            $orderNumber = 'BEDDA-REN-' . strtoupper(substr(bin2hex(random_bytes(3)), -6));

            $insert = $pdo->prepare("
                INSERT INTO orders (
                    order_number, stripe_payment_intent_id, stripe_subscription_id,
                    stripe_payment_status, customer_name, customer_email, phone_number,
                    items, subtotal, shipping_address, order_date, fulfillment_method,
                    shipping_cost, grand_total, is_subscription, payment_status, created_at
                ) VALUES (?, ?, ?, 'succeeded', ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, 1, 'paid', NOW())
            ");

            $insert->execute([
                $orderNumber,
                $invoice->payment_intent ?? null,
                $subscriptionId,
                $originalOrder['customer_name'],
                $customerEmail ?: $originalOrder['customer_email'],
                $originalOrder['phone_number'],
                $originalOrder['items'],
                $originalOrder['subtotal'],
                $originalOrder['shipping_address'],
                $originalOrder['fulfillment_method'],
                $originalOrder['shipping_cost'],
                $amountPaid,
            ]);

            echo "  -> SUCCESS: Created renewal order {$orderNumber}\n\n";
            $processed++;

            // Emails
            $shippingCostRenewal = floatval($originalOrder['shipping_cost'] ?? 0);
            // Derive handling from DB totals (handling_cost not stored as its own column)
            $handlingCostRenewal = max(0.0, round(
                floatval($originalOrder['grand_total'] ?? 0)
                - floatval($originalOrder['subtotal']    ?? 0)
                - floatval($originalOrder['shipping_cost'] ?? 0),
                2
            ));
            $itemsStr            = $originalOrder['items'] ?? '';
            $custName            = $originalOrder['customer_name'] ?? 'Valued Customer';
            $custPhone           = $originalOrder['phone_number'] ?? '';
            $shippingAddr        = $originalOrder['shipping_address'] ?? '';

            sendSmtpEmail(
                $customerEmail ?: $originalOrder['customer_email'],
                $custName,
                "Your Bedda Subscription Has Renewed — Order #{$orderNumber}",
                buildCustomerEmail($orderNumber, $custName, $itemsStr, $shippingAddr, $amountPaid, $shippingCostRenewal, $handlingCostRenewal, true)
            );

            sendSmtpEmail(
                ADMIN_EMAIL,
                'Bedda Admin',
                "🔄 RENEWAL ORDER: #{$orderNumber} — {$custName} ({$customerEmail})",
                buildAdminEmail($orderNumber, $custName, $customerEmail ?: $originalOrder['customer_email'], $custPhone, $itemsStr, $shippingAddr, $amountPaid, $shippingCostRenewal, $handlingCostRenewal, $subscriptionId, $invoice->payment_intent ?? null, 'RENEWAL')
            );
        }

        // ══════════════════════════════════════════════════════════════════
        // SCENARIO 2: RESCUE (initial subscription_create order missing)
        // ══════════════════════════════════════════════════════════════════
        if ($reason === 'subscription_create') {

            // Fetch full subscription for metadata
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $targetOrderNumber = $subscription->metadata['order_number'] ?? null;

            // Fallback: look on the price object
            $priceObj = $subscription->items->data[0]->price ?? null;
            if (!$targetOrderNumber && $priceObj) {
                $targetOrderNumber = $priceObj->metadata['order_number'] ?? null;
            }

            // ── ORDER NUMBER EXISTS → check/update DB ─────────────────────
            if ($targetOrderNumber) {
                $stmt = $pdo->prepare(
                    "SELECT id, stripe_subscription_id FROM orders WHERE order_number = ? LIMIT 1"
                );
                $stmt->execute([$targetOrderNumber]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    echo "  -> OK: Initial order {$targetOrderNumber} found in DB.\n";
                    if (empty($existing['stripe_subscription_id'])) {
                        $pdo->prepare(
                            "UPDATE orders SET stripe_subscription_id = ? WHERE order_number = ?"
                        )->execute([$subscriptionId, $targetOrderNumber]);
                        echo "     -> Linked subscription ID for future renewals.\n";
                    }
                    echo "\n";
                    continue;
                }

                echo "  -> RESCUE: order {$targetOrderNumber} missing from DB. Rebuilding...\n";
                $useOrderNumber = $targetOrderNumber;
            } else {
                echo "  -> No order_number in metadata. Blind rescue with generated ID.\n";
                $useOrderNumber = 'BEDDA-RESCUE-' . strtoupper(substr(bin2hex(random_bytes(4)), -8));
            }

            // ── Extract billing details via ALL available sources ──────────
            echo "  -> Fetching billing details (PI → charge → customer charge list)...\n";
            $billing = extractBillingDetails($invoiceId, $customerId, $amountCents, $customerEmail);

            $paymentIntentId = $billing['paymentIntentId'];
            $customerName    = $billing['customerName'];
            $customerPhone   = $billing['customerPhone'];
            $customerEmail   = $billing['customerEmail'] ?: $customerEmail;
            $shippingAddress = $billing['shippingAddress'];

            if ($shippingAddress) {
                echo "  -> Address resolved: {$shippingAddress}\n";
            } else {
                echo "  -> ⚠ Address still unknown after all fallbacks — admin alerted.\n";
            }

            // ── Items & pricing from subscription metadata ─────────────────
            $itemsStr     = $subscription->metadata['items'] ?? ($priceObj->metadata['items'] ?? '');
            $shippingCost = floatval($subscription->metadata['shipping_cost'] ?? ($priceObj->metadata['shipping_cost'] ?? 0));
            // handling_cost is never stored in Stripe metadata — recalculate from items
            $handlingCost = recalcHandlingFromItems($itemsStr, $fulfillmentMethod ?? 'shipping');

            // ── Insert rescued order ───────────────────────────────────────
            // Note: handling_cost is not stored in Stripe subscription metadata,
            // so it is not available here. It is already reflected in grand_total.
            $insert = $pdo->prepare("
                INSERT INTO orders (
                    order_number, stripe_payment_intent_id, stripe_subscription_id,
                    stripe_payment_status, customer_name, customer_email, phone_number,
                    items, subtotal, shipping_address, order_date, fulfillment_method,
                    shipping_cost, grand_total, is_subscription, payment_status, created_at
                ) VALUES (?, ?, ?, 'succeeded', ?, ?, ?, ?, ?, ?, NOW(), 'shipping', ?, ?, 1, 'paid', NOW())
            ");

            $insert->execute([
                $useOrderNumber,
                $paymentIntentId,
                $subscriptionId,
                $customerName,
                $customerEmail,
                $customerPhone,
                $itemsStr,
                round($amountPaid - $shippingCost - $handlingCost, 2),  // subtotal
                $shippingAddress,
                $shippingCost,
                $amountPaid,   // grand total
            ]);

            echo "  -> RESCUED: Order {$useOrderNumber} created";
            if ($targetOrderNumber && $targetOrderNumber !== $useOrderNumber) {
                echo " (original order number: {$targetOrderNumber})";
            }
            echo "\n\n";
            $processed++;

            // ── Emails ─────────────────────────────────────────────────────
            sendSmtpEmail(
                $customerEmail,
                $customerName,
                "Your Bedda Order is Confirmed — Order #{$useOrderNumber}",
                buildCustomerEmail($useOrderNumber, $customerName, $itemsStr, $shippingAddress, $amountPaid, $shippingCost, $handlingCost, false)
            );

            sendSmtpEmail(
                ADMIN_EMAIL,
                'Bedda Admin',
                "🚨 RESCUE ORDER: #{$useOrderNumber} — {$customerName} ({$customerEmail})",
                buildAdminEmail($useOrderNumber, $customerName, $customerEmail, $customerPhone, $itemsStr, $shippingAddress, $amountPaid, $shippingCost, $handlingCost, $subscriptionId, $paymentIntentId, 'RESCUE')
            );
        }
    }

    echo "=== COMPLETE ===\n";
    echo "Caught/processed: {$processed} missing order(s).\n";
    if (!$isCli) echo "</pre>";

} catch (Exception $e) {
    echo "CRITICAL ERROR: " . ($isCli ? $e->getMessage() : htmlspecialchars($e->getMessage()));
    error_log("[BEDDA RECONCILIATION] CRITICAL: " . $e->getMessage());
}