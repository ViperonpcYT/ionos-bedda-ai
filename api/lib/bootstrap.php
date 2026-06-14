<?php
/**
 * Shared Bedda API bootstrap — config, JSON responses, database.
 */
declare(strict_types=1);

const BEDDA_API_VERSION = '1.1.0';

function bedda_send_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bedda_load_dotenv(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $envFile = dirname(__DIR__) . '/.env';
    if (!is_readable($envFile)) {
        return;
    }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

function bedda_env(string $key, string $default = ''): string
{
    $val = getenv($key);
    return ($val !== false && $val !== '') ? (string) $val : $default;
}

function bedda_config_from_env(): array
{
    return [
        'DB_DRIVER' => strtolower(bedda_env('DB_DRIVER', 'mysql')),
        'DB_HOST' => bedda_env('DB_HOST', bedda_env('ANALYTICS_DB_HOST', bedda_env('ORDERS_DB_HOST'))),
        'DB_PORT' => bedda_env('DB_PORT', bedda_env('ANALYTICS_DB_PORT', bedda_env('ORDERS_DB_PORT', '3306'))),
        'DB_NAME' => bedda_env('DB_NAME', bedda_env('ANALYTICS_DB_NAME', bedda_env('ORDERS_DB_NAME'))),
        'DB_USER' => bedda_env('DB_USER', bedda_env('ANALYTICS_DB_USER', bedda_env('ORDERS_DB_USER'))),
        'DB_PASS' => bedda_env('DB_PASS', bedda_env('ANALYTICS_DB_PASS', bedda_env('ORDERS_DB_PASS'))),
        'DB_CHARSET' => bedda_env('DB_CHARSET', 'utf8mb4'),
        'STRIPE_SECRET_KEY' => bedda_env('STRIPE_SECRET_KEY'),
        'STRIPE_WEBHOOK_SECRET' => bedda_env('STRIPE_WEBHOOK_SECRET', bedda_env('WEBHOOK_KEY')),
        'HCAPTCHA_SECRET' => bedda_env('HCAPTCHA_SECRET', bedda_env('CAPTCHA_SECRET_KEY')),
        'MAIL_FROM' => bedda_env('MAIL_FROM', bedda_env('SMTP_FROM_EMAIL', 'support@onlybikes.shop')),
        'SITE_URL' => bedda_env('SITE_URL', bedda_env('SITE_ORIGIN', 'https://onlybikes.shop')),
    ];
}

function bedda_load_config(): ?array
{
    static $config = null;
    if ($config !== null) {
        return $config === false ? null : $config;
    }

    $apiDir = dirname(__DIR__);

    // Array-only config files (never load secure-config.php — avoids jsonResponse redeclare with old customer-auth.php on server)
    foreach ([$apiDir . '/config.local.php', $apiDir . '/secure-config.test.php'] as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $loaded = require $path;
        if (is_array($loaded) && bedda_db_configured($loaded)) {
            $config = $loaded;
            return $config;
        }
    }

    bedda_load_dotenv();
    $fromEnv = bedda_config_from_env();
    if (bedda_db_configured($fromEnv)) {
        $config = $fromEnv;
        return $config;
    }

    $config = false;
    return null;
}

function bedda_db_driver(?array $config = null): string
{
    $config ??= bedda_load_config();
    return strtolower((string) ($config['DB_DRIVER'] ?? 'mysql'));
}

function bedda_db_configured(?array $config = null): bool
{
    $config ??= bedda_load_config();
    if (!$config) {
        return false;
    }

    if (bedda_db_driver($config) === 'sqlite') {
        $path = trim((string) ($config['DB_PATH'] ?? ''));
        return $path !== '';
    }

    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
        $value = trim((string) ($config[$key] ?? ''));
        if ($value === '' || strpos($value, 'YOUR_') === 0) {
            return false;
        }
    }

    return true;
}

function bedda_db_unavailable(): void
{
    bedda_send_json([
        'success' => false,
        'message' => 'DB config missing',
    ], 503);
}

/** @return PDO */
function bedda_connect_pdo(?array $config = null): PDO
{
    $config ??= bedda_load_config();
    if (!$config) {
        throw new PDOException('No database config');
    }

    if (bedda_db_driver($config) === 'sqlite') {
        $path = $config['DB_PATH'];
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    $charset = $config['DB_CHARSET'] ?? 'utf8mb4';
    $port = trim((string) ($config['DB_PORT'] ?? '3306'));
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['DB_HOST'],
        $port,
        $config['DB_NAME'],
        $charset
    );
    return new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** Orders/inventory DB config — products + stock live in ORDERS_DB_* */
function bedda_orders_db_config(): array
{
    bedda_load_dotenv();

    return [
        'DB_DRIVER' => 'mysql',
        'DB_HOST' => bedda_env('ORDERS_DB_HOST', bedda_env('DB_HOST')),
        'DB_PORT' => bedda_env('ORDERS_DB_PORT', bedda_env('DB_PORT', '3306')),
        'DB_NAME' => bedda_env('ORDERS_DB_NAME', bedda_env('DB_NAME')),
        'DB_USER' => bedda_env('ORDERS_DB_USER', bedda_env('DB_USER')),
        'DB_PASS' => bedda_env('ORDERS_DB_PASS', bedda_env('DB_PASS')),
        'DB_CHARSET' => bedda_env('DB_CHARSET', 'utf8mb4'),
    ];
}

/** @return PDO */
function bedda_pdo_orders(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = bedda_orders_db_config();
    if (!bedda_db_configured($config)) {
        bedda_db_unavailable();
    }

    try {
        $pdo = bedda_connect_pdo($config);
    } catch (PDOException $e) {
        error_log('Bedda orders DB connection failed: ' . $e->getMessage());
        bedda_send_json([
            'success' => false,
            'message' => 'Database connection failed',
        ], 503);
    }

    return $pdo;
}

/** @return PDO */
function bedda_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!bedda_db_configured()) {
        bedda_db_unavailable();
    }

    try {
        $pdo = bedda_connect_pdo();
    } catch (PDOException $e) {
        error_log('Bedda DB connection failed: ' . $e->getMessage());
        bedda_send_json([
            'success' => false,
            'message' => 'Database connection failed',
        ], 503);
    }

    return $pdo;
}

/** UserClicks / analytics DB (events table) */
function bedda_analytics_db_config(): array
{
    bedda_load_dotenv();

    return [
        'DB_DRIVER' => 'mysql',
        'DB_HOST' => bedda_env('ANALYTICS_DB_HOST', bedda_env('DB_HOST')),
        'DB_PORT' => bedda_env('ANALYTICS_DB_PORT', bedda_env('DB_PORT', '3306')),
        'DB_NAME' => bedda_env('ANALYTICS_DB_NAME', bedda_env('DB_NAME')),
        'DB_USER' => bedda_env('ANALYTICS_DB_USER', bedda_env('DB_USER')),
        'DB_PASS' => bedda_env('ANALYTICS_DB_PASS', bedda_env('DB_PASS')),
        'DB_CHARSET' => bedda_env('DB_CHARSET', 'utf8mb4'),
    ];
}

/** Analytics / logging — never HTTP 503; returns null if DB unavailable */
function bedda_pdo_optional(): ?PDO
{
    static $pdo = null;
    static $failed = false;
    if ($failed) {
        return null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (function_exists('getAnalyticsDatabase')) {
        try {
            $pdo = getAnalyticsDatabase();
            return $pdo;
        } catch (Throwable $e) {
            error_log('OnlyBikes analytics DB failed: ' . $e->getMessage());
            $failed = true;
            return null;
        }
    }

    $config = bedda_analytics_db_config();
    if (!bedda_db_configured($config)) {
        return null;
    }
    try {
        $pdo = bedda_connect_pdo($config);
        return $pdo;
    } catch (PDOException $e) {
        error_log('OnlyBikes analytics DB connection failed: ' . $e->getMessage());
        $failed = true;
        return null;
    }
}

function bedda_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function bedda_ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/** @return array<string, mixed>|null */
function bedda_customer_row_to_api(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'email' => $row['email'],
        'first_name' => $row['first_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'points' => (int) ($row['points'] ?? 0),
    ];
}

function bedda_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (bedda_db_driver() === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                first_name TEXT NOT NULL DEFAULT '',
                last_name TEXT NOT NULL DEFAULT '',
                points INTEGER NOT NULL DEFAULT 0,
                reset_code TEXT NULL,
                reset_expires_at TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS analytics_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                session_id TEXT NULL,
                user_id TEXT NULL,
                page TEXT NULL,
                payload TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS customers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NOT NULL DEFAULT '',
                last_name VARCHAR(100) NOT NULL DEFAULT '',
                points INT NOT NULL DEFAULT 0,
                reset_code VARCHAR(10) NULL,
                reset_expires_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS analytics_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(64) NOT NULL,
                session_id VARCHAR(128) NULL,
                user_id VARCHAR(128) NULL,
                page VARCHAR(512) NULL,
                payload JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_type (event_type),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    $done = true;
}

function bedda_cors_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
        exit;
    }
}
