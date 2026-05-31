<?php
/**
 * Local API testing only — copied to secure-config.php by scripts/test-api.sh
 * Do not deploy to production.
 */
declare(strict_types=1);

return [
    'DB_DRIVER' => 'sqlite',
    'DB_PATH' => __DIR__ . '/data/test-bedda.sqlite',
    'SITE_URL' => 'http://127.0.0.1:8765',
    'MAIL_FROM' => 'orders@bedda.ca',
];
