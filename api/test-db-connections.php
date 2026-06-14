<?php
/**
 * Upload to IONOS and open once in browser (then delete or protect).
 * Tests all five enterprise databases from api/.env
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/secure-config.php';

$checks = [
    'UserClicks' => 'getAnalyticsDatabase',
    'Orders' => 'getOrderDatabase',
    'Newsletter' => 'getNewsletterDatabase',
    'Coupons' => 'getCouponDatabase',
    'Customers' => 'getCustomersDatabase',
];

foreach ($checks as $label => $fn) {
    if (!function_exists($fn)) {
        echo "FAIL {$label}: function {$fn} missing\n";
        continue;
    }
    try {
        $pdo = $fn();
        $pdo->query('SELECT 1');
        $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
        echo "OK   {$label} → {$db}\n";
    } catch (Throwable $e) {
        echo "FAIL {$label}: " . $e->getMessage() . "\n";
    }
}
