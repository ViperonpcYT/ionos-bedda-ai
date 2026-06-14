<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$config = bedda_load_config();
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$expectedHealthKey = trim(bedda_env('HEALTHCHECK_KEY', ''));
$providedHealthKey = (string) ($_SERVER['HTTP_X_HEALTH_KEY'] ?? ($_GET['health_key'] ?? ''));
$isAuthorized = $isLocal
    || ($expectedHealthKey !== '' && $providedHealthKey !== '' && hash_equals($expectedHealthKey, $providedHealthKey));

// Keep the endpoint usable for uptime checks while avoiding recon leakage.
$response = [
    'ok' => true,
    'status' => 'healthy',
    'time' => gmdate('c'),
];

if ($isAuthorized) {
    $response['api_version'] = BEDDA_API_VERSION;
    $response['php'] = PHP_VERSION;
    $response['db_configured'] = bedda_db_configured($config);
}

bedda_send_json($response);
