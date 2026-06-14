<?php
/**
 * Newsletter confirmation — double opt-in token handler.
 */
declare(strict_types=1);

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/security-helpers.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/newsletter-schema.php';
require_once __DIR__ . '/lib/newsletter-subscribe-lib.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '' || strlen($token) < 32) {
    onlybikes_newsletter_confirm_page('Invalid confirmation link', false);
    exit;
}

try {
    $pdo = getNewsletterDatabase();
    ensureNewsletterDatabaseSchema($pdo);

    $stmt = $pdo->prepare(
        "UPDATE newsletter_subscribers
         SET status = 'confirmed', confirmed_at = NOW(), updated_at = NOW()
         WHERE token = ? AND status = 'pending'"
    );
    $stmt->execute([$token]);

    if ($stmt->rowCount() === 1) {
        $subStmt = $pdo->prepare(
            'SELECT email, name, unsubscribe_token FROM newsletter_subscribers WHERE token = ? LIMIT 1'
        );
        $subStmt->execute([$token]);
        $subscriber = $subStmt->fetch(PDO::FETCH_ASSOC);

        if ($subscriber) {
            onlybikes_send_newsletter_welcome(
                (string) $subscriber['email'],
                (string) ($subscriber['name'] ?? ''),
                (string) ($subscriber['unsubscribe_token'] ?? '')
            );
        }

        onlybikes_newsletter_confirm_page(
            'Subscription confirmed!',
            true,
            'Thanks for subscribing to OnlyBikes. A welcome email is on its way.'
        );
        exit;
    }

    $checkStmt = $pdo->prepare('SELECT status FROM newsletter_subscribers WHERE token = ? LIMIT 1');
    $checkStmt->execute([$token]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && ($existing['status'] ?? '') === 'confirmed') {
        onlybikes_newsletter_confirm_page(
            'Already confirmed',
            true,
            'Your newsletter subscription is already active.'
        );
    } else {
        onlybikes_newsletter_confirm_page(
            'Invalid or expired link',
            false,
            'This confirmation link is invalid or has expired. Please subscribe again from the site.'
        );
    }
} catch (Throwable $e) {
    error_log('[OnlyBikes] Newsletter confirm error: ' . $e->getMessage());
    onlybikes_newsletter_confirm_page(
        'Something went wrong',
        false,
        'Please try again later or contact support@onlybikes.shop.'
    );
}

function onlybikes_newsletter_confirm_page(string $title, bool $success, string $message = ''): void
{
    $origin = onlybikes_site_origin();
    $icon = $success ? '✅' : '❌';
    $color = $success ? '#3d5a45' : '#dc2626';
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $safeTitle ?> — OnlyBikes</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f5f5f4;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #fff;
            padding: 48px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
            max-width: 480px;
            width: 100%;
        }
        .icon { font-size: 56px; margin-bottom: 16px; }
        h1 { color: <?= $color ?>; margin-bottom: 12px; font-size: 26px; }
        p { color: #57534e; margin-bottom: 28px; line-height: 1.6; }
        .btn {
            display: inline-block;
            background: #4a7c59;
            color: #fff;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
        }
        .btn:hover { background: #3d5a45; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon"><?= $icon ?></div>
        <h1><?= $safeTitle ?></h1>
        <?php if ($message !== ''): ?>
        <p><?= $safeMessage ?></p>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') ?>" class="btn">Back to OnlyBikes</a>
    </div>
    <script>
        if (window.self !== window.top) {
            window.top.location.href = window.location.href;
        }
    </script>
</body>
</html>
    <?php
}
