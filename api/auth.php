<?php
/**
 * Bedda Authentication Module
 * Handles API key and session-based authentication
 * FIXED: Uses secure-config.php, no hardcoded passwords
 */

// Prevent direct access
if (!defined('BEDDA_SECURITY')) {
    define('BEDDA_SECURITY', true);
}

// Load secure configuration
require_once __DIR__ . '/secure-config.php';

// ============================================================================
// API KEY VERIFICATION
// ============================================================================

/**
 * Verify API key
 */
function verifyApiKey($apiKey) {
    global $VALID_API_KEYS;
    
    if (empty($apiKey)) {
        return false;
    }
    
    // Check against valid keys
    if (in_array($apiKey, $VALID_API_KEYS)) {
        return true;
    }
    
    // Log failed attempt
    logSecurityEvent('invalid_api_key', [
        'key_hash' => substr(md5($apiKey), 0, 8),
        'ip' => getClientIP()
    ]);
    
    return false;
}

// ============================================================================
// SESSION CONFIGURATION
// ============================================================================

/**
 * Initialize secure session
 */
function initSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        // Secure session settings
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_secure', 1);
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', 3600);
        
        session_start();
        
        // Regenerate session ID periodically
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }
}

// ============================================================================
// ADMIN PASSWORD AUTHENTICATION
// ============================================================================

/**
 * Verify admin password
 * NOTE: Set ADMIN_PASSWORD_HASH in .env file using:
 * php -r "echo password_hash('your_password', PASSWORD_BCRYPT);"
 */
function verifyAdminPassword($password) {
    $hash = ADMIN_PASSWORD_HASH;
    
    // If no hash is set, reject all passwords (secure default)
    if (empty($hash)) {
        logSecurityEvent('admin_login_failed_no_hash', [
            'ip' => getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        return false;
    }
    
    if (password_verify($password, $hash)) {
        initSecureSession();
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_login_time'] = time();
        $_SESSION['admin_ip'] = getClientIP();
        return true;
    }
    
    // Log failed login
    logSecurityEvent('admin_login_failed', [
        'ip' => getClientIP(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    
    return false;
}

/**
 * Check if admin is authenticated
 */
function isAdminAuthenticated() {
    initSecureSession();
    
    if (empty($_SESSION['admin_authenticated'])) {
        return false;
    }
    
    // Check session age (1 hour max)
    if (time() - ($_SESSION['admin_login_time'] ?? 0) > 3600) {
        logoutAdmin();
        return false;
    }
    
    // Check IP hasn't changed
    if ($_SESSION['admin_ip'] !== getClientIP()) {
        logoutAdmin();
        return false;
    }
    
    return true;
}

/**
 * Require admin authentication
 */
function requireAdminAuth() {
    if (!isAdminAuthenticated()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required']);
        exit();
    }
}

/**
 * Logout admin
 */
function logoutAdmin() {
    initSecureSession();
    session_destroy();
    $_SESSION = [];
}

// ============================================================================
// RATE LIMITING FOR AUTH
// ============================================================================

/**
 * Check auth rate limit
 */
function checkAuthRateLimit() {
    $ip = getClientIP();
    $rateFile = __DIR__ . '/rate-limits/auth-' . md5($ip) . '.json';
    
    $attempts = [];
    if (file_exists($rateFile)) {
        $attempts = json_decode(file_get_contents($rateFile), true) ?: [];
    }
    
    // Clean old attempts (older than 15 minutes)
    $fifteenMinAgo = time() - 900;
    $attempts = array_filter($attempts, function($t) use ($fifteenMinAgo) {
        return $t > $fifteenMinAgo;
    });
    
    // Max 5 failed attempts per 15 minutes
    if (count($attempts) >= 5) {
        return false;
    }
    
    return true;
}

/**
 * Record auth attempt
 */
function recordAuthAttempt() {
    $ip = getClientIP();
    $rateFile = __DIR__ . '/rate-limits/auth-' . md5($ip) . '.json';
    
    if (!is_dir(__DIR__ . '/rate-limits')) {
        mkdir(__DIR__ . '/rate-limits', 0755, true);
    }
    
    $attempts = [];
    if (file_exists($rateFile)) {
        $attempts = json_decode(file_get_contents($rateFile), true) ?: [];
    }
    
    $attempts[] = time();
    file_put_contents($rateFile, json_encode($attempts), LOCK_EX);
}

// ============================================================================
// CORS HELPERS
// ============================================================================

/**
 * Set CORS headers
 */
function setCorsHeaders($allowedOrigins = null) {
    if ($allowedOrigins === null) {
        $allowedOrigins = ['https://bedda.ca', 'https://www.bedda.ca'];
    }
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Allow-Credentials: true');
}

// ============================================================================
// SECURITY MIDDLEWARE
// ============================================================================

/**
 * Security middleware
 */
function securityMiddleware() {
    // Set security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    
    // Check for common attack patterns
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $suspiciousPatterns = [
        '/\.\./',
        '/<script/i',
        '/javascript:/i',
        '/union\s+select/i',
        '/;\s*drop\s+table/i',
    ];
    
    foreach ($suspiciousPatterns as $pattern) {
        if (preg_match($pattern, $uri)) {
            logSecurityEvent('suspicious_request_pattern', [
                'pattern' => $pattern,
                'uri' => $uri,
                'ip' => getClientIP()
            ]);
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

// Run security middleware
securityMiddleware();
