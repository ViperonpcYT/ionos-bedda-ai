<?php
declare(strict_types=1);

/**
 * Poll roast job status after HTTP 504 / network drop.
 * GET ?job_id=uuid
 */

require_once dirname(__DIR__) . '/secure-config.php';
require_once dirname(__DIR__) . '/lib/security-helpers.php';
require_once dirname(__DIR__) . '/lib/roast-config.php';
require_once dirname(__DIR__) . '/lib/roast-envelope.php';
require_once dirname(__DIR__) . '/lib/roast-jobs.php';

ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    roast_json_headers();
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    roast_send_json(
        roast_envelope('', 'failed', false, null, [], roast_error('METHOD', 'GET required.', false)),
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

$jobId = trim((string) ($_GET['job_id'] ?? ''));
if ($jobId === '' || !preg_match('/^[a-f0-9-]{36}$/i', $jobId)) {
    roast_send_json(
        roast_envelope('', 'failed', false, null, [], roast_error('NOT_FOUND', 'Invalid job id.', false)),
        400
    );
    exit;
}

$row = roast_jobs_get($jobId);
if (!$row) {
    roast_send_json(
        roast_envelope($jobId, 'failed', false, null, [], roast_error('NOT_FOUND', 'Job not found.', false)),
        404
    );
    exit;
}

$ipHash = roast_ip_hash();
if (($row['ip_hash'] ?? '') !== $ipHash) {
    roast_send_json(
        roast_envelope($jobId, 'failed', false, null, [], roast_error('NOT_FOUND', 'Job not found.', false)),
        404
    );
    exit;
}

roast_send_json(roast_jobs_row_to_envelope($row));
