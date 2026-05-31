<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

bedda_cors_preflight();
bedda_ensure_session();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$body = [];

if ($method === 'POST') {
    $body = bedda_read_json_body();
    $action = (string) ($body['action'] ?? $action);
}

if (!bedda_db_configured()) {
    bedda_db_unavailable();
}

$pdo = bedda_pdo();
bedda_ensure_schema($pdo);

switch ($action) {
    case 'me':
        handle_me($pdo);
        break;
    case 'logout':
        handle_logout();
        break;
    case 'login':
        if ($method !== 'POST') {
            bedda_send_json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        handle_login($pdo, $body);
        break;
    case 'register':
        if ($method !== 'POST') {
            bedda_send_json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        handle_register($pdo, $body);
        break;
    case 'forgot':
        if ($method !== 'POST') {
            bedda_send_json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        handle_forgot($pdo, $body);
        break;
    case 'reset-password':
        if ($method !== 'POST') {
            bedda_send_json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        handle_reset_password($pdo, $body);
        break;
    default:
        bedda_send_json(['success' => false, 'message' => 'Unknown action'], 400);
}

function handle_me(PDO $pdo): void
{
    $customerId = $_SESSION['customer_id'] ?? null;
    if (!$customerId) {
        bedda_send_json(['success' => false, 'message' => 'Not authenticated'], 401);
    }

    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, points FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $customerId]);
    $row = $stmt->fetch();

    if (!$row) {
        unset($_SESSION['customer_id']);
        bedda_send_json(['success' => false, 'message' => 'Not authenticated'], 401);
    }

    bedda_send_json(['success' => true, 'data' => bedda_customer_row_to_api($row)]);
}

function handle_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    bedda_send_json(['success' => true]);
}

function handle_login(PDO $pdo, array $body): void
{
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');

    if ($email === '' || $password === '') {
        bedda_send_json(['success' => false, 'message' => 'Email and password are required'], 400);
    }

    $stmt = $pdo->prepare('SELECT id, email, first_name, last_name, points, password_hash FROM customers WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        bedda_send_json(['success' => false, 'message' => 'Invalid email or password'], 401);
    }

    $_SESSION['customer_id'] = (int) $row['id'];
    bedda_send_json(['success' => true, 'data' => bedda_customer_row_to_api($row)]);
}

function handle_register(PDO $pdo, array $body): void
{
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $firstName = trim((string) ($body['first_name'] ?? ''));
    $lastName = trim((string) ($body['last_name'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        bedda_send_json(['success' => false, 'message' => 'Valid email is required'], 400);
    }
    if (strlen($password) < 8) {
        bedda_send_json(['success' => false, 'message' => 'Password must be at least 8 characters'], 400);
    }

    $check = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) {
        bedda_send_json(['success' => false, 'message' => 'An account with this email already exists'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare(
        'INSERT INTO customers (email, password_hash, first_name, last_name, points) VALUES (?, ?, ?, ?, 0)'
    );
    $insert->execute([$email, $hash, $firstName, $lastName]);
    $id = (int) $pdo->lastInsertId();

    $_SESSION['customer_id'] = $id;
    bedda_send_json([
        'success' => true,
        'data' => [
            'id' => $id,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'points' => 0,
        ],
    ], 201);
}

function handle_forgot(PDO $pdo, array $body): void
{
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    if ($email === '') {
        bedda_send_json(['success' => false, 'message' => 'Email is required'], 400);
    }

    // Always return success to avoid email enumeration
    $stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if ($row) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $update = $pdo->prepare('UPDATE customers SET reset_code = ?, reset_expires_at = ? WHERE id = ?');
        $update->execute([$code, $expires, (int) $row['id']]);
        // Email delivery would be wired here using MAIL_FROM in secure-config.php
        error_log("Bedda password reset code for {$email}: {$code}");
    }

    bedda_send_json(['success' => true, 'message' => 'If that email exists, a reset code was sent.']);
}

function handle_reset_password(PDO $pdo, array $body): void
{
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $code = preg_replace('/\D/', '', (string) ($body['code'] ?? ''));
    $newPassword = (string) ($body['new_password'] ?? '');

    if ($email === '' || strlen($code) !== 6) {
        bedda_send_json(['success' => false, 'message' => 'Invalid reset request'], 400);
    }
    if (strlen($newPassword) < 8) {
        bedda_send_json(['success' => false, 'message' => 'Password must be at least 8 characters'], 400);
    }

    $stmt = $pdo->prepare(
        'SELECT id, reset_code, reset_expires_at FROM customers WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || ($row['reset_code'] ?? '') !== $code) {
        bedda_send_json(['success' => false, 'message' => 'Invalid or expired code'], 400);
    }

    $expires = $row['reset_expires_at'] ?? null;
    if ($expires && strtotime((string) $expires) < time()) {
        bedda_send_json(['success' => false, 'message' => 'Invalid or expired code'], 400);
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $pdo->prepare(
        'UPDATE customers SET password_hash = ?, reset_code = NULL, reset_expires_at = NULL WHERE id = ?'
    );
    $update->execute([$hash, (int) $row['id']]);

    bedda_send_json(['success' => true, 'message' => 'Password updated. You can sign in now.']);
}
