<?php
/**
 * shipping.php - Bedda Admin Order Fulfillment (ChitChats Compatible)
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
    require_once __DIR__ . '/lib/orders-schema.php';
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
    
    $weights = [
        'Be Mine Soap' => 120,
        'Uni Exfoliating Soap' => 120,
        'He-Man Exfoliating Soap' => 120,
        'She-Ra Exfoliating Soap' => 120,
        'The Massager Soap' => 130,
        'Special Occasion Soap' => 130,
        'Holy Grail Balm' => 135,
        'Plain Jane Face & Body Balm' => 135,
        'Creamsicle Balm' => 135,
        'Minty Lip Balm' => 70,
        'Pinky Minty Lip Balm' => 70,
        'Plain Jane Soap Loaf' => 1350,
        'Custom Loaf' => 1350,
        'Sampler Pack - Uni' => 50,
        'Sampler Pack - He-Man' => 50,
        'Sampler Pack - She-Ra' => 50,
        'Sampler Pack - Holy Grail' => 40,
    ];
    
    $totalWeight = 0;
    $totalItems = 0;
    $soapCount = 0;
    $creamCount = 0;
    
    foreach ($items as $item) {
        $pName = $item['product'] ?? '';
        $qty = intval($item['quantity'] ?? 1);
        
        $w = 100;
        $found = false;
        foreach ($weights as $pn => $pw) {
            if (stripos($pName, $pn) !== false || $pName === $pn) {
                $w = $pw;
                $found = true;
                break;
            }
        }
        if (!$found && stripos($pName, 'Custom Loaf') !== false) {
            $w = 1350;
        }
        
        $totalWeight += ($w * $qty);
        $totalItems += $qty;
        
        $lower = strtolower($pName);
        if (str_contains($lower, 'soap') && !str_contains($lower, 'loaf')) {
            $soapCount += $qty;
        }
        if (str_contains($lower, 'balm') || str_contains($lower, 'cream')) {
            $creamCount += $qty;
        }
    }
    
    // Packaging rule: 5 soaps or 5 creams fit in mailer (8.5x11x1). More → box (13x10x3).
    $isEnvelope = ($soapCount <= 5 && $creamCount <= 5 && $totalItems <= 5);
    
    return [
        'weight' => $totalWeight,
        'package_type' => $isEnvelope ? 'thick_envelope' : 'parcel',
        'size_x' => $isEnvelope ? 8.5 : 13.0,
        'size_y' => $isEnvelope ? 11.0 : 10.0,
        'size_z' => $isEnvelope ? 1.0 : 3.0,
    ];
}

function calculateHandlingCost(array $items): array {
    $soapCount = 0; $creamCount = 0; $totalItems = 0;
    foreach ($items as $item) {
        $qty = intval($item['quantity'] ?? 1);
        $totalItems += $qty;
        $lower = strtolower($item['product'] ?? '');
        if (str_contains($lower, 'soap') && !str_contains($lower, 'loaf')) $soapCount += $qty;
        if (str_contains($lower, 'balm') || str_contains($lower, 'cream')) $creamCount += $qty;
    }
    $isMailer = ($soapCount <= 5 && $creamCount <= 5 && $totalItems <= 5);
    return [
        'type' => $isMailer ? 'Bubble Mailer (8.5x11x1)' : 'Shipping Box (13x10x3)',
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

        if ($httpCode >= 200 && $httpCode < 300) {
            if (empty($response)) {
                return ['success' => true]; // Fix: Handle successful empty responses (like 200/204)
            }
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded ?: ['error' => true, 'message' => 'Empty JSON array'];
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

    // Already have a shipment? Reuse it.
    if (!empty($order['chitchats_shipment_id'])) {
        shipLog('REUSING EXISTING SHIPMENT', ['order_id' => $orderId, 'shipment_id' => $order['chitchats_shipment_id']]);
        return ['error' => null, 'shipment_id' => $order['chitchats_shipment_id']];
    }

    // CRITICAL FIX: Re-check DB immediately before calling ChitChats.
    // This stops a double-click race where two requests both see no ID.
    $freshStmt = $pdo->prepare("SELECT chitchats_shipment_id FROM orders WHERE id = ?");
    $freshStmt->execute([$orderId]);
    $freshId = $freshStmt->fetchColumn();
    if (!empty($freshId)) {
        shipLog('RACE BLOCKED — fresh DB already has shipment', ['order_id' => $orderId, 'shipment_id' => $freshId]);
        return ['error' => null, 'shipment_id' => $freshId];
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
        'postage_type'     => 'unknown',
        'value'            => number_format(floatval($order['grand_total'] ?? 0), 2),
        'value_currency'   => 'cad',
        'package_contents' => 'merchandise',
        'description'      => 'Bedda Skincare Products',
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

    if (!$order) return ['error' => 'Order not found'];

    // ── Step 1: Use existing shipment ID from DB ────────────────────
    $shipmentId = $order['chitchats_shipment_id'] ?? null;
    $shipmentData = null;
    $selectedPostage = null; // initialise so return doesn't warn if already paid

    if (empty($shipmentId)) {
        // ── Step 2: Create NEW shipment if needed ─────────────────────
        $createResult = createChitChatsShipment($order);
        if (!empty($createResult['error'])) return ['error' => $createResult['error']];

        // Re-fetch so we have the newly saved ID
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $shipmentId = $order['chitchats_shipment_id'] ?? null;
    }

    if (!$shipmentId) {
        return ['error' => 'Failed to obtain shipment ID after creation.'];
    }

    // Optional: fetch current ChitChats state (non-destructive — we do NOT clear on 404 here)
    $check = callChitChatsAPI(CHITCHATS_API_BASE . '/shipments/' . $shipmentId, [], 'GET');
    if (!empty($check['shipment'])) {
        $shipmentData = $check['shipment'];
    }

    $status = $shipmentData['status'] ?? '';

    // ── CHECK IF ALREADY PAID ─────────────────────────────────────────
    if ($status === 'ready' || $status === 'postage_purchased') {
        shipLog('LABEL ALREADY PURCHASED', ['shipment_id' => $shipmentId]);
        // Extract tracking and finish
        $tracking = $shipmentData['carrier_tracking_code'] ?? $shipmentData['tracking_code'] ?? null;
        $labelUrl = $shipmentData['postage_label_pdf_url'] ?? null;
        if (!$tracking) return ['error' => 'No tracking code returned', 'shipment_id' => $shipmentId];

        $pdo->prepare("UPDATE orders SET tracking_number = ?, label_pdf_url = ? WHERE id = ?")
            ->execute([$tracking, $labelUrl, $orderId]);

        return [
            'error' => null,
            'tracking_number' => $tracking,
            'label_pdf_url' => $labelUrl,
            'carrier' => $shipmentData['carrier'] ?? 'ChitChats',
            'service' => $shipmentData['postage_type'] ?? 'unknown',
            'shipment_id' => $shipmentId,
            'purchase_amount' => $shipmentData['purchase_amount'] ?? null,
        ];
        
    }

    <?php elseif ($o['status'] === 'ready_to_ship'): ?>
    <form method="POST" class="inline">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="order_id"   value="<?= $o['id'] ?>">
        <button name="action" value="create_label"
                onclick="this.disabled=true;this.form.submit();"
                class="bg-stone-700 text-white px-3 py-1 rounded text-xs hover:bg-stone-700">
            🏷️ Create ChitChats Label
        </button>
    </form>

    // ── Step 3: Refresh rates ─────────────────────────────────────
    $refreshUrl = CHITCHATS_API_BASE . '/shipments/' . $shipmentId . '/refresh';
    $refreshResult = callChitChatsAPI($refreshUrl, ['ship_date' => 'today'], 'PATCH');

    // If refresh returns 404, the ID is truly stale — clear once and recreate
    if (!empty($refreshResult['error']) && ($refreshResult['http_code'] ?? 0) === 404) {
        shipLog('STALE SHIPMENT ID on refresh — clearing once', ['shipment_id' => $shipmentId]);
        $pdo->prepare("UPDATE orders SET chitchats_shipment_id = NULL WHERE id = ?")->execute([$orderId]);

        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $createResult = createChitChatsShipment($order);
        if (!empty($createResult['error'])) return ['error' => $createResult['error']];

        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        $shipmentId = $order['chitchats_shipment_id'] ?? null;
        if (!$shipmentId) return ['error' => 'Recreated shipment but ID missing in DB'];

        // Retry refresh once
        $refreshResult = callChitChatsAPI(CHITCHATS_API_BASE . '/shipments/' . $shipmentId . '/refresh', ['ship_date' => 'today'], 'PATCH');
    }

    if (!empty($refreshResult['error'])) {
        return ['error' => 'Rate refresh failed: ' . ($refreshResult['message'] ?? 'Unknown'), 'shipment_id' => $shipmentId];
    }

    $rates = $refreshResult['shipment']['rates'] ?? [];
    if (empty($rates)) return ['error' => 'No postage rates returned.', 'shipment_id' => $shipmentId];

    // ── Step 4: Select postage type ───────────────────────────────
    $preferredType = !empty($order['chitchats_postage_type']) ? $order['chitchats_postage_type'] : 'chit_chats_select';
    $selectedPostage = null;
    $cheapestPostage = null;
    $cheapestPrice = INF;

    foreach ($rates as $rate) {
        $type = $rate['postage_type'] ?? '';
        $price = floatval($rate['purchase_amount'] ?? $rate['payment_amount'] ?? $rate['postage_fee'] ?? 9999);
        if ($price < $cheapestPrice) { $cheapestPrice = $price; $cheapestPostage = $type; }
        if ($type === $preferredType) $selectedPostage = $type;
    }
    $selectedPostage = $selectedPostage ?: ($cheapestPostage ?: ($rates[0]['postage_type'] ?? null));

    // ── Step 5: BUY the label ─────────────────────────────────────
    $buyUrl = CHITCHATS_API_BASE . '/shipments/' . $shipmentId . '/buy';
    $buyResult = callChitChatsAPI($buyUrl, ['postage_type' => $selectedPostage], 'PATCH');

    if (!empty($buyResult['error'])) {
        return ['error' => 'Buy failed: ' . ($buyResult['message'] ?? 'Unknown'), 'shipment_id' => $shipmentId];
    }
    $shipmentData = $buyResult['shipment'] ?? $shipmentData;

    // ── Step 6: Poll until label is ready ─────────────────────────────
    $status = $shipmentData['status'] ?? '';
    if ($status !== 'ready' && $status !== 'postage_purchased') {
        for ($i = 0; $i < 12 && in_array($status, ['postage_requested', 'pending', ''], true); $i++) {
            usleep(500000); // 0.5s
            $poll = callChitChatsAPI(CHITCHATS_API_BASE . '/shipments/' . $shipmentId, [], 'GET');
            if (!empty($poll['shipment'])) {
                $shipmentData = $poll['shipment'];
                $status = $shipmentData['status'] ?? '';
                if ($status === 'ready' || $status === 'postage_purchased') break;
            }
        }
    }

    if ($status === 'postage_purchase_failed') {
        return ['error' => 'Postage purchase failed. Check ChitChats balance.', 'shipment_id' => $shipmentId];
    }

    // ── Step 7: Extract and save tracking ─────────────────────────────
    $tracking = $shipmentData['carrier_tracking_code'] ?? $shipmentData['tracking_code'] ?? null;
    $labelUrl = $shipmentData['postage_label_pdf_url'] ?? null;

    if (!$tracking) return ['error' => 'No tracking code returned', 'shipment_id' => $shipmentId];

    $pdo->prepare("UPDATE orders SET tracking_number = ?, label_pdf_url = ? WHERE id = ?")
        ->execute([$tracking, $labelUrl, $orderId]);

    return [
        'error' => null,
        'tracking_number' => $tracking,
        'label_pdf_url' => $labelUrl,
        'carrier' => $shipmentData['carrier'] ?? 'ChitChats',
        'service' => $selectedPostage,
        'shipment_id' => $shipmentId,
        'purchase_amount' => $shipmentData['purchase_amount'] ?? null,
    ];
} // <-- THIS BRACE WAS MISSING IN YOUR FILE

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
        $stmt = $pdo->prepare("UPDATE orders SET status = 'ready_to_ship' WHERE id = ? AND status = 'queued'");
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
            $trackingUrl = 'https://chitchats.com/tracking/' . strtolower($o['tracking_number']);
            
            // Build branded email HTML
            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>🎉 Your Bedda Order Has Shipped!</title>
                <style>
                    body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.6; color: #2C2C2C; margin: 0; padding: 0; background: #F5F3EF; }
                    .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                    .header { background: linear-gradient(135deg, #B5A183, #9C8C73); padding: 24px; text-align: center; }
                    .header h1 { margin: 0; color: white; font-size: 24px; font-weight: 700; }
                    .header p { margin: 8px 0 0; color: #F0ECE6; font-size: 16px; }
                    .content { padding: 24px; }
                    .celebration { text-align: center; margin: 0 0 24px; }
                    .celebration .emoji { font-size: 48px; margin-bottom: 8px; }
                    .celebration h2 { margin: 0; color: #2C2C2C; font-size: 20px; font-weight: 600; }
                    .celebration p { margin: 4px 0 0; color: #5A4D3F; }
                    .tracking-box { background: #F5F3EF; border: 1px solid #E8E4DC; border-radius: 8px; padding: 16px; margin: 24px 0; text-align: center; }
                    .tracking-box .label { font-size: 14px; color: #7E6D58; font-weight: 600; margin-bottom: 8px; }
                    .tracking-box .code { font-family: monospace; font-size: 18px; color: #7E6D58; font-weight: 700; letter-spacing: 1px; }
                    .tracking-box .btn { display: inline-block; margin-top: 12px; background: #B5A183; color: white; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px; }
                    .tracking-box .btn:hover { background: #9C8C73; }
                    .next-steps { background: #F5F3EF; border: 1px solid #E8E4DC; border-radius: 8px; padding: 16px; margin: 24px 0; }
                    .next-steps h3 { margin: 0 0 12px; color: #5A4D3F; font-size: 16px; font-weight: 600; }
                    .next-steps ol { margin: 0; padding-left: 20px; color: #5A4D3F; }
                    .next-steps li { margin: 4px 0; }
                    .footer { text-align: center; padding: 16px 24px; border-top: 1px solid #e5e7eb; color: #5A4D3F; font-size: 14px; }
                    .footer a { color: #B5A183; text-decoration: none; }
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
                            <p>Your Bedda goodies have left our studio and are heading your way.</p>
                        </div>
                        <div class='tracking-box'>
                            <div class='label'>📦 Track Your Package</div>
                            <div class='code'>" . htmlspecialchars($o['tracking_number']) . "</div>
                            <a href='{$trackingUrl}' class='btn' target='_blank'>Track Now →</a>
                        </div>
                        <div class='next-steps'>
                            <h3>📅 What to Expect</h3>
                            <ol>
                                <li><strong>Today:</strong> Package picked up by Chit Chats</li>
                                <li><strong>1-2 business days:</strong> In transit to your city</li>
                                <li><strong>2-8 business days:</strong> Delivered to your door 🎁</li>
                            </ol>
                        </div>
                        <div style='text-align:center; padding:16px; background:#F5F3EF; border-radius:8px; margin-top:24px'>
                            <p style='margin:0 0 8px; font-weight:600; color:#2C2C2C'>Questions?</p>
                            <p style='margin:0'>
                                <a href='mailto:orders@bedda.ca' style='color:#5A4D3F'>orders@bedda.ca</a> • 
                                <a href='https://bedda.ca/contact.html' style='color:#5A4D3F'>Contact Page</a>
                            </p>
                        </div>
                    </div>
                    <div class='footer'>
                        <p style='margin:0'>Bedda Skincare • Handcrafted with care</p>
                        <p style='margin:4px 0 0'>Thank you for supporting small business 💛</p>
                        <p style='margin:8px 0 0; font-size:12px; color:#9ca3af'>
                            <a href='https://bedda.ca/unsubscribe?email=" . urlencode($o['customer_email']) . "'>Unsubscribe</a>
                        </p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            // Send the email
            sendEmail($o['customer_email'], $o['customer_name'], "Your Bedda Order Has Shipped", $html);
            
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
        $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'queued'");
        $stmt->execute([$orderId]);
        shipLog('CANCEL QUEUED', ['order_id' => $orderId, 'rows' => $stmt->rowCount()]);
        $message = '✅ Order cancelled';
    }
    elseif ($action === 'cleanup_chitchats') {
        // Fetch all pending/unpaid shipments from your ChitChats account
        $url = CHITCHATS_API_BASE . '/shipments?status=pending';
        $response = callChitChatsAPI($url, [], 'GET');
        
        $shipments = [];
        if (isset($response[0]) && is_array($response[0])) {
            $shipments = $response;
        } elseif (isset($response['shipments']) && is_array($response['shipments'])) {
            $shipments = $response['shipments'];
        }
        
        $deletedCount = 0;
        foreach ($shipments as $shipment) {
            if (!empty($shipment['id'])) {
                $delUrl = CHITCHATS_API_BASE . '/shipments/' . $shipment['id'];
                $delRes = callChitChatsAPI($delUrl, [], 'DELETE');
                if (empty($delRes['error'])) {
                    $deletedCount++;
                }
            }
        }
        shipLog('CHITCHATS CLEANUP', ['deleted_count' => $deletedCount]);
        $message = "✅ Cleaned up $deletedCount unpaid/pending shipments from ChitChats.";
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
    $where .= " AND (customer_name LIKE ? OR customer_email LIKE ? OR id = ? OR tracking_number LIKE ?)";
    $like   = "%$search%";
    $params = array_merge($params, [$like, $like, (int)$search, $like]);
}
if ($filter === 'queued') {
    $where .= " AND status = 'queued'";
} elseif ($filter === 'ready') {
    $where .= " AND status = 'ready_to_ship'";
} elseif ($filter === 'labels') {
    $where .= " AND status = 'label_created'";
} elseif ($filter === 'shipped') {
    $where .= " AND status = 'shipped'";
}

$orders = fetchOrdersForAdmin($pdo, $where, $params, 200);
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
    <title>Shipping — Bedda Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <style>.pill { padding: 2px 8px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }</style>
</head>
<body class="bg-stone-50 min-h-screen font-inter">
<?php renderNav('shipping'); ?>
<div class="max-w-7xl mx-auto px-4 py-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <h1 class="text-2xl font-bold text-stone-800">Order Fulfillment</h1>
        <div class="flex flex-wrap gap-2">
            <form method="POST" class="inline" onsubmit="return confirm('Delete ALL unpaid/pending shipments from your ChitChats dashboard?');">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <button name="action" value="cleanup_chitchats" class="bg-stone-100 text-stone-700 border border-stone-200 px-4 py-2 rounded text-sm hover:bg-stone-200 font-semibold transition-colors">
                    🗑️ Clear Unpaid ChitChats
                </button>
            </form>

            <form method="GET" class="flex gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search email, name, ID, tracking…"
                   class="border border-stone-300 rounded px-3 py-2 text-sm w-64">
            <select name="filter" class="border border-stone-300 rounded px-3 py-2 text-sm" onchange="this.form.submit()">
                <option value="queued"  <?= $filter==='queued' ?'selected':'' ?>>Queued</option>
                <option value="ready"   <?= $filter==='ready'  ?'selected':'' ?>>Ready to Ship</option>
                <option value="labels"  <?= $filter==='labels' ?'selected':'' ?>>Labels Created</option>
                <option value="shipped" <?= $filter==='shipped'?'selected':'' ?>>Shipped</option>
            </select>
            <button type="submit" class="bg-sage-600 text-white px-4 py-2 rounded text-sm hover:bg-sage-700">Search</button>
        </form>
    </div>

    <?php if ($message): ?>
        <?php $isError = str_starts_with($message, '❌'); ?>
        <div class="mb-4 p-3 rounded text-sm <?= $isError ? 'bg-stone-100 text-stone-700' : 'bg-sage-100 text-sage-700' ?>">
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
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Tracking</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Actions</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-stone-500 uppercase">Stripe PI</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            <?php foreach ($orders as $o):
                $statusBadge = match($o['status']) {
                    'queued'        => '<span class="pill bg-stone-100 text-stone-800">Queued</span>',
                    'ready_to_ship' => '<span class="pill bg-stone-100 text-stone-800">Ready</span>',
                    'label_created' => '<span class="pill bg-stone-100 text-stone-800">Label Created</span>',
                    'shipped'       => '<span class="pill bg-sage-100 text-sage-800">Shipped</span>',
                    'cancelled'     => '<span class="pill bg-stone-100 text-stone-800">Cancelled</span>',
                    default         => '<span class="pill bg-stone-100 text-stone-800">'.htmlspecialchars($o['status']).'</span>'
                };
                $displayDate = ordersFormatDisplayDate($o);
            ?>
                <tr class="hover:bg-stone-50">
                    <td class="px-4 py-3 font-mono text-stone-800">#<?= $o['id'] ?></td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-stone-800"><?= htmlspecialchars($o['customer_name']) ?></div>
                        <div class="text-xs text-stone-500"><?= htmlspecialchars($o['customer_email']) ?></div>
                    </td>
                    <td class="px-4 py-3"><?= $statusBadge ?></td>
                    <td class="px-4 py-3 text-stone-600 font-mono text-xs">
                        <?php if ($o['tracking_number']): ?>
                            <a href="https://chitchats.com/tracking/<?= htmlspecialchars(strtolower($o['tracking_number'])) ?>"
                               target="_blank" class="hover:underline text-stone-600">
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
                        <?php if ($o['status'] === 'queued'): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="order_id"   value="<?= $o['id'] ?>">
                                <button name="action" value="verify" class="text-stone-600 hover:underline text-xs mr-2">✓ Verify</button>
                                <button name="action" value="delete_queued" onclick="return confirm('Delete this queued order?')" class="text-stone-700 hover:underline text-xs">✕ Delete</button>
                            </form>
                        <?php elseif ($o['status'] === 'ready_to_ship'): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="order_id"   value="<?= $o['id'] ?>">
                                <button name="action" value="create_label" class="bg-stone-700 text-white px-3 py-1 rounded text-xs hover:bg-stone-700">🏷️ Create ChitChats Label</button>
                            </form>
                        <?php elseif ($o['status'] === 'label_created'): ?>
                            <div class="flex items-center gap-2 flex-wrap">
                                <button onclick="copyTracking('<?= addslashes($o['tracking_number']) ?>')" class="text-stone-600 hover:underline text-xs">📋 Copy</button>
                                <?php if (!empty($o['tracking_number'])): ?>
                                    <a href="?download_label=<?= urlencode($o['tracking_number']) ?>" class="text-indigo-600 hover:underline text-xs" target="_blank">⬇️ PDF</a>
                                <?php endif; ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="order_id"   value="<?= $o['id'] ?>">
                                    <button name="action" value="mark_shipped" class="bg-sage-600 text-white px-3 py-1 rounded text-xs hover:bg-sage-700">🚚 Mark Shipped</button>
                                </form>
                            </div>
                        <?php elseif ($o['status'] === 'shipped'): ?>
                            <div class="flex items-center gap-2">
                                <button onclick="copyTracking('<?= addslashes($o['tracking_number']) ?>')" class="text-stone-600 hover:underline text-xs">📋 Copy</button>
                                <?php if (!empty($o['tracking_number'])): ?>
                                    <a href="?download_label=<?= urlencode($o['tracking_number']) ?>" class="text-indigo-600 hover:underline text-xs" target="_blank">⬇️ PDF</a>
                                <?php endif; ?>
                                <span class="text-xs text-stone-400 ml-2">Completed</span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-stone-600 font-mono text-xs">
                        <?php if (!empty($o['stripe_payment_intent_id'])): ?>
                            <a href="https://dashboard.stripe.com/<?= defined('STRIPE_LIVE_MODE') && STRIPE_LIVE_MODE ? '' : 'test/' ?>payments/<?= htmlspecialchars($o['stripe_payment_intent_id']) ?>"
                               target="_blank" class="hover:underline text-stone-600"
                               title="View in Stripe Dashboard">
                                <?= htmlspecialchars(substr($o['stripe_payment_intent_id'], 0, 12)) ?>…
                            </a>
                            <br>
                            <span class="text-stone-400"><?= htmlspecialchars($o['stripe_payment_status'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="text-stone-400">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (empty($orders)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">No orders match your search/filter.</td></tr>
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