<?php
declare(strict_types=1);

if (!function_exists('roast_stock_specs_path')) {
    function roast_stock_specs_path(): string
    {
        return __DIR__ . '/../data/bike-stock-specs.json';
    }
}

if (!function_exists('roast_stock_specs_load')) {
    /** @return array<string, array<string, mixed>> */
    function roast_stock_specs_load(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $path = roast_stock_specs_path();
        if (!is_readable($path)) {
            $cache = [];
            return $cache;
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $cache = is_array($data) ? $data : [];
        return $cache;
    }
}

if (!function_exists('roast_stock_specs_normalize_key')) {
    function roast_stock_specs_normalize_key(string $make, string $model): string
    {
        $m = strtolower(trim($make));
        $mod = strtolower(trim($model));
        $m = str_replace(['sur-ron', 'sur ron'], 'surron', $m);
        return $m . ':' . $mod;
    }
}

if (!function_exists('roast_stock_specs_lookup')) {
    /** @return array{specs_text: string, entry: array<string, mixed>|null} */
    function roast_stock_specs_lookup(string $make, string $model): array
    {
        $all = roast_stock_specs_load();
        $key = roast_stock_specs_normalize_key($make, $model);

        $entry = $all[$key] ?? null;
        if ($entry === null) {
            foreach ($all as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $aliases = $row['aliases'] ?? [];
                $hay = strtolower($make . ' ' . $model);
                if (is_array($aliases)) {
                    foreach ($aliases as $alias) {
                        if (is_string($alias) && str_contains($hay, strtolower($alias))) {
                            $entry = $row;
                            break 2;
                        }
                    }
                }
            }
        }

        if (!is_array($entry)) {
            return [
                'specs_text' => 'No detailed stock specifications on file for this model. Compare against typical OEM e-moto parts: black aluminum bars, stock fork, spoked wheels.',
                'entry' => null,
            ];
        }

        $lines = [];
        $components = $entry['components'] ?? [];
        if (is_array($components)) {
            foreach ($components as $part => $spec) {
                $lines[] = ucfirst((string) $part) . ': ' . (string) $spec;
            }
        }

        return [
            'specs_text' => implode("\n", $lines),
            'entry' => $entry,
        ];
    }
}

if (!function_exists('roast_stock_specs_prompt_block')) {
    function roast_stock_specs_prompt_block(string $make, string $model): string
    {
        $lookup = roast_stock_specs_lookup($make, $model);
        return "Stock specifications for {$make} {$model}:\n" . $lookup['specs_text'];
    }
}
