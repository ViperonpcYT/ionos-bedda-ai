<?php

require_once __DIR__ . '/config.php';
session_name(EMAIL_ADMIN_SESSION);
session_start();

// Check login AFTER session is started
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getNewsletterDatabase();
require_once API_PATH . '/lib/newsletter-schema.php';
ensureNewsletterDatabaseSchema($pdo);

// Get stats (auto-adds missing columns on legacy IONOS tables when possible)
$totalSubscribers = countConfirmedNewsletterSubscribers($pdo);
$queueStats = getEmailQueueDashboardStats($pdo);
$pending = $queueStats['pending'];
$sentToday = $queueStats['sentToday'];
$failed = $queueStats['failed'];
$recentEmails = $queueStats['recent'];

// Handle newsletter queued message
$queuedMessage = '';
if (isset($_GET['queued'])) {
    $queuedMessage = "✅ Newsletter queued for " . (int)$_GET['queued'] . " subscribers!";
}

// Handle actions
$testResult = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check for all POST requests
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        http_response_code(403);
        die('Invalid CSRF token');
    }

    if (isset($_POST['action'])) {

        // Handle: Queue Newsletter to All Subscribers
        if ($_POST['action'] === 'queue_newsletter' && !empty($_POST['subject']) && !empty($_POST['html'])) {
            define('ADMIN_DASHBOARD_INCLUDED', true);
            require_once __DIR__ . '/queue-newsletter.php';
            // queue-newsletter.php handles redirect or JSON response
            exit;
        }

        // Handle: Send Test Email
        elseif ($_POST['action'] === 'send_test' && !empty($_POST['test_email']) && !empty($_POST['subject']) && !empty($_POST['html'])) {
            $testEmail = filter_var($_POST['test_email'], FILTER_VALIDATE_EMAIL);
            if (!$testEmail) {
                $testResult = '❌ Invalid test email address.';
            } else {
                $subject = $_POST['subject'];
                $html = $_POST['html'];
                $text = $_POST['text'] ?? strip_tags($html);

                // Use sendSmtpEmail from secure-config.php
                $success = sendSmtpEmail($testEmail, 'Test User', $subject, $html, $text);

                $testResult = $success
                    ? '✅ Test email sent successfully.'
                    : '❌ Failed to send test email. Check /api/php-errors.log';
            }
        }

        // Unknown action
        else {
            $testResult = '❌ Unknown action: ' . htmlspecialchars($_POST['action']);
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>OnlyBikes Email Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-stone-50 min-h-screen">
    <?php renderNav('dashboard'); ?>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <?php if ($queuedMessage): ?>
            <div class="mb-6 p-4 rounded bg-green-100 text-green-700">
                <?= htmlspecialchars($queuedMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($testResult): ?>
            <div class="mb-6 p-4 rounded <?= strpos($testResult, '✅') !== false ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                <?= $testResult ?>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white p-6 rounded-lg shadow-sm border">
                <p class="text-sm text-stone-600">Confirmed Subscribers</p>
                <p class="text-3xl font-bold text-stone-800"><?= $totalSubscribers ?></p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm border">
                <p class="text-sm text-stone-600">Pending Emails</p>
                <p class="text-3xl font-bold text-stone-800"><?= $pending ?></p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm border">
                <p class="text-sm text-stone-600">Sent Today</p>
                <p class="text-3xl font-bold text-stone-800"><?= $sentToday ?></p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm border">
                <p class="text-sm text-stone-600">Failed</p>
                <p class="text-3xl font-bold text-stone-800"><?= $failed ?></p>
            </div>
        </div>

        <!-- Newsletter Composer -->
        <div class="bg-white rounded-lg shadow-sm border p-6">
            <h2 class="text-lg font-bold mb-4">Create Newsletter</h2>
            <form method="POST" id="newsletterForm">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

                <input type="hidden" name="action" value="queue_newsletter">
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">Subject</label>
                    <input type="text" name="subject" id="subject" required class="w-full border border-stone-300 rounded px-3 py-2">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">HTML Content</label>
                    <textarea name="html" id="html" rows="10" required class="w-full border border-stone-300 rounded px-3 py-2 font-mono text-sm"></textarea>
                    <p class="text-xs text-stone-500 mt-1">Full HTML email template</p>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">Plain Text (optional – auto-generated if empty)</label>
                    <textarea name="text" id="text" rows="5" class="w-full border border-stone-300 rounded px-3 py-2 font-mono text-sm"></textarea>
                </div>
                <div class="flex space-x-4">
                    <button type="submit" class="bg-sage-600 text-white px-6 py-2 rounded hover:bg-sage-700">
                        Queue Newsletter to All Subscribers
                    </button>
                    <button type="button" onclick="preview()" class="bg-stone-200 text-stone-800 px-6 py-2 rounded hover:bg-stone-300">
                        Preview HTML
                    </button>
                </div>
            </form>

            <hr class="my-6">

            <h3 class="font-medium mb-3">Send Test Email</h3>
            <form method="POST" class="grid md:grid-cols-3 gap-4 items-end">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="action" value="send_test">
                
                <div>
                    <label class="block text-sm font-medium mb-1">Test Email Address</label>
                    <input type="email" name="test_email" required class="w-full border border-stone-300 rounded px-3 py-2" placeholder="your@email.com">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Subject (copy from above)</label>
                    <input type="text" name="subject" required class="w-full border border-stone-300 rounded px-3 py-2" id="test_subject">
                </div>
                <div>
                    <button type="button" onclick="copyToTest()" class="w-full bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                        Copy & Send Test
                    </button>
                </div>
                <input type="hidden" name="html" id="test_html">
                <input type="hidden" name="text" id="test_text">
            </form>
            <p class="text-xs text-stone-500 mt-2">Click "Copy & Send Test" to send a test email with the content above</p>
        </div>
        <!-- Recent Activity -->
        <div class="mt-8 bg-white rounded-lg shadow-sm border p-6">
            <h2 class="text-lg font-bold mb-4">Recent Activity</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b text-stone-500">
                            <th class="pb-2 font-medium">Recipient</th>
                            <th class="pb-2 font-medium">Status</th>
                            <th class="pb-2 font-medium">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($recentEmails as $row): ?>
                        <tr>
                            <td class="py-3"><?= htmlspecialchars($row['email']) ?></td>
                            <td class="py-3">
                                <span class="px-2 py-1 rounded-full text-xs font-semibold 
                                    <?= $row['status'] === 'sent' ? 'bg-green-100 text-green-700' : 'bg-sage-100 text-sage-700' ?>">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                            <td class="py-3 text-stone-500"><?= $row['sent_at'] ?: 'Pending' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function preview() {
            const subject = document.getElementById('subject').value;
            const html = document.getElementById('html').value;
            const win = window.open('', '_blank');
            win.document.write('<html><head><title>' + subject + '</title></head><body>' + html + '</body></html>');
        }

        function copyToTest() {
            // Copy values from main form to test form
            document.getElementById('test_subject').value = document.getElementById('subject').value;
            document.getElementById('test_html').value = document.getElementById('html').value;
            document.getElementById('test_text').value = document.getElementById('text').value;
            
            // Submit the test form
            const testEmail = document.querySelector('input[name="test_email"]').value;
            if (!testEmail) {
                alert('Please enter a test email address');
                return;
            }
            if (!document.getElementById('subject').value || !document.getElementById('html').value) {
                alert('Please fill in subject and HTML content first');
                return;
            }
            
            // Submit the form
            document.querySelector('form[method="POST"]:last-of-type').submit();
        }
    </script>
</body>
</html>
