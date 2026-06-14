<?php
require_once __DIR__ . '/config.php';
session_name(EMAIL_ADMIN_SESSION);
session_start();
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update IP whitelist (comma separated)
    $newIPs = array_filter(array_map('trim', explode(',', $_POST['ip_whitelist'])));
    // Update limits
    $newBatchSize = (int)$_POST['batch_size'];
    $newBatchDelay = (int)$_POST['batch_delay'];
    $newDailyCap = (int)$_POST['daily_cap'];
    $newMaxRetries = (int)$_POST['max_retries'];

    // Validate
    if ($newBatchSize < 1 || $newBatchSize > 200) {
        $error = 'Batch size must be between 1 and 200.';
    } elseif ($newDailyCap < 1 || $newDailyCap > 10000) {
        $error = 'Daily cap must be between 1 and 10000.';
    } elseif ($newMaxRetries < 0 || $newMaxRetries > 10) {
        $error = 'Max retries must be between 0 and 10.';
    } else {
        // Read current config file
        $configFile = __DIR__ . '/config.php';
        $content = file_get_contents($configFile);

        // Replace IP whitelist
        $ipListStr = "[\n";
        foreach ($newIPs as $ip) {
            $ipListStr .= "    '" . addslashes($ip) . "',\n";
        }
        $ipListStr .= "]";
        $content = preg_replace('/\$IP_WHITELIST\s*=\s*\[[^\]]*\];/s', "\$IP_WHITELIST = $ipListStr;", $content);

        // Replace limits
        $content = preg_replace("/define\('BATCH_SIZE',\s*\d+\);/", "define('BATCH_SIZE', $newBatchSize);", $content);
        $content = preg_replace("/define\('BATCH_DELAY',\s*\d+\);/", "define('BATCH_DELAY', $newBatchDelay);", $content);
        $content = preg_replace("/define\('DAILY_CAP',\s*\d+\);/", "define('DAILY_CAP', $newDailyCap);", $content);
        $content = preg_replace("/define\('MAX_RETRIES',\s*\d+\);/", "define('MAX_RETRIES', $newMaxRetries);", $content);

        if (file_put_contents($configFile, $content)) {
            $message = '✅ Settings updated successfully. Reload the page to see changes.';
        } else {
            $error = '❌ Failed to write config file. Check file permissions.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-50 min-h-screen">
    <?php renderNav('settings'); ?>

    <div class="max-w-3xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-6">Settings</h1>

        <?php if ($message): ?>
            <div class="bg-green-100 text-green-700 p-4 rounded mb-6"><?= $message ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded mb-6"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST" class="bg-white p-6 rounded shadow-sm border space-y-4">
            <div>
                <label class="block text-sm font-medium mb-1">IP Whitelist (comma-separated)</label>
                <input type="text" name="ip_whitelist" value="<?= htmlspecialchars(implode(', ', $IP_WHITELIST)) ?>" 
                       class="w-full border border-stone-300 rounded px-3 py-2" placeholder="203.0.113.5, 198.51.100.10">
                <p class="text-xs text-stone-500 mt-1">IPs that bypass the secret key. Leave empty to require key for everyone.</p>
            </div>
            
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Batch Size</label>
                    <input type="number" name="batch_size" value="<?= BATCH_SIZE ?>" min="1" max="200" 
                           class="w-full border border-stone-300 rounded px-3 py-2">
                    <p class="text-xs text-stone-500 mt-1">Emails per batch (1-200)</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Batch Delay (seconds)</label>
                    <input type="number" name="batch_delay" value="<?= BATCH_DELAY ?>" min="10" max="300" 
                           class="w-full border border-stone-300 rounded px-3 py-2">
                    <p class="text-xs text-stone-500 mt-1">Wait time between batches</p>
                </div>
            </div>
            
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Daily Cap</label>
                    <input type="number" name="daily_cap" value="<?= DAILY_CAP ?>" min="1" max="10000" 
                           class="w-full border border-stone-300 rounded px-3 py-2">
                    <p class="text-xs text-stone-500 mt-1">Maximum emails per day</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Max Retries</label>
                    <input type="number" name="max_retries" value="<?= MAX_RETRIES ?>" min="0" max="10" 
                           class="w-full border border-stone-300 rounded px-3 py-2">
                    <p class="text-xs text-stone-500 mt-1">Failed email retry attempts</p>
                </div>
            </div>
            
            <div class="pt-4">
                <button type="submit" class="bg-sage-600 text-white px-6 py-2 rounded hover:bg-sage-700">
                    Save Settings
                </button>
            </div>
        </form>

        <div class="mt-8 bg-yellow-50 border border-yellow-200 p-4 rounded">
            <h3 class="font-bold mb-2">⚠️ Security Note</h3>
            <p class="text-sm text-yellow-800 mb-2">
                To change the admin password or secret key, you must manually edit <code>config.php</code> and replace the hashes.
            </p>
            <p class="text-sm text-yellow-800">
                Run <code>generate-hashes.php</code> to generate new hashes with your desired password and secret key.
            </p>
        </div>

        <div class="mt-4 bg-blue-50 border border-blue-200 p-4 rounded">
            <h3 class="font-bold mb-2">📊 Current Configuration</h3>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <div><strong>Batch Size:</strong> <?= BATCH_SIZE ?> emails</div>
                <div><strong>Batch Delay:</strong> <?= BATCH_DELAY ?> seconds</div>
                <div><strong>Daily Cap:</strong> <?= DAILY_CAP ?> emails</div>
                <div><strong>Max Retries:</strong> <?= MAX_RETRIES ?> attempts</div>
                <div class="col-span-2"><strong>Whitelisted IPs:</strong> <?= empty($IP_WHITELIST) ? 'None (secret key required)' : count($IP_WHITELIST) . ' IPs' ?></div>
            </div>
        </div>
    </div>
</body>
</html>
