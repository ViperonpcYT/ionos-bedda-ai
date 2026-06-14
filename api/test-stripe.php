<?php
/**
 * One-time check: Stripe SDK + API key. Delete after OK on production.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/secure-config.php';

if (!defined('STRIPE_PUBLISHABLE_KEY') || STRIPE_PUBLISHABLE_KEY === '') {
    echo "FAIL: STRIPE_PUBLISHABLE_KEY missing in api/.env\n";
    exit(1);
}
if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
    echo "FAIL: STRIPE_SECRET_KEY missing in api/.env\n";
    exit(1);
}

echo 'Publishable: ' . substr(STRIPE_PUBLISHABLE_KEY, 0, 12) . "…\n";
echo 'Mode: ' . (STRIPE_LIVE_MODE ? 'live' : 'test') . "\n";

if (!loadStripe()) {
    echo "FAIL: Stripe PHP SDK not found (upload stripe-php-master/ to site root)\n";
    exit(1);
}
echo "OK   Stripe PHP SDK loaded\n";

try {
    $client = getStripe();
    if (!$client) {
        throw new RuntimeException('getStripe() returned null');
    }
    $balance = $client->balance->retrieve();
    echo "OK   API connection (currency: " . ($balance->available[0]->currency ?? 'n/a') . ")\n";
} catch (Throwable $e) {
    echo 'FAIL API: ' . $e->getMessage() . "\n";
    exit(1);
}

echo "\nFrontend: open /api/public-config.php — should include stripePublishableKey.\n";
echo "Webhook: add whsec_… to STRIPE_WEBHOOK_SECRET in .env after creating endpoint in Stripe Dashboard.\n";
