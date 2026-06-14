<?php
/**
 * Shared API helpers (formerly in Bedda secure-config.php).
 */
declare(strict_types=1);

if (!defined('RATE_LIMIT_DIR')) {
    define('RATE_LIMIT_DIR', dirname(__DIR__) . '/rate-limits/');
}
if (!defined('RATE_LIMIT_ENABLED')) {
    define('RATE_LIMIT_ENABLED', true);
}
if (!defined('RATE_LIMIT_ORDERS_PER_IP_PER_HOUR')) {
    define('RATE_LIMIT_ORDERS_PER_IP_PER_HOUR', 5);
}
if (!defined('RATE_LIMIT_ORDERS_PER_EMAIL_PER_DAY')) {
    define('RATE_LIMIT_ORDERS_PER_EMAIL_PER_DAY', 10);
}
if (!defined('RATE_LIMIT_ORDERS_PER_PHONE_PER_DAY')) {
    define('RATE_LIMIT_ORDERS_PER_PHONE_PER_DAY', 10);
}
if (!defined('COUPON_RATE_LIMIT_WINDOW')) {
    define('COUPON_RATE_LIMIT_WINDOW', 60);
}
if (!defined('COUPON_RATE_LIMIT_PER_MINUTE')) {
    define('COUPON_RATE_LIMIT_PER_MINUTE', 20);
}
if (!defined('SPAM_SUSPICIOUS_THRESHOLD')) {
    define('SPAM_SUSPICIOUS_THRESHOLD', 5);
}
if (!defined('SPAM_BLOCK_THRESHOLD')) {
    define('SPAM_BLOCK_THRESHOLD', 10);
}
if (!defined('SPAM_NOTIFICATION_SUBJECT')) {
    define('SPAM_NOTIFICATION_SUBJECT', '[OnlyBikes] Suspicious order blocked');
}
if (!defined('CAPTCHA_SITE_KEY')) {
    define('CAPTCHA_SITE_KEY', '');
}
if (!defined('ORDER_HONEYPOT_ENABLED')) {
    define('ORDER_HONEYPOT_ENABLED', true);
}
if (!defined('ORDER_MIN_TIME_SECONDS')) {
    define('ORDER_MIN_TIME_SECONDS', 3);
}
if (!defined('ORDER_MAX_TIME_SECONDS')) {
    define('ORDER_MAX_TIME_SECONDS', 86400);
}

if (!function_exists('onlybikes_support_email')) {
    function onlybikes_support_email(): string
    {
        if (defined('SUPPORT_EMAIL') && SUPPORT_EMAIL !== '') {
            return SUPPORT_EMAIL;
        }
        $env = getenv('SUPPORT_EMAIL');
        return ($env !== false && $env !== '') ? (string) $env : 'support@onlybikes.shop';
    }
}

if (!function_exists('beddaAllowedHosts')) {
    function beddaAllowedHosts(): array
    {
        $hosts = ['localhost', '127.0.0.1', 'onlybikes.shop', 'www.onlybikes.shop'];
        foreach (['SITE_ORIGIN', 'SITE_URL'] as $key) {
            $origin = getenv($key);
            if ($origin === false || $origin === '') {
                continue;
            }
            $h = parse_url((string) $origin, PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $hosts[] = strtolower($h);
            }
        }
        $current = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($current !== '') {
            $hosts[] = $current;
        }
        return array_values(array_unique($hosts));
    }
}

function onlybikes_truthy(mixed $value): bool
{
    if ($value === true || $value === 1 || $value === '1') {
        return true;
    }
    if ($value === false || $value === 0 || $value === '0' || $value === null || $value === '') {
        return false;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? !empty($value);
}

if (!function_exists('onlybikes_handling_cost')) {
    /** Must match api/create-payment-intent.php and main.js calculateHandlingCost(). */
    function onlybikes_handling_cost(array $items, string $fulfillmentMethod = 'shipping'): float
    {
        if ($fulfillmentMethod !== 'shipping') {
            return 0.0;
        }
        if (!function_exists('onlybikes_calculate_cart_package')) {
            require_once __DIR__ . '/product-shipping-specs.php';
        }
        $package = onlybikes_calculate_cart_package($items);
        $isSmallParcel = $package['package_type'] === 'thick_envelope';
        return $isSmallParcel ? 3.75 : 4.25;
    }
}

if (!function_exists('logOrder')) {
    function logOrder(array $data, bool $isSpam = false): void
    {
        $entry = [
            'time' => gmdate('c'),
            'spam' => $isSpam,
            'data' => $data,
        ];
        error_log('[OnlyBikes][order] ' . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents(
                $dir . '/orders.log',
                json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    }
}

if (!function_exists('beddaSessionCookieSecure')) {
    function beddaSessionCookieSecure(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}

if (!function_exists('getClientIP')) {
    function getClientIP(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $raw = (string) $_SERVER[$key];
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $raw = trim(explode(',', $raw)[0]);
            }
            if (filter_var($raw, FILTER_VALIDATE_IP)) {
                return $raw;
            }
        }
        return 'unknown';
    }
}

if (!function_exists('setSecurityHeaders')) {
    function setSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        $origin = function_exists('cfg')
            ? rtrim(cfg('SITE_ORIGIN', ''), '/')
            : rtrim((string) getenv('SITE_ORIGIN'), '/');
        if ($origin !== '' && !empty($_SERVER['HTTP_ORIGIN'])) {
            $reqOrigin = rtrim((string) $_SERVER['HTTP_ORIGIN'], '/');
            if ($reqOrigin === $origin) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Access-Control-Allow-Credentials: true');
            }
        }
    }
}

if (!function_exists('jsonResponse')) {
    function jsonResponse(bool $success, string $message, array $data = [], int $code = 200): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput(string $value, string $type = 'string'): string
    {
        $value = trim($value);
        switch ($type) {
            case 'email':
                return filter_var($value, FILTER_SANITIZE_EMAIL) ?: '';
            case 'ai_prompt':
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
                return mb_substr(strip_tags($value), 0, 2000);
            case 'name':
                return preg_replace('/[^\p{L}\p{M}\s\'\-\.]/u', '', $value) ?? '';
            case 'alnum':
                return preg_replace('/[^a-zA-Z0-9_\-]/', '', $value) ?? '';
            default:
                return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}

if (!function_exists('logSecurityEvent')) {
    function logSecurityEvent(string $event, array $context = []): void
    {
        error_log('[OnlyBikes][security] ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('isDisposableEmail')) {
    function isDisposableEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return false;
        }
        $domain = substr(strrchr($email, '@'), 1);
        static $blocked = [
            'tempmail.com', 'throwaway.com', 'mailinator.com', 'guerrillamail.com',
            'sharklasers.com', 'spam4.me', 'trashmail.com', 'yopmail.com',
            '10minutemail.com', 'fakeinbox.com', 'mailnesia.com',
        ];
        return in_array($domain, $blocked, true);
    }
}

if (!function_exists('isLikelyBot')) {
    /**
     * @param array<string, mixed>|null $headers
     */
    function isLikelyBot(string $userAgent, ?array $headers = null): bool
    {
        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return true;
        }
        foreach (['curl/', 'python-requests', 'scrapy', 'httpclient', 'wget/', 'bot', 'spider', 'crawler'] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }
        if ($headers !== null) {
            $hasAccept = false;
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === 'accept' && trim((string) $value) !== '') {
                    $hasAccept = true;
                    break;
                }
            }
            if (!$hasAccept) {
                return true;
            }
        }
        return false;
    }
}

if (!class_exists('AdminBruteProtect')) {
    /**
     * Simple file-based lockout for admin API endpoints (manage-coupons, reconcile, etc.).
     */
    class AdminBruteProtect
    {
        private string $dir;
        private int $maxAttempts = 8;
        private int $windowSeconds = 900;

        public function __construct()
        {
            $this->dir = defined('RATE_LIMIT_DIR') ? RATE_LIMIT_DIR : (dirname(__DIR__) . '/rate-limits/');
            if (!is_dir($this->dir)) {
                @mkdir($this->dir, 0755, true);
            }
        }

        private function pathFor(string $ip): string
        {
            $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $ip) ?: 'unknown';
            return rtrim($this->dir, '/\\') . DIRECTORY_SEPARATOR . 'admin_bf_' . $safe . '.json';
        }

        /** @return list<int> */
        private function readAttempts(string $ip): array
        {
            $path = $this->pathFor($ip);
            if (!is_readable($path)) {
                return [];
            }
            $raw = @file_get_contents($path);
            $data = $raw ? json_decode($raw, true) : null;
            if (!is_array($data) || !isset($data['attempts']) || !is_array($data['attempts'])) {
                return [];
            }
            $cutoff = time() - $this->windowSeconds;
            return array_values(array_filter(
                array_map('intval', $data['attempts']),
                static fn(int $t): bool => $t >= $cutoff
            ));
        }

        private function writeAttempts(string $ip, array $attempts): void
        {
            $path = $this->pathFor($ip);
            @file_put_contents(
                $path,
                json_encode(['attempts' => array_values($attempts)], JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
        }

        public function check(string $ip): bool
        {
            return count($this->readAttempts($ip)) < $this->maxAttempts;
        }

        public function record(string $ip): void
        {
            $attempts = $this->readAttempts($ip);
            $attempts[] = time();
            $this->writeAttempts($ip, $attempts);
        }
    }
}
