<?php

declare(strict_types=1);



/**

 * Master coordinator endpoint — sync 4-agent pipeline, JSON envelope.

 * POST multipart: image= file, optional make/model override, optional retry_job_id for Agent4-only retry.

 */



require_once dirname(__DIR__) . '/secure-config.php';

require_once dirname(__DIR__) . '/lib/security-helpers.php';

require_once dirname(__DIR__) . '/lib/roast-config.php';

require_once dirname(__DIR__) . '/lib/roast-envelope.php';

require_once dirname(__DIR__) . '/lib/roast-jobs.php';

require_once dirname(__DIR__) . '/lib/roast-cloud-vision.php';

require_once dirname(__DIR__) . '/lib/roast-coordinator.php';
require_once dirname(__DIR__) . '/lib/roast-pvp.php';
require_once dirname(__DIR__) . '/lib/runtime-credits.php';

require_once __DIR__ . '/agents/agent1-identify.php';

require_once __DIR__ . '/agents/agent2-condition.php';

require_once __DIR__ . '/agents/agent3-mods.php';

require_once __DIR__ . '/agents/agent4-roast.php';



ini_set('display_errors', '0');

ini_set('log_errors', '1');

set_time_limit(120);



if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    roast_json_headers();

    http_response_code(204);

    exit;

}



if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ping'])) {
    require_once dirname(__DIR__) . '/lib/roast-local-inference.php';
    $local = roast_local_models_ready();
    roast_send_json([
        'ok' => true,
        'event_active' => roast_event_active(),
        'event_end' => ROAST_EVENT_END,
        'step_min_ms' => ROAST_STEP_MIN_MS,
        'pipeline_mode' => ROAST_PIPELINE_MODE,
        'local_judge_ready' => $local['ready'],
        'groq_configured' => roast_groq_api_key() !== '',
        'vision_configured' => roast_groq_api_key() !== '',
        'openrouter_configured' => ROAST_OPENROUTER_API_KEY !== '',
        'pipeline' => 'coordinator_4_agent_enterprise',
        'agents' => roast_pipeline_model_catalog(),
    ]);
    exit;
}



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    roast_send_json(

        roast_envelope('', 'failed', false, null, [], roast_error('METHOD', 'POST required.', false)),

        405

    );

    exit;

}



if (!roast_allowed_origin()) {

    roast_send_json(

        roast_envelope('', 'failed', false, null, [], roast_error('ORIGIN', 'Bad origin.', false)),

        403

    );

    exit;

}



if (!roast_event_active()) {

    roast_send_json(

        roast_envelope('', 'failed', false, null, [], roast_error('EVENT_DISABLED', 'This limited event is not active.', false)),

        503

    );

    exit;

}



$ipHash = roast_ip_hash();

$bypassActive = roast_request_bypass_active();

$pvpMatchId = trim((string) ($_POST['pvp_match_id'] ?? ''));
$pvpToken = roast_pvp_normalize_token((string) ($_POST['pvp_token'] ?? ''));
$isPvpSubmit = $pvpMatchId !== '' && $pvpToken !== '' && roast_pvp_validate_participant($pvpMatchId, $pvpToken);

if (roast_jobs_count_today() >= ROAST_DAILY_MAX_JOBS && !$bypassActive) {

    roast_send_json(

        roast_envelope('', 'failed', false, null, [], roast_error('RATE_LIMIT', 'Daily roast limit reached. Come back tomorrow.', false)),

        429

    );

    exit;

}



if (roast_jobs_ip_count_today($ipHash) >= ROAST_RATE_LIMIT_PER_IP_PER_DAY && !$isPvpSubmit && !$bypassActive) {

    roast_send_json(

        roast_envelope('', 'failed', false, null, [], roast_error('RATE_LIMIT', 'You already got judged today. Try again tomorrow.', false)),

        429

    );

    exit;

}



$retryJobId = trim((string) ($_POST['retry_job_id'] ?? ''));

$ctx = [

    'make_override' => trim((string) ($_POST['make_override'] ?? '')),

    'model_override' => trim((string) ($_POST['model_override'] ?? '')),

];



if ($retryJobId !== '') {

    $row = roast_jobs_get($retryJobId);

    if (!$row || ($row['ip_hash'] ?? '') !== $ipHash) {

        roast_send_json(

            roast_envelope($retryJobId, 'failed', false, null, [], roast_error('NOT_FOUND', 'Job not found.', false)),

            404

        );

        exit;

    }

    $identity = json_decode((string) ($row['identity_json'] ?? '{}'), true) ?: [];

    $inspect = json_decode((string) ($row['inspect_json'] ?? '{}'), true) ?: [];

    if ($ctx['make_override'] !== '') {

        $identity['make'] = $ctx['make_override'];

    }

    if ($ctx['model_override'] !== '') {

        $identity['model'] = $ctx['model_override'];

    }

    $steps = json_decode((string) ($row['steps_json'] ?? '[]'), true) ?: [];

    $score = roast_compute_shame_score($identity, $inspect);

    $a4 = roast_agent4_roast($identity, $inspect, $score);

    if (!$a4['ok']) {

        roast_jobs_update($retryJobId, [

            'status' => 'partial',

            'phase' => 'judge',

            'error_json' => $a4['error'] ?? roast_error('LOCAL_ROAST_FAILED', 'Roast failed.', true, 'judge'),

        ]);

        roast_send_json(roast_envelope(

            $retryJobId,

            'partial',

            true,

            roast_build_result($score, $identity, $inspect, null, 'Judgment cut short — showing what we got.'),

            $steps,

            $a4['error'] ?? null

        ));

        exit;

    }

    $steps[] = roast_step('judge', 'Calculating shame score…', 90, (int) ($a4['ms'] ?? 0), true);

    roast_jobs_update($retryJobId, [

        'status' => 'complete',

        'phase' => 'done',

        'roast_text' => $a4['text'],

        'score' => $score,

        'steps_json' => $steps,

        'error_json' => null,

    ]);

    roast_send_json(roast_envelope(

        $retryJobId,

        'complete',

        true,

        roast_build_result($score, $identity, $inspect, $a4['text']),

        $steps

    ));

    exit;

}



if (empty($_FILES['image'])) {

    roast_send_json(

        roast_envelope('', 'failed', false, null, [], roast_error('IMAGE', 'Upload a bike photo.', false)),

        400

    );

    exit;

}



$lock = roast_acquire_lock(5);

if ($lock === false) {

    roast_send_json(

        roast_envelope('', 'failed', false, null, [], roast_error('BUSY', 'Someone else is being judged — try again in a few seconds.', true)),

        503

    );

    exit;

}



$jobId = roast_jobs_new_id();

$imagePath = '';



try {

    $saved = roast_save_uploaded_image($_FILES['image']);

    if (!$saved['ok']) {

        roast_send_json(

            roast_envelope($jobId, 'failed', false, null, [], roast_error('IMAGE', $saved['error'] ?? 'Upload failed.', false)),

            400

        );

        exit;

    }

    $imagePath = (string) $saved['path'];

    $imageHash = (string) $saved['hash'];



    $cached = roast_jobs_find_complete_by_hash($imageHash);

    if ($cached) {

        roast_jobs_create($jobId, $ipHash, $imageHash);

        $identity = json_decode((string) ($cached['identity_json'] ?? '{}'), true) ?: [];

        $inspect = json_decode((string) ($cached['inspect_json'] ?? '{}'), true) ?: [];

        $steps = json_decode((string) ($cached['steps_json'] ?? '[]'), true) ?: [];

        $score = roast_compute_shame_score($identity, $inspect);

        $roastText = (string) ($cached['roast_text'] ?? '');

        roast_jobs_update($jobId, [

            'status' => 'complete',

            'phase' => 'done',

            'identity_json' => $identity,

            'inspect_json' => $inspect,

            'roast_text' => $roastText,

            'score' => $score,

            'steps_json' => $steps,

        ]);

        if ($isPvpSubmit) {
            roast_pvp_link_job($pvpMatchId, $pvpToken, $jobId);
        }

        roast_delete_image($imagePath);

        roast_send_json(roast_envelope(

            $jobId,

            'complete',

            true,

            roast_build_result($score, $identity, $inspect, $roastText),

            $steps

        ));

        exit;

    }

    $customerId = runtime_credits_customer_id_from_session();
    $unlockToken = trim((string) ($_POST['ad_unlock_token'] ?? ''));
    $creditGate = runtime_credits_require_solo($customerId, $unlockToken);
    if (!$creditGate['ok']) {
        roast_delete_image($imagePath);
        roast_send_json(
            roast_envelope($jobId, 'failed', false, null, [], $creditGate['error'] ?? roast_error('CREDITS', 'Credits required.', false)),
            402
        );
        exit;
    }
    $creditMethod = $creditGate['method'] ?? 'unknown';



    roast_jobs_create($jobId, $ipHash, $imageHash);

    roast_jobs_update($jobId, ['image_hash' => $imageHash]);

    $cleanupPath = $imagePath;
    register_shutdown_function(static function () use ($jobId, $cleanupPath): void {
        $row = roast_jobs_get($jobId);
        if ($row && ($row['status'] ?? '') === 'processing') {
            roast_jobs_update($jobId, [
                'status' => 'partial',
                'error_json' => roast_error('TIMEOUT', 'Server cut judgment short.', true, (string) ($row['phase'] ?? '')),
            ]);
        }
        if ($cleanupPath !== '') {
            roast_delete_image($cleanupPath);
        }
    });

    $run = roast_coordinator_run(

        $jobId,

        $imagePath,

        $ctx,

        static fn (string $path, array $c) => roast_agent1_identify($path, $c),

        static fn (string $path, array $id) => roast_agent2_condition($path, $id),

        static fn (string $path, array $id) => roast_agent3_mods($path, $id),

        static fn (array $id, array $ins, ?int $sc) => roast_agent4_roast($id, $ins, $sc)

    );



    roast_delete_image($imagePath);

    $imagePath = '';



    if (($run['status'] ?? '') === 'failed') {

        if ($customerId && ($creditMethod ?? '') === 'balance') {
            runtime_credits_refund_failed_job($customerId, 'solo', $jobId);
        }

        roast_send_json(roast_envelope(

            $jobId,

            'failed',

            false,

            null,

            $run['steps'] ?? [],

            $run['error'] ?? null

        ));

        exit;

    }



    $result = roast_coordinator_build_result($run);

    if ($isPvpSubmit) {
        roast_pvp_link_job($pvpMatchId, $pvpToken, $jobId);
    }

    roast_send_json(roast_envelope(

        $jobId,

        (string) $run['status'],

        true,

        $result,

        $run['steps'] ?? [],

        $run['error'] ?? null

    ));

} finally {

    roast_release_lock($lock);

    if ($imagePath !== '') {

        roast_delete_image($imagePath);

    }

}


