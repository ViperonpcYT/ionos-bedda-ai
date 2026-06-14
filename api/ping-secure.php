<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/secure-config.php';

echo json_encode([
    'ok' => true,
    'probe' => 'ping-secure',
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
