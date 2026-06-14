<?php
require_once __DIR__ . '/config.php';
session_name(EMAIL_ADMIN_SESSION);
session_start();
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getNewsletterDatabase();
require_once API_PATH . '/lib/newsletter-schema.php';
ensureNewsletterDatabaseSchema($pdo);

// Handle actions
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        die('Invalid CSRF token');
    }
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'retry' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("UPDATE email_queue SET status='pending', retry_count=0, last_error=NULL WHERE id=?");
            $stmt->execute([$_POST['id']]);
            $message = '✅ Email reset to pending';
        }
        if ($_POST['action'] === 'clear_all_pending') {
            $pdo->exec("DELETE FROM email_queue WHERE status='pending'");
            $message = '✅ All pending emails cleared';
        }
        if ($_POST['action'] === 'process_batch') {
            include __DIR__ . '/process-queue.php';
            $result = processQueue();
            $message = "✅ Processed batch: {$result['sent']} sent, {$result['failed']} failed";
        }
    }
}

$pending = fetchEmailQueueByStatus($pdo, 'pending', 100);
$failed = fetchEmailQueueByStatus($pdo, 'failed', 50);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Queue</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-50 min-h-screen">
    <?php renderNav('queue'); ?>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-6">Email Queue</h1>

        <?php if ($message): ?>
            <div class="bg-green-100 text-green-700 p-4 rounded mb-6"><?= $message ?></div>
        <?php endif; ?>

        <div class="mb-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold">Pending (<?= count($pending) ?>)</h2>
                <div class="space-x-2">
                    <form method="POST" class="inline-block">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="action" value="process_batch">
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            🚀 Run Batch Now
                        </button>
                    </form>
                    <form method="POST" class="inline-block">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="action" value="clear_all_pending">
                        <button type="submit" onclick="return confirm('Clear ALL pending emails? This cannot be undone!')" 
                                class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
                            🗑️ Clear All Pending
                        </button>
                    </form>
                </div>
            </div>

            <?php if (empty($pending)): ?>
                <div class="bg-white p-8 rounded shadow-sm border text-center text-stone-500">
                    No pending emails in the queue
                </div>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($pending as $item): ?>
                    <div class="bg-white p-4 rounded shadow-sm border flex justify-between items-center">
                        <div class="flex-1">
                            <p class="font-medium"><?= htmlspecialchars($item['recipient_email']) ?></p>
                            <p class="text-sm text-stone-600"><?= htmlspecialchars($item['subject']) ?></p>
                            <p class="text-xs text-stone-500">
                                Send after: <?= $item['send_after'] ?>
                                <?php if ($item['retry_count'] > 0): ?>
                                    | Retries: <?= $item['retry_count'] ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="id" value="<?= $item['id'] ?>">
                            <input type="hidden" name="action" value="retry">
                            <button type="submit" class="text-blue-600 hover:underline text-sm">Reset</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <h2 class="text-lg font-bold mb-4">Failed (<?= count($failed) ?>)</h2>
            <?php if (empty($failed)): ?>
                <div class="bg-white p-8 rounded shadow-sm border text-center text-stone-500">
                    No failed emails
                </div>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($failed as $item): ?>
                    <div class="bg-red-50 p-4 rounded shadow-sm border border-red-200">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <p class="font-medium"><?= htmlspecialchars($item['recipient_email']) ?></p>
                                <p class="text-sm"><?= htmlspecialchars($item['subject']) ?></p>
                                <p class="text-xs text-red-700 mt-1">Error: <?= htmlspecialchars($item['last_error']) ?></p>
                                <p class="text-xs text-stone-500">Created: <?= $item['created_at'] ?> | Retries: <?= $item['retry_count'] ?></p>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <input type="hidden" name="action" value="retry">
                                <button type="submit" class="text-blue-600 hover:underline text-sm">Retry</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
