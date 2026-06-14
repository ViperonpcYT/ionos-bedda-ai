<?php
declare(strict_types=1);

require_once __DIR__ . '/roast-config.php';
require_once __DIR__ . '/runtime-credits.php';

if (!function_exists('roast_ads_house_promos')) {
    /** @return list<array{title:string,href:string,image:string}> */
    function roast_ads_house_promos(): array
    {
        $paths = [
            dirname(__DIR__) . '/data/roast-ads.json',
            dirname(__DIR__, 2) . '/api/data/roast-ads.json',
        ];
        foreach ($paths as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['promos']) || !is_array($data['promos'])) {
                continue;
            }
            $out = [];
            foreach ($data['promos'] as $row) {
                if (!is_array($row) || empty($row['title']) || empty($row['href'])) {
                    continue;
                }
                $out[] = [
                    'title' => (string) $row['title'],
                    'href' => (string) $row['href'],
                    'image' => (string) ($row['image'] ?? 'images/onlybikes-logo.svg'),
                ];
            }
            if ($out !== []) {
                return $out;
            }
        }

        return [
            [
                'title' => 'Shop OnlyBikes parts — supports the roast event.',
                'href' => 'products.html',
                'image' => 'images/onlybikes-logo.svg',
            ],
        ];
    }
}

if (!function_exists('roast_ads_network_configured')) {
    function roast_ads_network_configured(string $network, array $cfg): bool
    {
        return match ($network) {
            'adsense' => ($cfg['adsense_client'] ?? '') !== '',
            'monetag' => ($cfg['monetag_zone'] ?? '') !== '',
            'house' => true,
            default => false,
        };
    }
}

if (!function_exists('roast_ads_build_waterfall')) {
    /** @param array<string, string> $cfg @return list<string> */
    function roast_ads_build_waterfall(array $cfg): array
    {
        $defaultOrder = 'monetag,adsense,house';
        $requested = array_map('trim', explode(',', roast_env('ROAST_AD_WATERFALL', $defaultOrder)));
        $modalNetworks = ['monetag', 'adsense', 'house'];
        $out = [];
        foreach ($requested as $tier) {
            $tier = strtolower($tier);
            if (!in_array($tier, $modalNetworks, true)) {
                continue;
            }
            if (!roast_ads_network_configured($tier, $cfg)) {
                continue;
            }
            if (!in_array($tier, $out, true)) {
                $out[] = $tier;
            }
        }
        if (!in_array('house', $out, true)) {
            $out[] = 'house';
        }
        return $out;
    }
}

if (!function_exists('roast_ads_public_config')) {
    /** @return array<string, mixed> */
    function roast_ads_public_config(): array
    {
        runtime_credits_define_constants();

        $cfg = [
            'adsense_client' => trim(roast_env('ROAST_ADSENSE_CLIENT', '')),
            'adsense_slot' => trim(roast_env('ROAST_ADSENSE_SLOT', '')),
            'monetag_zone' => trim(roast_env('ROAST_MONETAG_ZONE_ID', '')),
        ];

        $fillTimeoutMs = max(1500, (int) roast_env('ROAST_AD_FILL_TIMEOUT_MS', '4500'));

        return [
            'waterfall' => roast_ads_build_waterfall($cfg),
            'adsense_client' => $cfg['adsense_client'],
            'adsense_slot' => $cfg['adsense_slot'],
            'adsense_has_slot' => $cfg['adsense_slot'] !== '',
            'monetag_zone' => $cfg['monetag_zone'],
            'fill_timeout_ms' => $fillTimeoutMs,
            'house_promos' => roast_ads_house_promos(),
            'min_view_solo_sec' => RUNTIME_AD_MIN_VIEW_SOLO_SEC,
            'min_view_pvp_sec' => RUNTIME_AD_MIN_VIEW_PVP_SEC,
        ];
    }
}
