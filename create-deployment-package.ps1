# Create Deployment Package for Ionos AI Setup (GLIBC 2.31 build + Qwen2.5-0.5B)
#
# BEFORE RUNNING THIS SCRIPT:
#   1. Push .github/workflows/build-llama-ionos.yml to a GitHub repo
#   2. Run the workflow from the GitHub Actions tab
#   3. Download BOTH artifacts:
#      - "llama-cli-ionos" (contains llama-cli binary)
#      - "qwen2.5-0.5b-instruct-q4_k_m" (contains quantized model)
#   4. Place the extracted files at:
#      - .\llama-cli\llama-cli-new
#      - .\models\qwen2.5-0.5b-instruct-q4_k_m.gguf
#
# This script packages:
#   - The new GLIBC 2.31-compatible binary (llama-cli-new -> llama-cli on server)
#   - The new Qwen2.5-0.5B-Instruct q4_k_m model
#   - The updated PHP files (ai-diagnostic.php, ai-engine.php, secure-config.php)

$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$packageName = "bedda-ai-glibc231-$timestamp.zip"
$packagePath = "$PWD\$packageName"

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "  Creating Deployment Package (GLIBC 2.31)" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan

# Check for the new binary built by GitHub Actions
$newBinary = "llama-cli\llama-cli-new"
if (-not (Test-Path $newBinary)) {
    Write-Host "`nERROR: New binary not found at: $newBinary" -ForegroundColor Red
    Write-Host ""
    Write-Host "TO GET THE NEW BINARY:" -ForegroundColor Yellow
    Write-Host "  1. Push this repo to GitHub (the .github/workflows/ folder is already set up)" -ForegroundColor White
    Write-Host "  2. Go to GitHub -> Actions -> 'Build llama-cli + Qwen2.5-0.5B for Ionos'" -ForegroundColor White
    Write-Host "  3. Click 'Run workflow'" -ForegroundColor White
    Write-Host "  4. Wait ~15 min, download the 'llama-cli-ionos' artifact" -ForegroundColor White
    Write-Host "  5. Extract it and place the 'llama-cli' file at: .\llama-cli\llama-cli-new" -ForegroundColor White
    exit 1
}

$binarySize = (Get-Item $newBinary).Length
Write-Host "New binary found: $([math]::Round($binarySize / 1KB, 1)) KB" -ForegroundColor Green

# Check for the new Qwen model built by GitHub Actions
$newModel = "models\qwen2.5-0.5b-instruct-q4_k_m.gguf"
if (-not (Test-Path $newModel)) {
    Write-Host "`nERROR: New model not found at: $newModel" -ForegroundColor Red
    Write-Host ""
    Write-Host "TO GET THE NEW MODEL:" -ForegroundColor Yellow
    Write-Host "  1. Run the GitHub Actions workflow (same as above)" -ForegroundColor White
    Write-Host "  2. Download the 'qwen2.5-0.5b-instruct-q4_k_m' artifact" -ForegroundColor White
    Write-Host "  3. Extract it and place the .gguf file at: .\models\qwen2.5-0.5b-instruct-q4_k_m.gguf" -ForegroundColor White
    exit 1
}

$modelSize = (Get-Item $newModel).Length
Write-Host "New model found: $([math]::Round($modelSize / 1MB, 1)) MB" -ForegroundColor Green

# PHP files to include (analytics only - AI files were removed)
$phpFiles = @(
    "api\config.php",
    "api\log-event.php",
    "api\get-analytics.php",
    "api\get-summary.php",
    "api\.htaccess"
)

# Frontend files to include (widget + analytics dashboard updates + mobile header fix + logger fixes)
$frontendFiles = @(
    "bedda-ai.js",
    "logger-backend.js",
    "analytics-backend.html",
    "index.html",
    "about.html",
    "products.html",
    "ingredients.html",
    "bundles.html",
    "build-loaf.html",
    "contact.html",
    "checkout-success.html",
    ".htaccess"
)

$missing = @()
foreach ($file in $phpFiles) {
    if (-not (Test-Path $file)) { $missing += $file }
}
if ($missing.Count -gt 0) {
    Write-Host "`nERROR: Missing PHP files:" -ForegroundColor Red
    foreach ($f in $missing) { Write-Host "  - $f" -ForegroundColor Red }
    exit 1
}

# Create temp directory
$tempDir = "$env:TEMP\bedda-deploy-$timestamp"
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null
New-Item -ItemType Directory -Path "$tempDir\llama-cli" -Force | Out-Null
New-Item -ItemType Directory -Path "$tempDir\models" -Force | Out-Null
New-Item -ItemType Directory -Path "$tempDir\api" -Force | Out-Null

# Copy binary (rename to llama-cli for the server)
Copy-Item $newBinary "$tempDir\llama-cli\llama-cli" -Force

# Copy model
Copy-Item $newModel "$tempDir\models\qwen2.5-0.5b-instruct-q4_k_m.gguf" -Force

# Copy PHP files
foreach ($file in $phpFiles) {
    $dest = "$tempDir\" + $file.Replace("\", "\")
    Copy-Item $file $dest -Force
}

# Copy frontend files (widget + updated HTML pages)
$missingFrontend = @()
foreach ($file in $frontendFiles) {
    if (-not (Test-Path $file)) { $missingFrontend += $file; continue }
    $dest = "$tempDir\" + $file
    $destDir = Split-Path -Parent $dest
    if ($destDir -and -not (Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir -Force | Out-Null }
    Copy-Item $file $dest -Force
}
if ($missingFrontend.Count -gt 0) {
    Write-Host "`nWARNING: Missing frontend files (will skip):" -ForegroundColor Yellow
    foreach ($f in $missingFrontend) { Write-Host "  - $f" -ForegroundColor Yellow }
}

# Create README
$readme = @"
BEDDA AI UPGRADE — Qwen2.5-0.5B + GLIBC 2.31 BUILD
=====================================================
Created: $(Get-Date)

UPGRADE SUMMARY:
- Upgraded from SmolLM2-135M to Qwen2.5-0.5B-Instruct (4x larger, much smarter)
- Quantized to q4_k_m (~300-400MB) to fit within Ionos 640MB memory limit
- Binary rebuilt on Ubuntu 20.04 for GLIBC 2.31 compatibility

CONTENTS:
---------
llama-cli/
  llama-cli        (NEW binary — built on Ubuntu 20.04, GLIBC 2.31 compatible)

models/
  qwen2.5-0.5b-instruct-q4_k_m.gguf  (NEW model — Qwen2.5-0.5B quantized to q4_k_m)

api/
  ai-diagnostic.php   (updated — GLIBC compatibility checks)
  ai-engine.php       (updated — curated responses, self-evaluation guardrails)
  secure-config.php   (updated — points to new model path)

DEPLOYMENT STEPS:
-----------------
1. BACKUP OLD MODEL (important!):
   SSH or FTP to: /homepages/6/d4299539843/htdocs/models/
   Rename: smollm2-135m-instruct-q8_0.gguf -> smollm2-135m-backup.gguf

2. Upload llama-cli/llama-cli to: /homepages/6/d4299539843/htdocs/llama-cli/
   - BINARY mode, permissions 755

3. Upload models/qwen2.5-0.5b-instruct-q4_k_m.gguf to: /homepages/6/d4299539843/htdocs/models/

4. Upload api/ files to: /homepages/6/d4299539843/htdocs/api/

5. Run diagnostic: https://onlybikes.example/api/ai-diagnostic.php
   Look for:
     [5.5] GLIBC COMPATIBILITY: Status: COMPATIBLE
     [9]   ACTUAL AI TEST: AI IS FUNCTIONAL

6. Test: https://onlybikes.example/api/ai-engine.php?ping=1
   Should return: {"success":true,"available":true,...}

ROLLBACK IF NEEDED:
-------------------
If Qwen model causes issues (OOM, too slow, etc.):
1. Edit api/.env or api/secure-config.php
2. Change AI_MODEL_PATH back to: /homepages/6/d4299539843/htdocs/models/smollm2-135m-backup.gguf
3. No need to re-upload binary — llama-cli works with both models

TROUBLESHOOTING:
----------------
- Still see GLIBC errors? The old binary is still there. Re-upload.
- "Permission denied"? chmod 755 the binary via SSH or Ionos file manager.
- Diagnostic GLIBC INCOMPATIBLE? Wrong build — must use ubuntu-20.04 runner.
- OOM errors? Model too large for memory. Revert to backup SmolLM2 model.
- Too slow (>8s)? Expected for 0.5B model. Consider reducing context length in config.
"@

$readme | Out-File -FilePath "$tempDir\README.txt" -Encoding UTF8

# Create zip
Compress-Archive -Path "$tempDir\*" -DestinationPath $packagePath -Force
Remove-Item $tempDir -Recurse -Force

Write-Host "`nPackage created: $packageName" -ForegroundColor Green
Write-Host "Location: $packagePath" -ForegroundColor Green
Write-Host ""
Write-Host "UPLOAD INSTRUCTIONS:" -ForegroundColor Yellow
Write-Host "  1. FTP: llama-cli/llama-cli  ->  /homepages/6/d4299539843/htdocs/llama-cli/llama-cli (mode: BINARY, chmod 755)" -ForegroundColor White
Write-Host "  2. FTP: models/*.gguf         ->  /homepages/6/d4299539843/htdocs/models/" -ForegroundColor White
Write-Host "  3. FTP: api/*.php            ->  /homepages/6/d4299539843/htdocs/api/" -ForegroundColor White
Write-Host "  4. Test: https://onlybikes.example/api/ai-diagnostic.php" -ForegroundColor White
