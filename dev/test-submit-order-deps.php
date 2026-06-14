<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/security-helpers.php';

$requiredFns = ['logOrder', 'beddaAllowedHosts', 'onlybikes_handling_cost', 'onlybikes_support_email'];
$requiredConsts = [
    'ORDER_HONEYPOT_ENABLED',
    'ORDER_MIN_TIME_SECONDS',
    'ORDER_MAX_TIME_SECONDS',
    'COUPON_RATE_LIMIT_WINDOW',
];

foreach ($requiredFns as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "FAIL missing function: {$fn}\n");
        exit(1);
    }
}
foreach ($requiredConsts as $name) {
    if (!defined($name)) {
        fwrite(STDERR, "FAIL missing constant: {$name}\n");
        exit(1);
    }
}

$handling = onlybikes_handling_cost([
    ['quantity' => 1],
    ['quantity' => 1],
], 'shipping');
if ($handling !== 3.75 && $handling !== 4.25) {
    fwrite(STDERR, "FAIL unexpected handling cost: {$handling}\n");
    exit(1);
}

echo "OK submit-order dependencies\n";
echo 'support=' . onlybikes_support_email() . "\n";
echo 'handling_2_items=' . $handling . "\n";
