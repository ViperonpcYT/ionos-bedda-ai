<?php
if (defined('ADMIN_DASHBOARD_INCLUDED')) {
    $result = queueConfirmedSubscribers();
    $queued = $result['queued'] ?? 0;

    // Clean any previous buffered output
    if (ob_get_length()) ob_clean();

    $redirectUrl = 'dashboard.php?' . http_build_query(['queued' => $queued]);
    $seconds = 5;  // ← MUST be defined BEFORE the echo statements

    // ---- ERROR CASE ----
    if (isset($result['error'])) {
        echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Queue Error</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: "Inter", system-ui, -apple-system, sans-serif;
        background: #f5ecd7;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        color: #333;
    }
    .card {
        background: white;
        border-radius: 16px;
        padding: 40px 30px;
        max-width: 420px;
        width: 90%;
        text-align: center;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    .icon { font-size: 48px; margin-bottom: 20px; }
    h2 { margin-bottom: 12px; color: #8b6914; font-weight: 600; }
    p { margin-bottom: 20px; line-height: 1.6; }
    a.button {
        display: inline-block;
        background: #8b6914;
        color: white;
        padding: 12px 28px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 500;
        transition: background 0.2s;
    }
    a.button:hover { background: #6b4f0e; }
</style>
</head>
<body>
<div class="card">
    <div class="icon">⚠️</div>
    <h2>Something went wrong</h2>
    <p>' . htmlspecialchars($result['message'] ?? 'An error occurred while queuing subscribers.') . '</p>
    <a href="dashboard.php" class="button">← Back to Dashboard</a>
</div>
</body>
</html>';
        exit;
    }

    // ---- SUCCESS CASE ----
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Redirecting…</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: "Inter", system-ui, -apple-system, sans-serif;
        background: #f5ecd7;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
        color: #333;
    }
    .card {
        background: white;
        border-radius: 16px;
        padding: 40px 30px;
        max-width: 440px;
        width: 90%;
        text-align: center;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    .status-icon { font-size: 52px; margin-bottom: 16px; }
    h2 { font-weight: 600; margin-bottom: 12px; color: #8b6914; }
    .queued-count { font-size: 18px; color: #555; margin-bottom: 24px; }
    .countdown-box {
        font-size: 20px;
        font-weight: 500;
        color: #4a4a4a;
        background: #f9f5f0;
        padding: 18px;
        border-radius: 12px;
        margin: 10px auto 0;
        max-width: 260px;
    }
    .countdown-number {
        display: inline-block;
        font-size: 36px;
        font-weight: 700;
        color: #8b6914;
        min-width: 50px;
    }
</style>
</head>
<body>
<div class="card">
    <div class="status-icon">✅</div>
    <h2>Subscribers Queued</h2>
    <p class="queued-count"><strong>' . (int)$queued . '</strong> email' . ($queued !== 1 ? 's' : '') . ' added to the queue.</p>
    <div class="countdown-box">
        <span>Redirecting in </span>
        <span class="countdown-number" id="timer">' . $seconds . '</span>
        <span>s…</span>
    </div>
</div>
<script>
    let remaining = ' . (int)$seconds . ';
    const timerEl = document.getElementById("timer");
    const redirectUrl = ' . json_encode($redirectUrl) . ';
    const interval = setInterval(() => {
        remaining--;
        if (remaining <= 0) {
            clearInterval(interval);
            window.location.href = redirectUrl;
        } else {
            timerEl.textContent = remaining;
        }
    }, 1000);
</script>
</body>
</html>';
    exit;
}