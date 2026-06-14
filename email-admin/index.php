<?php
require_once __DIR__ . '/config.php'; // defines constants and loads NewsLetterConfig
session_name(EMAIL_ADMIN_SESSION);
session_start();

if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders('text/html; charset=utf-8', true);
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

const ADMIN_LOGIN_MAX_ATTEMPTS = 5;
const ADMIN_LOGIN_WINDOW_SECONDS = 900;

function adminLoginRateFile(string $ip): string {
    $dir = __DIR__ . '/../api/rate-limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir . '/email-admin-login-' . md5($ip) . '.json';
}

function adminLoginAttempts(string $ip): array {
    $file = adminLoginRateFile($ip);
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data)) {
        return [];
    }
    $cutoff = time() - ADMIN_LOGIN_WINDOW_SECONDS;
    return array_values(array_filter($data, static function ($attempt) use ($cutoff) {
        return is_int($attempt) && $attempt >= $cutoff;
    }));
}

function adminLoginBlocked(string $ip): bool {
    return count(adminLoginAttempts($ip)) >= ADMIN_LOGIN_MAX_ATTEMPTS;
}

function recordAdminLoginFailure(string $ip): void {
    $attempts = adminLoginAttempts($ip);
    $attempts[] = time();
    @file_put_contents(adminLoginRateFile($ip), json_encode(array_values($attempts)), LOCK_EX);
}

function clearAdminLoginFailures(string $ip): void {
    $file = adminLoginRateFile($ip);
    if (file_exists($file)) {
        @unlink($file);
    }
}

// ---------- Handle Logout ----------
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = array();

    // Destroy the session
    session_destroy();

    // Expire session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Redirect to login
    header('Location: index.php');
    exit;
}


// ---------- IP & Secret Key Check ----------
$clientIP = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$ipAllowed = empty($IP_WHITELIST) ? false : in_array($clientIP, $IP_WHITELIST);
$requiresAccessKey = !$ipAllowed && !isset($_SESSION['ip_bypassed']);
$gateError = '';

if ($requiresAccessKey) {
    $providedKey = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $providedKey = trim((string) ($_POST['access_key'] ?? ''));
    } elseif (isset($_GET['key'])) {
        // Prevent secret leakage in URLs and Referer headers.
        $gateError = 'Access keys in URL are disabled. Enter the key in the form below.';
        logSecurityEvent('email_admin_url_key_rejected', ['ip' => $clientIP]);
    }

    if ($providedKey !== '') {
        if (password_verify($providedKey, SECRET_KEY_HASH)) {
            $_SESSION['ip_bypassed'] = true;
            header('Location: index.php');
            exit;
        }
        $gateError = 'Invalid access key.';
        logSecurityEvent('email_admin_bad_access_key', ['ip' => $clientIP]);
    }
}

// ---------- If Already Logged In, Redirect to Dashboard ----------
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

// ---------- Password Form ----------
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requiresAccessKey) {
        // Render access key step below.
    } elseif (adminLoginBlocked($clientIP)) {
        $error = 'Too many login attempts. Try again in 15 minutes.';
        logSecurityEvent('email_admin_rate_limited', ['ip' => $clientIP]);
    } else {
        $password = $_POST['password'] ?? '';

        if (password_verify($password, ADMIN_PASSWORD_HASH)) {

            // Prevent session fixation
            session_regenerate_id(true);

            // Use consistent session variable across all admin pages
            $_SESSION['logged_in'] = true;
            clearAdminLoginFailures($clientIP);

            header('Location: dashboard.php');
            exit;
        } else {
            recordAdminLoginFailure($clientIP);
            $remaining = max(0, ADMIN_LOGIN_MAX_ATTEMPTS - count(adminLoginAttempts($clientIP)));
            $error = $remaining > 0
                ? 'Incorrect password. ' . $remaining . ' attempt(s) left before a 15-minute lockout.'
                : 'Incorrect password.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>OnlyBikes Email Admin - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-100 flex items-center justify-center min-h-screen">
    <?php if ($requiresAccessKey): ?>
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h1 class="text-2xl font-bold mb-6 text-stone-800">Admin Access Key Required</h1>
        <?php if ($gateError): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= htmlspecialchars($gateError) ?></div>
        <?php endif; ?>
        <form method="POST">
            <label class="block text-sm font-medium mb-2">Access Key</label>
            <input type="password" name="access_key" class="w-full border border-stone-300 rounded px-3 py-2 mb-4" required autofocus>
            <button type="submit" class="w-full bg-sage-600 text-white py-2 rounded hover:bg-sage-700">Continue</button>
        </form>
        <p class="text-xs text-stone-500 mt-4">Your IP: <?= htmlspecialchars($clientIP) ?></p>
    </div>
    <?php else: ?>
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h1 class="text-2xl font-bold mb-6 text-stone-800">Email Admin Login</h1>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <label class="block text-sm font-medium mb-2">Password</label>
            <input type="password" name="password" class="w-full border border-stone-300 rounded px-3 py-2 mb-4" required autofocus>
            <button type="submit" class="w-full bg-sage-600 text-white py-2 rounded hover:bg-sage-700">Login</button>
        </form>
        <div class="mt-6 pt-6 border-t text-xs text-stone-500">
            <p><strong>Security Status:</strong></p>
            <p>Your IP: <?= htmlspecialchars($clientIP) ?></p>
            <p>Whitelisted: <?= $ipAllowed ? '✅ Yes' : '❌ No (using secret key)' ?></p>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
