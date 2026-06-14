<?php
/**
 * Analytics DB (UserClicks) — get-analytics.php, get-summary.php, queue/dashboard.php.
 * Set ANALYTICS_DB_* in api/.env (database dbs15092315).
 */
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'message' => 'Access denied']));
}

if (!function_exists('onlybikes_analytics_load_env')) {
    function onlybikes_analytics_load_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;
        $envFile = __DIR__ . '/.env';
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
}

onlybikes_analytics_load_env();

// Load enterprise DB helpers before defining getDatabase() — secure-config owns getDatabase() (newsletter).
if (!function_exists('getAnalyticsDatabase') && is_readable(__DIR__ . '/secure-config.php')) {
    require_once __DIR__ . '/secure-config.php';
}

function onlybikes_analytics_env(string $key, string $default = ''): string
{
    $val = getenv($key);
    return ($val !== false && $val !== '') ? (string) $val : $default;
}

define('DB_HOST', onlybikes_analytics_env('ANALYTICS_DB_HOST', onlybikes_analytics_env('DB_HOST')));
define('DB_PORT', onlybikes_analytics_env('ANALYTICS_DB_PORT', onlybikes_analytics_env('DB_PORT', '3306')));
define('DB_NAME', onlybikes_analytics_env('ANALYTICS_DB_NAME', onlybikes_analytics_env('DB_NAME')));
define('DB_USER', onlybikes_analytics_env('ANALYTICS_DB_USER', onlybikes_analytics_env('DB_USER')));
define('DB_PASS', onlybikes_analytics_env('ANALYTICS_DB_PASS', onlybikes_analytics_env('DB_PASS')));

/** Analytics only — UserClicks DB (same as log-event.php). */
function getAnalyticsDatabaseFromConfig(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (function_exists('getAnalyticsDatabase')) {
        $pdo = getAnalyticsDatabase();
        return $pdo;
    }
    if (DB_HOST === '' || DB_NAME === '' || DB_USER === '') {
        throw new PDOException('ANALYTICS_DB_* not set in api/.env');
    }
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

if (!function_exists('getDatabase')) {
    function getDatabase(): PDO
    {
        return getAnalyticsDatabaseFromConfig();
    }
}
