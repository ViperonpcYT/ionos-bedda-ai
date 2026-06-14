<?php
/**
 * Bedda - Enterprise Stripe Webhook Handler
 * 
 * Unified endpoint for subscription + payment intent events
 * Features: Idempotency | Structured Logging | Security Validation | DB Transactions | Observability
 * 
 * @package Bedda\Webhooks
 * @version 2.0.0
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', '/tmp/bedda-webhook-enterprise.log');
header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// 1. BOOTSTRAP & DEPENDENCY VALIDATION
// ============================================================================

require_once __DIR__ . '/secure-config.php';

// Validate critical constants exist
$requiredConstants = ['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET'];
foreach ($requiredConstants as $const) {
    if (!defined($const)) {
        http_response_code(500);
        echo json_encode(['error' => 'configuration_error', 'missing' => $const], JSON_PRETTY_PRINT);
        exit;
    }
}

// Load Stripe SDK with fallback
$stripePaths = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/stripe-php-master/init.php',
    __DIR__ . '/../stripe-php-master/init.php',
];
$stripeLoaded = false;
foreach ($stripePaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $stripeLoaded = true;
        break;
    }
}
if (!$stripeLoaded) {
    error_log('[BEDDA][CRITICAL] Stripe SDK not loaded - checked paths: ' . implode(', ', $stripePaths));
    http_response_code(503);
    echo json_encode(['error' => 'service_unavailable', 'component' => 'stripe_sdk'], JSON_PRETTY_PRINT);
    exit;
}

// ============================================================================
// 2. ENTERPRISE UTILITIES (Inline for portability - extract to classes in larger apps)
// ============================================================================

class WebhookProcessor {
    
    private PDO $pdo;
    private array $context;
    private string $correlationId;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->correlationId = bin2hex(random_bytes(8));
        $this->context = [
            'correlation_id' => $this->correlationId,
            'timestamp' => microtime(true),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
    }
    
    /**
     * Structured logging with correlation ID
     */
    private function log(string $level, string $message, array $extra = []): void {
        $entry = array_merge($this->context, [
            'level' => strtoupper($level),
            'message' => $message,
            'extra' => $extra,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
        ]);
        error_log(json_encode($entry, JSON_UNESCAPED_SLASHES));
        
        // Hook for external monitoring (Datadog, New Relic, etc.)
        if (function_exists('sendToMonitoringService')) {
            sendToMonitoringService($entry);
        }
    }
    
    /**
     * Idempotency check: prevent duplicate event processing
     */
    private function isEventProcessed(string $eventId): bool {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM webhook_events WHERE stripe_event_id = ? LIMIT 1");
            $stmt->execute([$eventId]);
            return (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            $this->log('error', 'Idempotency check failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);
            // Fail-open with warning rather than blocking legitimate events
            return false;
        }
    }
    
    /**
     * Record processed event for idempotency
     */
    private function markEventProcessed(string $eventId, string $eventType, array $metadata = []): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO webhook_events (stripe_event_id, event_type, processed_at, metadata) 
                VALUES (?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $eventId, 
                $eventType, 
                json_encode($metadata, JSON_UNESCAPED_SLASHES)
            ]);
        } catch (Exception $e) {
            $this->log('warn', 'Failed to record event processing', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Verify payment amount against metadata (security)
     */
    private function verifyAmount(float $expected, float $received, string $context): bool {
        $diff = abs($expected - $received);
        if ($diff > 0.01) {
            $this->log('alert', 'Amount verification FAILED', [
                'context' => $context,
                'expected' => $expected,
                'received' => $received,
                'diff' => $diff
            ]);
            return false;
        }
        return true;
    }
    
    /**
     * Execute DB operation with transaction safety
     */
    private function withTransaction(callable $operation): bool {
        try {
            $this->pdo->beginTransaction();
            $result = $operation($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->log('error', 'Transaction failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return false;
        }
    }
    
    /**
     * Send structured response
     */
    private function respond(int $code, array $payload): void {
        http_response_code($code);
        echo json_encode(array_merge(['correlation_id' => $this->correlationId], $payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    // ============================================================================
    // 3. EVENT HANDLERS
    // ============================================================================
    
    public function handleInvoicePaid(array $event): void {
        $invoice = $event['data']['object'];
        
        // Only process subscription cycle renewals
        if (($invoice['billing_reason'] ?? '') !== 'subscription_cycle') {
            $this->log('debug', 'Skipped non-cycle invoice', ['invoice_id' => $invoice['id'], 'reason' => $invoice['billing_reason']]);
            return;
        }
        
        $subscriptionId = $invoice['subscription'] 
            ?? $invoice['parent']['subscription_details']['subscription'] 
            ?? null;
            
        $customerEmail = $invoice['customer_email'] ?? null;
        
        if (!$customerEmail) {
            $this->log('warn', 'Invoice missing customer_email', ['invoice_id' => $invoice['id']]);
            return;
        }
        
        $this->withTransaction(function(PDO $pdo) use ($invoice, $subscriptionId, $customerEmail) {
            // Retrieve original order context
            $stmt = $pdo->prepare("
                SELECT * FROM orders 
                WHERE stripe_subscription_id = ? AND is_subscription = 1 
                ORDER BY created_at ASC LIMIT 1
            ");
            $stmt->execute([$subscriptionId]);
            $originalOrder = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$originalOrder) {
                $this->log('warn', 'No original order found for subscription renewal', ['subscription_id' => $subscriptionId]);
                return true; // Not an error, just informational
            }
            
            // Generate renewal order number
            $orderNumber = 'BEDDA-REN-' . strtoupper(substr(bin2hex(random_bytes(3)), -6));
            
            // Insert renewal order
            $insert = $pdo->prepare("
                INSERT INTO orders (
                    order_number, stripe_payment_intent_id, stripe_subscription_id,
                    stripe_payment_status, customer_name, customer_email, phone_number,
                    items, subtotal, shipping_address, order_date, fulfillment_method,
                    shipping_cost, grand_total, is_subscription, payment_status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, 1, 'paid', NOW())
            ");
            
            $grandTotal = ($invoice['amount_paid'] ?? 0) / 100;
            
            $insert->execute([
                $orderNumber,
                $invoice['payment_intent'] ?? null,
                $subscriptionId,
                'succeeded',
                $originalOrder['customer_name'],
                $customerEmail,
                $originalOrder['phone_number'],
                $originalOrder['items'],
                $originalOrder['subtotal'],
                $originalOrder['shipping_address'],
                $originalOrder['fulfillment_method'],
                $originalOrder['shipping_cost'],
                $grandTotal
            ]);
            
            $orderId = $pdo->lastInsertId();
            
            // Log security event
            if (function_exists('logSecurityEvent')) {
                logSecurityEvent('subscription_renewed', [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'subscription_id' => $subscriptionId,
                    'amount' => $grandTotal,
                    'customer_email' => $customerEmail
                ]);
            }
            
            // Send confirmation email
            if (function_exists('sendSmtpEmail')) {
                sendSmtpEmail(
                    $customerEmail,
                    $originalOrder['customer_name'],
                    "✓ Bedda Subscription Renewed | Order #$orderNumber",
                    "Great news! Your Bedda Skincare subscription has renewed successfully.\n\n" .
                    "Order Number: $orderNumber\n" .
                    "Amount Charged: $" . number_format($grandTotal, 2) . "\n\n" .
                    "Questions? Reply to this email or contact support@bedda.ca"
                );
            }
            
            $this->log('info', 'Subscription renewal order created', [
                'order_number' => $orderNumber,
                'subscription_id' => $subscriptionId,
                'amount' => $grandTotal
            ]);
            
            return true;
        });
    }
    
    public function handleInvoicePaymentFailed(array $event): void {
        $invoice = $event['data']['object'];
        $subscriptionId = $invoice['subscription'] ?? null;
        $customerEmail = $invoice['customer_email'] ?? null;
        
        if (!$subscriptionId || !$customerEmail) {
            $this->log('warn', 'Payment failed event missing critical data', ['invoice_id' => $invoice['id'] ?? 'unknown']);
            return;
        }
        
        $errorDetails = $invoice['last_finalization_error'] ?? $invoice['charges'] ?? 'Unknown error';
        
        // Admin alert (high priority)
        if (function_exists('sendSmtpEmail')) {
            sendSmtpEmail(
                'orders@bedda.ca',
                'Bedda Admin',
                '🚨 ACTION REQUIRED: Subscription Payment Failed',
                "Customer: $customerEmail\n" .
                "Subscription ID: $subscriptionId\n" .
                "Invoice ID: {$invoice['id']}\n" .
                "Attempted Amount: $" . number_format(($invoice['amount_due'] ?? 0) / 100, 2) . "\n" .
                "Error: " . (is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "\n\n" .
                "Manage: https://dashboard.stripe.com/subscriptions/$subscriptionId"
            );
        }
        
        // Customer notification (friendly tone)
        if (function_exists('sendSmtpEmail')) {
            sendSmtpEmail(
                $customerEmail,
                'Bedda Skincare',
                '⚠️ Update Payment Method for Your Bedda Subscription',
                "Hi there,\n\n" .
                "We were unable to process your recent Bedda Skincare subscription renewal. " .
                "This is usually due to an expired card or insufficient funds.\n\n" .
                "✅ To avoid shipping delays, please update your payment method:\n" .
                "https://billing.stripe.com/p/session/{$invoice['id']}\n\n" .
                "Questions? Reply to this email — we're here to help.\n\n" .
                "— Team Bedda"
            );
        }
        
        $this->log('warn', 'Subscription payment failed', [
            'subscription_id' => $subscriptionId,
            'customer_email' => $customerEmail,
            'invoice_id' => $invoice['id']
        ]);
    }
    
    public function handleSubscriptionDeleted(array $event): void {
        $subscription = $event['data']['object'];
        $subscriptionId = $subscription['id'];
        
        $this->withTransaction(function(PDO $pdo) use ($subscriptionId) {
            // Cancel active subscription orders
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET status = 'cancelled', 
                    updated_at = NOW(),
                    cancellation_reason = 'subscription_deleted'
                WHERE stripe_subscription_id = ? 
                AND status NOT IN ('cancelled', 'refunded')
            ");
            $stmt->execute([$subscriptionId]);
            $affected = $stmt->rowCount();
            
            $this->log('info', 'Subscription deletion processed', [
                'subscription_id' => $subscriptionId,
                'orders_updated' => $affected
            ]);
            
            return true;
        });
    }
    
    public function handlePaymentIntentSucceeded(array $event): void {
        $paymentIntent = $event['data']['object'];
        $metadata = $paymentIntent['metadata'] ?? [];
        
        if (!empty($paymentIntent['invoice'])) {
            $this->log('info', 'Skipping subscription PaymentIntent (handled by invoice.paid)', ['pi' => $paymentIntent['id']]);
            return;
        }
        
        $metadata = $paymentIntent['metadata'] ?? [];
        
        // Validate required metadata
        $requiredMetadata = ['order_number', 'subtotal', 'shipping_cost'];
        foreach ($requiredMetadata as $key) {
            if (!isset($metadata[$key])) {
                $this->log('alert', 'PaymentIntent missing required metadata', [
                    'payment_intent_id' => $paymentIntent['id'],
                    'missing_key' => $key
                ]);
                $this->respond(400, ['error' => 'invalid_metadata', 'missing' => $key]);
                exit;
            }
        }
        
        // Security: Verify amount matches metadata
        $expectedAmount = 
            floatval($metadata['subtotal']) + 
            floatval($metadata['shipping_cost']) + 
            floatval($metadata['handling_cost'] ?? 0);
        $receivedAmount = ($paymentIntent['amount'] ?? 0) / 100;
        
        if (!$this->verifyAmount($expectedAmount, $receivedAmount, "order_{$metadata['order_number']}")) {
            $this->respond(400, ['error' => 'amount_verification_failed']);
            exit;
        }
        
        $this->withTransaction(function(PDO $pdo) use ($paymentIntent, $metadata, $receivedAmount) {
            $orderNumber = $metadata['order_number'];
            
            // Update order
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET payment_status = 'paid',
                    paid_at = NOW(),
                    stripe_payment_intent_id = ?,
                    stripe_session_id = ?,
                    updated_at = NOW()
                WHERE order_number = ?
                AND payment_status != 'paid' -- Prevent duplicate updates
            ");
            $stmt->execute([
                $paymentIntent['id'],
                $paymentIntent['id'], // Or use checkout_session_id if available
                $orderNumber
            ]);
            
            if ($stmt->rowCount() === 0) {
                $this->log('warn', 'Order not found or already paid', ['order_number' => $orderNumber]);
                return true;
            }
            
            // Security audit log
            if (function_exists('logSecurityEvent')) {
                logSecurityEvent('payment_received', [
                    'order_number' => $orderNumber,
                    'amount' => $receivedAmount,
                    'payment_intent' => $paymentIntent['id'],
                    'customer_ip' => $this->context['ip']
                ]);
            }
            
            $this->log('info', 'Order payment confirmed', [
                'order_number' => $orderNumber,
                'amount' => $receivedAmount,
                'payment_intent' => $paymentIntent['id']
            ]);
            
            // TODO: Trigger fulfillment service (Chit Chats API)
            // if (function_exists('triggerFulfillment')) {
            //     triggerFulfillment($orderNumber);
            // }
            
            return true;
        });
    }
    
    public function handlePaymentIntentFailed(array $event): void {
        $paymentIntent = $event['data']['object'];
        $error = $paymentIntent['last_payment_error'] ?? null;
        
        $this->log('warn', 'PaymentIntent failed', [
            'payment_intent_id' => $paymentIntent['id'],
            'error_code' => $error['code'] ?? null,
            'error_message' => $error['message'] ?? null,
            'decline_code' => $error['decline_code'] ?? null
        ]);
        
        // Optional: Notify customer of failed payment for one-time orders
        // (Implementation depends on your order lookup logic)
    }
    
    // ============================================================================
    // 4. MAIN PROCESSOR
    // ============================================================================
    
    public function process(array $event): void {
        $eventId = $event['id'] ?? 'unknown';
        $eventType = $event['type'] ?? 'unknown';
        
        // Idempotency gate
        if ($this->isEventProcessed($eventId)) {
            $this->log('debug', 'Duplicate event ignored (idempotent)', ['event_id' => $eventId]);
            $this->respond(200, ['status' => 'ignored_duplicate', 'event_id' => $eventId]);
            return;
        }
        
        $this->log('info', "Processing event: $eventType", ['event_id' => $eventId]);
        
        // Route to handler
        try {
            match ($eventType) {
                'invoice.paid' => $this->handleInvoicePaid($event),
                'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event),
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
                default => $this->log('debug', 'Event type ignored', ['event_type' => $eventType])
            };
            
            // Mark as processed AFTER successful handling
            $this->markEventProcessed($eventId, $eventType, ['order_number' => $event['data']['object']['metadata']['order_number'] ?? null]);
            
            $this->respond(200, ['status' => 'processed', 'event_id' => $eventId]);
            
        } catch (Exception $e) {
            $this->log('error', 'Unhandled exception in event handler', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->respond(500, ['error' => 'internal_error', 'correlation_id' => $this->correlationId]);
        }
    }
}

// ============================================================================
// 5. ENTRY POINT
// ============================================================================

try {
    // Parse incoming webhook payload
    $payload = @file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    
    if (!$payload) {
        http_response_code(400);
        echo json_encode(['error' => 'empty_payload'], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Initialize verification container
    $event = null;
    
    // Attempt 1: Try checking against the main payment webhook secret
    try {
        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $sigHeader,
            STRIPE_WEBHOOK_SECRET
        );
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        // Attempt 2: Fall back to subscription webhook secret if Attempt 1 fails
        if (defined('STRIPE_WEBHOOK_SECRET_SUB') && !empty(STRIPE_WEBHOOK_SECRET_SUB)) {
            try {
                $event = \Stripe\Webhook::constructEvent(
                    $payload,
                    $sigHeader,
                    STRIPE_WEBHOOK_SECRET_SUB
                );
            } catch (\Stripe\Exception\SignatureVerificationException $subEx) {
                // Both webhook secrets failed validation - bubble up the exception
                throw $subEx;
            }
        } else {
            // Fallback secret wasn't defined; bubble up the original error
            throw $e;
        }
    }
    
    // Get DB connection
    $pdo = getOrderDatabase(); // From secure-config.php
    require_once __DIR__ . '/lib/orders-schema.php';
    ensureOrdersSchema($pdo);

    // Process event normally now that verification succeeded
    $processor = new WebhookProcessor($pdo);
    $processor->process($event->toArray());
    
} catch (\UnexpectedValueException $e) {
    // Invalid payload structure
    http_response_code(400);
    echo json_encode(['error' => 'invalid_payload', 'message' => $e->getMessage()], JSON_PRETTY_PRINT);
    exit;
    
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    // Both attempts failed security validation - potential bad request
    error_log('[BEDDA][SECURITY] Invalid webhook signature against all configurations: ' . $e->getMessage());
    http_response_code(401);
    echo json_encode(['error' => 'invalid_signature'], JSON_PRETTY_PRINT);
    exit;
}