<?php
/**
 * Chit Chats config probe — upload to api/test-chitchats-config.php, open once in browser, then delete.
 * Does NOT expose tokens. Optional: ?run=1 to hit Chit Chats API with a test address.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/secure-config.php';
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'secure-config: ' . $e->getMessage()]);
    exit;
}

require_once __DIR__ . '/lib/chitchats-config.php';

$runApi = isset($_GET['run']) && $_GET['run'] === '1';

if ($runApi) {
    echo json_encode(onlybikes_chitchats_test_quote(), JSON_PRETTY_PRINT);
    exit;
}

echo json_encode([
    'ok' => onlybikes_chitchats_configured(),
    'hint' => onlybikes_chitchats_configured()
        ? 'Config OK. Add ?run=1 to test a live quote against Chit Chats.'
        : 'Add CHITCHATS_CLIENT_ID and CHITCHATS_ACCESS_TOKEN to api/.env on Ionos, then reload.',
    'status' => onlybikes_chitchats_config_status(),
], JSON_PRETTY_PRINT);
