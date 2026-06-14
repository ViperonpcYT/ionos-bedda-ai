<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/customer-auth-errors.log');

try {
    require_once __DIR__ . '/secure-config.php';
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Auth service configuration error.',
        'error' => 'secure-config',
    ]);
    exit;
}

require_once __DIR__ . '/lib/security-helpers.php';
require_once __DIR__ . '/lib/customers-schema.php';

$siteOrigin = function_exists('cfg')
    ? rtrim(cfg('SITE_ORIGIN', 'https://onlybikes.shop'), '/')
    : rtrim((string) getenv('SITE_ORIGIN'), '/');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . ($siteOrigin ?: '*'));
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders();
}

function enforceAuthRateLimit(string $action): void {
    $limitedActions = ['login', 'forgot', 'reset-password'];
    if (!in_array($action, $limitedActions, true)) {
        return;
    }

    $dir = defined('RATE_LIMIT_DIR') ? RATE_LIMIT_DIR : __DIR__ . '/rate-limits/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = $dir . 'customer-auth-' . md5($ip . '|' . $action) . '.json';
    $now = time();
    $window = 15 * 60;
    $maxAttempts = 8;
    $data = ['count' => 0, 'first_attempt' => $now];

    if (is_readable($file)) {
        $stored = json_decode((string) @file_get_contents($file), true);
        if (is_array($stored) && ($now - (int) ($stored['first_attempt'] ?? 0)) <= $window) {
            $data = $stored;
        }
    }

    if ((int) ($data['count'] ?? 0) >= $maxAttempts) {
        jsonResponse(false, 'Too many attempts. Please try again in 15 minutes.', [], 429);
    }

    $data['count'] = (int) ($data['count'] ?? 0) + 1;
    $data['first_attempt'] = (int) ($data['first_attempt'] ?? $now);
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function clearAuthRateLimit(string $action): void {
    $dir = defined('RATE_LIMIT_DIR') ? RATE_LIMIT_DIR : __DIR__ . '/rate-limits/';
    $ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = $dir . 'customer-auth-' . md5($ip . '|' . $action) . '.json';
    if (is_file($file)) {
        @unlink($file);
    }
}

// Start secure session
session_set_cookie_params([
    'lifetime' => 86400 * 30,
    'path' => '/',
    'domain' => '',
    'secure' => beddaSessionCookieSecure(),
    'httponly' => true,
    'samesite' => 'Strict'
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


$action = $_GET['action'] ?? '';

// For POST requests, action comes from the JSON body
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $postData = json_decode($rawInput, true);
    $postData = is_array($postData) ? $postData : [];
    $action = $postData['action'] ?? $action;
    enforceAuthRateLimit($action);
}

// Check if the customers database function exists (requires updated secure-config.php)
if (!function_exists('getCustomersDatabase')) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Customer accounts are temporarily unavailable. Please check back later.',
        'configured' => false,
        'error' => 'Database not configured'
    ]);
    exit;
}

try {
    $pdo = getCustomersDatabase();
} catch (Throwable $e) {
    error_log('[OnlyBikes] customer-auth connect: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Customer accounts are temporarily unavailable. Please check back later.',
        'configured' => false,
        'error' => 'database_connection',
        'hint' => 'Check api/.env CUSTOMERS_DB_NAME=dbs15747049 on server. Open /api/diag-customers.php once.',
    ]);
    exit;
}

try {
    onlybikes_ensure_customers_schema($pdo);
    $pointsCol = onlybikes_customers_points_column($pdo);
} catch (Throwable $e) {
    error_log('[OnlyBikes] customer-auth schema: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Customer accounts are temporarily unavailable. Please check back later.',
        'configured' => false,
        'error' => 'schema_migration',
        'hint' => 'Run api/sql/05b-migrate-customers-ionos-panel.sql in phpMyAdmin on dbs15747049.',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $postData ?? [];

    try {
    if ($action === 'register') {
        $email = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $password = $data['password'] ?? '';
        $firstName = sanitizeInput($data['first_name'] ?? $data['firstName'] ?? '', 'name');
        $lastName = sanitizeInput($data['last_name'] ?? $data['lastName'] ?? '', 'name');

        if (!$email || strlen($password) < 8) {
            jsonResponse(false, 'Valid email and password (min 8 chars) required.', [], 400);
        }

        $stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(false, 'Email already registered.', [], 400);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            "INSERT INTO customers (email, password_hash, first_name, last_name, {$pointsCol}) VALUES (?, ?, ?, ?, 0)"
        );

        if ($stmt->execute([$email, $hash, $firstName, $lastName])) {
            $userId = $pdo->lastInsertId();
            $_SESSION['customer_id'] = $userId;
            $_SESSION['customer_email'] = $email;

            $runtimeBal = ['pvp_credits' => 0, 'solo_credits' => 0];
            if (is_readable(__DIR__ . '/lib/runtime-credits.php')) {
                require_once __DIR__ . '/lib/runtime-credits.php';
                $bonus = runtime_credits_signup_bonus((int) $userId);
                if (!empty($bonus['pvp_credits']) || !empty($bonus['solo_credits'])) {
                    $runtimeBal = [
                        'pvp_credits' => (int) ($bonus['pvp_credits'] ?? 0),
                        'solo_credits' => (int) ($bonus['solo_credits'] ?? 0),
                    ];
                } elseif (!empty($bonus['already_granted'])) {
                    $runtimeBal = runtime_credits_get_balance(runtime_credits_pdo(), (int) $userId);
                }
            }

            jsonResponse(true, 'Account created successfully.', [
                'id'         => (int)$userId,
                'points'     => 0,
                'pvp_credits' => $runtimeBal['pvp_credits'],
                'solo_credits' => $runtimeBal['solo_credits'],
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'email'      => $email
            ]);
        } else {
            jsonResponse(false, 'Failed to create account.', [], 500);
        }
    } 
    elseif ($action === 'login') {
        $email = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $password = $data['password'] ?? '';

        $stmt = $pdo->prepare(
            "SELECT id, email, password_hash, {$pointsCol} AS points, first_name, last_name FROM customers WHERE email = ?"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && !empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
            $_SESSION['customer_id'] = $user['id'];
            $_SESSION['customer_email'] = $user['email'];
            clearAuthRateLimit('login');

            $runtimeBal = ['pvp_credits' => 0, 'solo_credits' => 0];
            if (is_readable(__DIR__ . '/lib/runtime-credits.php')) {
                require_once __DIR__ . '/lib/runtime-credits.php';
                try {
                    $runtimeBal = runtime_credits_get_balance(runtime_credits_pdo(), (int) $user['id']);
                } catch (Throwable $e) {
                    error_log('[OnlyBikes] login runtime balance: ' . $e->getMessage());
                }
            }

            jsonResponse(true, 'Logged in successfully.', [
                'id' => (int)$user['id'],
                'points' => onlybikes_customer_points_value($user),
                'pvp_credits' => $runtimeBal['pvp_credits'],
                'solo_credits' => $runtimeBal['solo_credits'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'email' => $user['email']
            ]);
        } else {
            jsonResponse(false, 'Invalid email or password.', [], 401);
        }
    }
    elseif ($action === 'forgot') {
        $email = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
        if (!$email) {
            jsonResponse(false, 'Please enter a valid email address.', [], 400);
        }
        $stmt = $pdo->prepare("SELECT id, first_name FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user) {
            jsonResponse(true, 'If an account exists with that email, a reset code has been sent.');
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_BCRYPT);
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $stmt = $pdo->prepare("UPDATE customers SET reset_token = ?, reset_expires = ? WHERE id = ?");
        $stmt->execute([$hash, $expires, $user['id']]);
        $subject = 'OnlyBikes Password Reset Code';
        $message = "Hi " . ($user['first_name'] ?: 'there') . ",\n\nYour password reset code is: $code\n\nThis code expires in 15 minutes.\nIf you didn't request this, you can safely ignore this email.\n\n- OnlyBikes\n";
        $from = defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : (getenv('SUPPORT_EMAIL') ?: 'support@onlybikes.shop');
        $headers = "From: {$from}\r\nReply-To: {$from}";
        @mail($email, $subject, $message, $headers);
        jsonResponse(true, 'If an account exists with that email, a reset code has been sent.');
    }
    elseif ($action === 'reset-password') {
        $email = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $code = preg_replace('/\D/', '', $data['code'] ?? '');
        $password = $data['new_password'] ?? '';
        if (!$email || strlen($code) !== 6 || strlen($password) < 8) {
            jsonResponse(false, 'Please provide a valid code and password (min 8 chars).', [], 400);
        }
        $stmt = $pdo->prepare("SELECT id, reset_token, reset_expires FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !$user['reset_token'] || strtotime($user['reset_expires']) < time()) {
            jsonResponse(false, 'Invalid or expired code.', [], 400);
        }
        if (!password_verify($code, $user['reset_token'])) {
            jsonResponse(false, 'Invalid code.', [], 400);
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE customers SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $stmt->execute([$hash, $user['id']]);
        jsonResponse(true, 'Password updated successfully. You can now log in.');
    }
    } catch (Throwable $e) {
        error_log('[OnlyBikes] customer-auth POST action=' . ($action ?? '') . ': ' . $e->getMessage());
        jsonResponse(false, 'Account action failed. Please try again.', ['error' => 'server_error'], 500);
    }
    jsonResponse(false, 'Invalid action.', [], 400);
}
elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'me') {
        if (!isset($_SESSION['customer_id'])) {
            jsonResponse(false, 'Not logged in.', [], 401);
        }
        
        $stmt = $pdo->prepare(
            "SELECT id, email, first_name, last_name, {$pointsCol} AS points FROM customers WHERE id = ?"
        );
        $stmt->execute([$_SESSION['customer_id']]);
        $user = $stmt->fetch();

        if ($user) {
            $user['points'] = onlybikes_customer_points_value($user);
            $user['pvp_credits'] = 0;
            $user['solo_credits'] = 0;
            if (is_readable(__DIR__ . '/lib/runtime-credits.php')) {
                require_once __DIR__ . '/lib/runtime-credits.php';
                try {
                    $rb = runtime_credits_get_balance(runtime_credits_pdo(), (int) $user['id']);
                    $user['pvp_credits'] = $rb['pvp_credits'];
                    $user['solo_credits'] = $rb['solo_credits'];
                } catch (Throwable $e) {
                    error_log('[OnlyBikes] me runtime balance: ' . $e->getMessage());
                }
            }
            jsonResponse(true, 'Authenticated', $user);
        } else {
            unset($_SESSION['customer_id']);
            jsonResponse(false, 'User not found.', [], 401);
        }
    }
    elseif ($action === 'logout') {
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        jsonResponse(true, 'Logged out successfully.');
    }
}

jsonResponse(false, 'Invalid action.', [], 400);
