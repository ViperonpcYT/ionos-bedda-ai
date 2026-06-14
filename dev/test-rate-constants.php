<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib/security-helpers.php';

$required = [
    'COUPON_RATE_LIMIT_WINDOW',
    'COUPON_RATE_LIMIT_PER_MINUTE',
    'RATE_LIMIT_ORDERS_PER_IP_PER_HOUR',
    'SPAM_BLOCK_THRESHOLD',
];

$missing = [];
foreach ($required as $name) {
    if (!defined($name)) {
        $missing[] = $name;
    }
}

if ($missing !== []) {
    fwrite(STDERR, 'FAIL missing: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

$limiterFile = __DIR__ . '/../api/rate-limiter.php';
$src = file_get_contents($limiterFile);
if ($src === false) {
    fwrite(STDERR, "FAIL could not read rate-limiter.php\n");
    exit(1);
}

// Ensure canValidateCoupon body references defined constants (static sanity check).
foreach (['COUPON_RATE_LIMIT_WINDOW', 'COUPON_RATE_LIMIT_PER_MINUTE'] as $name) {
    if (!str_contains($src, $name)) {
        fwrite(STDERR, "WARN $name not referenced in rate-limiter.php\n");
    }
}

echo "OK rate-limit constants defined\n";
echo 'COUPON_RATE_LIMIT_WINDOW=' . COUPON_RATE_LIMIT_WINDOW . "\n";
echo 'COUPON_RATE_LIMIT_PER_MINUTE=' . COUPON_RATE_LIMIT_PER_MINUTE . "\n";
