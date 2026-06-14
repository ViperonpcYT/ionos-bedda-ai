<?php
/**
 * shipping.php - OnlyBikes Admin Order Fulfillment (ChitChats Compatible)
 *
 * FIXES APPLIED:
 *  1. createChitChatsShipment() now saves shipment_id immediately — prevents
 *     duplicate creation on every "Create Label" click.
 *  2. buyChitChatsLabel() polls after /buy until status leaves "postage_requested"
 *     (per ChitChats API docs requirement).
 *  3. Stores postage_label_pdf_url in orders table after successful buy.
 *  4. downloadChitChatsLabel() now fetches the stored PDF URL directly — the
 *     /shipments/tracking/{id}/label endpoint does NOT exist in the ChitChats API.
 *  5. Removed fake tracking fallback (CC + date + orderId). If buy fails,
 *     the admin is shown a clear error; the shipment stays in ChitChats
 *     dashboard for manual processing.
 *  6. ORDER BY now uses order_date (matches INSERT column). If your table has
 *     a created_at column, revert this one line.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../api/secure-config.php';
session_name(EMAIL_ADMIN_SESSION);
session_start();
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$search = $_GET['q'] ?? '';
$filter = $_GET['filter'] ?? 'queued';
$message = '';

// ------------------------------------------------------------------
// LOGGING HELPERS
// ------------------------------------------------------------------
function shipLog(string $msg, array $ctx = []): void {
    $ctxStr = empty($ctx) ? '' : ' | CTX: ' . json_encode($ctx);
    error_log('[BEDDA-SHIPPING] ' . $msg . $ctxStr);
}

// ------------------------------------------------------------------
// DATABASE
// ------------------------------------------------------------------
try {
    // FIX: use the correct function name from secure-config.php.
    // If your functions.php defines getOrdersDatabase(), rename this call back.
    $pdo = getOrderDatabase();
    require_once API_PATH . '/lib/orders-schema.php';
    require_once API_PATH . '/lib/product-shipping-specs.php';
    require_once API_PATH . '/lib/points-ledger.php';
    ensureOrdersSchema($pdo);
    shipLog('DB connected');
} catch (Throwable $e) {
    shipLog('DB CONNECTION FAILED: ' . $e->getMessage());
    die('<p style="padding:2rem;color:red">Database connection failed. Check error log.</p>');
}

// ------------------------------------------------------------------
// ADDRESS PARSER
// ------------------------------------------------------------------
function parseShippingAddress(string $fullAddress): array {
    $result = ['street' => '', 'address2' => '', 'city' => '', 'province' => '', 'postal_code' => ''];
    if (empty($fullAddress)) return $result;
    $lines = array_values(array_filter(array_map('trim', explode("\n", $fullAddress))));
    if (empty($lines)) return $result;
    if (stripos($lines[0], 'PICKUP') !== false) return $result;

    $result['street'] = $lines[0] ?? '';
    for ($i = 1; $i < count($lines); $i++) {
        $line = $lines[$i];
        if (preg_match('/^(.+?),\s*([A-Z]{2})\s+([A-Z]\d[A-Z]\s?\d[A-Z]\d)$/i', $line, $m)) {
            $result['city']        = trim($m[1]);
            $result['province']    = strtoupper($m[2]);
            $result['postal_code'] = strtoupper(str_replace(' ', '', $m[3]));
            break;
        } elseif (empty($result['address2'])) {
            $result['address2'] = $line;
        }
    }
    return $result;
}

// ------------------------------------------------------------------
// PACKAGE DETAILS
// ------------------------------------------------------------------
function getOrderPackageDetails(string $itemsJson): array {
    $items = json_decode($itemsJson ?: '[]', true) ?: [];
    $package = onlybikes_calculate_cart_package($items);

    return [
        'weight' => $package['weight'],
        'package_type' => $package['package_type'],
        'size_x' => $package['size_x'],
        'size_y' => $package['size_y'],
        'size_z' => $package['size_z'],
    ];
}

function calculateHandlingCost(array $items): array {
    $package = onlybikes_calculate_cart_package($items);
    $isMailer = $package['package_type'] === 'thick_envelope';
    return [
        'type' => $isMailer ? 'Small Parcel' : 'Standard Parcel',
        'cost' => $isMailer ? 0.90 : 2.15,
    ];
}

// ------------------------------------------------------------------
// CHITCHATS API CALLER
// ------------------------------------------------------------------
function callChitChatsAPI(string $url, array $data, string $method = 'POST', int $maxRetries = 2): array {
    shipLog('API REQUEST', ['method' => $method, 'url' => $url, 'payload' => $data]);
    $retries = 0;
    while ($retries <= $maxRetries) {
        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . CHITCHATS_ACCESS_TOKEN,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($method !== 'GET') {
            $curlOpts[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        curl_setopt_array($ch, $curlOpts);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        shipLog('API RESPONSE', [
            'url'              => $url,
            'http_code'        => $httpCode,
            'curl_error'       => $curlError,
            'response_preview' => substr($response ?: '', 0, 800),
            'attempt'          => $retries + 1,
        ]);

        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded ?: ['error' => true, 'message' => 'Empty JSON response'];
            }
            return ['error' => true, 'message' => 'Invalid JSON: ' . json_last_error_msg(), 'raw' => substr($response, 0, 500)];
        }

        if ($httpCode >= 500 && $retries < $maxRetries) {
            $retries++;
            $sleep = pow(2, $retries);
            shipLog('API SERVER ERROR, RETRYING', ['sleep' => $sleep, 'attempt' => $retries]);
            sleep($sleep);
            continue;
        }

        // Parse the actual ChitChats error message from the response body
        $chitchatsMsg = null;
        if ($response) {
            $errBody = json_decode($response, true);
            $chitchatsMsg = $errBody['error']['message'] ?? $errBody['message'] ?? null;
        }

        return [
            'error'        => true,
            'http_code'    => $httpCode,
            'message'      => $chitchatsMsg ?: ($curlError ?: "API request failed (HTTP $httpCode)"),
            'response'     => substr($response ?: '', 0, 2000),
            'url'          => $url,
            'method'       => $method,
            'sent_payload' => $data,
        ];
    }
    return ['error' => true, 'message' => 'Max retries exceeded'];
}

// ------------------------------------------------------------------
// CREATE SHIPMENT
// FIX: saves chitchats_shipment_id to DB immediately after creation.
// This is the idempotency gate — every subsequent call reuses the ID.
// ------------------------------------------------------------------
function createChitChatsShipment(array $order): array {
    global $pdo;
    $orderId = (int)$order['id'];

    // Already have a shipment? Reuse it — never create twice.
    if (!empty($order['chitchats_shipment_id'])) {
        shipLog('REUSING EXISTING SHIPMENT', ['order_id' => $orderId, 'shipment_id' => $order['chitchats_shipment_id']]);
        return ['error' => null, 'shipment_id' => $order['chitchats_shipment_id']];
    }

    $parsed     = parseShippingAddress($order['shipping_address'] ?? '');
    $street     = !empty($order['shipping_street'])   ? $order['shipping_street']   : $parsed['street'];
    $address2   = !empty($order['shipping_address2']) ? $order['shipping_address2'] : $parsed['address2'];
    $city       = !empty($order['shipping_city'])     ? $order['shipping_city']     : $parsed['city'];
    $province   = !empty($order['province'])          ? $order['province']          : $parsed['province'];
    $postalCode = !empty($order['postal_code'])       ? $order['postal_code']       : $parsed['postal_code'];

    if (empty($street) || empty($city) || empty($province) || empty($postalCode)) {
        shipLog('MISSING ADDRESS', compact('orderId', 'street', 'city', 'province', 'postalCode'));
        return ['error' => 'Missing shipping address — cannot create label'];
    }

    $pkg = getOrderPackageDetails($order['items'] ?? '[]');

    $payload = [
        'name'             => $order['customer_name'] ?: 'Customer',
        'address_1'        => $street,
        'address_2'        => $address2 ?: null,
        'city'             => $city,
        'province_code'    => $province,
        'postal_code'      => $postalCode,
        'country_code'     => 'CA',
        'phone'            => $order['phone_number'] ?? null,
        'package_type'     => $pkg['package_type'],
        'weight'           => $pkg['weight'],
        'weight_unit'      => 'g',
        'size_x'           => $pkg['size_x'],
        'size_y'           => $pkg['size_y'],
        'size_z'           => $pkg['size_z'],
        'size_unit'        => 'in',
        // FIX: use 'unknown' so ChitChats returns rates — we pick one at buy time.
        'postage_type'     => 'unknown',
        'value'            => number_format(floatval($order['grand_total'] ?? 0), 2),
        'value_currency'   => 'cad',
        'package_contents' => 'merchandise',
        'description'      => 'OnlyBikes E-Moto Parts',
        // Pass your internal order number so it shows up in ChitChats dashboard
        'order_id'         => $order['order_number'] ?? null,
        'ship_date'        => 'today',
    ];

    $createUrl = CHITCHATS_API_BASE . '/shipments';
    $result    = callChitChatsAPI($createUrl, $payload, 'POST');

    if (!empty($result['shipment']['id'])) {
        $shipmentId = $result['shipment']['id'];
        shipLog('SHIPMENT CREATED', ['order_id' => $orderId, 'shipment_id' => $shipmentId]);

        // Save immediately — this is the key idempotency step
        $stmt = $pdo->prepare("UPDATE orders SET chitchats_shipment_id = ? WHERE id = ?");
        $stmt->execute([$shipmentId, $orderId]);
        shipLog('DB UPDATED chitchats_shipment_id', ['order_id' => $orderId, 'shipment_id' => $shipmentId, 'rows' => $stmt->rowCount()]);

        return ['error' => null, 'shipment_id' => $shipmentId];
    }

    shipLog('SHIPMENT CREATE FAILED', ['order_id' => $orderId, 'result' => $result]);
    return [
        'error'   => 'ChitChats create failed: ' . ($result['message'] ?? 'Unknown') . ' (HTTP ' . ($result['http_code'] ?? '---') . ')',
        'details' => $result,
    ];
}

// ------------------------------------------------------------------
// BUY LABEL
// FIX 1: polls after /buy until status leaves 'postage_requested'
//         (required per ChitChats API docs).
// FIX 2: stores postage_label_pdf_url in the orders table.
// FIX 3: no fake fallback tracking — returns an error instead.
// FIX 4: Auto-recovers from invalid/stale shipment IDs
// ------------------------------------------------------------------
function buyChitChatsLabel(int $orderId): array {
    global $pdo;
    shipLog('BUY LABEL START', ['order_id' => $orderId]);

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        shipLog('ORDER NOT FOUND', ['order_id' => $orderId]);
        return ['error' => 'Order not found'];
    }

    // ── Step 1: Verify or clear stale shipment ID ───────────────────
    $shipmentId = $order['chitchats_shipment_id'] ?? null;
    $needsNewShipment = false;
    
    if ($shipmentId) {
        // Verify this shipment actually exists in ChitChats
        $verifyUrl = CHITCHATS_API_BASE . '/shipments/' . $shipmentId;
        $verifyResult = callChitChatsAPI($verifyUrl, [], 'GET');
        
        if (!empty($verifyResult['error']) && ($verifyResult['http_code'] ?? 0) === 404) {
            shipLog('STALE SHIPMENT ID — clearing for fresh creation', [
                'order_id' => $orderId,
                'stale_id' => $shipmentId,
                'postal_code' => $order['postal_code'] ?? 'unknown'
            ]);
            // Clear the bad ID
            $pdo->prepare("UPDATE orders SET chitchats_shipment_id = NULL WHERE id = ?")
                ->execute([$orderId]);
            $needsNewShipment = true;
        } else {
            shipLog('Existing shipment verified', ['shipment_id' => $shipmentId]);
        }
    } else {
        $needsNewShipment = true;
    }
    
    // ── Step 2: Create NEW shipment if needed (with postal verification) ─
    if ($needsNewShipment) {
        $createResult = createChitChatsShipment($order);
        if (!empty($createResult['error'])) {
            return ['error' => $createResult['error']];
        }
        // Re-fetch order to get the new shipment_id
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $shipmentId = $order['chitchats_shipment_id'] ?? null;
        
        if (!$shipmentId) {
            return ['error' => 'Failed to save new shipment ID to database'];
        }
        shipLog('NEW SHIPMENT CREATED', [
            'order_id' => $orderId,
            'shipment_id' => $shipmentId,
            'postal_code' => $order['postal_code'] ?? 'unknown'
        ]);
    }
    
    // ── Step 3: Refresh rates ────────────────────────────────────────
    $refreshUrl = CHITCHATS_API_BASE . '/shipments/' . $shipmentId . '/refresh';
    $refreshResult = callChitChatsAPI($refreshUrl, ['ship_date' => 'today'], 'PATCH');

    if (!empty($refreshResult['error'])) {
        shipLog('REFRESH FAILED', ['order_id' => $orderId, 'error' => $refreshResult]);
        return [
            'error' => 'Rate refresh failed: ' . ($refreshResult['message'] ?? 'Unknown'),
            'shipment_id' => $shipmentId
        ];
    }

    $rates = $refreshResult['shipment']['rates'] ?? [];
    if (empty($rates)) {
        return ['error' => 'No postage rates returned. Check weight/dimensions.', 'shipment_id' => $shipmentId];
    }

    // ── Step 4: Select postage type (customer preference → cheapest) ─
    $preferredType = !empty($order['chitchats_postage_type']) 
        ? $order['chitchats_postage_type'] 
        : 'chit_chats_select';
    
    $selectedPostage = null;
    $cheapestPostage = null;
    $cheapestPrice = INF;
    
    foreach ($rates as $rate) {
        $type = $rate['postage_type'] ?? '';
        $price = floatval($rate['purchase_amount'] ?? $rate['payment_amount'] ?? $rate['postage_fee'] ?? 9999);
        
        if ($price < $cheapestPrice) {
            $cheapestPrice = $price;
            $cheapestPostage = $type;
        }
        if ($type === $preferredType) {
            $selectedPostage = $type;
        }
    }
    
    if (!$selectedPostage && $cheapestPostage) {
        $selectedPostage = $cheapestPostage;
    } elseif (!$selectedPostage) {
        $selectedPostage = $rates[0]['postage_type'] ?? null;
    }
    
    if (!$selectedPostage) {
        return ['error' => 'Could not determine postage type', 'shipment_id' => $shipmentId];
    }

    // ── Step 5: BUY the label ────────────────────────────────────────
    $buyUrl = CHITCHATS_API_BASE . '/shipments/' . $shipmentId . '/buy';
    $buyResult = callChitChatsAPI($buyUrl, ['postage_type' => $selectedPostage], 'PATCH');

    if (!empty($buyResult['error'])) {
        return [
            'error' => 'Buy failed: ' . ($buyResult['message'] ?? 'Unknown'),
            'shipment_id' => $shipmentId
        ];
    }

    // ── Step 6: Poll until label is ready ────────────────────────────
    $shipmentData = $buyResult['shipment'] ?? [];
    for ($i = 0; $i < 12 && ($shipmentData['status'] ?? '') === 'postage_requested'; $i++) {
        usleep(500000); // 0.5s
        $poll = callChitChatsAPI(CHITCHATS_API_BASE . '/shipments/' . $shipmentId, [], 'GET');
        if (!empty($poll['shipment'])) {
            $shipmentData = $poll['shipment'];
        }
    }

    if (($shipmentData['status'] ?? '') === 'postage_purchase_failed') {
        return ['error' => 'Postage purchase failed. Check ChitChats balance.', 'shipment_id' => $shipmentId];
    }

    // ── Step 7: Extract and save tracking ────────────────────────────
    $tracking = $shipmentData['carrier_tracking_code'] ?? $shipmentData['tracking_code'] ?? null;
    $labelUrl = $shipmentData['postage_label_pdf_url'] ?? null;
    
    if (!$tracking) {
        return ['error' => 'No tracking code returned', 'shipment_id' => $shipmentId];
    }
    
    // Save to DB
    $pdo->prepare("UPDATE orders SET tracking_number = ?, label_pdf_url = ? WHERE id = ?")
        ->execute([$tracking, $labelUrl, $orderId]);
    
    shipLog('LABEL SUCCESS', [
        'order_id' => $orderId,
        'tracking' => $tracking,
        'postal_code' => $order['postal_code'] ?? 'unknown'
    ]);
    
    return [
        'error' => null,
        'tracking_number' => $tracking,
        'label_pdf_url' => $labelUrl,
        'carrier' => $shipmentData['carrier'] ?? 'ChitChats',
        'service' => $selectedPostage,
        'shipment_id' => $shipmentId,
        'purchase_amount' => $shipmentData['purchase_amount'] ?? null,
    ];
}

// ------------------------------------------------------------------
// DOWNLOAD LABEL
// FIX: The ChitChats API has NO /shipments/tracking/{id}/label endpoint.
//      The label PDF URL is returned in the /buy response as postage_label_pdf_url.
//      We store it in the DB and proxy it here.
// ------------------------------------------------------------------
function downloadChitChatsLabel(string $trackingNumber): void {
    global $pdo;

    // Look up the stored PDF URL from the DB
    $stmt = $pdo->prepare("SELECT label_pdf_url, chitchats_shipment_id FROM orders WHERE tracking_number = ? LIMIT 1");
    $stmt->execute([$trackingNumber]);
    $row = $stmt->fetch();

    $pdfUrl = $row['label_pdf_url'] ?? null;

    if (!$pdfUrl) {
        // Fallback: try to fetch it fresh from the ChitChats shipment
        $shipmentId = $row['chitchats_shipment_id'] ?? null;
        if ($shipmentId) {
            shipLog('LABEL PDF URL MISSING — fetching fresh from ChitChats', ['shipment_id' => $shipmentId]);
            $getResult = callChitChatsAPI(CHITCHATS_API_BASE . '/shipments/' . $shipmentId, [], 'GET');
            $pdfUrl = $getResult['shipment']['postage_label_pdf_url'] ?? null;
            // Save it for next time
            if ($pdfUrl) {
                $pdo->prepare("UPDATE orders SET label_pdf_url = ? WHERE chitchats_shipment_id = ?")
                    ->execute([$pdfUrl, $shipmentId]);
            }
        }
    }

    if (!$pdfUrl) {
        shipLog('LABEL DOWNLOAD FAILED — no PDF URL found', ['tracking' => $trackingNumber]);
        die('Could not find label PDF. Open ChitChats dashboard and download from there directly.');
    }

    // Proxy the PDF (URL contains a short-lived auth token from ChitChats)
    $ch = curl_init($pdfUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $pdf      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    shipLog('LABEL DOWNLOAD', ['tracking' => $trackingNumber, 'http_code' => $httpCode, 'size' => strlen($pdf ?: '')]);

    if ($httpCode === 200 && $pdf) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="label-' . $trackingNumber . '.pdf"');
        echo $pdf;
        exit;
    }

    shipLog('LABEL DOWNLOAD HTTP FAILED', ['tracking' => $trackingNumber, 'http_code' => $httpCode]);
    die('Failed to download label PDF from ChitChats (HTTP ' . $httpCode . '). Try again in a moment or download from the ChitChats dashboard.');
}

// ------------------------------------------------------------------
// POST HANDLERS
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['order_id'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        shipLog('CSRF FAILURE', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        die('Invalid CSRF token');
    }

    $orderId = (int)$_POST['order_id'];
    $action  = $_POST['action'];
    shipLog('POST ACTION', ['action' => $action, 'order_id' => $orderId]);

    if ($action === 'verify') {
        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'ready_to_ship' WHERE id = ? AND " . ordersQueuedWhereSql()
        );
        $stmt->execute([$orderId]);
        shipLog('VERIFY EXECUTED', ['order_id' => $orderId, 'rows' => $stmt->rowCount()]);
        $message = '✅ Order verified — ready to ship';
    }
    elseif ($action === 'create_label') {
        // FIX: removed fake fallback tracking parameter — buyChitChatsLabel handles it cleanly
        $labelData = buyChitChatsLabel($orderId);

        if (empty($labelData['error'])) {
            $tracking = $labelData['tracking_number'];
            $stmt = $pdo->prepare("UPDATE orders SET status = 'label_created', tracking_number = ?, label_created_at = NOW() WHERE id = ? AND status = 'ready_to_ship'");
            $stmt->execute([$tracking, $orderId]);
            shipLog('LABEL CREATED SUCCESS', ['order_id' => $orderId, 'tracking' => $tracking, 'rows' => $stmt->rowCount()]);
            $message = "✅ Label created — Tracking: {$tracking} ({$labelData['service']}) — {$labelData['carrier']}";
            if ($labelData['purchase_amount']) {
                $message .= " — Cost: \${$labelData['purchase_amount']} CAD";
            }
        } else {
            // FIX: no fake tracking. Show the error clearly so admin can fix it.
            // The shipment_id (if created) is shown so admin can go to ChitChats dashboard.
            $shipmentNote = !empty($labelData['shipment_id'])
                ? " (ChitChats shipment {$labelData['shipment_id']} exists — buy manually if needed)"
                : '';
            shipLog('LABEL CREATED WITH ERROR', ['order_id' => $orderId, 'error' => $labelData['error']]);
            $message = "❌ Label error: {$labelData['error']}{$shipmentNote}";
        }
    }
    elseif ($action === 'mark_shipped') {
        $order = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'label_created'");
        $order->execute([$orderId]);
        $o = $order->fetch();
        
        if ($o) {
            // Update inventory
            $items = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $items->execute([$orderId]);
            $orderItems = $items->fetchAll();
            foreach ($orderItems as $item) {
                $pdo->prepare("
                    UPDATE products
                    SET stock_in_stock = GREATEST(0, stock_in_stock - ?),
                        stock_sold = stock_sold + ?,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$item['quantity'], $item['quantity'], $item['product_id']]);
            }
            
            // Mark as shipped
            $pdo->prepare("UPDATE orders SET status = 'shipped', shipped_at = NOW(), inventory_synced = 1 WHERE id = ?")
                ->execute([$orderId]);

            // Build tracking URL
            $trackingUrl = 'https://chitchats.com/tracking/' . strtolower($o['tracking_number'] ?? '');            
            // Build branded email HTML
            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Your OnlyBikes Order Has Shipped!</title>
                <style>
                    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #2C2C2C; margin: 0; padding: 0; background: #F5F3EF; }
                    .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                    .header { background: linear-gradient(135deg, #111827, #22c55e); padding: 24px; text-align: center; }
                    .header h1 { margin: 0; color: white; font-size: 24px; font-weight: 700; }
                    .header p { margin: 8px 0 0; color: #F5F3EF; font-size: 16px; }
                    .content { padding: 24px; }
                    .celebration { text-align: center; margin: 0 0 24px; }
                    .celebration .emoji { font-size: 48px; margin-bottom: 8px; }
                    .celebration h2 { margin: 0; color: #2C2C2C; font-size: 20px; font-weight: 600; }
                    .celebration p { margin: 4px 0 0; color: #5A4D3F; }
                    .tracking-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; margin: 24px 0; text-align: center; }
                    .tracking-box .label { font-size: 14px; color: #1e40af; font-weight: 600; margin-bottom: 8px; }
                    .tracking-box .code { font-family: monospace; font-size: 18px; color: #1e40af; font-weight: 700; letter-spacing: 1px; }
                    .tracking-box .btn { display: inline-block; margin-top: 12px; background: #5A4D3F; color: white; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; }
                    .tracking-box .btn:hover { background: #5A4D3F; }
                    .next-steps { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin: 24px 0; }
                    .next-steps h3 { margin: 0 0 12px; color: #166534; font-size: 16px; font-weight: 600; }
                    .next-steps ol { margin: 0; padding-left: 20px; color: #166534; }
                    .next-steps li { margin: 4px 0; }
                    .footer { text-align: center; padding: 16px 24px; border-top: 1px solid #e5e7eb; color: #5A4D3F; font-size: 14px; }
                    .footer a { color: #5A4D3F; text-decoration: none; }
                    .footer a:hover { text-decoration: underline; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>✨ Your Order Is On The Way!</h1>
                        <p>Order #{$o['order_number']}</p>
                    </div>
                    <div class='content'>
                        <div class='celebration'>
                            <div class='emoji'>🚚💨</div>
                            <h2>Great news, {$o['customer_name']}!</h2>
                            <p>Your OnlyBikes parts have shipped and are heading your way.</p>
                        </div>
                        <div class='tracking-box'>
                            <div class='label'>Track Your Package</div>
                            <div class='code'>" . htmlspecialchars($o['tracking_number'] ?? '') . "</div>
                            <a href='{$trackingUrl}' class='btn' target='_blank'>Track Now →</a>
                        </div>
                        <div class='next-steps'>
                            <h3>What to Expect</h3>
                            <ol>
                                <li><strong>Today:</strong> Package picked up by Chit Chats</li>
                                <li><strong>1-2 business days:</strong> In transit to your city</li>
                                <li><strong>2-8 business days:</strong> Delivered to your door</li>
                            </ol>
                        </div>
                        <div style='text-align:center; padding:16px; background:#F5F3EF; border-radius:8px; margin-top:24px'>
                            <p style='margin:0 0 8px; font-weight:600; color:#2C2C2C'>Questions?</p>
                            <p style='margin:0'>
                                <a href='mailto:support@onlybikes.example' style='color:#5A4D3F'>support@onlybikes.example</a> | 
                                <a href='https://onlybikes.example/contact.html' style='color:#5A4D3F'>Contact Page</a>
                            </p>
                        </div>
                    </div>
                    <div class='footer'>
                        <p style='margin:0'>OnlyBikes - E-moto parts from Canada</p>
                        <p style='margin:4px 0 0'>Thank you for riding with OnlyBikes.</p>
                        <p style='margin:8px 0 0; font-size:12px; color:#9ca3af'>
                            <a href='https://onlybikes.example/unsubscribe?email=" . urlencode($o['customer_email']) . "'>Unsubscribe</a>
                        </p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            // Send the email
            sendEmail($o['customer_email'], $o['customer_name'], "Your OnlyBikes Order Has Shipped", $html);
            
            shipLog('MARKED SHIPPED', ['order_id' => $orderId, 'tracking' => $o['tracking_number']]);
            $message = "✅ Order shipped — stock updated";
            
        } else {
            shipLog('MARK SHIPPED FAILED: order not in label_created', ['order_id' => $orderId]);
            $message = "❌ Order not found or not in label_created status";
        }
    }
    elseif ($action === 'delete_queued') {
        // UPDATE to cancelled instead of DELETE so reconcile-payments.php
        // doesn't recreate the order as a ghost payment on next run.
        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'cancelled' WHERE id = ? AND " . ordersQueuedWhereSql()
        );
        $stmt->execute([$orderId]);
        shipLog('CANCEL QUEUED', ['order_id' => $orderId, 'rows' => $stmt->rowCount()]);
        $message = '✅ Order cancelled';
    }
    elseif ($action === 'clear_unpaid_chitchats') {
        // ------------------------------------------------------------------
        // CLEAR UNPAID / INCOMPLETE CHITCHATS SHIPMENTS
        // SAFE statuses to delete: 'created', 'postage_purchase_failed'
        // NEVER touched: postage_purchased, received_by_chitchats, in_transit,
        //                delivered, voided — anything paid or real.
        //
        // Two-phase:
        //   Phase 1 — paginate ChitChats list API, delete junk ones.
        //   Phase 2 — scan our DB for known shipment IDs, check each one
        //             directly and delete if still unpaid (covers quote-time
        //             shipments created by get-shipping-quote.php that may
        //             not appear in the list, plus DB orphans).
        // ------------------------------------------------------------------
        $SAFE_TO_DELETE = ['created', 'postage_purchase_failed', 'incomplete'];
        $deleted    = 0;
        $skipped    = 0;
        $errors     = 0;
        $apiChecked = 0;
        $dbChecked  = 0;
        $apiDebug   = [];
        $deletedIds = []; // track IDs deleted in phase 1 to skip in phase 2

        // Helper closure: delete one ChitChats shipment and un-link from DB
        $doDelete = function(string $sid, string $source) use ($pdo, &$deleted, &$errors, &$deletedIds): void {
            $dr = callChitChatsAPI(CHITCHATS_API_BASE . '/shipments/' . $sid, [], 'DELETE');
            if (empty($dr['error'])) {
                $deleted++;
                $deletedIds[] = $sid;
                $pdo->prepare("UPDATE orders SET chitchats_shipment_id = NULL WHERE chitchats_shipment_id = ?")
                    ->execute([$sid]);
                shipLog("DELETED [{$source}]", ['shipment_id' => $sid]);
            } else {
                $errors++;
                shipLog("DELETE FAILED [{$source}]", [
                    'shipment_id' => $sid,
                    'error'       => $dr['message'] ?? 'unknown',
                    'http_code'   => $dr['http_code'] ?? '---',
                    'response'    => substr($dr['response'] ?? '', 0, 300),
                ]);
            }
            usleep(120000);
        };

        // ── Phase 1: ChitChats list API ───────────────────────────────────
        $page = 1;
        $pageSize = 100;
        shipLog('CLEAR UNPAID PHASE-1 START');

        do {
            $listUrl = CHITCHATS_API_BASE . '/shipments?count=' . $pageSize . '&page=' . $page;
            $raw     = callChitChatsAPI($listUrl, [], 'GET');

            // Handle multiple possible response structures from ChitChats
            $shipments = [];
            if (!empty($raw['shipments']) && is_array($raw['shipments'])) {
                $shipments = $raw['shipments'];           // expected: { shipments: [...] }
            } elseif (!empty($raw['data']) && is_array($raw['data'])) {
                $shipments = $raw['data'];                // alternate: { data: [...] }
            } elseif (isset($raw[0]) && is_array($raw[0])) {
                $shipments = $raw;                        // flat array response
            }

            // First-page debug: record what keys came back so we can diagnose issues
            if ($page === 1) {
                $topKeys = array_keys($raw ?: []);
                $apiDebug[] = 'API response keys: [' . implode(', ', $topKeys) . ']';
                $apiDebug[] = 'Shipments found page 1: ' . count($shipments);
                if (!empty($raw['error'])) {
                    $apiDebug[] = 'API error: ' . ($raw['message'] ?? 'unknown') . ' HTTP:' . ($raw['http_code'] ?? '?');
                }
                shipLog('CLEAR UNPAID API STRUCTURE', [
                    'keys'         => $topKeys,
                    'count'        => count($shipments),
                    'raw_preview'  => substr(json_encode($raw), 0, 800),
                ]);
            }

            if (empty($shipments)) break;

            foreach ($shipments as $shipment) {
                $sid    = (string)($shipment['id'] ?? '');
                $status = $shipment['status'] ?? '';
                if (!$sid) continue;
                $apiChecked++;

                if (!in_array($status, $SAFE_TO_DELETE, true)) {
                    $skipped++;
                    continue;
                }
                $doDelete($sid, 'api-list');
            }

            $page++;
            usleep(300000);

        } while (count($shipments) === $pageSize);

        shipLog('CLEAR UNPAID PHASE-1 DONE', compact('apiChecked', 'deleted', 'skipped', 'errors'));

        // ── Phase 2: DB scan ──────────────────────────────────────────────
        // Fetch every chitchats_shipment_id we have on record for unshipped
        // orders, check its live status, and delete if still unpaid.
        // This catches shipments created during quote requests (get-shipping-
        // quote.php) that are linked to a specific order row in our DB.
        $dbRows = $pdo->query(
            "SELECT id, chitchats_shipment_id FROM orders
             WHERE chitchats_shipment_id IS NOT NULL
               AND status NOT IN ('shipped', 'label_created')"
        )->fetchAll();

        shipLog('CLEAR UNPAID PHASE-2 START', ['db_rows' => count($dbRows)]);

        foreach ($dbRows as $row) {
            $sid = (string)$row['chitchats_shipment_id'];
            if (in_array($sid, $deletedIds, true)) continue; // already gone

            $dbChecked++;
            $gr     = callChitChatsAPI(CHITCHATS_API_BASE . '/shipments/' . $sid, [], 'GET');
            $status = $gr['shipment']['status'] ?? null;

            if ($status === null) {
                // 404 or bad response — shipment doesn't exist in ChitChats; clean up DB
                $pdo->prepare("UPDATE orders SET chitchats_shipment_id = NULL WHERE id = ?")
                    ->execute([$row['id']]);
                shipLog('DB ORPHAN CLEARED (no ChitChats record)', ['sid' => $sid, 'order_id' => $row['id']]);
                $deleted++; // counts as cleaned up
                $deletedIds[] = $sid;
                continue;
            }

            if (!in_array($status, $SAFE_TO_DELETE, true)) {
                // Real paid shipment — never touch
                $skipped++;
                continue;
            }

            $doDelete($sid, 'db-scan');
            usleep(120000);
        }

        shipLog('CLEAR UNPAID DONE', compact('deleted', 'skipped', 'errors', 'apiChecked', 'dbChecked'));

        $debugStr = !empty($apiDebug) ? ' — [' . implode('; ', $apiDebug) . ']' : '';

        if ($errors > 0) {
            $message = "⚠️ Phase 1 (API list): checked {$apiChecked}. "
                     . "Phase 2 (DB scan): checked {$dbChecked}. "
                     . "Deleted/cleaned {$deleted} total. Skipped {$skipped} real ones. "
                     . "{$errors} error(s) — see /tmp/bedda-shipping-errors.log.{$debugStr}";
        } else {
            $message = "✅ Phase 1 (API list): checked {$apiChecked}. "
                     . "Phase 2 (DB scan): checked {$dbChecked}. "
                     . "Deleted/cleaned {$deleted} junk shipment(s). Skipped {$skipped} real paid/in-transit ones.{$debugStr}";
        }
    }
}

// ------------------------------------------------------------------
// QUERY BUILDER
// FIX: ORDER BY uses order_date (the column in your INSERT).
//      If your table has a created_at column, change order_date back.
// ------------------------------------------------------------------
$where  = "WHERE 1=1";
$params = [];
if ($search) {
    $where .= " AND (customer_name LIKE ? OR customer_email LIKE ? OR tracking_number LIKE ?
                OR order_number LIKE ? OR stripe_payment_intent_id LIKE ?";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
    if (ctype_digit($search)) {
        $where .= " OR id = ?";
        $params[] = (int) $search;
    }
    $where .= ")";
}
if ($filter === 'queued') {
    $where .= ' AND ' . ordersQueuedWhereSql();
} elseif ($filter === 'ready') {
    $where .= " AND status = 'ready_to_ship'";
} elseif ($filter === 'labels') {
    $where .= " AND status = 'label_created'";
} elseif ($filter === 'shipped') {
    $where .= " AND status = 'shipped'";
} elseif ($filter === 'cancelled') {
    $where .= " AND status = 'cancelled'";
}

$orders = fetchOrdersForAdmin($pdo, $where, $params, 200);
try {
    $customersPdo = function_exists('getCustomersDatabase') ? getCustomersDatabase() : null;
    $orders = onlybikes_orders_enrich_points_trust($orders, $customersPdo);
} catch (Throwable $e) {
    error_log('[shipping] points enrich: ' . $e->getMessage());
}
shipLog('ORDERS LOADED', ['filter' => $filter, 'search' => $search, 'count' => count($orders)]);

// Label download
if (isset($_GET['download_label']) && $_GET['download_label']) {
    downloadChitChatsLabel($_GET['download_label']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shipping - OnlyBikes Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <style>.pill { padding: 2px 8px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }</style>
</head>
<body class="bg-stone-50 min-h-screen font-inter">
<?php renderNav('shipping'); ?>
<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <h1 class="text-2xl font-bold text-stone-800">Order Fulfillment</h1>
        <div class="flex flex-col sm:flex-row gap-2 items-start sm:items-center">
        <form method="GET" class="flex gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search email, name, ID, tracking…"
                   class="border border-stone-300 rounded px-3 py-2 text-sm w-64">
            <select name="filter" class="border border-stone-300 rounded px-3 py-2 text-sm" onchange="this.form.submit()">
                <option value="queued"     <?= $filter==='queued'    ?'selected':'' ?>>Queued</option>
                <option value="ready"      <?= $filter==='ready'     ?'selected':'' ?>>Ready to Ship</option>
                <option value="labels"     <?= $filter==='labels'    ?'selected':'' ?>>Labels Created</option>
                <option value="shipped"    <?= $filter==='shipped'   ?'selected':'' ?>>Shipped</option>
                <option value="cancelled"  <?= $filter==='cancelled' ?'selected':'' ?>>Cancelled</option>
            </select>
            <button type="submit" class="bg-sage-600 text-white px-4 py-2 rounded text-sm hover:bg-sage-700">Search</button>
        </form>
        <form method="POST" class="inline"
              onsubmit="return confirm('This will delete ALL unpaid / incomplete ChitChats shipments (status: created or postage_purchase_failed).\n\nPaid labels, in-transit packages, and delivered orders are NEVER touched.\n\nContinue?')">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="order_id"   value="0">
            <button name="action" value="clear_unpaid_chitchats"
                    class="bg-red-600 text-white px-4 py-2 rounded text-sm hover:bg-red-700 whitespace-nowrap">
                🗑️ Clear Junk ChitChats Shipments
            </button>
        </form>
        </div>
    </div>

    <?php if ($message): ?>
        <?php $isError = str_starts_with($message, '❌'); ?>
        <div class="mb-4 p-3 rounded text-sm <?= $isError ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow-sm border overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-stone-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Order #</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Trust</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Points</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Tracking</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Actions</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Stripe PI</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            <?php foreach ($orders as $o):
                $statusBadge = match($o['status']) {
                    'queued', 'pending' => '<span class="pill bg-yellow-100 text-yellow-800">Queued</span>',
                    'ready_to_ship' => '<span class="pill bg-blue-100 text-blue-800">Ready</span>',
                    'label_created' => '<span class="pill bg-purple-100 text-purple-800">Label Created</span>',
                    'shipped'       => '<span class="pill bg-green-100 text-green-800">Shipped</span>',
                    'cancelled'     => '<span class="pill bg-red-100 text-red-800">Cancelled</span>',
                    default         => '<span class="pill bg-stone-100 text-stone-800">'.htmlspecialchars($o['status']).'</span>'
                };
                $displayDate = ordersFormatDisplayDate($o);
                $trust = (int) ($o['trust_percent'] ?? onlybikes_trust_percent((int) ($o['spam_score'] ?? 0)));
                $trustClass = onlybikes_trust_badge_class($trust);
            ?>
                <tr class="hover:bg-stone-50">
                    <td class="px-4 py-3 font-mono text-stone-800">
                        <div class="text-xs text-stone-400">#<?= (int) $o['id'] ?></div>
                        <div class="text-xs break-all"><?= htmlspecialchars($o['order_number'] ?? '—') ?></div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-stone-800"><?= htmlspecialchars($o['customer_name']) ?></div>
                        <div class="text-xs text-stone-500"><?= htmlspecialchars($o['customer_email']) ?></div>
                    </td>
                    <td class="px-4 py-3"><?= $statusBadge ?></td>
                    <td class="px-4 py-3">
                        <span class="pill <?= $trustClass ?>"><?= $trust ?>% trust</span>
                        <div class="text-xs text-stone-400 mt-0.5">spam <?= (int) ($o['spam_score'] ?? 0) ?></div>
                    </td>
                    <td class="px-4 py-3 text-xs">
                        <?php if ((int) ($o['points_used'] ?? 0) > 0): ?>
                            <div class="text-red-700 font-medium">−<?= (int) $o['points_used'] ?> used</div>
                            <?php if (!empty($o['points_discount_cad'])): ?>
                                <div class="text-stone-500">$<?= number_format((float) $o['points_discount_cad'], 2) ?> off</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-stone-400">— redeemed</div>
                        <?php endif; ?>
                        <?php if ((int) ($o['points_earned'] ?? 0) > 0): ?>
                            <div class="text-green-700 font-medium">+<?= (int) $o['points_earned'] ?> earned</div>
                        <?php endif; ?>
                        <?php if ($o['customer_points_balance'] !== null): ?>
                            <div class="text-stone-500 mt-1">bal <?= (int) $o['customer_points_balance'] ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-stone-600 font-mono text-xs">
                        <?php if ($o['tracking_number']): ?>
                            <a href="https://chitchats.com/tracking/<?= htmlspecialchars(strtolower($o['tracking_number'])) ?>"
                               target="_blank" class="hover:underline text-blue-600">
                                <?= htmlspecialchars($o['tracking_number']) ?>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                        <?php if (!empty($o['chitchats_shipment_id'])): ?>
                            <div class="text-stone-400 text-xs mt-0.5">CC: <?= htmlspecialchars($o['chitchats_shipment_id']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-stone-500 text-xs"><?= $displayDate ?></td>
                    <td class="px-4 py-3 space-x-2">
                        <?php if (ordersIsQueuedStatus($o['status'] ?? '')): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="order_id"   value="<?= $o['id'] ?>">
                                <button name="action" value="verify" class="text-blue-600 hover:underline text-xs mr-2">✓ Verify</button>
                                <button name="action" value="delete_queued" onclick="return confirm('Delete this queued order?')" class="text-red-600 hover:underline text-xs">✕ Delete</button>
                            </form>
                        <?php elseif ($o['status'] === 'ready_to_ship'): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="order_id"   value="<?= $o['id'] ?>">
                                <button name="action" value="create_label" class="bg-purple-600 text-white px-3 py-1 rounded text-xs hover:bg-purple-700">🏷️ Create ChitChats Label</button>
                            </form>
                        <?php elseif ($o['status'] === 'label_created'): ?>
                            <div class="flex items-center gap-2 flex-wrap">
                                <button onclick="copyTracking('<?= addslashes($o['tracking_number']) ?>')" class="text-blue-600 hover:underline text-xs">📋 Copy</button>
                                <?php if (!empty($o['tracking_number'])): ?>
                                    <a href="?download_label=<?= urlencode($o['tracking_number']) ?>" class="text-indigo-600 hover:underline text-xs" target="_blank">⬇️ PDF</a>
                                <?php endif; ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="order_id"   value="<?= $o['id'] ?>">
                                    <button name="action" value="mark_shipped" class="bg-green-600 text-white px-3 py-1 rounded text-xs hover:bg-green-700">🚚 Mark Shipped</button>
                                </form>
                            </div>
                        <?php elseif ($o['status'] === 'shipped'): ?>
                            <div class="flex items-center gap-2">
                                <button onclick="copyTracking('<?= addslashes($o['tracking_number']) ?>')" class="text-blue-600 hover:underline text-xs">📋 Copy</button>
                                <?php if (!empty($o['tracking_number'])): ?>
                                    <a href="?download_label=<?= urlencode($o['tracking_number']) ?>" class="text-indigo-600 hover:underline text-xs" target="_blank">⬇️ PDF</a>
                                <?php endif; ?>
                                <span class="text-xs text-stone-400 ml-2">Completed</span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-stone-600 font-mono text-xs max-w-[220px]">
                        <?php if (!empty($o['stripe_payment_intent_id'])): ?>
                            <a href="https://dashboard.stripe.com/<?= defined('STRIPE_LIVE_MODE') && STRIPE_LIVE_MODE ? '' : 'test/' ?>payments/<?= htmlspecialchars($o['stripe_payment_intent_id']) ?>"
                               target="_blank" class="hover:underline text-blue-600 break-all block"
                               title="Open in Stripe Dashboard">
                                <?= htmlspecialchars($o['stripe_payment_intent_id']) ?>
                            </a>
                            <span class="text-stone-400"><?= htmlspecialchars($o['stripe_payment_status'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="text-stone-400">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (empty($orders)): ?>
                <tr><td colspan="10" class="px-4 py-8 text-center text-stone-400">No orders match your search/filter.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($filter === 'queued'): ?>
    <script>setTimeout(() => location.reload(), 60000);</script>
    <?php endif; ?>
</div>

<script>
function copyTracking(tracking) {
    navigator.clipboard.writeText(tracking).then(() => {
        alert('Tracking copied: ' + tracking);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = tracking;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        alert('Tracking copied: ' + tracking);
    });
}
</script>
</body>
</html>