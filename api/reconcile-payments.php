<?php
/**
 * Bedda Payment/Order Reconciliation
 * Location: /api/reconcile-payments.php
 *
 * Closes BOTH desync gaps:
 *   A) Stripe charged but no order row  -> creates the order from Stripe metadata
 *   B) Order row exists but stripe_payment_status != live status -> patches it
 *   C) Subscription renewal invoices with no order row -> creates a renewal order
 *
 * Idempotent. Safe to run repeatedly. No double-charges. No double orders.
 *
 * Trigger via:
 *   1. IONOS cron every 15 min:  curl -s 'https://bedda.ca/api/reconcile-payments.php?key=YOUR_API_KEY'
 *   2. Manual:  https://bedda.ca/api/reconcile-payments.php?key=YOUR_API_KEY&hours=72
 *   3. Auto:    called at the end of stripe-webhook.php as a tail-safety pass
 */

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/orders-schema.php';

ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(120);
header('Content-Type: application/json');

// ============================================================
// AUTH — must pass an API key from VALID_API_KEYS + brute-force protection
// ============================================================
$bfp = new AdminBruteProtect();
$ip = getClientIP();

// Always check brute force first
if (!$bfp->check($ip)) {
    http_response_code(429);
    echo json_encode(['success'=>false,'message'=>'Too many attempts. Try again in 15 minutes.']);
    exit;
}

$key = $_GET['key'] ?? ($_SERVER['HTTP_X_ADMIN_KEY'] ?? '');
global $VALID_API_KEYS;
if (empty($key) || !is_array($VALID_API_KEYS) || !in_array($key, $VALID_API_KEYS, true)) {
    $bfp->record($ip);
    logSecurityEvent('reconcile_unauth', ['ip'=>$ip]);
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$hours = max(1, min(168, intval($_GET['hours'] ?? 48)));
$since = time() - ($hours * 3600);

$stripe = getStripe();
if (!$stripe) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Stripe SDK unavailable']);
    exit;
}

$pdo = getOrderDatabase();
ensureOrdersSchema($pdo);
$report = ['created'=>0, 'updated'=>0, 'subscription_renewals'=>0, 'errors'=>0, 'checked'=>0, 'skipped'=>0, 'details'=>[]];

// ============================================================
// PASS 1: Pull recent successful PaymentIntents from Stripe → reconcile each
// ============================================================
try {
    $params = ['limit'=>100, 'created'=>['gte'=>$since]];
    $hasMore = true;
    $starting = null;
    while ($hasMore) {
        if ($starting) $params['starting_after'] = $starting;
        $list = $stripe->paymentIntents->all($params);
        foreach ($list->data as $pi) {
            $report['checked']++;
            try {
                if ($pi->status !== 'succeeded') continue;
                if (!reconcileIsOnlyBikesPaymentIntent($pi)) {
                    $report['skipped']++;
                    continue;
                }
                reconcileOnePaymentIntent($pdo, $pi, $report);
            } catch (Throwable $e) {
                $report['errors']++;
                $report['details'][] = ['pi'=>$pi->id, 'err'=>$e->getMessage()];
                error_log('[BEDDA RECONCILE PI] ' . $pi->id . ': ' . $e->getMessage());
            }
        }
        $hasMore = $list->has_more;
        if ($hasMore && !empty($list->data)) $starting = end($list->data)->id;
    }
} catch (Throwable $e) {
    $report['errors']++;
    $report['details'][] = ['stage'=>'list_pi','err'=>$e->getMessage()];
    error_log('[BEDDA RECONCILE LIST PI] ' . $e->getMessage());
}

// ============================================================
// PASS 2: Subscription invoice renewals → make sure each paid invoice has an order row
// ============================================================
try {
    $invParams = ['limit'=>100, 'status'=>'paid', 'created'=>['gte'=>$since]];
    $hasMore = true; $starting = null;
    while ($hasMore) {
        if ($starting) $invParams['starting_after'] = $starting;
        $invList = $stripe->invoices->all($invParams);
        foreach ($invList->data as $inv) {
            try {
                if (!$inv->subscription) continue;
                reconcileInvoice($pdo, $stripe, $inv, $report);
            } catch (Throwable $e) {
                $report['errors']++;
                $report['details'][] = ['invoice'=>$inv->id, 'err'=>$e->getMessage()];
                error_log('[BEDDA RECONCILE INV] ' . $inv->id . ': ' . $e->getMessage());
            }
        }
        $hasMore = $invList->has_more;
        if ($hasMore && !empty($invList->data)) $starting = end($invList->data)->id;
    }
} catch (Throwable $e) {
    $report['errors']++;
    $report['details'][] = ['stage'=>'list_invoices','err'=>$e->getMessage()];
}

// ============================================================
// PASS 3: Find pending/processing local orders older than 30 min → check status with Stripe
// ============================================================
try {
    $stmt = $pdo->prepare("
        SELECT id, order_number, stripe_payment_intent_id, stripe_payment_status, late_confirm_notified
        FROM orders
        WHERE stripe_payment_status IN ('pending','processing','requires_action','requires_confirmation','')
          AND status != 'cancelled'
          AND stripe_payment_intent_id IS NOT NULL
          AND order_date < (NOW() - INTERVAL 30 MINUTE)
        LIMIT 100
    ");
    $stmt->execute();
    while ($row = $stmt->fetch()) {
        try {
            $pi = $stripe->paymentIntents->retrieve($row['stripe_payment_intent_id']);
            $oldStatus = (string) ($row['stripe_payment_status'] ?? '');
            if ($pi->status === 'succeeded') {
                dbUpdate($pdo, 'orders',
                    ['stripe_payment_status' => 'succeeded'],
                    ['id' => $row['id']]
                );
                $report['updated']++;
                $report['details'][] = ['stale_to_paid'=>$row['order_number']];
                reconcileMaybeNotifyLateConfirm($pdo, (int) $row['id'], $row['order_number'], $pi, $oldStatus);
            } elseif (in_array($pi->status, ['canceled','requires_payment_method'], true)) {
                dbUpdate($pdo, 'orders',
                    ['stripe_payment_status' => $pi->status],
                    ['id' => $row['id']]
                );
                $report['updated']++;
                $report['details'][] = ['marked_failed'=>$row['order_number'], 'status'=>$pi->status];
            }
        } catch (Throwable $e) {
            $report['errors']++;
            $report['details'][] = ['order'=>$row['order_number'], 'err'=>$e->getMessage()];
        }
    }
} catch (Throwable $e) {
    $report['errors']++;
    $report['details'][] = ['stage'=>'pending_scan','err'=>$e->getMessage()];
}

// ============================================================
// LOG SUMMARY
// ============================================================
@file_put_contents(
    __DIR__ . '/logs/' . date('Y-m-d') . '-reconcile.log',
    json_encode(['t'=>date('c'),'hours'=>$hours] + $report) . "\n",
    FILE_APPEND | LOCK_EX
);

echo json_encode(['success'=>true, 'hours_window'=>$hours] + $report);
exit;

// ============================================================
// HELPERS
// ============================================================

function reconcileIsOnlyBikesPaymentIntent($pi): bool
{
    $meta = (array) ($pi->metadata->toArray() ?? []);
    if (!empty($meta['order_number']) && is_string($meta['order_number'])) {
        return true;
    }
    $site = strtolower(trim((string) ($meta['site'] ?? '')));
    if ($site === 'onlybikes.shop' || $site === 'www.onlybikes.shop') {
        return true;
    }
    return false;
}

function reconcilePaymentBecameSucceeded(?string $oldStatus, string $newStatus): bool
{
    return $newStatus === 'succeeded' && strtolower((string) $oldStatus) !== 'succeeded';
}

function reconcileMaybeNotifyLateConfirm(PDO $pdo, int $orderId, string $orderNumber, $pi, ?string $oldStatus): void
{
    if (!reconcilePaymentBecameSucceeded($oldStatus, (string) $pi->status)) {
        return;
    }
    if (ordersTableHasColumn($pdo, 'orders', 'late_confirm_notified')) {
        $chk = $pdo->prepare('SELECT late_confirm_notified FROM orders WHERE id = ? LIMIT 1');
        $chk->execute([$orderId]);
        if ((int) ($chk->fetchColumn() ?: 0) === 1) {
            return;
        }
    }
    notifyOrderConfirmed($orderNumber, $pi);
    if (ordersTableHasColumn($pdo, 'orders', 'late_confirm_notified')) {
        dbUpdate($pdo, 'orders', ['late_confirm_notified' => 1], ['id' => $orderId]);
    }
}

function reconcileOnePaymentIntent(PDO $pdo, $pi, array &$report): void {
    // Look for existing local order by payment_intent_id
    $stmt = $pdo->prepare("SELECT id, order_number, status, stripe_payment_status, late_confirm_notified FROM orders WHERE stripe_payment_intent_id = ? LIMIT 1");
    $stmt->execute([$pi->id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $oldStatus = (string) ($existing['stripe_payment_status'] ?? '');
        if ($oldStatus !== $pi->status) {
            dbUpdate($pdo, 'orders',
                ['stripe_payment_status' => $pi->status],
                ['id' => $existing['id']]
            );
            $report['updated']++;
            $report['details'][] = ['updated'=>$existing['order_number'], 'to'=>$pi->status];
            if ($existing['status'] !== 'cancelled') {
                reconcileMaybeNotifyLateConfirm($pdo, (int) $existing['id'], $existing['order_number'], $pi, $oldStatus);
            }
        }
        return;
    }

    // Also try matching by order_number embedded in metadata (in case PI was swapped)
    $orderNumber = $pi->metadata->order_number ?? null;
    if ($orderNumber) {
        $stmt = $pdo->prepare("SELECT id, order_number, status, stripe_payment_status, late_confirm_notified FROM orders WHERE order_number = ? LIMIT 1");
        $stmt->execute([$orderNumber]);
        $byName = $stmt->fetch();
        if ($byName) {
            $oldStatus = (string) ($byName['stripe_payment_status'] ?? '');
            dbUpdate($pdo, 'orders',
                ['stripe_payment_intent_id' => $pi->id, 'stripe_payment_status' => $pi->status],
                ['id' => $byName['id']]
            );
            $report['updated']++;
            $report['details'][] = ['linked_by_metadata'=>$orderNumber];
            if ($byName['status'] !== 'cancelled') {
                reconcileMaybeNotifyLateConfirm($pdo, (int) $byName['id'], $byName['order_number'], $pi, $oldStatus);
            }
            return;
        }
    }

    // Missing local order — only reconstruct true OnlyBikes checkouts (metadata.order_number).
    $reconstructed = reconstructOrderFromPI($pi);
    if (!$reconstructed) {
        $report['skipped']++;
        $report['details'][] = ['skipped_alien_pi'=>$pi->id];
        return;
    }
    try {
        dbInsert($pdo, 'orders', $reconstructed);
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate') !== false) {
            return;
        }
        throw $e;
    }
    $report['created']++;
    $report['details'][] = ['ghost_recovered'=>$reconstructed['order_number'], 'pi'=>$pi->id];
    $newId = (int) $pdo->lastInsertId();
    reconcileMaybeNotifyLateConfirm($pdo, $newId, $reconstructed['order_number'], $pi, 'pending');
    alertAdminGhostPayment($pi, true);
}

function reconstructOrderFromPI($pi): ?array {
    $meta = (array)($pi->metadata->toArray() ?? []);
    $orderNumber = trim((string) ($meta['order_number'] ?? ''));
    if ($orderNumber === '') {
        return null;
    }
    return [
        'order_number'              => $orderNumber,
        'stripe_payment_intent_id'  => $pi->id,
        'stripe_payment_status'     => $pi->status,
        'stripe_subscription_id'    => $meta['subscription_id'] ?? null,
        'chitchats_shipment_id'     => null,
        'customer_name'             => $meta['customer_name']  ?? ($pi->shipping->name ?? ''),
        'customer_email'            => $meta['customer_email'] ?? ($pi->receipt_email   ?? ''),
        'phone_number'              => $meta['phone']          ?? '',
        'items'                     => $meta['items_json']     ?? json_encode([['product'=>'(reconstructed)','price'=>round($pi->amount/100,2),'quantity'=>1]]),
        'subtotal'                  => round($pi->amount / 100, 2),
        'shipping_address'          => $meta['shipping_address'] ?? (formatStripeShipping($pi->shipping ?? null)),
        'order_date'                => date('Y-m-d H:i:s', $pi->created ?? time()),
        'ip_address'                => $meta['ip']             ?? '0.0.0.0',
        'spam_score'                => 0,
        'fulfillment_method'        => $meta['fulfillment_method'] ?? 'shipping',
        'pickup_location'           => $meta['pickup_location'] ?? null,
        'pickup_date'               => $meta['pickup_date']     ?? null,
        'shipping_cost'             => isset($meta['shipping_cost']) ? floatval($meta['shipping_cost']) : 0,
        'shipping_carrier'          => $meta['shipping_carrier'] ?? 'Unknown',
        'chitchats_postage_type'    => null,
        'grand_total'               => round($pi->amount / 100, 2),
        'is_subscription'           => !empty($meta['subscription_id']) ? 1 : 0,
        'subscription_interval'     => isset($meta['subscription_interval']) ? intval($meta['subscription_interval']) : null,
        'coupon_code'               => !empty($meta['coupon_code']) ? strtoupper((string) $meta['coupon_code']) : null,
        'discount_amount'           => isset($meta['coupon_discount']) ? (float) $meta['coupon_discount'] : 0,
        'late_confirm_notified'     => 1,
        'status'                    => 'queued',
    ];
}

function formatStripeShipping($s): string {
    if (!$s || !$s->address) return '';
    $a = $s->address;
    return trim(($s->name ?? '') . "\n" . ($a->line1 ?? '') . "\n" . ($a->line2 ? $a->line2 . "\n" : '') . ($a->city ?? '') . ', ' . ($a->state ?? '') . ' ' . ($a->postal_code ?? '') . "\n" . ($a->country ?? ''));
}

function reconcileInvoice(PDO $pdo, $stripe, $inv, array &$report): void {
    $piId = is_string($inv->payment_intent) ? $inv->payment_intent : ($inv->payment_intent->id ?? null);
    if (!$piId) return;

    // Already have a row for this invoice's PI? — handled by Pass 1.
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE stripe_payment_intent_id = ? LIMIT 1");
    $stmt->execute([$piId]);
    if ($stmt->fetch()) return;

    $subscriptionId = is_string($inv->subscription) ? $inv->subscription : ($inv->subscription->id ?? null);
    if (!$subscriptionId) {
        return;
    }

    // Only renew subscriptions that already have an OnlyBikes order row (skip legacy Bedda subs).
    $orig = $pdo->prepare(
        'SELECT order_number FROM orders WHERE stripe_subscription_id = ? AND is_subscription = 1 ORDER BY id ASC LIMIT 1'
    );
    $orig->execute([$subscriptionId]);
    $origRow = $orig->fetch(PDO::FETCH_ASSOC);
    if (!$origRow) {
        $report['skipped']++;
        $report['details'][] = ['skipped_sub_no_local'=>$subscriptionId];
        return;
    }

    $sub = $stripe->subscriptions->retrieve($subscriptionId);
    $orderNumber = 'OB-SUB-' . strtoupper(substr($inv->id, -8));
    $cust = $stripe->customers->retrieve($inv->customer);

    $row = [
        'order_number'             => $orderNumber,
        'stripe_payment_intent_id' => $piId,
        'stripe_payment_status'    => 'succeeded',
        'stripe_subscription_id'   => $inv->subscription,
        'chitchats_shipment_id'    => null,
        'customer_name'            => $cust->name  ?? '',
        'customer_email'           => $cust->email ?? '',
        'phone_number'             => $cust->phone ?? '',
        'items'                    => json_encode([['product'=>'Subscription Renewal','price'=>round($inv->amount_paid/100,2),'quantity'=>1]]),
        'subtotal'                 => round($inv->amount_paid / 100, 2),
        'shipping_address'         => formatStripeShipping($cust->shipping ?? null),
        'order_date'               => date('Y-m-d H:i:s', $inv->created ?? time()),
        'ip_address'               => '0.0.0.0',
        'spam_score'               => 0,
        'fulfillment_method'       => 'shipping',
        'pickup_location'          => null,
        'pickup_date'              => null,
        'shipping_cost'            => 0,
        'shipping_carrier'         => 'Subscription',
        'chitchats_postage_type'   => null,
        'grand_total'              => round($inv->amount_paid / 100, 2),
        'is_subscription'          => 1,
        'subscription_interval'    => null,
        'status'                   => 'queued',
        'late_confirm_notified'    => 1,
    ];
    try {
        dbInsert($pdo, 'orders', $row);
        $report['subscription_renewals']++;
        $report['details'][] = ['renewal_created'=>$orderNumber, 'sub'=>$subscriptionId];
        notifyRenewal($row, $inv, false);
    } catch (Throwable $e) {
        // Likely UNIQUE collision — another reconcile pass already inserted it
        if (strpos($e->getMessage(), 'Duplicate') === false) throw $e;
    }
}

function notifyOrderConfirmed(string $orderNumber, $pi): void {
    if (!function_exists('sendSmtpEmail')) return;
    $support = defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : ADMIN_EMAIL;
    $body = "<p>Payment confirmed for order <b>" . htmlspecialchars($orderNumber) . "</b>.</p>"
          . "<p>PaymentIntent: " . htmlspecialchars($pi->id) . "<br>"
          . "Amount: $" . number_format($pi->amount / 100, 2) . "</p>"
          . "<p>OnlyBikes reconcile: submit-order or webhook did not mark this paid in time. Check order fulfillment.</p>";
    @sendSmtpEmail(ADMIN_EMAIL, 'OnlyBikes Admin', "[OnlyBikes Reconcile] Late confirm — $orderNumber", $body);
}

function notifyRenewal(array $row, $inv, bool $emailCustomer = false): void {
    if (!function_exists('sendSmtpEmail')) return;
    $body = "<p>Subscription renewal recorded.</p>"
          . "<p>Order: " . htmlspecialchars($row['order_number']) . "<br>"
          . "Customer: " . htmlspecialchars($row['customer_email']) . "<br>"
          . "Subscription: " . htmlspecialchars((string) $inv->subscription) . "<br>"
          . "Amount: $" . number_format($row['grand_total'], 2) . "</p>";
    @sendSmtpEmail(ADMIN_EMAIL, 'OnlyBikes Admin', "[OnlyBikes Renewal] " . $row['order_number'], $body);
    if ($emailCustomer && !empty($row['customer_email'])) {
        $custBody = "<p>Hi " . htmlspecialchars($row['customer_name']) . ",</p>"
                  . "<p>Your OnlyBikes subscription renewed (order <b>" . htmlspecialchars($row['order_number']) . "</b>).</p>"
                  . "<p>Questions? Email " . htmlspecialchars(defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : 'support@onlybikes.shop') . "</p>";
        @sendSmtpEmail($row['customer_email'], $row['customer_name'] ?: 'Customer',
            "OnlyBikes Subscription Renewed — " . $row['order_number'], $custBody);
    }
}

function alertAdminGhostPayment($pi, bool $recovered = false): void {
    if (!function_exists('sendSmtpEmail')) return;
    static $alerted = [];
    if (isset($alerted[$pi->id])) {
        return;
    }
    $alerted[$pi->id] = true;

    $subj = $recovered
        ? "[OnlyBikes] Recovered missing order — " . $pi->id
        : "[OnlyBikes] Stripe payment needs review — " . $pi->id;
    $meta = (array) $pi->metadata->toArray();
    $body = "<h2>" . ($recovered ? 'Order auto-created from Stripe metadata' : 'Unmatched Stripe payment') . "</h2>"
          . "<p>PaymentIntent: " . htmlspecialchars($pi->id) . "<br>"
          . "Amount: $" . number_format($pi->amount / 100, 2) . "<br>"
          . "Email: " . htmlspecialchars($pi->receipt_email ?? ($meta['customer_email'] ?? '(none)')) . "</p>"
          . "<p>Metadata:<br><pre>" . htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT)) . "</pre></p>"
          . ($recovered
              ? "<p>Verify fulfillment — customer may not have received the normal confirmation email.</p>"
              : "<p>No OnlyBikes order_number in metadata — ignored by cron (not reconstructed).</p>");
    @sendSmtpEmail(ADMIN_EMAIL, 'OnlyBikes Admin', $subj, $body);
}
