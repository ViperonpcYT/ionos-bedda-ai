<?php
declare(strict_types=1);

require_once __DIR__ . '/roast-config.php';

if (!function_exists('roast_envelope')) {
    /**
     * @param array<string, mixed>|null $result
     * @param array<int, array<string, mixed>> $steps
     * @param array<string, mixed>|null $error
     * @return array<string, mixed>
     */
    function roast_envelope(
        string $jobId,
        string $status,
        bool $ok,
        ?array $result = null,
        array $steps = [],
        ?array $error = null
    ): array {
        return [
            'ok' => $ok,
            'job_id' => $jobId,
            'status' => $status,
            'steps' => $steps,
            'result' => $result,
            'error' => $error,
        ];
    }
}

if (!function_exists('roast_error')) {
    /** @return array<string, mixed> */
    function roast_error(string $code, string $message, bool $retryable, string $phase = ''): array
    {
        $err = [
            'code' => $code,
            'message' => $message,
            'retryable' => $retryable,
        ];
        if ($phase !== '') {
            $err['phase'] = $phase;
        }
        return $err;
    }
}

if (!function_exists('roast_step')) {
    /** @return array<string, mixed> */
    function roast_step(string $phase, string $label, int $pct, int $ms, bool $ok): array
    {
        return [
            'phase' => $phase,
            'label' => $label,
            'pct' => $pct,
            'ms' => $ms,
            'ok' => $ok,
        ];
    }
}

if (!function_exists('roast_build_result')) {
    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     */
    function roast_build_result(
        ?int $score,
        array $identity,
        array $inspect,
        ?string $roastText,
        ?string $partialNotice = null
    ): array {
        $result = [
            'score' => $score,
            'identity' => $identity,
            'inspect' => $inspect,
            'roast' => $roastText,
            'interpretation_notice' => ROAST_INTERPRETATION_NOTICE,
        ];
        if ($partialNotice !== null && $partialNotice !== '') {
            $result['partial_notice'] = $partialNotice;
        }
        return $result;
    }
}

if (!function_exists('roast_http_status_for_error')) {
    /** Map roast_error codes to HTTP status for API handlers. */
    function roast_http_status_for_error(?array $error, int $default = 400): int
    {
        if ($error === null) {
            return $default;
        }
        $code = strtoupper((string) ($error['code'] ?? ''));
        switch ($code) {
            case 'RATE':
            case 'RATE_LIMIT':
            case 'NOT_READY':
                return 429;
            case 'PVP':
                $msg = strtolower((string) ($error['message'] ?? ''));
                if (str_contains($msg, 'not found')) {
                    return 404;
                }
                if (str_contains($msg, 'not in') || str_contains($msg, 'not active')) {
                    return 403;
                }
                return 403;
            case 'EXPIRED':
                return 410;
            case 'DB':
            case 'JOIN':
            case 'STATUS':
            case 'LIVE':
                return 503;
            case 'IMAGE':
            case 'MODE':
            case 'ACTION':
            case 'TOKEN':
                return 400;
            default:
                return $default;
        }
    }
}

if (!function_exists('roast_send_json')) {
    /** @param array<string, mixed> $payload */
    function roast_send_json(array $payload, int $code = 200): void
    {
        roast_json_headers();
        if ($code === 429 && isset($payload['retry_after']) && !headers_sent()) {
            header('Retry-After: ' . max(1, (int) $payload['retry_after']));
        }
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
