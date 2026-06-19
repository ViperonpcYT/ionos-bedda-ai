<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/secure-config.php';
require_once dirname(__DIR__) . '/lib/roast-config.php';
require_once dirname(__DIR__) . '/lib/roast-jobs.php';
require_once dirname(__DIR__) . '/lib/roast-local-inference.php';
require_once dirname(__DIR__) . '/lib/ai-inference.php';
require_once dirname(__DIR__) . '/lib/runtime-credits.php';
require_once dirname(__DIR__) . '/lib/roast-cloud-budget.php';
require_once dirname(__DIR__) . '/lib/roast-pvp.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
$cronSecret = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
if ($cronSecret === '' || !hash_equals($cronSecret, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$local = roast_local_models_ready();
$cloud = roast_cloud_models_ready();

$htdocs = dirname(__DIR__, 2);
$cliCandidates = [
    ROAST_JUDGE_GGUF,
    rtrim((string) AI_BINARY_PATH, '/\\') . '/llama-cli',
    rtrim((string) ROAST_BINARY_PATH, '/\\') . '/llama-cli',
    $htdocs . '/llama-b9285/llama-cli',
    $htdocs . '/llama-cli',
    $htdocs . '/models/llama-cli',
];
$pathDiag = [];
foreach (array_unique(array_filter($cliCandidates)) as $p) {
    $pathDiag[] = [
        'path' => $p,
        'exists' => is_file($p),
        'readable' => is_readable($p),
        'executable' => is_executable($p),
        'size_mb' => is_file($p) ? round(filesize($p) / 1048576, 1) : null,
    ];
}

$textCli = aiDetectBinary();
$judgeOk = is_readable(ROAST_JUDGE_GGUF);
$judgeSizeOk = function_exists('roast_judge_gguf_size_ok') ? roast_judge_gguf_size_ok() : $judgeOk;
$canRunWithoutLocal = roast_groq_api_key() !== '' && ROAST_OPENROUTER_API_KEY !== '';

$checks = [
    'event_enabled' => ROAST_EVENT_ENABLED,
    'event_active' => roast_event_active(),
    'pipeline_mode' => ROAST_PIPELINE_MODE,
    'cloud_vision_ready' => roast_groq_api_key() !== '',
    'cloud_fallback_ready' => ROAST_OPENROUTER_API_KEY !== '',
    'local_judge_ready' => $local['ready'],
    'local_judge_missing' => $local['missing'],
    'cloud_keys_missing' => $cloud['missing'],
    'groq_api_key_set' => roast_groq_api_key() !== '',
    'openrouter_api_key_set' => ROAST_OPENROUTER_API_KEY !== '',
    'judge_gguf' => ROAST_JUDGE_GGUF,
    'judge_gguf_ok' => $judgeOk,
    'judge_gguf_size_ok' => $judgeSizeOk,
    'judge_gguf_size_mb' => is_file(ROAST_JUDGE_GGUF) ? round(filesize(ROAST_JUDGE_GGUF) / 1048576, 1) : null,
    'judge_gguf_expected_mb' => 1065,
    'ai_binary_path' => AI_BINARY_PATH,
    'roast_binary_path' => ROAST_BINARY_PATH,
    'text_cli' => $textCli,
    'path_diagnostics' => $pathDiag,
    'upload_fix' => [
        'model' => 'FileZilla → /htdocs/models/qwen2.5-1.5b-instruct-q4_k_m.gguf (~1065 MB)',
        'binary' => 'FileZilla → /htdocs/llama-b9285/llama-cli then chmod 755',
    ],
    'pipeline_can_run_cloud_judge' => $canRunWithoutLocal,
    'tmp_dir_writable' => roast_ensure_tmp_dir(),
    'stock_specs_ok' => is_readable(dirname(__DIR__) . '/data/bike-stock-specs.json'),
    'agents' => roast_pipeline_model_catalog(),
];

$dbOk = false;
try {
    $pdo = roast_jobs_pdo();
    $dbOk = $pdo instanceof PDO;
    if ($dbOk) {
        $pdo->query('SELECT 1 FROM roast_jobs LIMIT 1');
    }
} catch (Throwable $e) {
    $checks['analytics_db_error'] = $e->getMessage();
}
$checks['analytics_db_ok'] = $dbOk;

$runtimeDbOk = false;
try {
    $rcPdo = runtime_credits_pdo();
    runtime_credits_ensure_schema($rcPdo);
    $rcPdo->query('SELECT 1 FROM runtime_balances LIMIT 1');
    $runtimeDbOk = true;
} catch (Throwable $e) {
    $checks['runtime_db_error'] = $e->getMessage();
}
$checks['runtime_db_ok'] = $runtimeDbOk;
$checks['groq_budget'] = roast_groq_budget_status();

$iceBundle = roast_pvp_ice_bundle();
$checks['pvp_turn_configured'] = $iceBundle['turn_configured'];
$checks['pvp_turn_source'] = $iceBundle['turn_source'];
$checks['pvp_turn_key_set'] = $iceBundle['turn_key_set'] ?? false;
$checks['pvp_turn_status'] = $iceBundle['turn_status'] ?? 'unknown';
if (!$iceBundle['turn_configured']) {
    $checks['pvp_turn_hint'] = roast_pvp_turn_setup_hint();
}

$required = [
    'groq_api_key_set',
    'openrouter_api_key_set',
    'tmp_dir_writable',
    'analytics_db_ok',
    'stock_specs_ok',
];
// Local judge optional if Groq+OpenRouter can fallback for Agent 4
if (!$judgeOk || !$textCli) {
    $checks['local_judge_warning'] = 'Upload model + llama-cli for free local roasts; cloud fallbacks will work without them.';
    if (!$canRunWithoutLocal) {
        $required[] = 'judge_gguf_ok';
        $required[] = 'text_cli';
    }
} elseif (!$judgeSizeOk) {
    $checks['local_judge_warning'] = 'Judge GGUF file is truncated — re-upload full ~1065 MB model from GitHub Action artifact.';
} else {
    $required[] = 'judge_gguf_ok';
    $required[] = 'text_cli';
}

$failed = array_values(array_filter($required, static fn ($r) => empty($checks[$r])));
$status = $failed === [] ? 200 : 503;
http_response_code($status);
echo json_encode([
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
