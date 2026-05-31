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

function bedda_load_config(): ?array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $apiDir = dirname(__DIR__);
    $candidates = [
        $apiDir . '/secure-config.php',
        $apiDir . '/config.local.php',
    ];

    foreach ($candidates as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $loaded = require $path;
        if (is_array($loaded)) {
            $config = $loaded;
            return $config;
        }
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
        if ($value === '' || str_starts_with($value, 'YOUR_')) {
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
function bedda_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = bedda_load_config();
    if (!$config || !bedda_db_configured($config)) {
        bedda_db_unavailable();
    }

    try {
        if (bedda_db_driver($config) === 'sqlite') {
            $path = $config['DB_PATH'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            $charset = $config['DB_CHARSET'] ?? 'utf8mb4';
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $config['DB_HOST'],
                $config['DB_NAME'],
                $charset
            );
            $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
    } catch (PDOException $e) {
        error_log('Bedda DB connection failed: ' . $e->getMessage());
        bedda_send_json([
            'success' => false,
            'message' => 'Database connection failed',
        ], 503);
    }

    return $pdo;
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
