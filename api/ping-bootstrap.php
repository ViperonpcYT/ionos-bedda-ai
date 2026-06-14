<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/bootstrap.php';

$config = bedda_load_config();

echo json_encode([
    'ok' => true,
    'probe' => 'ping-bootstrap',
    'db_configured' => bedda_db_configured($config),
    'config_present' => is_array($config),
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
