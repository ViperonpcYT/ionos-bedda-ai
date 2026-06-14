<?php
/**
 * Public storefront config — no secrets. Stripe publishable key only.
 * Intentionally avoids bootstrap.php (no DB needed).
 */
declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

if (!function_exists('onlybikes_public_load_env')) {
    function onlybikes_public_load_env(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;
        $envFile = __DIR__ . '/.env';
        if (!is_readable($envFile)) {
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv("$key=$value");
            }
        }
    }
}

if (!function_exists('onlybikes_public_env')) {
    function onlybikes_public_env(string $key, string $default = ''): string
    {
        $val = getenv($key);
        return ($val !== false && $val !== '') ? (string) $val : $default;
    }
}

onlybikes_public_load_env();

$publishable = onlybikes_public_env('STRIPE_PUBLISHABLE_KEY');
$siteOrigin = onlybikes_public_env('SITE_ORIGIN', onlybikes_public_env('SITE_URL', 'https://onlybikes.shop'));
$support = onlybikes_public_env('SUPPORT_EMAIL', 'support@onlybikes.shop');

$roastAds = [
    'provider' => 'house',
    'adsense_client' => '',
    'adsense_slot' => '',
    'medianet_cid' => '',
    'min_view_solo_sec' => 18,
    'min_view_pvp_sec' => 12,
];
if (is_readable(__DIR__ . '/lib/roast-ads.php')) {
    require_once __DIR__ . '/lib/roast-ads.php';
    $roastAds = roast_ads_public_config();
}

if ($publishable === '' && is_readable(__DIR__ . '/secure-config.php')) {
    require_once __DIR__ . '/secure-config.php';
    if (defined('STRIPE_PUBLISHABLE_KEY')) {
        $publishable = STRIPE_PUBLISHABLE_KEY;
    }
    if (function_exists('cfg')) {
        $siteOrigin = cfg('SITE_ORIGIN', $siteOrigin);
        $support = cfg('SUPPORT_EMAIL', $support);
    }
}

echo json_encode([
    'brandName' => 'OnlyBikes',
    'siteOrigin' => rtrim($siteOrigin, '/'),
    'stripePublishableKey' => $publishable,
    'currency' => strtolower(onlybikes_public_env('STRIPE_CURRENCY', 'cad')),
    'supportEmail' => $support,
    'roastAds' => $roastAds,
], JSON_UNESCAPED_SLASHES);
