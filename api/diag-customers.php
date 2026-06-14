<?php
/**
 * Customer DB diagnostic — open once, then delete from server.
 * https://onlybikes.shop/api/diag-customers.php
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$out = [
    'ok' => false,
    'env_file' => is_readable(__DIR__ . '/.env'),
    'secure_config' => is_readable(__DIR__ . '/secure-config.php'),
    'customers_schema_lib' => is_readable(__DIR__ . '/lib/customers-schema.php'),
];

try {
    require_once __DIR__ . '/lib/security-helpers.php';
    require_once __DIR__ . '/lib/customers-schema.php';
    require_once __DIR__ . '/secure-config.php';

    $out['has_getCustomersDatabase'] = function_exists('getCustomersDatabase');
    $out['CUSTOMERS_DB_HOST'] = onlybikes_env('CUSTOMERS_DB_HOST', '(empty)');
    $out['CUSTOMERS_DB_NAME'] = onlybikes_env('CUSTOMERS_DB_NAME', '(empty)');
    $out['CUSTOMERS_DB_USER'] = onlybikes_env('CUSTOMERS_DB_USER', '(empty)');
    $out['CUSTOMERS_DB_PASS_set'] = onlybikes_env('CUSTOMERS_DB_PASS') !== '';

    if (!function_exists('getCustomersDatabase')) {
        $out['error'] = 'getCustomersDatabase() missing — re-upload secure-config.php';
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo = getCustomersDatabase();
    $out['connected_database'] = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $out['customers_table'] = onlybikes_customers_table_exists($pdo);

    if ($out['customers_table']) {
        $out['columns'] = array_keys(onlybikes_customers_columns($pdo));
        try {
            onlybikes_ensure_customers_schema($pdo);
            $out['schema_ok'] = true;
            $out['columns_after'] = array_keys(onlybikes_customers_columns($pdo, true));
            $out['points_column'] = onlybikes_customers_points_column($pdo);
            $out['has_password_hash'] = onlybikes_customers_has_column($pdo, 'password_hash');
        } catch (Throwable $schemaEx) {
            $out['schema_ok'] = false;
            $out['schema_error'] = $schemaEx->getMessage();
        }
    }

    $out['ok'] = ($out['connected_database'] === 'dbs15747049') && !empty($out['has_password_hash']);
    $out['hint'] = $out['ok']
        ? 'Auth should work. Test register/login. Delete this file.'
        : 'Fix CUSTOMERS_DB_NAME in api/.env (must be dbs15747049) and re-upload .env + lib/customers-schema.php';
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
    $out['hint'] = 'Upload api/.env with CUSTOMERS_DB_NAME=dbs15747049 and api/secure-config.php';
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
