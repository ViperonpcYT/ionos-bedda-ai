<?php
declare(strict_types=1);

/**
 * Chit Chats shipping — reads CHITCHATS_* from api/.env.
 * Works even if live secure-config.php has not been updated with CHITCHATS defines.
 */

function onlybikes_chitchats_load_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    if (function_exists('onlybikes_load_env')) {
        onlybikes_load_env();
        return;
    }

    $envFile = dirname(__DIR__) . '/.env';
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
            $_ENV[$key] = $value;
        }
    }
}

function onlybikes_chitchats_credential_valid(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    foreach (['YOUR_', 'PASTE_', 'REPLACE_', 'CHANGEME', 'EXAMPLE'] as $bad) {
        if (stripos($value, $bad) !== false) {
            return false;
        }
    }
    return true;
}

function onlybikes_chitchats_env(string $key, string $default = ''): string
{
    onlybikes_chitchats_load_env();

    if (function_exists('onlybikes_env')) {
        $fromHelper = trim(onlybikes_env($key, ''));
        if ($fromHelper !== '') {
            return $fromHelper;
        }
    }

    if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
        return trim((string) $_ENV[$key]);
    }

    $fromGetenv = getenv($key);
    if ($fromGetenv !== false && $fromGetenv !== '') {
        return trim((string) $fromGetenv);
    }

    return $default;
}

function onlybikes_chitchats_client_id(): string
{
    if (defined('CHITCHATS_CLIENT_ID')) {
        $fromConst = trim((string) CHITCHATS_CLIENT_ID);
        if (onlybikes_chitchats_credential_valid($fromConst)) {
            return $fromConst;
        }
    }

    $fromEnv = trim(onlybikes_chitchats_env('CHITCHATS_CLIENT_ID'));
    return onlybikes_chitchats_credential_valid($fromEnv) ? $fromEnv : '';
}

function onlybikes_chitchats_access_token(): string
{
    if (defined('CHITCHATS_ACCESS_TOKEN')) {
        $fromConst = trim((string) CHITCHATS_ACCESS_TOKEN);
        if (onlybikes_chitchats_credential_valid($fromConst)) {
            return $fromConst;
        }
    }

    $fromEnv = trim(onlybikes_chitchats_env('CHITCHATS_ACCESS_TOKEN'));
    return onlybikes_chitchats_credential_valid($fromEnv) ? $fromEnv : '';
}

function onlybikes_chitchats_warehouse(): array
{
    onlybikes_chitchats_load_env();

    $postal = strtoupper(str_replace(' ', '', onlybikes_chitchats_env('ONLYBIKES_WAREHOUSE_POSTAL', 'L5M3R5')));
    $city = onlybikes_chitchats_env('ONLYBIKES_WAREHOUSE_CITY', 'Mississauga');
    $province = strtoupper(onlybikes_chitchats_env('ONLYBIKES_WAREHOUSE_PROVINCE', 'ON'));

    return [
        'postal_code' => $postal,
        'city' => $city,
        'province_code' => $province,
        'country_code' => 'CA',
    ];
}

function onlybikes_chitchats_api_base(): string
{
    if (defined('CHITCHATS_API_BASE')) {
        $base = rtrim(trim((string) CHITCHATS_API_BASE), '/');
        if ($base !== '') {
            return $base;
        }
    }

    $clientId = onlybikes_chitchats_client_id();
    if ($clientId === '') {
        return '';
    }

    return 'https://chitchats.com/api/v1/clients/' . rawurlencode($clientId);
}

function onlybikes_chitchats_configured(): bool
{
    return onlybikes_chitchats_client_id() !== ''
        && onlybikes_chitchats_access_token() !== '';
}

/** Safe status for diagnostics — never exposes secrets. */
function onlybikes_chitchats_config_status(): array
{
    onlybikes_chitchats_load_env();

    $envPath = dirname(__DIR__) . '/.env';
    $clientId = onlybikes_chitchats_client_id();
    $token = onlybikes_chitchats_access_token();

    return [
        'env_file_readable' => is_readable($envPath),
        'client_id_present' => $clientId !== '',
        'access_token_present' => $token !== '',
        'configured' => onlybikes_chitchats_configured(),
        'api_base_set' => onlybikes_chitchats_api_base() !== '',
        'client_id_prefix' => $clientId !== '' ? substr($clientId, 0, 2) . '****' : null,
        'warehouse' => onlybikes_chitchats_warehouse(),
    ];
}

/**
 * @return array<string, mixed>
 */
function onlybikes_chitchats_request(string $path, array $data = [], string $method = 'POST', int $maxRetries = 2): array
{
    $base = onlybikes_chitchats_api_base();
    $token = onlybikes_chitchats_access_token();
    if ($base === '' || $token === '') {
        return ['error' => true, 'message' => 'Chit Chats is not configured'];
    }

    $url = $base . '/' . ltrim($path, '/');
    $method = strtoupper($method);
    $retries = 0;

    while ($retries <= $maxRetries) {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . $token,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($data !== []) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 201 && $response) {
            $decoded = json_decode($response, true);
            return is_array($decoded) ? $decoded : ['error' => true, 'message' => 'Invalid API response'];
        }

        if ($httpCode >= 500 && $retries < $maxRetries) {
            $retries++;
            sleep((int) pow(2, $retries));
            continue;
        }

        $apiMessage = '';
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $apiMessage = (string) (
                    $decoded['error']['message']
                    ?? $decoded['error']
                    ?? $decoded['message']
                    ?? ''
                );
                if (is_array($apiMessage)) {
                    $apiMessage = json_encode($apiMessage);
                }
            }
        }

        error_log('[OnlyBikes] ChitChats ' . $method . ' ' . $path . ' failed: HTTP ' . $httpCode . ' ' . ($curlError ?: $apiMessage));

        return [
            'error' => true,
            'http_code' => $httpCode,
            'message' => $apiMessage ?: ($curlError ?: "API request failed (HTTP $httpCode)"),
            'response' => is_string($response) ? substr($response, 0, 500) : '',
        ];
    }

    return ['error' => true, 'message' => 'Shipping service unavailable'];
}

/**
 * Quick connectivity test — creates a minimal CA shipment quote (same as checkout).
 *
 * @return array<string, mixed>
 */
function onlybikes_chitchats_test_quote(): array
{
    if (!onlybikes_chitchats_configured()) {
        return [
            'ok' => false,
            'step' => 'config',
            'message' => 'CHITCHATS_CLIENT_ID and CHITCHATS_ACCESS_TOKEN missing in api/.env',
            'status' => onlybikes_chitchats_config_status(),
        ];
    }

    $payload = [
        'name' => 'OnlyBikes Test',
        'address_1' => '123 King St W',
        'city' => 'Toronto',
        'province_code' => 'ON',
        'postal_code' => 'M5V2T6',
        'country_code' => 'CA',
        'package_contents' => 'merchandise',
        'description' => 'OnlyBikes test quote',
        'value' => '25.00',
        'value_currency' => 'cad',
        'package_type' => 'thick_envelope',
        'weight_unit' => 'g',
        'weight' => 300,
        'size_unit' => 'in',
        'size_x' => 11,
        'size_y' => 8.5,
        'size_z' => 1,
        'postage_type' => 'unknown',
        'ship_date' => 'today',
    ];

    $result = onlybikes_chitchats_request('shipments', $payload, 'POST', 0);
    if (isset($result['error'])) {
        return [
            'ok' => false,
            'step' => 'api',
            'http_code' => $result['http_code'] ?? null,
            'message' => $result['message'] ?? 'Chit Chats API error',
            'status' => onlybikes_chitchats_config_status(),
        ];
    }

    $rateCount = is_array($result['shipment']['rates'] ?? null) ? count($result['shipment']['rates']) : 0;

    return [
        'ok' => true,
        'step' => 'api',
        'rate_count' => $rateCount,
        'shipment_id' => $result['shipment']['id'] ?? null,
        'message' => $rateCount > 0 ? 'Chit Chats returned live rates' : 'Shipment created but no rates returned',
        'status' => onlybikes_chitchats_config_status(),
    ];
}

onlybikes_chitchats_load_env();
