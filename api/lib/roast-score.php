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
     * @param array<string, mixed> $opts pvp_live=true softens live-duel caps
     */
    function roast_compute_shame_score(array $identity, array $inspect, array $opts = []): int
    {
        if (!ROAST_SHAME_SCORE_ENABLED) {
            return 50;
        }

        $pvpLive = !empty($opts['pvp_live']);
        $subject = roast_normalize_visible_subject($identity, $inspect);
        $conf = max(0.0, min(1.0, (float) ($identity['confidence'] ?? 0)));
        $unknown = roast_identity_is_unknown($identity);

        if (in_array($subject, ['not_an_ebike', 'parts_only'], true)) {
            $score = 6 + (int) round($conf * 10);
            return max(5, min(22, $score));
        }

        $score = 100;

        if ($subject === 'unclear') {
            $score -= $pvpLive ? 28 : 48;
        } elseif ($subject === 'partial_bike') {
            $partialPenalty = 40;
            if ($pvpLive && !$unknown && $conf >= 0.55) {
                $partialPenalty = 18;
            } elseif ($pvpLive) {
                $partialPenalty = 28;
            }
            $score -= $partialPenalty;
        }

        if ($unknown) {
            $score -= $pvpLive ? 22 : 32;
        } elseif ($conf < 0.35) {
            $score -= $pvpLive ? 14 : 24;
        } elseif ($conf < 0.55) {
            $score -= $pvpLive ? 10 : 16;
        } elseif ($conf < 0.75) {
            $score -= $pvpLive ? 6 : 9;
        }

        $cleanDefault = 5;
        if ($pvpLive && !isset($inspect['cleanliness_score'])) {
            $notes = strtolower((string) ($inspect['condition_notes'] ?? ''));
            if (str_contains($notes, 'live_frame') || str_contains($notes, 'npc_grade')) {
                $cleanDefault = 8;
            }
        }
        $clean = (int) ($inspect['cleanliness_score'] ?? $cleanDefault);
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

        if (!$pvpLive) {
            if ($unknown && $conf < 0.3) {
                $score = min($score, 32);
            }
            if ($subject === 'partial_bike') {
                $score = min($score, 40);
            }
            if ($subject === 'unclear') {
                $score = min($score, 28);
            }
        } elseif ($unknown && $conf < 0.3) {
            $score = min($score, 48);
        }

        return max(0, min(100, $score));
    }
}

if (!function_exists('roast_pvp_identity_has_bike_signals')) {
    /**
     * True when identity/inspect suggest a bike is visible (even if make/model unknown).
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     */
    function roast_pvp_identity_has_bike_signals(array $identity, array $inspect): bool
    {
        if (!empty($identity['is_complete_ebike'])) {
            return true;
        }

        $subject = strtolower(trim((string) ($identity['visible_subject'] ?? '')));
        if (in_array($subject, ['full_bike', 'partial_bike'], true)) {
            return true;
        }

        if ($inspect['frame_visible'] ?? false) {
            return true;
        }

        $missing = $inspect['missing_parts'] ?? [];
        if (is_array($missing) && $missing !== []) {
            return true;
        }

        return false;
    }
}

if (!function_exists('roast_pvp_identity_is_provisional')) {
    /**
     * Vision failed to identify the bike — placeholder identity, not a confirmed ID.
     *
     * @param array<string, mixed> $identity
     */
    function roast_pvp_identity_is_provisional(array $identity): bool
    {
        if (!roast_identity_is_unknown($identity)) {
            return false;
        }

        $source = (string) ($identity['source'] ?? '');
        if (in_array($source, ['live_frame_fallback', 'degraded_live'], true)) {
            return true;
        }

        if (!empty($identity['fallback_reason'])) {
            return true;
        }

        return !empty($identity['degraded']);
    }
}

if (!function_exists('roast_compute_pvp_hero_floor')) {
    /** Deterministic hero band 75–95 from confidence only (identity fields). */
    function roast_compute_pvp_hero_floor(float $conf): int
    {
        $conf = max(0.0, min(1.0, $conf));
        $heroFloor = (int) round(72 + ($conf - 0.75) * 92);

        return max(75, min(95, $heroFloor));
    }
}

if (!function_exists('roast_compute_pvp_cred_score')) {
    /**
     * Live PvP cred: reward complete, identifiable bikes (target 70–95+ for hero shots).
     * Deterministic for identical identity + inspect inputs (no randomness).
     *
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $inspect
     */
    function roast_compute_pvp_cred_score(array $identity, array $inspect): int
    {
        if (!isset($inspect['cleanliness_score'])) {
            $inspect['cleanliness_score'] = 8;
        }
        if (!isset($inspect['condition_notes']) || trim((string) $inspect['condition_notes']) === '') {
            $inspect['condition_notes'] = 'live_frame';
        }

        $subject = strtolower(trim((string) ($identity['visible_subject'] ?? '')));
        if (!empty($identity['is_complete_ebike'])
            && !in_array($subject, ['not_an_ebike', 'parts_only'], true)) {
            $identity['visible_subject'] = 'full_bike';
        }

        $score = roast_compute_shame_score($identity, $inspect, ['pvp_live' => true]);
        $subject = roast_normalize_visible_subject($identity, $inspect);
        $conf = max(0.0, min(1.0, (float) ($identity['confidence'] ?? 0)));
        $unknown = roast_identity_is_unknown($identity);
        $provisional = roast_pvp_identity_is_provisional($identity);
        $bikeSignals = roast_pvp_identity_has_bike_signals($identity, $inspect);

        if (in_array($subject, ['not_an_ebike', 'parts_only'], true)) {
            return max(0, min(100, $score));
        }

        if ($subject === 'full_bike' && !$unknown && $conf >= 0.75) {
            $score = max($score, roast_compute_pvp_hero_floor($conf));
        } elseif ($subject === 'full_bike' && !$unknown && $conf >= 0.55) {
            $score = max($score, 70);
        } elseif (!$unknown && $conf >= 0.55 && in_array($subject, ['full_bike', 'partial_bike'], true)) {
            $score = max($score, 62);
        } elseif ($provisional) {
            $provisionalFloor = $bikeSignals ? 58 : 54;
            $score = max($score, $provisionalFloor);
        } elseif ($subject === 'unclear') {
            $score = max($score, $bikeSignals ? 58 : 54);
        } elseif ($subject === 'partial_bike') {
            $score = max($score, 58);
        }

        return max(0, min(100, $score));
    }
}
