<?php
/**
 * Bedda AI Engine — Production Router + Gated Diagnostic Sandbox
 * Location: /api/ai-engine.php
 *
 * Public AJAX:   POST  /api/ai-engine.php?ajax=1   (JSON body: intent, prompt, context)
 * Public probe:  GET   /api/ai-engine.php?ping=1   (JSON status)
 * Admin sandbox: GET   /api/ai-engine.php          (HTML — requires admin auth)
 *
 * All inference goes through aiRun() in secure-config.php (timeout, cache, self-heal).
 */

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/ai-inference.php';

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-errors.log');

// ============================================================
// CORS / SECURITY HEADERS
// ============================================================
$isAjax = isset($_GET['ajax']) || isset($_GET['ping']);
if ($isAjax) {
    onlybikes_ai_json_headers();
} else {
    if (function_exists('setSecurityHeaders')) {
        setSecurityHeaders();
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ============================================================
// GET ?logout=1 — destroy admin session
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['logout'])) {
    $sp = sys_get_temp_dir();
    if (is_writable($sp)) session_save_path($sp);
    if (session_status() === PHP_SESSION_NONE) {
        session_name('bedda_ai_admin');
        session_start();
    }
    $_SESSION = [];
    session_destroy();
    header('Location: /api/ai-engine.php');
    exit;
}

// ============================================================
// GET ?ping=1 — lightweight status (used by frontend feature-detect)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ping'])) {
    $bin = aiDetectBinary();
    echo json_encode([
        'success'      => true,
        'available'    => (bool)$bin && file_exists(AI_MODEL_PATH),
        'binary_found' => (bool)$bin,
        'model_found'  => file_exists(AI_MODEL_PATH),
        'intents'      => ['faq','shipment','subscription','routine','admin_email'],
    ]);
    exit;
}

// ============================================================
// POST ?ajax=1 — production AJAX endpoint
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax'])) {
    handleAjax();
    exit;
}

// ============================================================
// GET (no params) — admin diagnostic dashboard (gated)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isAdminAuthenticatedSimple()) {
        http_response_code(401);
        renderLoginPage();
        exit;
    }
    renderDiagnostics();
    exit;
}

// ============================================================
// POST without ?ajax — admin login (form post)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    handleAdminLogin();
    exit;
}

http_response_code(405);
echo json_encode(['success'=>false,'message'=>'Method not allowed']);
exit;

// ============================================================
// AJAX HANDLER
// ============================================================
function handleAjax(): void {
    $allowedHosts = beddaAllowedHosts();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $ref    = $_SERVER['HTTP_REFERER'] ?? '';
    $check  = $origin ?: $ref;
    $host   = $check ? parse_url($check, PHP_URL_HOST) : '';
    if ($host && !in_array($host, $allowedHosts, true)) {
        logSecurityEvent('ai_bad_origin', ['origin'=>$check]);
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Bad origin']);
        return;
    }

    $maxBytes = 8 * 1024;
    if (intval($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
        http_response_code(413);
        echo json_encode(['success'=>false,'message'=>'Payload too large']);
        return;
    }
    $raw = file_get_contents('php://input');
    if (strlen($raw) > $maxBytes) {
        http_response_code(413);
        echo json_encode(['success'=>false,'message'=>'Payload too large']);
        return;
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Invalid JSON']);
        return;
    }

    $ip = getClientIP();
    if (!aiRateLimitCheck($ip)) {
        http_response_code(429);
        echo json_encode(['success'=>false,'message'=>'Slow down. Try again in a minute.']);
        return;
    }

    $intent     = sanitizeInput($body['intent']  ?? '', 'alnum');
    $userPrompt = sanitizeInput($body['prompt']  ?? ($body['message'] ?? ''), 'ai_prompt');
    $context    = is_array($body['context'] ?? null) ? $body['context'] : [];

    $valid = ['faq','shipment','subscription','routine','admin_email'];
    if (!in_array($intent, $valid, true)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Unknown intent','intents'=>$valid]);
        return;
    }
    if (!in_array($intent, ['shipment','subscription'], true) && $userPrompt === '') {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Prompt required']);
        return;
    }

    try {
        switch ($intent) {
            case 'faq':          $result = intentFaq($userPrompt); break;
            case 'shipment':     $result = intentShipment($context, $userPrompt); break;
            case 'subscription': $result = intentSubscription($context, $userPrompt); break;
            case 'routine':      $result = intentRoutine($userPrompt, $context); break;
            case 'admin_email':  $result = intentAdminEmail($body, $userPrompt, $context); break;
            default:             $result = ['ok'=>false,'error'=>'No handler'];
        }
    } catch (Throwable $e) {
        error_log('[BEDDA AI] ' . $e->getMessage());
        logSecurityEvent('ai_exception', ['intent'=>$intent,'err'=>$e->getMessage()]);
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'AI handler failed']);
        return;
    }

    aiLog($ip, $intent, $userPrompt, $result);

    $ok = (bool) ($result['ok'] ?? false);
    if (!$ok && ($intent === 'faq' || $intent === 'routine')) {
        $support = onlybikes_ai_env('SUPPORT_EMAIL', 'support@onlybikes.shop');
        $result['ok'] = true;
        $result['text'] = $result['text'] ?? "I'm having a brief issue. Email {$support} and we'll help right away.";
        $ok = true;
    }

    http_response_code($ok ? 200 : 422);
    echo json_encode([
        'success' => $ok,
        'intent'  => $intent,
        'text'    => $result['text'] ?? '',
        'data'    => $result['data'] ?? null,
        'cached'  => $result['cached'] ?? false,
        'elapsed' => $result['elapsed'] ?? null,
        'message' => $result['ok'] ? 'ok' : ($result['error'] ?? 'AI error'),
    ]);
}

// ============================================================
// AI GUARDRAILS
// ============================================================

/**
 * Return a curated response for common FAQ questions.
 * Skips the LLM entirely — guarantees accuracy and instant response.
 */
function getCuratedFaqResponse(string $q): ?string {
    $q = strtolower(trim($q));
    if ($q === '') return null;

    $support = onlybikes_ai_env('SUPPORT_EMAIL', 'support@onlybikes.shop');

    $patterns = [
        '/^(hi|hello|hey|greetings|howdy)/' => 'Hello! Welcome to OnlyBikes. Ask about Surron, Talaria, or E-Ride parts, fitment, stock, or shipping.',
        '/ship|shipping|delivery|how long|arrive|tracking|mail|send/' => 'We ship Canada-wide with live Chit Chats rates at checkout. Typical transit is a few business days depending on carrier and destination.',
        '/price|cost|how much|\$|cheap|expensive/' => 'Prices are on each product card at onlybikes.shop/products.html. Kits and bundles are on bundles.html.',
        '/fit|fitment|compatible|work with|will it fit|surron|talaria|e-ride|ultra bee/' => 'Check fitment lines on each product and our Fitment Guide at onlybikes.shop/fitment.html before ordering.',
        '/stock|in stock|available|sold out|inventory/' => 'Stock badges on product cards update from our inventory. If something shows out of stock, email us — restocks happen regularly.',
        '/return|refund|exchange|warranty/' => 'All sales are final. Double-check fitment before checkout. Contact us if something arrives damaged or incorrect.',
        '/contact|email|reach|phone|call|message/' => 'Reach us at ' . $support . ' — we reply within one business day.',
        '/who|about|onlybikes|company/' => 'OnlyBikes is a Canadian e-moto parts shop focused on Surron, Talaria, and E-Ride upgrades with fitment called out up front.',
        '/fuck|shit|damn|bitch|asshole|stupid|hate|suck|wtf|hell/' => 'I understand you might be frustrated. I\'m here to help with parts, fitment, and orders — what do you need?',
    ];

    foreach ($patterns as $pattern => $response) {
        if (preg_match($pattern, $q)) {
            return $response;
        }
    }

    return null;
}

/**
 * Self-evaluation: Ask the AI to judge if its own output is brand-harmful.
 * Returns true if the AI says the response is harmful.
 */
function selfEvaluateBrandHarm(string $text): bool {
    $prompt = "<|im_start|>user\nYou are a brand safety checker. Read this customer service response and answer ONLY YES or NO.\n\nIs this response harmful to the brand? Consider: negative claims, unsupported medical claims, false comparisons, or hostile language.\n\nResponse: {$text}\n\nAnswer YES or NO only:oes\n<|im_start|>assistant\n";
    $result = aiRun($prompt);
    $judgment = strtoupper(trim($result['text'] ?? ''));
    return ($judgment === 'YES' || stripos($result['text'] ?? '', 'yes') !== false);
}

/**
 * Validate AI output quality. Replace with safe fallback if it fails checks.
 */
function guardAiResponse(string $text, string $intent): array {
    $text = trim($text);

    $fallbacks = [
        'faq'          => "I'm not sure I understood that. Could you rephrase? Or email orders@bedda.ca and we'll help right away.",
        'shipment'     => "We could not retrieve that tracking right now. Please try again shortly or email orders@bedda.ca.",
        'subscription' => "We could not retrieve that subscription info right now. Please try again shortly or email orders@bedda.ca.",
        'routine'      => "I'd be happy to suggest a routine. Could you tell me your skin type and any concerns? Or email orders@bedda.ca.",
        'admin_email'  => "We could not draft that email right now. Please try again or contact support.",
    ];
    $fallback = $fallbacks[$intent] ?? "I'm not sure I understood that. Could you rephrase?";

    if (strlen($text) < 15) {
        return ['text' => $fallback, 'guarded' => true, 'reason' => 'too_short'];
    }
    if (strlen($text) > 400) {
        $text = mb_substr($text, 0, 400) . '...';
    }
    $letters = preg_match_all('/[a-zA-Z]/', $text);
    $total = strlen($text);
    if ($total > 0 && $total < 80 && ($letters / $total) < 0.4) {
        return ['text' => $fallback, 'guarded' => true, 'reason' => 'gibberish'];
    }
    // Hard filters for obvious nonsense/hallucinations (keep these tight)
    $hardFilters = [
        '/\bgat land\b|\byes, it is a\b/i',
        '/\bFDA\b|\bapproved by\b/i',
    ];
    foreach ($hardFilters as $pattern) {
        if (preg_match($pattern, $text)) {
            return ['text' => $fallback, 'guarded' => true, 'reason' => 'hard_filter'];
        }
    }

    // Self-evaluation: let the AI judge if its own output is brand-harmful
    // Only run for FAQ intent to avoid extra latency on structured outputs
    if ($intent === 'faq' && strlen($text) > 30) {
        if (selfEvaluateBrandHarm($text)) {
            return ['text' => $fallback, 'guarded' => true, 'reason' => 'self_eval'];
        }
    }

    // Allow responses that direct to email (safe fallbacks from the model itself)
    if (stripos($text, 'email orders@bedda.ca') !== false) {
        return ['text' => $text, 'guarded' => false];
    }
    return ['text' => $text, 'guarded' => false];
}

// ============================================================
// INTENT HANDLERS
// ============================================================
function intentFaq(string $q): array {
    // 1. CURATED FIRST: Skip the model for common questions (instant, accurate)
    $curated = getCuratedFaqResponse($q);
    if ($curated !== null) {
        return ['ok' => true, 'text' => $curated, 'elapsed' => 0, 'cached' => false, 'curated' => true];
    }
    // 2. NONSENSE FILTER: If the question has no real words, skip inference
    $cleanQ = preg_replace('/[^\w\s]/', '', strtolower($q));
    if (strlen(trim($cleanQ)) < 3 || preg_match('/^\s*[^a-z]{3,}\s*$/i', $q)) {
        $fallback = "I'm not sure I understood that. Could you rephrase? Or email orders@bedda.ca and we'll help right away.";
        return ['ok' => true, 'text' => $fallback, 'elapsed' => 0, 'cached' => false, 'curated' => true];
    }
    // 3. MODEL INFERENCE: Few-shot prompt for novel questions
    $kb = <<<KB
You are a helpful customer service assistant for Bedda Skincare, a Mississauga ON small-batch tallow skincare maker founded by Josie Chaves.
Answer questions politely and professionally. If the user is rude or hostile, remain calm and helpful — do not engage with hostility.

RULES:
- Use ONLY the facts below. Do NOT invent products, prices, ingredients, or stories.
- If asked about something not covered here, say "I don't have that information — please email orders@bedda.ca and we'll help."
- Answer in 2-4 short sentences.

FACTS:
- Bedda = "beautiful" in Sicilian-Italian. Founded by Josie Chaves in Mississauga, inspired by her mother.
- Products & Prices: Plain Jane (unscented, eczema/sensitive), Uni ($5.60, cinnamon/lemon/lavender exfoliating), He-Man ($5.99, rosemary/cedarwood/lavender/patchouli exfoliating), She-Ra ($5.75, turmeric/orange exfoliating), The Massager ($6.25, lavender, textured), Special Occasion ($8.99, hidden heart, lemon/lavender/Rubia Cordifolia), Whipped Tallow Balm (dry/normal nightly moisturizer), Lavender Tallow Lotion (lighter daily), Herbal Bundle Bar (oily/acne), Beeswax Lip Balm.
- Ingredients: tallow (vitamins A/D/E/K, mirrors skin lipids), olive oil, coconut oil, essential oils.
- Shipping: Canada-wide via ChitChats (2-7 days). Local pickup Mississauga by appointment.
- Contact: orders@bedda.ca
- Process: cold process, soaps cure 4-6 weeks

EXAMPLES:
Q: What is tallow soap?
A: Tallow soap is rich in vitamins A, D, E, and K. It mirrors human skin lipids and is non-comedogenic for most people.

Q: Do you ship to Vancouver?
A: Yes, we ship Canada-wide via ChitChats, usually within 2-7 business days.

Q: What should I use for eczema?
A: Plain Jane is our unscented tallow soap specifically for eczema and sensitive skin.

Q: {$q}
A:
KB;
    $prompt = "<|im_start|>user\n{$kb}\n\nQuestion: {$q}<|im_end|>\n<|im_start|>assistant\n";
    $result = aiRun($prompt);
    if (!$result['ok'] || empty($result['text'])) {
        $support = onlybikes_ai_env('SUPPORT_EMAIL', 'support@onlybikes.shop');
        return [
            'ok' => true,
            'text' => "I'm not sure on that one — email {$support} and we'll get you a precise answer.",
            'elapsed' => $result['elapsed'] ?? 0,
            'fallback' => true,
        ];
    }
    if (!empty($result['text'])) {
        $guard = guardAiResponse($result['text'], 'faq');
        $result['text'] = $guard['text'];
        $result['guarded'] = $guard['guarded'];
        if ($guard['guarded']) {
            $result['guard_reason'] = $guard['reason'];
        }
    }
    return $result;
}

function intentShipment(array $ctx, string $q): array {
    $id = sanitizeInput($ctx['shipment_id'] ?? ($ctx['id'] ?? ''), 'alnum');
    if (!$id) return ['ok'=>false,'error'=>'shipment_id required'];
    $cc = chitchatsGetShipment($id);
    if (!$cc['ok']) return ['ok'=>true,'text'=>'We could not retrieve that tracking right now. Please try again shortly or email orders@bedda.ca.','data'=>null];
    $s = $cc['data']['shipment'] ?? $cc['data'];
    $facts = [
        'status'   => $s['status']           ?? 'unknown',
        'tracking' => $s['tracking_code']    ?? ($s['tracking_number'] ?? ''),
        'carrier'  => $s['postage_type']     ?? ($s['carrier'] ?? ''),
        'dest'     => trim(($s['to_city'] ?? '') . ', ' . ($s['to_province_code'] ?? ''), ', '),
        'updated'  => $s['updated_at']       ?? '',
    ];
    $prompt = "<|im_start|>user\nYou are Bedda's shipping assistant. Convert this shipment JSON into a clear 2-3 sentence update for the customer. Do not invent dates or facts.\nJSON: " . json_encode($facts);
    if ($q) $prompt .= "\nCustomer also asked: {$q}";
    $prompt .= "<|im_end|>\n<|im_start|>assistant\n";
    $r = aiRun($prompt);
    $r['data'] = $facts;
    return $r;
}

function intentSubscription(array $ctx, string $q): array {
    $id = sanitizeInput($ctx['subscription_id'] ?? '', 'alnum');
    if (!$id) return ['ok'=>false,'error'=>'subscription_id required'];
    $stripe = getStripe();
    if (!$stripe) return ['ok'=>false,'error'=>'Stripe unavailable'];
    try { $sub = $stripe->subscriptions->retrieve($id); }
    catch (Throwable $e) { return ['ok'=>false,'error'=>'Could not find subscription']; }
    $facts = [
        'status'             => $sub->status,
        'period_end'         => date('Y-m-d', $sub->current_period_end ?? time()),
        'cancel_at_end'      => (bool)($sub->cancel_at_period_end ?? false),
        'plan'               => $sub->items->data[0]->price->nickname ?? ($sub->items->data[0]->price->id ?? 'unknown'),
        'amount_cad'         => isset($sub->items->data[0]->price->unit_amount) ? round($sub->items->data[0]->price->unit_amount / 100, 2) : null,
        'interval'           => $sub->items->data[0]->price->recurring->interval ?? 'month',
    ];
    $prompt = "<|im_start|>user\nYou are Bedda's subscription assistant. Summarize this subscription for the customer in 2-3 short sentences.\nJSON: " . json_encode($facts);
    if ($q) $prompt .= "\nThey asked: {$q}";
    $prompt .= "<|im_end|>\n<|im_start|>assistant\n";
    $r = aiRun($prompt);
    $r['data'] = $facts;
    return $r;
}

function intentRoutine(string $q, array $ctx): array {
    $skin     = sanitizeInput($ctx['skin_type'] ?? '', 'alnum');
    $concerns = sanitizeInput($ctx['concerns']  ?? '', 'ai_prompt');
    $catalog = <<<CAT
Bedda products (use):
- Tallow Soap (gentle daily cleanser, all skin types)
- Whipped Tallow Balm (rich nightly moisturizer, dry/normal)
- Lavender Tallow Lotion (lighter daily moisturizer)
- Herbal Bundle Bar (oily/acne-prone)
- Beeswax Lip Balm (lips)
CAT;
    $prompt = "<|im_start|>user\nYou are Bedda's skincare guide. Using ONLY the listed products, suggest a simple morning + evening routine as 4-6 short bullets. Do not invent products.\n\n{$catalog}\n\nSkin type: {$skin}\nConcerns: {$concerns}\nUser note: {$q}<|im_end|>\n<|im_start|>assistant\n";
    return aiRun($prompt);
}

function intentAdminEmail(array $body, string $q, array $ctx): array {
    $apiKey = $body['admin_key'] ?? ($_SERVER['HTTP_X_ADMIN_KEY'] ?? '');
    global $VALID_API_KEYS;
    if (empty($apiKey) || !is_array($VALID_API_KEYS) || !in_array($apiKey, $VALID_API_KEYS, true)) {
        logSecurityEvent('ai_admin_email_unauth', ['ip'=>getClientIP()]);
        return ['ok'=>false,'error'=>'Admin auth required'];
    }
    $cust = sanitizeInput($ctx['customer_msg'] ?? '', 'ai_prompt');
    $tone = sanitizeInput($ctx['tone'] ?? 'warm', 'alnum') ?: 'warm';
    $prompt = "<|im_start|>user\nYou are an assistant for the Bedda Skincare admin. Draft a {$tone}, concise email reply under 120 words signed 'The Bedda Team'. Do not invent prices or shipping dates.\n\nCustomer said: {$cust}\n\nAdmin note: {$q}<|im_end|>\n<|im_start|>assistant\n";
    return aiRun($prompt);
}

// ============================================================
// RATE LIMIT
// ============================================================
function aiRateLimitCheck(string $ip): bool {
    if (!is_dir(RATE_LIMIT_DIR)) @mkdir(RATE_LIMIT_DIR, 0750, true);
    $file = RATE_LIMIT_DIR . 'ai-' . md5($ip) . '.json';
    $entries = file_exists($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    $now = time();
    $entries = array_values(array_filter($entries, fn($t) => $t > $now - 3600));
    $lastMin = array_filter($entries, fn($t) => $t > $now - 60);
    if (count($lastMin) >= AI_RATE_LIMIT_PER_IP_PER_MINUTE) return false;
    if (count($entries) >= AI_RATE_LIMIT_PER_IP_PER_HOUR)   return false;
    $entries[] = $now;
    @file_put_contents($file, json_encode($entries), LOCK_EX);
    return true;
}

// ============================================================
// AI LOG
// ============================================================
function aiLog(string $ip, string $intent, string $prompt, array $result): void {
    if (!is_dir(AI_LOG_DIR)) @mkdir(AI_LOG_DIR, 0750, true);
    @file_put_contents(
        AI_LOG_DIR . date('Y-m-d') . '-ai.log',
        json_encode([
            't'       => date('c'),
            'ip'      => $ip,
            'intent'  => $intent,
            'prompt'  => mb_substr($prompt, 0, 200),
            'ok'      => (bool)($result['ok'] ?? false),
            'cached'  => (bool)($result['cached'] ?? false),
            'elapsed' => $result['elapsed'] ?? null,
            'out_len' => isset($result['text']) ? strlen($result['text']) : 0,
        ]) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// ============================================================
// ADMIN AUTH (simple session, used only for dashboard view)
// ============================================================
function isAdminAuthenticatedSimple(): bool {
    $sess = sys_get_temp_dir();
    if (is_writable($sess)) session_save_path($sess);
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure',   1);
        ini_set('session.cookie_samesite', 'Strict');
        session_name('bedda_ai_admin');
        session_start();
    }
    return !empty($_SESSION['ai_admin']);
}
function handleAdminLogin(): void {
    $sp = sys_get_temp_dir();
    if (is_writable($sp)) session_save_path($sp);
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure',   1);
        ini_set('session.cookie_samesite', 'Strict');
        session_name('bedda_ai_admin');
        session_start();
    }
    $pw = $_POST['password'] ?? '';
    $hash = ADMIN_PASSWORD_HASH;
    if ($hash && password_verify($pw, $hash)) {
        $_SESSION['ai_admin'] = true;
        $_SESSION['ai_admin_ip'] = getClientIP();
        $_SESSION['ai_admin_t']  = time();
        header('Location: /api/ai-engine.php'); exit;
    }
    logSecurityEvent('ai_admin_login_fail', ['ip'=>getClientIP()]);
    sleep(1);
    http_response_code(401);
    renderLoginPage('Invalid password.');
    exit;
}

// ============================================================
// VIEW: LOGIN
// ============================================================
function renderLoginPage(string $err = ''): void {
    ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Admin</title>
<script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex items-center justify-center">
<form method="POST" class="bg-slate-800 p-6 rounded-lg border border-slate-700 w-80">
<h1 class="text-lg font-bold mb-4 text-sage-400">Bedda AI — Admin</h1>
<?php if ($err): ?><p class="text-stone-400 text-sm mb-3"><?= htmlspecialchars($err) ?></p><?php endif; ?>
<input type="password" name="password" placeholder="Admin password" required
       class="w-full bg-slate-900 border border-slate-700 rounded p-2 text-sm mb-3">
<button type="submit" name="admin_login" value="1"
        class="w-full bg-sage-500 hover:bg-sage-600 text-slate-950 font-bold py-2 rounded">Sign in</button>
</form></body></html>
    <?php
}

// ============================================================
// VIEW: DIAGNOSTICS (admin only)
// ============================================================
function renderDiagnostics(): void {
    $bin = aiDetectBinary();
    $shellOk = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
    $report = [
        'shell_exec_enabled' => ['status' => $shellOk, 'msg' => $shellOk ? 'enabled' : 'disabled'],
        'binary_detected'    => ['status' => (bool)$bin, 'msg' => $bin ?: 'not found'],
        'binary_executable'  => ['status' => $bin && is_executable($bin), 'msg' => $bin ? ('perms ' . substr(sprintf('%o', @fileperms($bin)), -4)) : 'n/a'],
        'model_exists'       => ['status' => file_exists(AI_MODEL_PATH), 'msg' => file_exists(AI_MODEL_PATH) ? (round(filesize(AI_MODEL_PATH)/(1024*1024),1) . ' MB') : 'missing'],
        'cache_writable'     => ['status' => is_dir(AI_CACHE_DIR) && is_writable(AI_CACHE_DIR), 'msg' => AI_CACHE_DIR],
        'memory_limit'       => ['status' => true, 'msg' => ini_get('memory_limit')],
        'php_version'        => ['status' => true, 'msg' => PHP_VERSION],
        'proc_open'          => ['status' => function_exists('proc_open'), 'msg' => function_exists('proc_open') ? 'available' : 'disabled'],
        'binary_test' => (function() use ($bin) {
            if (!$bin) return ['status'=>false,'msg'=>'no binary'];
            $binDir = dirname($bin);
            $ldPre = "LD_LIBRARY_PATH=" . escapeshellarg($binDir) . " ";
            $out = @shell_exec($ldPre . escapeshellarg($bin) . ' --version 2>&1');
            $out = trim((string)$out);
            $ok = ($out !== '' && stripos($out, 'not found') === false && stripos($out, 'GLIBC') === false);
            $first = strtok($out ?: '(no output)', "\n");
            return ['status' => $ok, 'msg' => substr($first, 0, 120)];
        })(),
    ];
    ?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Bedda AI Admin</title>
<script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-900 text-slate-100 min-h-screen font-sans">
<div class="max-w-6xl mx-auto p-6">
<header class="mb-6 border-b border-slate-700 pb-4 flex justify-between">
  <div><h1 class="text-2xl font-bold text-sage-400">Bedda AI Control Panel</h1>
  <p class="text-xs text-slate-400">SmolLM2 local inference under shared-host constraints.</p></div>
  <a href="?logout=1" class="text-xs text-slate-500 underline">Logout</a>
</header>
<section class="mb-8 bg-slate-950 p-4 rounded border border-slate-800">
<h2 class="text-sm font-bold text-sage-500 uppercase mb-3">Diagnostics</h2>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 text-xs font-mono">
<?php foreach ($report as $k => $v): ?>
<div class="p-2 rounded border <?= $v['status']?'bg-emerald-950/30 border-emerald-800/60 text-emerald-300':'bg-stone-950/30 border-stone-800/60 text-stone-300' ?>">
<div class="flex justify-between font-bold mb-1"><span><?= htmlspecialchars(str_replace('_',' ',$k)) ?></span><span><?= $v['status']?'PASS':'FAIL' ?></span></div>
<p class="text-slate-400 font-sans break-words"><?= htmlspecialchars($v['msg']) ?></p>
</div>
<?php endforeach; ?>
</div></section>

<section class="bg-slate-800 p-4 rounded border border-slate-700">
<h2 class="text-sm font-bold text-sage-500 uppercase mb-3">Live Inference Test</h2>
<select id="intent" class="bg-slate-900 border border-slate-700 rounded p-2 text-sm mb-3 w-full">
  <option value="faq">FAQ</option>
  <option value="routine">Skin routine</option>
  <option value="admin_email">Admin email draft</option>
</select>
<textarea id="prompt" placeholder="Prompt..." class="w-full bg-slate-900 border border-slate-700 rounded p-2 text-sm mb-3 h-24"></textarea>
<button id="run" class="bg-sage-500 hover:bg-sage-600 text-slate-950 font-bold px-4 py-2 rounded text-sm">Run</button>
<pre id="out" class="mt-4 bg-slate-950 p-3 rounded text-emerald-300 text-xs whitespace-pre-wrap min-h-[120px]"></pre>
</section></div>

<script>
document.getElementById('run').onclick = async () => {
  const out = document.getElementById('out');
  out.textContent = 'Running...';
  const res = await fetch('?ajax=1', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      intent: document.getElementById('intent').value,
      prompt: document.getElementById('prompt').value,
      admin_key: prompt('Admin API key for admin_email intent (leave blank for others):') || ''
    })
  });
  const j = await res.json();
  out.textContent = JSON.stringify(j, null, 2);
};
</script></body></html>
    <?php
}
