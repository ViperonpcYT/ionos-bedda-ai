<?php
/**
 * Diagnose customer auth (upload, open once, delete).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$out = ['ok' => false, 'checks' => []];

$out['checks']['security_helpers'] = is_readable(__DIR__ . '/lib/security-helpers.php');
$out['checks']['secure_config'] = is_readable(__DIR__ . '/secure-config.php');
$out['checks']['env'] = is_readable(__DIR__ . '/.env');

try {
    require_once __DIR__ . '/lib/security-helpers.php';
require_once __DIR__ . '/lib/customers-schema.php';
    require_once __DIR__ . '/secure-config.php';
    $out['checks']['jsonResponse'] = function_exists('jsonResponse');
    $out['checks']['getCustomersDatabase'] = function_exists('getCustomersDatabase');
    if (function_exists('getCustomersDatabase')) {
        $pdo = getCustomersDatabase();
        $pdo->query('SELECT 1');
        $out['checks']['customers_db'] = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $tables = $pdo->query("SHOW TABLES LIKE 'customers'")->fetchAll(PDO::FETCH_COLUMN);
        $out['checks']['customers_table'] = !empty($tables);
        if (!empty($tables)) {
            onlybikes_ensure_customers_schema($pdo);
            $out['checks']['points_column'] = onlybikes_customers_points_column($pdo);
            $out['checks']['has_password_hash'] = onlybikes_customers_has_column($pdo, 'password_hash');
            $out['checks']['customer_count'] = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        }
    }
    $out['ok'] = true;
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
