<?php
declare(strict_types=1);

require_once __DIR__ . '/roast-config.php';

if (!function_exists('roast_identity_is_unknown')) {
    /** @param array<string, mixed> $identity */
    function roast_identity_is_unknown(array $identity): bool
    {
        $make = strtolower(trim((string) ($identity['make'] ?? '')));
        $model = strtolower(trim((string) ($identity['model'] ?? '')));
        return $make === '' || $model === '' || $make === 'unknown' || $model === 'unknown';
    }
}

if (!function_exists('roast_inspect_wheel_only_focus')) {
    /** @param array<string, mixed> $inspect */
    function roast_inspect_wheel_only_focus(array $inspect): bool
    {
        $mods = $inspect['visual_mods'] ?? [];
        if (!is_array($mods) || $mods === []) {
            return false;
        }

        $wheelHints = ['wheel', 'tire', 'tyre', 'rim', 'spoke', 'hub'];
        foreach ($mods as $mod) {
            if (!is_array($mod)) {
                return false;
            }
            $part = strtolower((string) ($mod['part'] ?? ''));
            $hit = false;
            foreach ($wheelHints as $hint) {
                if (str_contains($part, $hint)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('roast_normalize_visible_subject')) {
    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     */
    function roast_normalize_visible_subject(array $identity, array $inspect): string
    {
        $subject = strtolower(trim((string) ($identity['visible_subject'] ?? '')));
        $allowed = ['full_bike', 'partial_bike', 'parts_only', 'not_an_ebike', 'unclear'];
        if (in_array($subject, $allowed, true)) {
            return $subject;
        }

        $conf = (float) ($identity['confidence'] ?? 0);
        $complete = $identity['is_complete_ebike'] ?? null;
        if ($complete === false) {
            return 'partial_bike';
        }

        if (roast_inspect_wheel_only_focus($inspect) && roast_identity_is_unknown($identity)) {
            return 'parts_only';
        }

        if (roast_identity_is_unknown($identity) && $conf < 0.2) {
            return 'unclear';
        }

        $missing = $inspect['missing_parts'] ?? [];
        if (is_array($missing)) {
            $critical = ['frame', 'seat', 'motor', 'battery', 'fork', 'swingarm', 'handlebar', 'whole bike', 'entire'];
            foreach ($missing as $item) {
                $text = strtolower((string) $item);
                foreach ($critical as $needle) {
                    if (str_contains($text, $needle)) {
                        return 'partial_bike';
                    }
                }
            }
            if (count($missing) >= 4) {
                return 'partial_bike';
            }
        }

        $frameVisible = $inspect['frame_visible'] ?? null;
        if ($frameVisible === false) {
            return 'partial_bike';
        }

        return 'full_bike';
    }
}

if (!function_exists('roast_compute_shame_score')) {
    /**
     * Cred score: 100 = spotless stock hero, lower = more roast fuel.
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     */
    function roast_compute_shame_score(array $identity, array $inspect): int
    {
        if (!ROAST_SHAME_SCORE_ENABLED) {
            return 50;
        }

        $subject = roast_normalize_visible_subject($identity, $inspect);
        $conf = max(0.0, min(1.0, (float) ($identity['confidence'] ?? 0)));
        $unknown = roast_identity_is_unknown($identity);

        if (in_array($subject, ['not_an_ebike', 'parts_only'], true)) {
            $score = 6 + (int) round($conf * 10);
            return max(5, min(22, $score));
        }

        $score = 100;

        if ($subject === 'unclear') {
            $score -= 48;
        } elseif ($subject === 'partial_bike') {
            $score -= 40;
        }

        if ($unknown) {
            $score -= 32;
        } elseif ($conf < 0.35) {
            $score -= 24;
        } elseif ($conf < 0.55) {
            $score -= 16;
        } elseif ($conf < 0.75) {
            $score -= 9;
        }

        $clean = (int) ($inspect['cleanliness_score'] ?? 5);
        if ($clean < 10) {
            $score -= (int) round((10 - $clean) * 5.5);
        }

        $damage = $inspect['damage'] ?? [];
        if (is_array($damage)) {
            $score -= min(45, count($damage) * 10);
        }

        $missing = $inspect['missing_parts'] ?? [];
        if (is_array($missing)) {
            $score -= min(36, count($missing) * 9);
        }

        $mods = $inspect['visual_mods'] ?? [];
        if (is_array($mods)) {
            $score -= min(36, count($mods) * 9);
        }

        if ($unknown && $conf < 0.3) {
            $score = min($score, 32);
        }
        if ($subject === 'partial_bike') {
            $score = min($score, 40);
        }
        if ($subject === 'unclear') {
            $score = min($score, 28);
        }

        return max(0, min(100, $score));
    }
}
