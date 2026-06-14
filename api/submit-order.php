<?php
/**
 * OnlyBikes Secure Order Submission - Enterprise Edition
 *
 * Security features:
 * - Rate limiting (IP, email, phone, cookie)
 * - DDoS protection (payload size limits + fast exit)
 * - Bot detection (user agent + timing + honeypot)
 * - Input validation & sanitization
 * - SQL injection prevention (PDO prepared statements)
 * - XSS prevention (htmlspecialchars throughout)
 * - Origin/Referer check (CSRF-style)
 * - Item count + price sanity caps
 * - No CAPTCHA dependency
 * - Friendly customer error messages, internals hidden
 */

// ============================================================
// HARD LIMITS — kill bad requests before loading anything
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);        // NEVER output errors to browser (corrupts JSON)
ini_set('log_errors', 1);            // Log to file instead
header('Content-Type: application/json');
ob_start();                          // Buffer any stray output
set_time_limit(120);
ini_set('error_log', __DIR__ . '/submit-order-errors.log');

$maxPayloadBytes = 50 * 1024; // 50KB — far more than any real order needs

$contentLength = intval($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxPayloadBytes) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Request too large. Please contact support@onlybikes.example if you need help.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . (beddaIsLocalRequest() ? 'http://localhost:8080' : rtrim(cfg('SITE_ORIGIN', 'https://onlybikes.example'), '/')));
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

// ============================================================
// EMAIL SENDERS — use shared sendSmtpEmail() from secure-config.php
// with fallback inline PHPMailer + full SMTP debug
// ============================================================

function sendOrderNotification($order) {
    // Prefer shared sendSmtpEmail from secure-config.php
    if (function_exists('sendSmtpEmail')) {
        return sendSmtpEmail(
            onlybikes_admin_email(),
            'OnlyBikes Admin',
            "New Order: {$order['orderNumber']}",
            createBusinessEmail($order),
            strip_tags(createBusinessEmail($order)),
            [
                'replyTo' => $order['customer']['email'] ?? null,
                'replyToName' => $order['customer']['name'] ?? '',
                'cc' => onlybikes_support_email(),
                'ccName' => 'OnlyBikes Support',
            ]
        );
    }

    // Fallback inline with debug
    try {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer') && function_exists('loadPHPMailer')) {
            loadPHPMailer();
        }
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log('[BEDDA] Admin email: PHPMailer class not available');
            return false;
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->SMTPDebug  = 3;
        $mail->Debugoutput = function($str, $level) { error_log("[BEDDA][SMTP][ADMIN][$level] $str"); };
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress(onlybikes_admin_email(), 'OnlyBikes Admin');
        if (!empty($order['customer']['email'])) {
            $mail->addReplyTo($order['customer']['email'], $order['customer']['name']);
        }
        $mail->Subject = "New Order: {$order['orderNumber']}";
        $mail->isHTML(true);
        $mail->Body = createBusinessEmail($order);
        return $mail->send();
    } catch (Throwable $e) {
        error_log('[BEDDA] Admin email error: ' . $e->getMessage());
        return false;
    }
}

function sendCustomerConfirmation($order) {
    // Prefer shared sendSmtpEmail from secure-config.php
    if (function_exists('sendSmtpEmail')) {
        return sendSmtpEmail(
            $order['customer']['email'],
            $order['customer']['name'],
            "Order Confirmation: {$order['orderNumber']}",
            createCustomerEmail($order),
            strip_tags(createCustomerEmail($order)),
            ['replyTo' => SUPPORT_EMAIL, 'replyToName' => 'OnlyBikes Support']
        );
    }

    // Fallback inline with debug
    try {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer') && function_exists('loadPHPMailer')) {
            loadPHPMailer();
        }
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log('[BEDDA] Customer email: PHPMailer class not available');
            return false;
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->SMTPDebug  = 3;
        $mail->Debugoutput = function($str, $level) { error_log("[BEDDA][SMTP][CUST][$level] $str"); };
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($order['customer']['email'], $order['customer']['name']);
        $mail->addReplyTo(SUPPORT_EMAIL, 'OnlyBikes Support');
        $mail->Subject = "Order Confirmation: {$order['orderNumber']}";
        $mail->isHTML(true);
        $mail->Body    = createCustomerEmail($order);
        $mail->AltBody = strip_tags(createCustomerEmail($order));
        return $mail->send();
    } catch (Throwable $e) {
        error_log('[BEDDA] Customer email error: ' . $e->getMessage());
        return false;
    }
}

// ============================================================
// UPDATED: createCustomerEmail() - Shipping included in grand total
// ============================================================

function createCustomerEmail($order) {
    $grandTotal      = floatval($order['grand_total']);        // already correct from main script
    $discountAmount  = floatval($order['discount_amount'] ?? 0);
    $shippingCost    = floatval($order['pure_shipping'] ?? $order['shipping_cost'] ?? 0);
    $handlingCost    = floatval($order['handling_cost'] ?? 0);

    $discountRow = '';
    if ($discountAmount > 0) {
        $discountLabel = '';
        if (!empty($order['points_used'])) {
            $discountLabel = 'Reward Points';
        } elseif (!empty($order['coupon']['code'])) {
            $discountLabel = htmlspecialchars($order['coupon']['code']);
        } elseif (!empty($order['is_subscription']) && $order['is_subscription']) {
            $discountLabel = 'Subscription (5% off)';
        }
        $discountRow = "<tr>
            <td colspan='3' style='padding:8px 8px 8px 24px;font-weight:600;color:#7E6D58'>
                🏷️ Discount (" . $discountLabel . "):
            </td>
            <td style='padding:8px;text-align:right;font-weight:600;color:#7E6D58'>
                -$" . number_format($discountAmount, 2) . "
            </td>
        </tr>";
    }

    // Build items table
    $rows = '';
    foreach ($order['items'] as $item) {
        $p = htmlspecialchars($item['product'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
        $q = intval($item['quantity'] ?? 1);
        $pr = floatval($item['price'] ?? 0);
        $sub = $pr * $q;
        $rows .= "<tr><td style='padding:8px;border-bottom:1px solid #e5e7eb'>{$p}</td>
                  <td style='padding:8px;border-bottom:1px solid #e5e7eb;text-align:right'>$" . number_format($pr, 2) . "</td>
                  <td style='padding:8px;border-bottom:1px solid #e5e7eb;text-align:center'>{$q}</td>
                  <td style='padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:600'>$" . number_format($sub, 2) . "</td></tr>";
    }
    
    // Shipping breakdown - always include if shipping cost > 0
    // Shipping & Handling breakdown
    $shippingRow = '';
    if ($shippingCost > 0) {
        $carrier = htmlspecialchars($order['shipping_carrier'] ?? 'Standard Shipping');
        $shippingRow = "<tr><td colspan='3' style='padding:8px 8px 8px 24px;font-weight:600;color:#1e40af'>📦 Shipping ({$carrier}):</td>
                        <td style='padding:8px;text-align:right;font-weight:600;color:#1e40af'>$" . number_format($shippingCost, 2) . "</td></tr>";
    }

    $handlingRow = '';
    if ($handlingCost > 0) {
        $handlingRow = "<tr><td colspan='3' style='padding:8px 8px 8px 24px;font-weight:600;color:#1e40af'>⚙️ Handling Fee:</td>
                        <td style='padding:8px;text-align:right;font-weight:600;color:#1e40af'>$" . number_format($handlingCost, 2) . "</td></tr>";
    }
    
    // Coupon section
    $couponHtml = '';
    $cDiscount = floatval($order['discount_amount'] ?? 0);
    if (!empty($order['coupon']) && $cDiscount > 0) {
        $cOriginal = floatval($order['original_subtotal'] ?? $order['subtotal'] ?? 0);
        $cFinal    = floatval($order['final_subtotal']    ?? ($cOriginal - $cDiscount));
        $percent   = $cOriginal > 0 ? round(($cDiscount / $cOriginal) * 100) : 0;
        $couponHtml = "<div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;margin:16px 0'>
            <p style='margin:0 0 8px 0;color:#166534;font-weight:600'>🎫 Coupon Applied</p>
            <p style='margin:0;font-size:14px;color:#15803d'>
                Code: <strong>" . htmlspecialchars($order['coupon']['code']) . "</strong><br>
                Saved: <strong>{$percent}% off</strong> ($" . number_format($cDiscount, 2) . ")<br>
                New Subtotal: <strong>$" . number_format($cFinal, 2) . "</strong>
            </p>
        </div>";
    }
    
    // Fulfillment note
    $fulfillmentHtml = '';
    $method = $order['fulfillment_method'] ?? 'shipping';
    if ($method === 'pickup') {
        $loc = htmlspecialchars($order['pickup_location'] ?? 'Unknown');
        $date = htmlspecialchars($order['pickup_date'] ?? 'TBD');
        $fulfillmentHtml = "<div style='background:#eff6ff;border-left:4px solid #5A4D3F;padding:12px;margin:16px 0'>
            <p style='margin:0;color:#1e40af;font-weight:600'>📦 Pickup Order</p>
            <p style='margin:4px 0 0 0;color:#1e40af'>Location: {$loc}<br>Date: {$date}<br><em>Bring this email to confirm pickup</em></p>
        </div>";
    }

    // Subscription section
    $subscriptionHtml = '';
    $isSub = !empty($order['is_subscription']) && $order['is_subscription'];
    $subId = $order['stripe_subscription_id'] ?? '';
    if ($isSub && $subId) {
        $interval = intval($order['subscription_interval'] ?? 2);
        $intervalText = $interval === 1 ? 'month' : 'months';
        $nextBill = date('F j, Y', strtotime("+{$interval} month"));
        $subscriptionHtml = "<div style='background:#fef3c2;border-left:4px solid #d97706;padding:12px;margin:16px 0'>
            <p style='margin:0;color:#92400e;font-weight:600'>🔄 Subscription Active</p>
            <p style='margin:4px 0 0 0;color:#92400e'>
                Billed every {$interval} {$intervalText}<br>
                Subscription ID: <code>" . htmlspecialchars($subId) . "</code><br>
                Next billing: {$nextBill}<br>
                <a href='https://billing.stripe.com/p/login/4gM5kEdep50ogFTeW908g00' style='color:#d97706;font-weight:600'>Manage subscription →</a>
            </p>
        </div>";
    }
    
    $name = htmlspecialchars($order['customer']['name'], ENT_QUOTES, 'UTF-8');
    $address = nl2br(htmlspecialchars($order['shipping']['fullAddress'], ENT_QUOTES, 'UTF-8'));
    
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><title>Order Confirmation #{$order['orderNumber']}</title></head>
    <body style='font-family:Inter,system-ui,-apple-system,sans-serif;line-height:1.6;color:#2C2C2C;margin:0;padding:20px;background:#F5F3EF'>
        <div style='max-width:600px;margin:0 auto;background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);overflow:hidden'>
            
            <!-- Header -->
            <div style='background:linear-gradient(135deg,#B5A183,#9C8C73);padding:24px;text-align:center'>
                <h1 style='margin:0;color:white;font-size:24px;font-weight:700'>✨ Order Confirmed!</h1>
                <p style='margin:8px 0 0 0;color:#F0ECE6;font-size:16px'>Order #{$order['orderNumber']}</p>
            </div>
            
            <div style='padding:24px'>
                <!-- Welcome -->
                <p style='margin:0 0 16px 0'>Hi {$name},</p>
                <p style='margin:0 0 24px 0'>Thank you for your order with OnlyBikes! Your payment has been securely processed via Stripe and your order is now confirmed.</p>
                
                {$couponHtml}
                {$subscriptionHtml}
                {$fulfillmentHtml}
                
                <!-- Order Summary -->
                <h2 style='margin:0 0 16px 0;color:#2C2C2C;font-size:18px;border-bottom:2px solid #f3f4f6;padding-bottom:8px'>📋 Order Summary</h2>
                <table style='width:100%;border-collapse:collapse;margin-bottom:16px'>
                    <thead>
                        <tr style='background:#F5F3EF'>
                            <th style='padding:12px 8px;text-align:left;font-weight:600;color:#374151'>Product</th>
                            <th style='padding:12px 8px;text-align:right;font-weight:600;color:#374151'>Price</th>
                            <th style='padding:12px 8px;text-align:center;font-weight:600;color:#374151'>Qty</th>
                            <th style='padding:12px 8px;text-align:right;font-weight:600;color:#374151'>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}{$discountRow}{$shippingRow}{$handlingRow}
                        <tr style='background:#F5F3EF;font-weight:700'>
                            <td colspan='3' style='padding:12px 8px;text-align:right;color:#2C2C2C'>Grand Total (incl. shipping):</td>
                            <td style='padding:12px 8px;text-align:right;color:#9C8C73;font-size:18px'>$" . number_format($grandTotal, 2) . "</td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- Shipping/Pickup Address -->
                <h2 style='margin:0 0 16px 0;color:#2C2C2C;font-size:18px;border-bottom:2px solid #f3f4f6;padding-bottom:8px'>
                    " . ($method === 'pickup' ? '📍 Pickup Location' : '🚚 Shipping To') . "
                </h2>
                <p style='margin:0 0 24px 0;white-space:pre-wrap'>{$address}</p>
                
                <!-- Payment Status -->
                <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px;margin-bottom:24px'>
                    <h3 style='margin:0 0 8px 0;color:#166534;font-weight:600'>💳 Payment Status</h3>
                    <p style='margin:0;color:#166534'><strong>Paid via Stripe ✓</strong></p>
                    <p style='margin:8px 0 0 0;color:#15803d;font-size:14px'>Total charged: <strong>$" . number_format($grandTotal, 2) . "</strong></p>
                </div>
                
                <!-- What to Expect -->
                <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px;margin-bottom:24px'>
                    <h3 style='margin:0 0 8px 0;color:#166534;font-weight:600'>📅 What to Expect Next</h3>
                    <ol style='margin:0;padding-left:20px;color:#166534'>
                        <li><strong>Within 24h:</strong> Order packed and shipping label created</li>
                        <li><strong>After shipping:</strong> Chit Chats tracking number emailed to you</li>
                        <li><strong>2-8 business days:</strong> Delivery to your address</li>
                    </ol>
                </div>
                
                <!-- Support -->
                <div style='text-align:center;padding:16px;background:#F5F3EF;border-radius:8px'>
                    <p style='margin:0 0 8px 0;font-weight:600;color:#2C2C2C'>Questions?</p>
                    <p style='margin:0'>
                        <a href='mailto:support@onlybikes.example' style='color:#5A4D3F;text-decoration:none;font-weight:500'>support@onlybikes.example</a> • 
                        <a href='https://onlybikes.example/contact.html' style='color:#5A4D3F;text-decoration:none;font-weight:500'>Contact Page</a>
                    </p>
                </div>
                
                <!-- Footer -->
                <div style='margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;text-align:center;color:#5A4D3F;font-size:14px'>
                    <p style='margin:0'>OnlyBikes • E-moto parts from Canada</p>
                    <p style='margin:4px 0 0 0'>Order #{$order['orderNumber']} • Placed: {$order['orderDate']}</p>
                    <p style='margin:8px 0 0 0;font-size:12px;color:#9ca3af'>Payment processed securely via Stripe. Questions? Reply to this email.</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
}


// ============================================================
// LOAD DEPENDENCIES
// ============================================================

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/security-helpers.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/customers-schema.php';
require_once __DIR__ . '/lib/points-ledger.php';
require_once __DIR__ . '/lib/coupons-schema.php';
if (file_exists(__DIR__ . '/rate-limiter.php')) {
    require_once __DIR__ . '/rate-limiter.php';
} else {
    // Stub if rate-limiter.php is missing
    if (!class_exists('RateLimiter')) {
        class RateLimiter {
            private function getRateFile($type, $key) {
                $dir = RATE_LIMIT_DIR;
                if (!is_dir($dir)) mkdir($dir, 0750, true);
                return $dir . $type . '_' . md5($key) . '.json';
            }
            
            private function readRecord($file) {
                if (!file_exists($file)) return ['count' => 0, 'first' => time(), 'blocked' => false];
                $data = json_decode(file_get_contents($file), true);
                return $data ?: ['count' => 0, 'first' => time(), 'blocked' => false];
            }
            
            private function writeRecord($file, $data) {
                file_put_contents($file, json_encode($data), LOCK_EX);
            }
            
            public function canSubmitOrder($email, $phone) {
                $ip = getClientIP();
                $now = time();
                $reasons = [];
                
                // IP check — per hour
                $ipFile = $this->getRateFile('ip', $ip);
                $ipRec = $this->readRecord($ipFile);
                if ($now - $ipRec['first'] > 3600) {
                    $ipRec = ['count' => 0, 'first' => $now, 'blocked' => false];
                }
                $ipRec['count']++;
                $this->writeRecord($ipFile, $ipRec);
                
                if ($ipRec['count'] > RATE_LIMIT_ORDERS_PER_IP_PER_HOUR) {
                    $reasons[] = 'ip_limit';
                    return ['allowed' => false, 'blocked' => true, 'score' => 100, 'reasons' => $reasons, 'message' => 'Too many orders from this IP. Please try again later.'];
                }
                
                // Email check — per day
                if ($email) {
                    $emFile = $this->getRateFile('email', $email);
                    $emRec = $this->readRecord($emFile);
                    if ($now - $emRec['first'] > 86400) {
                        $emRec = ['count' => 0, 'first' => $now, 'blocked' => false];
                    }
                    $emRec['count']++;
                    $this->writeRecord($emFile, $emRec);
                    
                    if ($emRec['count'] > RATE_LIMIT_ORDERS_PER_EMAIL_PER_DAY) {
                        $reasons[] = 'email_limit';
                        return ['allowed' => false, 'blocked' => true, 'score' => 100, 'reasons' => $reasons, 'message' => 'Too many orders from this email. Please try again tomorrow.'];
                    }
                }
                
                // Phone check — per day
                if ($phone) {
                    $phFile = $this->getRateFile('phone', $phone);
                    $phRec = $this->readRecord($phFile);
                    if ($now - $phRec['first'] > 86400) {
                        $phRec = ['count' => 0, 'first' => $now, 'blocked' => false];
                    }
                    $phRec['count']++;
                    $this->writeRecord($phFile, $phRec);
                    
                    if ($phRec['count'] > RATE_LIMIT_ORDERS_PER_PHONE_PER_DAY) {
                        $reasons[] = 'phone_limit';
                        return ['allowed' => false, 'blocked' => true, 'score' => 100, 'reasons' => $reasons, 'message' => 'Too many orders from this phone. Please try again tomorrow.'];
                    }
                }
                
                return ['allowed' => true, 'blocked' => false, 'score' => 0, 'reasons' => [], 'message' => 'OK'];
            }
            
            public function recordOrder($email, $phone, $orderData) {
                // Counts already incremented in canSubmitOrder
            }
        }
    }
}

// ============================================================
// SECURITY HEADERS
// ============================================================

try {

setSecurityHeaders();

// ============================================================
// CUSTOMER-FRIENDLY ERROR RESPONSE
// Validation errors shown as-is (helpful).
// Server errors hide internals, show support contact.
// ============================================================

function orderErrorResponse($message, $httpCode = 400, $logEvent = true) {
    if ($logEvent) {
        logSecurityEvent('order_error', ['message' => $message, 'code' => $httpCode]);
    }

    if ($httpCode >= 500) {
        // Server errors: hide internals, provide support contact
        $customerMessage = 'Something went wrong on our end. Please email '
            . onlybikes_support_email()
            . ' with your order details and we\'ll process it right away.';
    } else {
        // Client validation errors: show specific reason + support fallback
        $customerMessage = $message . ' If this error persists, please contact '
            . onlybikes_support_email() . ' for assistance.';
    }

    http_response_code($httpCode);
    echo json_encode([
        'success'       => false,
        'message'       => $customerMessage,
        'support_email' => onlybikes_support_email(),
    ]);
    exit();
}

// ============================================================
// ORIGIN CHECK (CSRF-style — blocks requests from other sites)
// ============================================================

$allowedHosts = beddaAllowedHosts();
$originHeader = $_SERVER['HTTP_ORIGIN'] ?? '';
$refererHeader = $_SERVER['HTTP_REFERER'] ?? '';

$checkUrl  = !empty($originHeader) ? $originHeader : $refererHeader;
$checkHost = parse_url($checkUrl, PHP_URL_HOST);

if (!in_array($checkHost, $allowedHosts, true)) {
    logSecurityEvent('invalid_origin', ['origin' => $checkUrl, 'ip' => getClientIP()]);
    orderErrorResponse('Invalid request origin. Please submit your order from the OnlyBikes storefront.', 403);
}

// ============================================================
// PARSE JSON BODY
// ============================================================

$rawInput = file_get_contents('php://input');

if (strlen($rawInput) > $maxPayloadBytes) {
    orderErrorResponse('Request too large.', 413);
}

$data = json_decode($rawInput, true);

if (!$data || !is_array($data)) {
    orderErrorResponse('Invalid request format. Please try again or contact support@onlybikes.example.');
}

// ============================================================
// HONEYPOT CHECK
// ============================================================

if (ORDER_HONEYPOT_ENABLED) {
    if (!empty($data['website']) || !empty($data['url']) || !empty($data['company'])) {
        logSecurityEvent('honeypot_triggered', ['ip' => getClientIP()]);
        logOrder(['type' => 'spam_honeypot', 'ip' => getClientIP()], true);
        // Fake success — bots don't know they were blocked
        echo json_encode(['success' => true, 'message' => 'Order received!', 'data' => ['orderNumber' => 'BOT-' . time()]]);
        exit();
    }
}

// ============================================================
// TIMING CHECK (bots submit too fast or use stale pages)
// ============================================================

if (empty($data['form_timestamp'])) {
    logSecurityEvent('missing_timestamp', ['ip' => getClientIP()]);
    orderErrorResponse('Invalid form submission. Please refresh the page and try again.');
}

$formTime = intval($data['form_timestamp']);
$elapsed  = time() - $formTime;

if ($elapsed < ORDER_MIN_TIME_SECONDS) {
    logSecurityEvent('fast_submission', ['elapsed' => $elapsed, 'ip' => getClientIP()]);
    orderErrorResponse('Please take your time filling out the form. Contact support@onlybikes.example if you keep seeing this.');
}

if ($elapsed > ORDER_MAX_TIME_SECONDS) {
    orderErrorResponse('Your session has expired. Please refresh the page and try again.');
}

// ============================================================
// BOT USER AGENT CHECK
// ============================================================

$userAgent = trim($_SERVER['HTTP_USER_AGENT'] ?? '');

if (isLikelyBot($userAgent, getallheaders())) {
    logSecurityEvent('bot_ua_detected', ['ua' => $userAgent, 'ip' => getClientIP()]);
    logOrder(['type' => 'spam_bot_detected', 'user_agent' => $userAgent], true);
    echo json_encode(['success' => true, 'message' => 'Order received!', 'data' => ['orderNumber' => 'BOT-' . time()]]);
    exit();
}

// ============================================================
// REQUIRED FIELD CHECK
// ============================================================

$errors = [];

// Base required fields for ALL orders
$requiredFields = [
    'customerName'  => 'Full name',
    'customerEmail' => 'Email address',
    'phoneNumber'   => 'Phone number',
];

// Shipping-specific fields
$shippingRequired = [
    'streetAddress' => 'Street address',
    'city'          => 'City',
    'province'      => 'Province',
    'postalCode'    => 'Postal code',
];

// Pickup-specific fields
$pickupRequired = [
    'pickup_location' => 'Pickup location',
    'pickup_date'     => 'Pickup date',
];

// Check base fields
foreach ($requiredFields as $field => $label) {
    if (empty(trim($data[$field] ?? ''))) {
        $errors[] = "{$label} is required.";
    }
}

// Check fulfillment-specific fields
$fulfillment = $data['fulfillment_method'] ?? 'shipping';
if ($fulfillment === 'shipping') {
    foreach ($shippingRequired as $field => $label) {
        if (empty(trim($data[$field] ?? ''))) {
            $errors[] = "{$label} is required for shipping.";
        }
    }
} elseif ($fulfillment === 'pickup') {
    foreach ($pickupRequired as $field => $label) {
        if (empty(trim($data[$field] ?? ''))) {
            $errors[] = "{$label} is required for local pickup.";
        }
    }
}

if (!empty($errors)) {
    orderErrorResponse('Please fix the following: ' . implode(' ', $errors));
}

// ============================================================
// SANITIZE
// ============================================================

$name       = sanitizeInput($data['customerName'],  'name');
$email      = sanitizeInput($data['customerEmail'], 'email');
$phone      = sanitizeInput($data['phoneNumber'],   'phone');
$street     = sanitizeInput($data['streetAddress'], 'address');
$address2   = !empty($data['address2']) ? sanitizeInput($data['address2'], 'address') : '';
$city       = sanitizeInput($data['city'],          'name');
$province   = sanitizeInput($data['province'],      'province');
$postalCode = sanitizeInput($data['postalCode'],    'postal');
$country    = strtoupper(sanitizeInput($data['country'] ?? 'CA', 'string') ?: 'CA');
if (!preg_match('/^[A-Z]{2}$/', $country)) {
    $country = 'CA';
}
$newsletter = onlybikes_truthy($data['newsletter'] ?? false);

// Normalise postal code (handle "A1A 1A1" with space)
$postalCode = strtoupper(str_replace(' ', '', $postalCode ?? ''));

// ============================================================
// VALIDATE SANITIZED VALUES
// ============================================================

if (!$name || strlen($name) < 2) {
    $errors[] = 'Please enter a valid full name (at least 2 characters).';
}
if (!$email) {
    $errors[] = 'Please enter a valid email address.';
}
if (!$phone || strlen($phone) < 10) {
    $errors[] = 'Please enter a valid phone number (at least 10 digits).';
}
// Shipping-specific validations (skip for pickup)
$fulfillment = $data['fulfillment_method'] ?? 'shipping';
if ($fulfillment === 'shipping') {
    if (!$street || strlen($street) < 5) {
        $errors[] = 'Please enter a valid street address.';
    }
    if (!$city || strlen($city) < 2) {
        $errors[] = 'Please enter a valid city name.';
    }
    if (in_array($country, ['CA', 'US'], true) && !$province) {
        $errors[] = $country === 'US' ? 'Please select a valid state.' : 'Please select a valid province.';
    }
    if ($country === 'CA') {
        if (!$postalCode || strlen($postalCode) !== 6 || !preg_match('/^[A-Z][0-9][A-Z][0-9][A-Z][0-9]$/', $postalCode)) {
            $errors[] = 'Please enter a valid Canadian postal code (e.g., A1A 1A1).';
        }
    } elseif ($country === 'US') {
        if (!$postalCode || !preg_match('/^\d{5}(\d{4})?$/', $postalCode)) {
            $errors[] = 'Please enter a valid US ZIP code.';
        }
    } elseif (!$postalCode || strlen($postalCode) < 3) {
        $errors[] = 'Please enter a valid postal code.';
    }

    $shipOptId = $data['shipping_option']['id'] ?? '';
    $shipEstimated = !empty($data['shipping_option']['estimated']);
    if ($shipOptId === 'fallback' || $shipEstimated) {
        $errors[] = 'Shipping rates must be loaded from Chit Chats before checkout. Please refresh and try again.';
    }
    if (empty($data['shipping_option']['total']) || floatval($data['shipping_option']['total']) <= 0) {
        $errors[] = 'Please select a live shipping rate before checkout.';
    }
} else {
    // Pickup validations
    $pickupLocation = $data['pickup_location'] ?? '';
    $pickupDate = $data['pickup_date'] ?? '';
    if (empty($pickupLocation)) {
        $errors[] = 'Please select a pickup location.';
    }
    if (empty($pickupDate)) {
        $errors[] = 'Please select a pickup date.';
    }
}

// Validate items
if (empty($data['items']) || !is_array($data['items'])) {
    $errors[] = 'Your cart appears to be empty. Please add items before ordering.';
} else {
    if (count($data['items']) > 50) {
        $errors[] = 'Too many items in order. Please contact support@onlybikes.example for large orders.';
    }
    foreach ($data['items'] as $item) {
        if (empty($item['product']) || !isset($item['price']) || empty($item['quantity'])) {
            $errors[] = 'One or more items in your cart are invalid. Please refresh and try again.';
            break;
        }
        if (floatval($item['price']) < 0 || floatval($item['price']) > 10000) {
            $errors[] = 'Invalid item price detected. Please contact support@onlybikes.example.';
            break;
        }
        if (intval($item['quantity']) < 1) {
            $errors[] = 'Invalid item quantity. Please contact support@onlybikes.example.';
            break;
        }
    }
}

if (!empty($errors)) {
    orderErrorResponse('Please fix the following: ' . implode(' ', $errors));
}

// ============================================================
// RATE LIMITING
// ============================================================

$rateLimiter = new RateLimiter();
$rateCheck   = $rateLimiter->canSubmitOrder($email, $phone);

if (!$rateCheck['allowed']) {
    logSecurityEvent('rate_limit_exceeded', ['reason' => $rateCheck['reason'], 'email' => md5($email), 'ip' => getClientIP()]);
    logOrder(['type' => 'spam_rate_limited', 'reason' => $rateCheck['reason']], true);
    orderErrorResponse($rateCheck['message'], 429);
}

// High spam score — silent block
if ($rateCheck['blocked']) {
    logSecurityEvent('order_blocked_spam', ['score' => $rateCheck['score'], 'ip' => getClientIP()]);
    logOrder(['type' => 'spam_blocked', 'score' => $rateCheck['score']], true);
    sendSpamNotification($data, $rateCheck);
    echo json_encode(['success' => true, 'message' => 'Order received!', 'data' => ['orderNumber' => 'SPAM-' . time()]]);
    exit();
}

// ============================================================
// BUILD ORDER
// ============================================================

$orderNumber = !empty($data['orderNumber']) ? sanitizeInput($data['orderNumber'], 'string') : 'OB-' . strtoupper(substr(uniqid('', true), -8));

// Capture the subscription ID
$stripeSubscriptionId = $data['subscriptionId'] ?? $data['stripe_subscription_id'] ?? null;
$orderDate   = date('Y-m-d H:i:s');

$subtotal = 0;
foreach ($data['items'] as $item) {
    $subtotal += floatval($item['price']) * intval($item['quantity']);
}

// Sanity cap
if ($subtotal > 5000) {
    logSecurityEvent('suspicious_order_total', ['subtotal' => $subtotal, 'ip' => getClientIP()]);
    orderErrorResponse('Your order total looks unusually high. Please contact support@onlybikes.example to place a large order and we\'ll help you out.');
}

// Handle fulfillment method FIRST
$fulfillmentMethod = $data['fulfillment_method'] ?? 'shipping';
$pickupLocation = $data['pickup_location'] ?? null;
$pickupDate = $data['pickup_date'] ?? null;

$shippingAddress  = $street;
if ($address2) $shippingAddress .= "\n" . $address2;

if ($fulfillmentMethod === 'pickup') {
    $shippingAddress = "PICKUP ORDER\nLocation: " . sanitizeInput($pickupLocation, 'string') . 
                      "\nDate: " . sanitizeInput($pickupDate, 'string') . 
                      "\nInstructions: Bring order confirmation to pickup location.";
    if (empty($pickupLocation) || empty($pickupDate)) {
        orderErrorResponse('Please select a pickup location and date.');
    }
} else {
    $shippingAddress .= "\n{$city}, {$province} {$postalCode}\n{$country}";
}

// Handle shipping cost from selected option
$shippingCost = 0;
$shippingCarrier = 'Standard';
if (!empty($data['shipping_option']) && is_array($data['shipping_option'])) {
    $shippingCost = floatval($data['shipping_option']['total'] ?? 0);
    $shippingCarrier = sanitizeInput($data['shipping_option']['carrier'] ?? 'Standard', 'string');
}

// Build order data array (FIXED syntax)
$orderData = [
    'orderNumber'  => $orderNumber,
    'orderDate'    => $orderDate,
    'customer'     => ['name' => $name, 'email' => $email, 'phone' => $phone],
    'shipping'     => [
        'street'      => $street,
        'address2'    => $address2,
        'city'        => $city,
        'province'    => $province,
        'postalCode'  => $postalCode,
        'country'     => $country,
        'fullAddress' => $shippingAddress,
    ],
    'items'         => $data['items'],
    'subtotal'      => round($subtotal, 2),
    'shipping_cost' => $shippingCost,
    'shipping_carrier' => $shippingCarrier,
    'grand_total'   => round($subtotal + $shippingCost, 2),
    'newsletter'    => $newsletter,
    'ip'            => getClientIP(),
    'userAgent'     => $userAgent,
    'spamScore'     => $rateCheck['score'] ?? 0,
    'spamReasons'   => $rateCheck['reasons'] ?? [],
    'fulfillment_method' => $fulfillmentMethod,
    'pickup_location'    => $pickupLocation,
    'pickup_date'        => $pickupDate,
    'is_subscription'    => !empty($data['isSubscription']) ? 1 : 0,
    'subscription_interval' => !empty($data['subscriptionInterval']) ? intval($data['subscriptionInterval']) : null,
    'stripe_subscription_id' => $stripeSubscriptionId,
];

// ---- Recalculate shipping from Chit Chats ----
$liveShippingCost = 0;
$liveCarrier = 'Standard';
$stripePaymentIntentId = $data['payment_intent_id'] ?? null;
$stripePaymentStatus = $data['payment_status'] ?? 'unknown';

// --- calculate handling (must match create-payment-intent.php) ---
$handlingCost = onlybikes_handling_cost($data['items'] ?? [], $fulfillmentMethod);

// Combine them so emails and DB show the total shipping cost to the customer
// AFTER the live quote block (after line 788), replace lines 790-825 with:
$totalShippingAndHandling = $liveShippingCost + $handlingCost;
$orderData['shipping_cost']    = $totalShippingAndHandling;
$orderData['shipping_carrier'] = $liveCarrier;

// Apply coupon if present
if (!empty($data['coupon']) && isset($data['coupon']['discount'])) {
    $discountAmount  = min(floatval($data['coupon']['discount']), $subtotal); // items only
    $finalSubtotal   = $subtotal - $discountAmount;
    $finalGrandTotal = $finalSubtotal + $totalShippingAndHandling; // shipping/handling untouched
    $orderData['original_subtotal'] = $subtotal;
    $orderData['discount_amount']   = $discountAmount;
    $orderData['final_subtotal']    = $finalSubtotal;
    $orderData['coupon']            = $data['coupon'];
} else {
    $discountAmount  = 0;
    $finalGrandTotal = $subtotal + $totalShippingAndHandling;
}
$orderData['grand_total'] = round($finalGrandTotal, 2);

if ($fulfillmentMethod === 'shipping' && !empty($data['items'])) {
    $quotePayload = [
        'items' => $data['items'],
        'postal_code' => $postalCode,
        'province' => $province,
        'country_code' => $country,
        'subtotal' => $subtotal,
        'fulfillment_method' => 'shipping',
        'street_address' => $street,
        'address2' => $address2,
        'city' => $city,
        'name' => $name,
    ];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $quoteUrl = $scheme . '://' . $host . '/api/get-shipping-quote.php';
    $ch = curl_init($quoteUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($quotePayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $quoteResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $quoteResponse) {
        $quoteData = json_decode($quoteResponse, true);
        if (!empty($quoteData['success']) && !empty($quoteData['options'])) {
            $selectedId = $data['shipping_option']['id'] ?? null;
            $matchedOption = null;
            if ($selectedId) {
                foreach ($quoteData['options'] as $opt) {
                    if (($opt['id'] ?? '') === $selectedId && empty($opt['estimated']) && floatval($opt['total'] ?? 0) > 0) {
                        $matchedOption = $opt;
                        break;
                    }
                }
            }
            if (!$matchedOption) {
                orderErrorResponse('Could not verify your selected shipping rate. Please refresh checkout and try again.');
            }
            $liveShippingCost = floatval($matchedOption['total']);
            $liveCarrier = sanitizeInput($matchedOption['carrier'] ?? 'Standard', 'string');
        } else {
            orderErrorResponse($quoteData['message'] ?? 'Live shipping rates are unavailable. Please try again.');
        }
    } else {
        orderErrorResponse('Unable to verify shipping cost. Please try again in a moment.');
    }
}

// Override the shipping values that will be used in DB and emails
// Recalculate total with live shipping + handling
$totalShippingAndHandling = $liveShippingCost + $handlingCost;

// Override the shipping values that will be used in DB and emails
$orderData['shipping_cost']    = $totalShippingAndHandling;
$orderData['pure_shipping']    = $liveShippingCost;
$orderData['handling_cost']    = $handlingCost;
$orderData['shipping_carrier'] = $liveCarrier;
$orderData['grand_total']      = round($subtotal + $totalShippingAndHandling, 2);
// Add to orderData array for logging/email context
$orderData['stripe_payment_intent_id'] = $stripePaymentIntentId;
$orderData['stripe_payment_status'] = $stripePaymentStatus;

$originalSubtotal = $subtotal;
$discountAmount = 0;
$pointsUsed = 0;
$pointsDiscountCad = 0.0;
$finalGrandTotal = $subtotal + $totalShippingAndHandling;
$sessionCustomerId = onlybikes_points_session_customer_id();

// Reject client-supplied customer_id for points (anti-tamper)
if (!empty($data['customer_id']) && $sessionCustomerId !== null && (int) $data['customer_id'] !== $sessionCustomerId) {
    logSecurityEvent('points_customer_id_mismatch', ['ip' => getClientIP()]);
    orderErrorResponse('Account mismatch. Please sign in again and retry checkout.', 403);
}
if (!empty($data['customer_id']) && $sessionCustomerId === null) {
    logSecurityEvent('points_without_session', ['ip' => getClientIP(), 'client_id' => (int) $data['customer_id']]);
    unset($data['customer_id'], $data['use_points']);
}

if (!empty($data['use_points']) && $sessionCustomerId !== null) {
    try {
        $customersDB = getCustomersDatabase();
        onlybikes_ensure_customers_schema($customersDB);
        if (!onlybikes_points_verify_customer_email($customersDB, $sessionCustomerId, $email)) {
            orderErrorResponse('Rewards account email does not match this order.', 403);
        }
        $availablePoints = onlybikes_customers_fetch_points($customersDB, $sessionCustomerId);
        if ($availablePoints !== null) {
            $redeemPlan = onlybikes_points_checkout_redeem_calc($availablePoints, $finalGrandTotal);
            if ($redeemPlan['points_used'] > 0) {
                $pointsUsed = $redeemPlan['points_used'];
                $pointsDiscountCad = $redeemPlan['discount_cad'];
                $discountAmount = $pointsDiscountCad;
                $finalSubtotal = $originalSubtotal - $discountAmount;
                $finalGrandTotal = $finalSubtotal + $totalShippingAndHandling;

                $orderData['original_subtotal'] = $originalSubtotal;
                $orderData['discount_amount'] = $discountAmount;
                $orderData['final_subtotal'] = $finalSubtotal;
                $orderData['grand_total'] = $finalGrandTotal;
                $orderData['points_used'] = $pointsUsed;
                $orderData['points_discount'] = $pointsDiscountCad;
            }
        }
    } catch (Throwable $e) {
        error_log('[OnlyBikes] Points plan error: ' . $e->getMessage());
        $pointsUsed = 0;
        $pointsDiscountCad = 0.0;
    }
} elseif (!empty($data['coupon']) && isset($data['coupon']['discount'])) {
    $discountAmount = floatval($data['coupon']['discount']);
    $discountAmount = min($discountAmount, $originalSubtotal);
    $finalSubtotal = $originalSubtotal - $discountAmount;
    $finalGrandTotal = $finalSubtotal + $totalShippingAndHandling; // shipping not discounted

    // Store discount info for emails & logging
    $orderData['original_subtotal'] = $originalSubtotal;
    $orderData['discount_amount']   = $discountAmount;
    $orderData['final_subtotal']    = $finalSubtotal;
    $orderData['grand_total']       = $finalGrandTotal;

    // Also update the coupon array passed to emails
    if (!isset($orderData['coupon'])) {
        $orderData['coupon'] = $data['coupon'];
    }
    $orderData['coupon']['discount']      = $discountAmount;
    $orderData['coupon']['finalSubtotal'] = $finalSubtotal;
} else {
    $orderData['grand_total'] = $finalGrandTotal;
}

// Override the grand_total for the rest of the script
$orderData['grand_total'] = $finalGrandTotal;

// ============================================================
// SERVER-SIDE PAYMENT VERIFICATION (CRITICAL: Don't trust client)
// ============================================================
$verifiedPaymentStatus = 'unknown';
$verifiedAmount = 0;

if (!empty($stripePaymentIntentId) && function_exists('getStripe')) {
    $stripe = getStripe();
    if ($stripe) {
        try {
            if (strpos($stripePaymentIntentId, 'pi_') === 0) {
                // Verify PaymentIntent
                $pi = $stripe->paymentIntents->retrieve($stripePaymentIntentId);
                $verifiedPaymentStatus = $pi->status;
                $verifiedAmount = $pi->amount; // in cents
                error_log("[BEDDA] Verified PI {$stripePaymentIntentId}: status={$verifiedPaymentStatus}, amount={$verifiedAmount}");
            } elseif (strpos($stripePaymentIntentId, 'seti_') === 0) {
                // Verify SetupIntent (for subscriptions)
                $si = $stripe->setupIntents->retrieve($stripePaymentIntentId);
                $verifiedPaymentStatus = $si->status;
                error_log("[BEDDA] Verified SI {$stripePaymentIntentId}: status={$verifiedPaymentStatus}");
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log("[BEDDA] Stripe verification failed: " . $e->getMessage());
            orderErrorResponse('Payment verification failed. Please try again or contact support.', 400);
        }
    }
}

// For paid orders, MUST verify status is actually succeeded
$expectedCents = intval(round($finalGrandTotal * 100));
if ($verifiedPaymentStatus !== 'succeeded' && $verifiedPaymentStatus !== 'requires_action') {
    // Payment not successful - don't save order
    error_log("[BEDDA] REJECTED: Payment status is '{$verifiedPaymentStatus}', not succeeded. Order {$orderNumber} NOT saved.");
    orderErrorResponse('Payment was not successful. Please check your payment details and try again.', 400);
}

// Verify amount matches (prevent tampering)
if ($verifiedAmount > 0 && abs($verifiedAmount - $expectedCents) > 1) { // 1 cent tolerance
    error_log("[BEDDA] REJECTED: Amount mismatch. Expected {$expectedCents}c, got {$verifiedAmount}c");
    orderErrorResponse('Payment amount does not match order total. Please refresh and try again.', 400);
}

// Use verified status
$stripePaymentStatus = $verifiedPaymentStatus;

// ============================================================
// SAVE TO DATABASE
// ============================================================

try {
    // Check if function exists
    if (!function_exists('getOrderDatabase')) {
        error_log('[BEDDA] Fatal: getOrderDatabase() not defined in secure-config.php');
        orderErrorResponse('Server configuration error. Please contact support.', 500, false);
    }
    
    $pdo = getOrderDatabase();

    require_once __DIR__ . '/lib/orders-schema.php';
    ensureOrdersSchema($pdo);

    if (!$pdo) {
        error_log('[BEDDA] Fatal: getOrderDatabase() returned null');
        orderErrorResponse('Database connection failed. Please try again later.', 500, false);
    }
    
    // Verify table has required columns
    $checkStmt = $pdo->prepare("SHOW COLUMNS FROM orders LIKE 'grand_total'");
    $checkStmt->execute();
    if ($checkStmt->rowCount() === 0) {
        error_log('[BEDDA] Warning: orders table missing grand_total column');
        // Continue anyway — MySQL will use default value
    }
    
    // Idempotent insert — if payment_intent OR order_number already exists, update the payment status & re-sync.
    // Requires UNIQUE keys on order_number AND stripe_payment_intent_id (see deployment SQL).
    $couponCodeForDb = null;
    if (!empty($data['coupon']['code'])) {
        $couponCodeForDb = strtoupper(trim((string) $data['coupon']['code']));
        if ($couponCodeForDb === '') {
            $couponCodeForDb = null;
        }
    }
    $discountAmountForDb = (float) ($orderData['discount_amount'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO orders
            (order_number, stripe_payment_intent_id, stripe_payment_status, stripe_subscription_id, chitchats_shipment_id,
            customer_name, customer_email, phone_number, items, subtotal,
            shipping_address, order_date, ip_address, spam_score,
            fulfillment_method, pickup_location, pickup_date,
            shipping_cost, shipping_carrier, chitchats_postage_type, grand_total, is_subscription, subscription_interval,
            coupon_code, discount_amount, status)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued')
        ON DUPLICATE KEY UPDATE
            stripe_payment_intent_id = COALESCE(VALUES(stripe_payment_intent_id), stripe_payment_intent_id),
            stripe_payment_status    = VALUES(stripe_payment_status),
            stripe_subscription_id   = COALESCE(VALUES(stripe_subscription_id), stripe_subscription_id),
            chitchats_shipment_id    = COALESCE(VALUES(chitchats_shipment_id), chitchats_shipment_id),
            shipping_cost            = VALUES(shipping_cost),
            shipping_carrier         = VALUES(shipping_carrier),
            grand_total              = VALUES(grand_total),
            coupon_code              = COALESCE(VALUES(coupon_code), coupon_code),
            discount_amount          = VALUES(discount_amount),
            status                   = IF(status IN ('pending', ''), 'queued', status)
    ");

    $stmt->execute([
        $orderNumber, 
        $stripePaymentIntentId,      
        $stripePaymentStatus,
        $stripeSubscriptionId,
        sanitizeInput($data['chitchats_shipment_id'] ?? '', 'alnum') ?: null,
        $name, $email, $phone,
        json_encode($data['items'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE),
        $subtotal, $shippingAddress, $orderDate, getClientIP(),
        $rateCheck['score'] ?? 0,
        $fulfillmentMethod, $pickupLocation, $pickupDate,
        $orderData['shipping_cost'],
        $orderData['shipping_carrier'],
        $data['shipping_option']['id'] ?? null,
        $orderData['grand_total'],
        $orderData['is_subscription'],
        $orderData['subscription_interval'],
        $couponCodeForDb,
        $discountAmountForDb,
    ]);
    
    $isUpdate = $stmt->rowCount() > 1; // 1 = insert, 2 = update

    if ($couponCodeForDb && !$isUpdate) {
        try {
            couponIncrementUsage(getCouponDatabase(), $couponCodeForDb);
        } catch (Throwable $e) {
            error_log('[OnlyBikes] Coupon usage increment error (non-fatal): ' . $e->getMessage());
        }
    }
    if ($couponCodeForDb) {
        try {
            couponSyncUsedCountFromOrders($pdo, $couponCodeForDb);
        } catch (Throwable $e) {
            error_log('[OnlyBikes] Coupon usage sync error (non-fatal): ' . $e->getMessage());
        }
    }
    
} catch (PDOException $e) {
    error_log('[BEDDA] PDO Exception: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    orderErrorResponse('We could not save your order due to a database issue. Please email '
        . onlybikes_support_email() . ' with your details and we\'ll process it manually right away.', 500, false);
} catch (Throwable $e) {
    error_log('[BEDDA] General Exception: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    orderErrorResponse('We could not save your order due to a server issue. Please email '
        . onlybikes_support_email() . ' with your details and we\'ll process it manually right away.', 500, false);
}

// (fulfillment handling done above; no second pass needed)

// ============================================================
// POINTS & REWARDS (ledger — only after successful payment)
// ============================================================
$pointsEarned = max(0, (int) floor($subtotal));
$totalPoints = 0;
$customerId = $sessionCustomerId;
$accountCreated = false;
$rewardCoupon = null;
$couponExpires = null;
$clientIp = getClientIP();

try {
    if (!function_exists('getCustomersDatabase')) {
        throw new RuntimeException('getCustomersDatabase() missing from secure-config.php');
    }
    $customersDB = getCustomersDatabase();
    onlybikes_ensure_customers_schema($customersDB);
    onlybikes_ensure_points_ledger_schema($customersDB);
    $pointsCol = onlybikes_customers_points_column($customersDB);

    if ($customerId && $pointsUsed > 0) {
        onlybikes_points_checkout_redeem(
            $customersDB,
            $customerId,
            $pointsUsed,
            $pointsDiscountCad,
            $orderNumber,
            $clientIp
        );
    }

    if ($customerId) {
        $totalPoints = onlybikes_points_checkout_earn(
            $customersDB,
            $customerId,
            $pointsEarned,
            $orderNumber,
            (float) $subtotal,
            $clientIp
        );
        if (is_readable(__DIR__ . '/lib/runtime-credits.php')) {
            require_once __DIR__ . '/lib/runtime-credits.php';
            runtime_credits_order_bonus($customerId, (float) $subtotal, $orderNumber);
        }
    }

    if (!$customerId && !empty($data['create_account']) && !empty($data['account_password']) && strlen($data['account_password']) >= 8) {
        $custStmt = $customersDB->prepare('SELECT id FROM customers WHERE email = ?');
        $custStmt->execute([$email]);
        if (!$custStmt->fetch()) {
            $nameParts = explode(' ', trim($name), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
            $hash = password_hash($data['account_password'], PASSWORD_BCRYPT);

            $insStmt = $customersDB->prepare(
                "INSERT INTO customers (email, password_hash, first_name, last_name, {$pointsCol}) VALUES (?, ?, ?, ?, ?)"
            );
            $insStmt->execute([$email, $hash, $firstName, $lastName, 0]);
            $accountCreated = true;
            $customerId = (int) $customersDB->lastInsertId();
            $totalPoints = onlybikes_points_checkout_earn(
                $customersDB,
                $customerId,
                $pointsEarned,
                $orderNumber,
                (float) $subtotal,
                $clientIp
            );
            if (is_readable(__DIR__ . '/lib/runtime-credits.php')) {
                require_once __DIR__ . '/lib/runtime-credits.php';
                runtime_credits_order_bonus($customerId, (float) $subtotal, $orderNumber);
            }
        }
    }

    onlybikes_orders_update_points_fields($pdo, $orderNumber, [
        'customer_id' => $customerId ?: null,
        'points_used' => $pointsUsed,
        'points_earned' => $pointsEarned,
        'points_discount_cad' => $pointsDiscountCad,
    ]);

} catch (Throwable $e) {
    error_log('[OnlyBikes] Points ledger error (order saved, points may need manual review): ' . $e->getMessage());
    if ($customerId) {
        $totalPoints = onlybikes_customers_fetch_points($customersDB ?? getCustomersDatabase(), $customerId) ?? 0;
    }
}

// ============================================================
// LOG & RECORD RATE LIMIT
// ============================================================

logOrder($orderData, false);
$rateLimiter->recordOrder($email, $phone, $orderData);

// ============================================================
// SEND EMAILS (non-fatal)
// ============================================================
$adminEmailSent    = false;
$customerEmailSent = false;

if (empty($isUpdate)) {
    try {
        error_log('[BEDDA] Attempting admin email...');
        $adminEmailSent = sendOrderNotification($orderData);

        error_log('[BEDDA] Attempting customer email...');
        $customerEmailSent = sendCustomerConfirmation($orderData);
    } catch (Throwable $e) {
        // We log the error but DON'T stop the script
        error_log('[BEDDA] Email Error: ' . $e->getMessage());
    }
} else {
    error_log('[BEDDA] Order is an update; skipping duplicate emails.');
}

$newsletterResult = null;
if ($newsletter && !empty($email)) {
    require_once __DIR__ . '/lib/newsletter-subscribe-lib.php';
    $newsletterResult = onlybikes_newsletter_subscribe($email, $name);
    if (!$newsletterResult['success']) {
        error_log('[OnlyBikes] Checkout newsletter signup failed: ' . ($newsletterResult['message'] ?? 'unknown'));
    }
}

// ============================================================
// THE CRITICAL FIX: Ensure clean JSON output
// ============================================================

// 1. Clear any hidden warnings or logs that IONOS might have added 
// to the output buffer so they don't break the JSON format.
if (ob_get_length()) ob_clean(); 

// 2. Send the success signal back to your browser
echo json_encode([
    'success' => true,
    'order_number' => $orderNumber,
    'customer_email_status' => $customerEmailSent,
    'admin_email_status' => $adminEmailSent,
    'newsletter' => $newsletterResult,
    'account_created' => $accountCreated,
    'points_earned' => $pointsEarned,
    'total_points' => $totalPoints,
    'reward_coupon' => $rewardCoupon,
    'coupon_expires' => $couponExpires,
    'coupon_discount' => $discountPercent ?? 0
]);

exit; // Stop everything here to prevent any extra text from leaking out

} catch (Throwable $e) {

    while (ob_get_level() > 0) ob_end_clean();

    error_log('[BEDDA] UNCAUGHT EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('[BEDDA] Stack trace: ' . $e->getTraceAsString());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'success' => false,
        'message' => 'Something went wrong on our end. Please email '
            . onlybikes_support_email()
            . ' with your order details and we\'ll process it right away.',
        'support_email' => onlybikes_support_email(),
    ]);
    exit();
}

function sendSpamNotification($data, $rateCheck) {
    $reasons = isset($rateCheck['reasons']) && is_array($rateCheck['reasons'])
        ? implode(', ', $rateCheck['reasons'])
        : 'none';

    $body  = "<h2>Spam order blocked</h2>";
    $body .= "<p><b>Time:</b> " . date('Y-m-d H:i:s') . "<br>";
    $body .= "<b>IP:</b> "      . (function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . "<br>";
    $body .= "<b>Score:</b> "   . ($rateCheck['score'] ?? 0) . "<br>";
    $body .= "<b>Reasons:</b> " . htmlspecialchars($reasons) . "</p>";
    $body .= "<pre>" . htmlspecialchars(print_r($data, true)) . "</pre>";

    if (function_exists('sendSmtpEmail')) {
        return sendSmtpEmail(ADMIN_EMAIL, 'OnlyBikes Admin', SPAM_NOTIFICATION_SUBJECT, $body);
    }
    // last-ditch fallback (rarely deliverable from shared host)
    $headers  = "From: " . SMTP_FROM_EMAIL . "\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    return @mail(ADMIN_EMAIL, SPAM_NOTIFICATION_SUBJECT, $body, $headers);
}

// ============================================================
// UPDATED: createBusinessEmail() - Shipping included in grand total
// ============================================================

function createBusinessEmail($order) {
    $grandTotal      = floatval($order['grand_total']);        // already correct from main script
    $discountAmount  = floatval($order['discount_amount'] ?? 0);
    $shippingCost    = floatval($order['pure_shipping'] ?? $order['shipping_cost'] ?? 0);
    $handlingCost    = floatval($order['handling_cost'] ?? 0);

    $discountRow = '';
    if ($discountAmount > 0) {
        $discountLabel = '';
        if (!empty($order['points_used'])) {
            $discountLabel = 'Reward Points';
        } elseif (!empty($order['coupon']['code'])) {
            $discountLabel = htmlspecialchars($order['coupon']['code']);
        }
        $discountRow = "<tr>
            <td colspan='3' style='padding:8px 8px 8px 24px;font-weight:600;color:#7E6D58'>
                🏷️ Discount (" . $discountLabel . "):
            </td>
            <td style='padding:8px;text-align:right;font-weight:600;color:#7E6D58'>
                -$" . number_format($discountAmount, 2) . "
            </td>
        </tr>";
    }

    // Build items table
    $rows = '';
    foreach ($order['items'] as $item) {
        $p = htmlspecialchars($item['product'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
        $q = intval($item['quantity'] ?? 1);
        $pr = floatval($item['price'] ?? 0);
        $itemTotal = $pr * $q;
        $rows .= "<tr><td style='padding:8px;border-bottom:1px solid #e5e7eb'>{$p}</td>
                  <td style='padding:8px;border-bottom:1px solid #e5e7eb;text-align:right'>$" . number_format($pr, 2) . "</td>
                  <td style='padding:8px;border-bottom:1px solid #e5e7eb;text-align:center'>{$q}</td>
                  <td style='padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:600'>$" . number_format($itemTotal, 2) . "</td></tr>";
    }
    
    // Shipping breakdown - always include if shipping cost > 0
    $shippingRow = '';
    if ($shippingCost > 0) {
        $carrier = htmlspecialchars($order['shipping_carrier'] ?? 'Standard Shipping');
        $shippingRow = "<tr><td colspan='3' style='padding:8px 8px 8px 24px;font-weight:600;color:#1e40af'>📦 Shipping ({$carrier}):</td>
                        <td style='padding:8px;text-align:right;font-weight:600;color:#1e40af'>$" . number_format($shippingCost, 2) . "</td></tr>";
    }
    
    // Coupon section (same logic as customer email)
    $couponHtml = '';
    if (!empty($order['coupon']) && !empty($order['discount_amount']) && $order['discount_amount'] > 0) {
        $originalSubtotal = floatval($order['original_subtotal'] ?? $order['subtotal'] ?? 0);
        $discount = floatval($order['discount_amount'] ?? 0);
        $finalSubtotal = floatval($order['final_subtotal'] ?? ($originalSubtotal - $discount));
        $percent = $originalSubtotal > 0 ? round(($discount / $originalSubtotal) * 100) : 0;
        $couponHtml = "<div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;margin:16px 0'>
            <p style='margin:0 0 8px 0;color:#166534;font-weight:600'>🎫 Coupon Applied</p>
            <p style='margin:0;font-size:14px;color:#15803d'>
                Code: <strong>" . htmlspecialchars($order['coupon']['code']) . "</strong><br>
                Saved: <strong>{$percent}% off</strong> ($" . number_format($discount, 2) . ")<br>
                New Subtotal: <strong>$" . number_format($finalSubtotal, 2) . "</strong>
            </p>
        </div>";
    }
    
    // Fulfillment note
    $fulfillmentHtml = '';
    $method = $order['fulfillment_method'] ?? 'shipping';
    if ($method === 'pickup') {
        $loc = htmlspecialchars($order['pickup_location'] ?? 'Unknown');
        $date = htmlspecialchars($order['pickup_date'] ?? 'TBD');
        $fulfillmentHtml = "<div style='background:#eff6ff;border-left:4px solid #5A4D3F;padding:12px;margin:16px 0'>
            <p style='margin:0;color:#1e40af;font-weight:600'>📦 Pickup Order</p>
            <p style='margin:4px 0 0 0;color:#1e40af'>Location: {$loc}<br>Date: {$date}</p>
        </div>";
    }
    
    $name = htmlspecialchars($order['customer']['name'], ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($order['customer']['email'], ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($order['customer']['phone'] ?: 'Not provided', ENT_QUOTES, 'UTF-8');
    $address = nl2br(htmlspecialchars($order['shipping']['fullAddress'], ENT_QUOTES, 'UTF-8'));
    
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><title>Order #{$order['orderNumber']}</title></head>
    <body style='font-family:Inter,system-ui,-apple-system,sans-serif;line-height:1.6;color:#2C2C2C;margin:0;padding:20px;background:#F5F3EF'>
        <div style='max-width:600px;margin:0 auto;background:white;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.1);overflow:hidden'>
            
            <!-- Header -->
            <div style='background:linear-gradient(135deg,#B5A183,#9C8C73);padding:24px;text-align:center'>
                <h1 style='margin:0;color:white;font-size:24px;font-weight:700'>🎉 New Order Received</h1>
                <p style='margin:8px 0 0 0;color:#F0ECE6;font-size:16px'>Order #{$order['orderNumber']}</p>
            </div>
            
            <div style='padding:24px'>
                {$couponHtml}
                {$fulfillmentHtml}
                
                <!-- Customer Info -->
                <h2 style='margin:0 0 16px 0;color:#2C2C2C;font-size:18px;border-bottom:2px solid #f3f4f6;padding-bottom:8px'>👤 Customer</h2>
                <p style='margin:0 0 4px 0'><strong>Name:</strong> {$name}</p>
                <p style='margin:0 0 4px 0'><strong>Email:</strong> <a href='mailto:{$email}' style='color:#5A4D3F;text-decoration:none'>{$email}</a></p>
                <p style='margin:0 0 16px 0'><strong>Phone:</strong> {$phone}</p>
                
                <!-- Shipping/Pickup -->
                <h2 style='margin:0 0 16px 0;color:#2C2C2C;font-size:18px;border-bottom:2px solid #f3f4f6;padding-bottom:8px'>
                    " . ($method === 'pickup' ? '📍 Pickup Details' : '🚚 Shipping Address') . "
                </h2>
                <p style='margin:0 0 16px 0;white-space:pre-wrap'>{$address}</p>
                
                <!-- Order Items -->
                <h2 style='margin:0 0 16px 0;color:#2C2C2C;font-size:18px;border-bottom:2px solid #f3f4f6;padding-bottom:8px'>🛍️ Order Items</h2>
                <table style='width:100%;border-collapse:collapse;margin-bottom:16px'>
                    <thead>
                        <tr style='background:#F5F3EF'>
                            <th style='padding:12px 8px;text-align:left;font-weight:600;color:#374151'>Product</th>
                            <th style='padding:12px 8px;text-align:right;font-weight:600;color:#374151'>Price</th>
                            <th style='padding:12px 8px;text-align:center;font-weight:600;color:#374151'>Qty</th>
                            <th style='padding:12px 8px;text-align:right;font-weight:600;color:#374151'>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}{$shippingRow}
                        <tr style='background:#F5F3EF;font-weight:700'>
                            <td colspan='3' style='padding:12px 8px;text-align:right;color:#2C2C2C'>Grand Total (incl. shipping):</td>
                            <td style='padding:12px 8px;text-align:right;color:#9C8C73;font-size:18px'>$" . number_format($grandTotal, 2) . "</td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- Next Steps -->
                <div style='background:#F5F3EF;border:1px solid #D9CDBF;border-radius:8px;padding:16px;margin-top:24px'>
                    <h3 style='margin:0 0 8px 0;color:#5A4D3F;font-weight:600'>✅ Next Steps</h3>
                    <ol style='margin:0;padding-left:20px;color:#5A4D3F'>
                        <li>Review order details above</li>
                        <li>Payment already received via Stripe — verify in dashboard</li>
                        <li>Create Chit Chats shipment & send tracking to customer</li>
                        <li>Mark order as 'Paid & Shipped' in admin panel</li>
                    </ol>
                </div>
                
                <!-- Footer -->
                <div style='margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;text-align:center;color:#5A4D3F;font-size:14px'>
                    <p style='margin:0'>OnlyBikes • support@onlybikes.example</p>
                    <p style='margin:4px 0 0 0'>Order placed: {$order['orderDate']}</p>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";
}