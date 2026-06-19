<?php
declare(strict_types=1);

/**
 * Validate PvP opponent manifest vs on-disk assets.
 * Run: php api/roast-limited/validate-pvp-manifest.php
 *
 * CI: .github/workflows/roast-pvp-ci.yml job pvp-manifest (push + PR, PHP 8.2, no secrets).
 * Opponent video/reference files under images/pvp-opponents/ must be committed with the
 * manifest — GitHub Actions only sees the checkout; server/SFTP uploads do not satisfy CI.
 */

$root = dirname(__DIR__, 2);
$manifestPath = dirname(__DIR__) . '/data/pvp-opponents.json';

$errors = [];
$warnings = [];
$opponents = [];

if (!is_readable($manifestPath)) {
    $errors[] = ['code' => 'manifest_missing', 'message' => 'Manifest not readable: ' . $manifestPath];
} else {
    $raw = file_get_contents($manifestPath);
    if ($raw === false) {
        $errors[] = ['code' => 'manifest_read_failed', 'message' => 'Could not read manifest'];
    } else {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $errors[] = ['code' => 'manifest_invalid_json', 'message' => 'Manifest is not valid JSON'];
        } elseif (!isset($data['opponents']) || !is_array($data['opponents'])) {
            $errors[] = ['code' => 'manifest_missing_opponents', 'message' => 'Manifest must contain an opponents array'];
        } else {
            $opponents = $data['opponents'];
        }
    }
}

$resolveAsset = static function (string $path) use ($root): string {
    $path = trim($path);
    if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return '';
    }
    $full = $root . '/' . ltrim(str_replace('\\', '/', $path), '/');
    return is_readable($full) ? $full : '';
};

$seenIds = [];
$rows = [];

foreach ($opponents as $index => $row) {
    if (!is_array($row)) {
        $errors[] = [
            'code' => 'opponent_not_object',
            'index' => $index,
            'message' => 'Opponent entry must be an object',
        ];
        continue;
    }

    $id = trim((string) ($row['id'] ?? ''));
    $enabled = !isset($row['enabled']) || $row['enabled'] !== false;
    $video = trim((string) ($row['video'] ?? ''));
    $reference = trim((string) ($row['reference_image'] ?? ''));
    $startingScore = $row['starting_score'] ?? null;

    $entryErrors = [];

    if ($id === '') {
        $entryErrors[] = 'missing id';
    } elseif (isset($seenIds[$id])) {
        $entryErrors[] = 'duplicate id (first at index ' . $seenIds[$id] . ')';
    } else {
        $seenIds[$id] = $index;
    }

    if ($startingScore === null || $startingScore === '') {
        $entryErrors[] = 'missing starting_score';
    } elseif (!is_numeric($startingScore)) {
        $entryErrors[] = 'starting_score must be numeric';
    } else {
        $score = (int) $startingScore;
        if ($score < 0 || $score > 100) {
            $entryErrors[] = 'starting_score must be 0-100 (got ' . $score . ')';
        }
        if ((string) (int) $startingScore !== (string) $startingScore && !is_int($startingScore)) {
            $entryErrors[] = 'starting_score should be an integer';
        }
    }

    $videoOk = false;
    if ($video === '') {
        if ($enabled) {
            $entryErrors[] = 'enabled opponent missing video path';
        }
    } elseif ($resolveAsset($video) === '') {
        $entryErrors[] = 'video file missing or unreadable: ' . $video;
    } else {
        $videoOk = true;
    }

    $refOk = null;
    if ($reference === '') {
        $refOk = null;
        if ($enabled) {
            $warnings[] = [
                'code' => 'reference_image_empty',
                'id' => $id,
                'message' => 'Enabled opponent has no reference_image',
            ];
        }
    } elseif ($resolveAsset($reference) === '') {
        $entryErrors[] = 'reference_image missing or unreadable: ' . $reference;
        $refOk = false;
    } else {
        $refOk = true;
    }

    foreach ([$video, $reference] as $assetPath) {
        if ($assetPath === '') {
            continue;
        }
        $assetBase = basename(str_replace('\\', '/', $assetPath));
        if (preg_match('/\bStarg\b/i', $assetBase)) {
            $entryErrors[] = 'asset filename typo: use Stark not Starg (' . $assetBase . ')';
        }
    }

    if (str_contains(strtolower($id), 'stark')) {
        foreach ([$video, $reference] as $assetPath) {
            if ($assetPath === '') {
                continue;
            }
            $base = basename(str_replace('\\', '/', $assetPath));
            if (!preg_match('/\bStark\b/i', $base)) {
                $entryErrors[] = 'stark opponent asset should include Stark in filename: ' . $base;
            }
        }
    }

    $identity = is_array($row['identity'] ?? null) ? $row['identity'] : [];
    $inspect = is_array($row['inspect'] ?? null) ? $row['inspect'] : [];
    if ($identity !== []) {
        if (isset($identity['confidence']) && is_numeric($identity['confidence'])) {
            $conf = (float) $identity['confidence'];
            if ($conf < 0 || $conf > 1) {
                $entryErrors[] = 'identity.confidence must be 0-1 (got ' . $conf . ')';
            }
        }
    }
    if ($inspect !== []) {
        if (isset($inspect['cleanliness_score']) && is_numeric($inspect['cleanliness_score'])) {
            $clean = (int) $inspect['cleanliness_score'];
            if ($clean < 0 || $clean > 10) {
                $entryErrors[] = 'inspect.cleanliness_score must be 0-10 (got ' . $clean . ')';
            }
        }
    }
    if ($identity !== [] && $inspect !== [] && isset($inspect['cleanliness_score']) && is_numeric($startingScore ?? '')) {
        require_once dirname(__DIR__) . '/lib/roast-score.php';
        $cascadeScore = roast_compute_pvp_cred_score($identity, $inspect);
        if ($cascadeScore < 0 || $cascadeScore > 100) {
            $entryErrors[] = 'pre-graded identity+inspect cascade score must be 0-100 (got ' . $cascadeScore . ')';
        }
    }

    foreach ($entryErrors as $msg) {
        $errors[] = [
            'code' => 'opponent_invalid',
            'index' => $index,
            'id' => $id !== '' ? $id : null,
            'message' => $msg,
        ];
    }

    $rows[] = [
        'index' => $index,
        'id' => $id,
        'enabled' => $enabled,
        'starting_score' => is_numeric($startingScore ?? '') ? (int) $startingScore : null,
        'video' => $video,
        'video_ok' => $videoOk,
        'reference_image' => $reference,
        'reference_ok' => $refOk,
        'ok' => $entryErrors === [],
    ];
}

$assetDir = $root . '/images/pvp-opponents';
$referencedBasenames = [];
foreach ($rows as $row) {
    foreach (['video', 'reference_image'] as $field) {
        $path = trim((string) ($row[$field] ?? ''));
        if ($path !== '') {
            $referencedBasenames[basename(str_replace('\\', '/', $path))] = true;
        }
    }
}

$orphaned = [];
if (is_dir($assetDir)) {
    foreach (glob($assetDir . '/*') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $base = basename($file);
        if ($base === '.gitkeep') {
            continue;
        }
        if (preg_match('/\bStarg\b/i', $base)) {
            $errors[] = [
                'code' => 'stark_filename_typo',
                'file' => $base,
                'message' => 'Rename asset to Stark (not Starg): ' . $base,
            ];
        }
        if (!isset($referencedBasenames[$base])) {
            $orphaned[] = $base;
        }
    }
    sort($orphaned, SORT_STRING);
}

if ($orphaned !== []) {
    $warnings[] = [
        'code' => 'orphaned_assets',
        'files' => $orphaned,
        'message' => 'Files in images/pvp-opponents not referenced by manifest',
    ];
}

$ok = $errors === [];
$report = [
    'ok' => $ok,
    'manifest' => $manifestPath,
    'opponent_count' => count($rows),
    'enabled_count' => count(array_filter($rows, static fn(array $r): bool => $r['enabled'])),
    'errors' => $errors,
    'warnings' => $warnings,
    'opponents' => $rows,
    'orphaned_assets' => $orphaned,
];

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($ok ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
http_response_code($ok ? 200 : 422);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
