<?php
require_once __DIR__ . '/config.php';
session_name(EMAIL_ADMIN_SESSION);
session_start();
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getNewsletterDatabase();
require_once API_PATH . '/lib/newsletter-schema.php';
ensureNewsletterDatabaseSchema($pdo);

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
            require_once __DIR__ . '/process-queue.php';
            $result = processQueue();
            $message = "✅ Processed batch: {$result['sent']} sent, {$result['failed']} failed";
        }
    }
}

$pending = fetchEmailQueueByStatus($pdo, 'pending', 100);
$sent = fetchEmailQueueByStatus($pdo, 'sent', 50);
$failed = fetchEmailQueueByStatus($pdo, 'failed', 50);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Logs</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-50 min-h-screen">
    <?php renderNav('logs'); ?>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-6">Email Logs</h1>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded bg-green-100 text-green-700"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="grid md:grid-cols-2 gap-8">
            <!-- Sent Emails -->
            <div>
                <h2 class="text-lg font-bold mb-4">Recent Sent (<?= count($sent) ?>)</h2>
                <?php if (empty($sent)): ?>
                    <div class="bg-white p-8 rounded shadow-sm border text-center text-stone-500">
                        No sent emails yet
                    </div>
                <?php else: ?>
                    <div class="space-y-2 max-h-[600px] overflow-y-auto">
                        <?php foreach ($sent as $e): ?>
                        <div class="bg-white p-3 rounded shadow-sm border text-sm">
                            <p><strong><?= htmlspecialchars($e['recipient_email']) ?></strong></p>
                            <p class="text-stone-600"><?= htmlspecialchars($e['subject']) ?></p>
                            <p class="text-stone-500 text-xs"><?= $e['sent_at'] ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Failed Emails -->
            <div>
                <h2 class="text-lg font-bold mb-4">Recent Failures (<?= count($failed) ?>)</h2>
                <?php if (empty($failed)): ?>
                    <div class="bg-white p-8 rounded shadow-sm border text-center text-stone-500">
                        No failed emails
                    </div>
                <?php else: ?>
                    <div class="space-y-2 max-h-[600px] overflow-y-auto">
                        <?php foreach ($failed as $e): ?>
                        <div class="bg-red-50 p-3 rounded shadow-sm border text-sm">
                            <p><strong><?= htmlspecialchars($e['recipient_email']) ?></strong></p>
                            <p class="text-stone-600"><?= htmlspecialchars($e['subject']) ?></p>
                            <p class="text-red-700 text-xs">Error: <?= htmlspecialchars($e['last_error']) ?></p>
                            <p class="text-stone-500 text-xs"><?= $e['created_at'] ?></p>
                            <form method="POST" class="mt-2">
                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                <button type="submit" class="text-blue-600 hover:underline text-xs">Retry</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>