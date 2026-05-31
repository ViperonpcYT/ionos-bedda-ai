<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

$config = bedda_load_config();
$dbOk = bedda_db_configured($config);

bedda_send_json([
    'ok' => true,
    'api_version' => BEDDA_API_VERSION,
    'php' => PHP_VERSION,
    'db_configured' => $dbOk,
    'time' => gmdate('c'),
]);
