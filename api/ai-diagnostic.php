<?php
header('Content-Type: text/plain; charset=utf-8');

$rootDir = '/homepages/6/d4299539843/htdocs';
$llamaDir = $rootDir . '/llama-cli';
$modelPath = $rootDir . '/models/smollm2-135m-instruct-q8_0.gguf';

function firstLine(string $text): string {
    $text = trim($text);
    if ($text === '') return '(no output)';
    $parts = preg_split('/\R/', $text);
    return trim((string)($parts[0] ?? ''));
}

function binaryPrefix(string $binary, string $llamaDir): string {
    $hasBundled = file_exists($llamaDir . '/libssl.so.3') || file_exists($llamaDir . '/libcrypto.so.3');
    if ($hasBundled) {
        return 'LD_LIBRARY_PATH=' . escapeshellarg($llamaDir) . ' ';
    }
    return '';
}

echo "========================================\n";
echo " BEDDA AI DIAGNOSTIC\n";
echo " Generated: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

$binary = null;
foreach (['llama-cli', 'llama-cli-main', 'main'] as $name) {
    $candidate = $llamaDir . '/' . $name;
    if (file_exists($candidate) && !is_dir($candidate)) {
        $binary = $candidate;
        break;
    }
}

echo "[1] FILE CHECKS\n";
echo "Binary path: " . ($binary ?: '(not found)') . "\n";
if ($binary) {
    echo "Binary executable: " . (is_executable($binary) ? 'YES' : 'NO') . "\n";
    echo "Binary size: " . round(filesize($binary) / 1024, 1) . " KB\n";
}
echo "Model path: {$modelPath}\n";
echo "Model exists: " . (file_exists($modelPath) ? 'YES' : 'NO') . "\n";
if (file_exists($modelPath)) {
    echo "Model size: " . round(filesize($modelPath) / 1024 / 1024, 1) . " MB\n";
}
echo "\n";

echo "[2] OPENSSL BUNDLE MODE\n";
$sslBundled = file_exists($llamaDir . '/libssl.so.3');
$cryptoBundled = file_exists($llamaDir . '/libcrypto.so.3');
echo "libssl.so.3 bundled: " . ($sslBundled ? 'YES' : 'NO') . "\n";
echo "libcrypto.so.3 bundled: " . ($cryptoBundled ? 'YES' : 'NO') . "\n";
echo "LD_LIBRARY_PATH mode: " . (($sslBundled || $cryptoBundled) ? 'ENABLED (legacy)' : 'DISABLED (new binary)') . "\n\n";

echo "[3] GLIBC COMPATIBILITY\n";
if (!$binary) {
    echo "Status: SKIPPED (binary missing)\n\n";
} else {
    $required = [];
    $objdump = @shell_exec("objdump -T " . escapeshellarg($binary) . " 2>/dev/null | grep -o 'GLIBC_[0-9]\\+\\.[0-9]\\+\\(\\.[0-9]\\+\\)\?' | sort -u");
    if ($objdump) {
        $required = array_values(array_filter(array_map('trim', explode("\n", $objdump))));
    }
    if (empty($required)) {
        $strs = @shell_exec("strings " . escapeshellarg($binary) . " 2>/dev/null | grep -o 'GLIBC_[0-9]\\+\\.[0-9]\\+\\(\\.[0-9]\\+\\)\?' | sort -u");
        if ($strs) {
            $required = array_values(array_filter(array_map('trim', explode("\n", $strs))));
        }
    }
    $systemLine = trim((string)@shell_exec("ldd --version 2>/dev/null | head -1"));
    $systemVer = '';
    if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $systemLine, $m)) {
        $systemVer = $m[1];
    }
    $maxRequired = '';
    foreach ($required as $v) {
        $n = preg_replace('/^GLIBC_/', '', $v);
        if ($n !== '' && ($maxRequired === '' || version_compare($n, $maxRequired, '>'))) {
            $maxRequired = $n;
        }
    }
    echo "System GLIBC: " . ($systemVer ?: 'UNKNOWN') . "\n";
    echo "Max required GLIBC: " . ($maxRequired ? "GLIBC_{$maxRequired}" : 'UNKNOWN') . "\n";
    if ($systemVer && $maxRequired) {
        echo "Status: " . (version_compare($systemVer, $maxRequired, '>=') ? 'COMPATIBLE' : 'INCOMPATIBLE') . "\n";
    } else {
        echo "Status: UNKNOWN\n";
    }
    echo "\n";
}

echo "[4] BINARY SMOKE TEST\n";
if (!$binary) {
    echo "Status: SKIPPED (binary missing)\n\n";
} else {
    $prefix = binaryPrefix($binary, $llamaDir);
    $helpOut = trim((string)@shell_exec($prefix . escapeshellarg($binary) . ' --help 2>&1'));
    echo "Status: " . ($helpOut !== '' ? 'PASS' : 'FAIL') . "\n";
    echo "First line: " . firstLine($helpOut) . "\n\n";
}

echo "[5] ACTUAL AI TEST (IONOS-SAFE)\n";
if (!$binary || !file_exists($modelPath)) {
    echo "Status: SKIPPED (binary/model missing)\n\n";
} else {
    $prefix = binaryPrefix($binary, $llamaDir);
    $prompt = "Reply with exactly: AI is working";
    $cmd = $prefix
        . escapeshellarg($binary)
        . ' -m ' . escapeshellarg($modelPath)
        . ' -p ' . escapeshellarg($prompt)
        . ' -n 12 -c 512 -t 1 --temp 0.1 -b 32 --no-display-prompt 2>&1';

    $start = microtime(true);
    $out = trim((string)@shell_exec($cmd));
    $elapsed = round(microtime(true) - $start, 2);

    echo "Command: {$cmd}\n";
    echo "Elapsed: {$elapsed}s\n";
    echo "Full output (stdout+stderr):\n---\n" . ($out !== '' ? $out : '(empty)') . "\n---\n";
    
    // Extract the actual generated response (lines after "generate:" that aren't perf stats)
    $lines = explode("\n", $out);
    $responseLines = [];
    $inGenerate = false;
    foreach ($lines as $line) {
        if (strpos($line, 'generate:') !== false) {
            $inGenerate = true;
            continue;
        }
        if ($inGenerate && strpos($line, 'llama_perf') !== false) {
            break;
        }
        if ($inGenerate && trim($line) !== '') {
            $responseLines[] = trim($line);
        }
    }
    $extracted = implode(' ', $responseLines);
    echo "Extracted response: " . ($extracted !== '' ? "\"{$extracted}\"" : '(empty)') . "\n";
    
    // Success if we got response text and no errors in the full output
    $hasError = stripos($out, 'error') !== false || stripos($out, 'failed') !== false || stripos($out, 'abort') !== false;
    $isOk = ($extracted !== '' && !$hasError);
    if ($isOk) {
        echo "Status: AI IS FUNCTIONAL\n";
    } else {
        echo "Status: AI FAILED OR EMPTY\n";
    }
    echo "\n";
}

echo "[6] THREAD LIMIT PROBE\n";
if (!$binary || !file_exists($modelPath)) {
    echo "Status: SKIPPED (binary/model missing)\n";
} else {
    $prefix = binaryPrefix($binary, $llamaDir);
    $probePrompt = 'Reply: ok';
    $t2 = trim((string)@shell_exec(
        $prefix
        . escapeshellarg($binary)
        . ' -m ' . escapeshellarg($modelPath)
        . ' -p ' . escapeshellarg($probePrompt)
        . ' -n 4 -c 256 -t 2 --temp 0.1 -b 16 --no-display-prompt -no-cnv 2>/dev/null'
    ));
    $t1 = trim((string)@shell_exec(
        $prefix
        . escapeshellarg($binary)
        . ' -m ' . escapeshellarg($modelPath)
        . ' -p ' . escapeshellarg($probePrompt)
        . ' -n 4 -c 256 -t 1 --temp 0.1 -b 16 --no-display-prompt -no-cnv 2>/dev/null'
    ));

    if ($t2 === '' && $t1 !== '') {
        echo "Result: Likely thread restricted on hosting. Use -t 1.\n";
    } else {
        echo "Result: No clear thread-only failure detected.\n";
    }
}

echo "\n========================================\n";
echo " END OF DIAGNOSTIC\n";
echo "========================================\n";
