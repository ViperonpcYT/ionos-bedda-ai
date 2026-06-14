<?php
/**
 * Ionos diagnostic — upload to api/diag.php
 * Does NOT load bootstrap or secure-config.
 */
header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);

echo "BEDDA DIAG\n";
echo "time=" . gmdate('c') . "\n";
echo "php=" . PHP_VERSION . "\n";
echo "sapi=" . php_sapi_name() . "\n";
echo "dir=" . __DIR__ . "\n";
echo "env_readable=" . (is_readable(__DIR__ . '/.env') ? 'yes' : 'no') . "\n";
echo "bootstrap_readable=" . (is_readable(__DIR__ . '/lib/bootstrap.php') ? 'yes' : 'no') . "\n";
echo "htaccess_readable=" . (is_readable(__DIR__ . '/.htaccess') ? 'yes' : 'no') . "\n";

if (is_readable(__DIR__ . '/.htaccess')) {
    echo "--- .htaccess (first 500 bytes) ---\n";
    echo substr((string) file_get_contents(__DIR__ . '/.htaccess'), 0, 500);
    echo "\n--- end ---\n";
}

if (is_readable(__DIR__ . '/lib/bootstrap.php')) {
    echo "bootstrap_load=";
    try {
        require_once __DIR__ . '/lib/bootstrap.php';
        echo "ok\n";
        echo "db_configured=" . (bedda_db_configured() ? 'yes' : 'no') . "\n";
    } catch (Throwable $e) {
        echo "FAIL\n";
        echo "error=" . $e->getMessage() . "\n";
        echo "file=" . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}
