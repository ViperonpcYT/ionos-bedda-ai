<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'ok' => true,
    'probe' => 'ping',
    'php' => PHP_VERSION,
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
