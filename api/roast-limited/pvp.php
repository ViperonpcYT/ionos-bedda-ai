<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/secure-config.php';
require_once dirname(__DIR__) . '/lib/security-helpers.php';
require_once dirname(__DIR__) . '/lib/roast-config.php';
require_once dirname(__DIR__) . '/lib/roast-envelope.php';
require_once dirname(__DIR__) . '/lib/roast-pvp.php';
require_once dirname(__DIR__) . '/lib/runtime-credits.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    roast_json_headers();
    http_response_code(204);
    exit;
}

if (!roast_allowed_origin()) {
    roast_send_json(['ok' => false, 'error' => roast_error('ORIGIN', 'Bad origin.', false)], 403);
    exit;
}

if (!roast_event_active()) {
    roast_send_json(['ok' => false, 'error' => roast_error('EVENT_DISABLED', 'Event not active.', false)], 503);
    exit;
}

$token = roast_pvp_normalize_token((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'status'));
    if ($action === 'status') {
        if ($token === '') {
            roast_send_json(['ok' => true, 'status' => 'idle', 'message' => 'Enable camera and pass face check to start.']);
            exit;
        }
        roast_pvp_ping($token);
        roast_send_json(roast_pvp_status($token));
        exit;
    }
    if ($action === 'stats') {
        if ($token !== '') {
            roast_pvp_ping($token);
        }
        roast_send_json(roast_pvp_stats());
        exit;
    }
    if ($action === 'config') {
        runtime_credits_define_constants();
        roast_send_json([
            'ok' => true,
            'apiVersion' => ROAST_PVP_API_VERSION,
            'turnstileSiteKey' => roast_env('TURNSTILE_SITE_KEY', ''),
            'roundSec' => ROAST_PVP_ROUND_SEC,
            'adMinViewPvpSec' => RUNTIME_AD_MIN_VIEW_PVP_SEC,
            'webrtcMaxVideoKbps' => PVP_WEBRTC_MAX_VIDEO_KBPS,
            'webrtcMaxAudioKbps' => PVP_WEBRTC_MAX_AUDIO_KBPS,
            'webrtcMaxWidth' => PVP_WEBRTC_MAX_WIDTH,
            'webrtcMaxHeight' => PVP_WEBRTC_MAX_HEIGHT,
            'webrtcMaxFps' => PVP_WEBRTC_MAX_FPS,
        ]);
        exit;
    }
    if ($action === 'ice') {
        $bundle = roast_pvp_ice_bundle();
        roast_send_json([
            'ok' => true,
            'iceServers' => $bundle['iceServers'],
            'turnSource' => $bundle['turn_source'],
            'turnConfigured' => $bundle['turn_configured'],
            'turnWarning' => $bundle['turn_warning'],
            'turnKeySet' => $bundle['turn_key_set'] ?? false,
            'turnStatus' => $bundle['turn_status'] ?? 'unknown',
        ]);
        exit;
    }
    if ($action === 'signal_stats' && isset($_GET['match_id'])) {
        roast_send_json(roast_pvp_signal_stats(trim((string) $_GET['match_id']), $token));
        exit;
    }
    if ($action === 'signals' && isset($_GET['match_id'])) {
        $since = (int) ($_GET['since'] ?? 0);
        roast_send_json(roast_pvp_poll_signals(trim((string) $_GET['match_id']), $token, $since));
        exit;
    }
    if ($action === 'match' && isset($_GET['match_id'])) {
        $match = roast_pvp_get_match(trim((string) $_GET['match_id']));
        if (!$match || $token === '' || roast_pvp_role_for_token($match, $token) === '') {
            roast_send_json(['ok' => false, 'error' => roast_error('PVP', 'Match not found.', false)], 404);
            exit;
        }
        $match = roast_pvp_check_expiry($match) ?? $match;
        roast_send_json(roast_pvp_build_status($match, $token));
        exit;
    }
    roast_send_json(['ok' => false, 'error' => roast_error('ACTION', 'Unknown action.', false)], 400);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    roast_send_json(['ok' => false, 'error' => roast_error('METHOD', 'POST required.', false)], 405);
    exit;
}

$action = trim((string) ($_POST['action'] ?? 'join'));
if ($token === '') {
    roast_send_json(['ok' => false, 'error' => roast_error('TOKEN', 'Session token required.', false)], 400);
    exit;
}

if ($action === 'join') {
    $faceHash = roast_pvp_normalize_face_hash((string) ($_POST['face_hash'] ?? ''));
    $customerId = runtime_credits_customer_id_from_session();
    $unlockToken = trim((string) ($_POST['ad_unlock_token'] ?? ''));
    $creditGate = runtime_credits_check_pvp($customerId, $unlockToken);
    if (!$creditGate['ok']) {
        roast_send_json([
            'ok' => false,
            'error' => $creditGate['error'] ?? roast_error('CREDITS', 'PvP credits required.', false),
        ], 402);
        exit;
    }
    $joinResult = roast_pvp_join($token, $faceHash);
    if (!($joinResult['ok'] ?? false)) {
        roast_send_json($joinResult);
        exit;
    }
    if ($joinResult['fresh_entry'] ?? false) {
        try {
            runtime_credits_commit_pvp_entry(
                $customerId,
                $unlockToken,
                (string) ($creditGate['method'] ?? ''),
                (string) ($joinResult['billing_reference'] ?? $token)
            );
        } catch (Throwable $e) {
            error_log('[Roast PvP] credit commit after join failed: ' . $e->getMessage());
            roast_pvp_leave($token);
            roast_send_json([
                'ok' => false,
                'error' => roast_error('CREDITS_DB', 'Credits system unavailable. Try again shortly.', true),
            ], 503);
            exit;
        }
    }
    $matchId = trim((string) ($joinResult['match_id'] ?? ''));
    $initialJobId = trim((string) ($_POST['job_id'] ?? ''));
    $initialFile = $_FILES['image'] ?? null;
    $hasInitialFile = is_array($initialFile)
        && ($initialFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    if ($matchId !== '' && ($hasInitialFile || $initialJobId !== '')) {
        $seeded = roast_pvp_seed_initial_live_score(
            $matchId,
            $token,
            $hasInitialFile ? $initialFile : null,
            $initialJobId !== '' ? $initialJobId : null
        );
        if (($seeded['ok'] ?? false) && empty($seeded['skipped'])) {
            $joinResult = array_merge($joinResult, $seeded);
        }
    }
    roast_send_json($joinResult);
    exit;
}

if ($action === 'leave') {
    roast_pvp_leave($token);
    roast_send_json(['ok' => true, 'status' => 'idle']);
    exit;
}

if ($action === 'signal') {
    $matchId = trim((string) ($_POST['match_id'] ?? ''));
    $type = trim((string) ($_POST['signal_type'] ?? ''));
    $payload = (string) ($_POST['payload'] ?? '');
    if ($matchId === '' || $payload === '') {
        roast_send_json(['ok' => false, 'error' => roast_error('PVP', 'match_id and payload required.', false)], 400);
        exit;
    }
    if (!roast_pvp_validate_participant($matchId, $token)) {
        roast_send_json(['ok' => false, 'error' => roast_error('PVP', 'Not in this match or match ended.', false)], 403);
        exit;
    }
    $ok = roast_pvp_store_signal($matchId, $token, $type, $payload);
    if (!$ok) {
        roast_send_json(['ok' => false, 'error' => roast_error('SIGNAL', 'Could not store WebRTC signal.', false)], 500);
        exit;
    }
    roast_send_json(['ok' => true]);
    exit;
}

if ($action === 'link_job') {
    $matchId = trim((string) ($_POST['match_id'] ?? ''));
    $jobId = trim((string) ($_POST['job_id'] ?? ''));
    if ($matchId === '' || $jobId === '') {
        roast_send_json(['ok' => false, 'error' => roast_error('PVP', 'match_id and job_id required.', false)], 400);
        exit;
    }
    if (!roast_pvp_validate_participant($matchId, $token)) {
        roast_send_json(['ok' => false, 'error' => roast_error('PVP', 'Not in this match.', false)], 403);
        exit;
    }
    $result = roast_pvp_link_job($matchId, $token, $jobId);
    if (!$result['ok']) {
        roast_send_json($result, 400);
        exit;
    }
    $match = roast_pvp_get_match($matchId);
    roast_send_json($match ? roast_pvp_build_status($match, $token) : $result);
    exit;
}

if ($action === 'set_mode') {
    $matchId = trim((string) ($_POST['match_id'] ?? ''));
    $mode = trim((string) ($_POST['mode'] ?? ''));
    if ($matchId === '') {
        roast_send_json(['ok' => false, 'error' => roast_error('PVP', 'match_id required.', false)], 400);
        exit;
    }
    $result = roast_pvp_set_mode($matchId, $token, $mode);
    if (!($result['ok'] ?? false)) {
        $err = is_array($result['error'] ?? null) ? $result['error'] : null;
        roast_send_json($result, roast_http_status_for_error($err));
        exit;
    }
    if ($mode === 'live') {
        $initialJobId = trim((string) ($_POST['job_id'] ?? ''));
        $initialFile = $_FILES['image'] ?? null;
        $hasInitialFile = is_array($initialFile)
            && ($initialFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
        if ($hasInitialFile || $initialJobId !== '') {
            $seeded = roast_pvp_seed_initial_live_score(
                $matchId,
                $token,
                $hasInitialFile ? $initialFile : null,
                $initialJobId !== '' ? $initialJobId : null
            );
            if (($seeded['ok'] ?? false) && empty($seeded['skipped'])) {
                $result = array_merge($result, $seeded);
            }
        }
    }
    roast_send_json($result);
    exit;
}

if ($action === 'live_frame') {
    require_once dirname(__DIR__) . '/lib/roast-cloud-vision.php';
    $matchId = trim((string) ($_POST['match_id'] ?? ''));
    $file = $_FILES['image'] ?? null;
    if ($matchId === '') {
        roast_send_json([
            'ok' => false,
            'error' => roast_error('IMAGE', 'Match session missing — refresh and rejoin the duel.', false),
        ], 400);
        exit;
    }
    $uploadCheck = roast_validate_live_frame_upload(is_array($file) ? $file : null);
    if (!($uploadCheck['ok'] ?? false)) {
        roast_send_json([
            'ok' => false,
            'error' => roast_error('IMAGE', (string) ($uploadCheck['error'] ?? 'Frame upload failed.'), false),
        ], 400);
        exit;
    }
    $result = roast_pvp_live_frame($matchId, $token, $file);
    if (!($result['ok'] ?? false)) {
        $err = is_array($result['error'] ?? null) ? $result['error'] : null;
        roast_send_json($result, roast_http_status_for_error($err));
        exit;
    }
    roast_send_json($result);
    exit;
}

if ($action === 'ping') {
    roast_pvp_ping($token);
    roast_send_json(['ok' => true]);
    exit;
}

if ($action === 'verify_turnstile') {
    $turnstileToken = trim((string) ($_POST['turnstile_token'] ?? ''));
    roast_send_json(roast_pvp_verify_turnstile($turnstileToken));
    exit;
}

if ($action === 'clear_signals') {
    $matchId = trim((string) ($_POST['match_id'] ?? ''));
    if ($matchId === '') {
        roast_send_json(['ok' => false, 'error' => roast_error('PVP', 'match_id required.', false)], 400);
        exit;
    }
    if (!roast_pvp_clear_signals($matchId, $token)) {
        roast_send_json(['ok' => false, 'error' => roast_error('PVP', 'Could not clear signals.', false)], 403);
        exit;
    }
    roast_send_json(['ok' => true]);
    exit;
}

roast_send_json(['ok' => false, 'error' => roast_error('ACTION', 'Unknown action.', false)], 400);
