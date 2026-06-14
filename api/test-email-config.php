<?php
/**
 * Safe mail + newsletter config check (no secrets sent). Delete or protect on production.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/security-helpers.php';
require_once __DIR__ . '/lib/mail.php';

echo "OnlyBikes email diagnostics\n";
echo "===========================\n\n";

echo 'SITE_ORIGIN: ' . onlybikes_site_origin() . "\n";
echo 'ADMIN_EMAIL: ' . onlybikes_admin_email() . "\n";
echo 'SUPPORT_EMAIL: ' . onlybikes_support_email() . "\n";
echo 'SMTP_FROM: ' . (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '(undefined)') . "\n";
echo 'SMTP_HOST: ' . (defined('SMTP_HOST') ? SMTP_HOST : '(undefined)') . "\n";
echo 'SMTP_PORT: ' . (defined('SMTP_PORT') ? (string) SMTP_PORT : '(undefined)') . "\n";
echo 'SMTP_USER set: ' . (defined('SMTP_USERNAME') && SMTP_USERNAME !== '' ? 'yes' : 'NO') . "\n";
echo 'SMTP_PASS set: ' . (defined('SMTP_PASSWORD') && SMTP_PASSWORD !== '' ? 'yes' : 'NO') . "\n";
echo 'PHPMailer: ' . (loadPHPMailer() ? 'ok' : 'MISSING') . "\n";
echo 'sendSmtpEmail: ' . (function_exists('sendSmtpEmail') ? 'ok' : 'MISSING') . "\n";
echo 'SMTP ready: ' . (onlybikes_smtp_configured() ? 'yes' : 'NO — emails will not send') . "\n\n";

if (function_exists('getNewsletterDatabase')) {
    try {
        $pdo = getNewsletterDatabase();
        $pdo->query('SELECT 1');
        echo 'Newsletter DB: OK → ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
    } catch (Throwable $e) {
        echo 'Newsletter DB: FAIL — ' . $e->getMessage() . "\n";
        echo "Add NEWSLETTER_DB_* to api/.env (see IONOS-DATABASES.md)\n";
    }
} else {
    echo "Newsletter DB: getNewsletterDatabase() missing from secure-config.php\n";
}

echo "\nTo send a test message:\n";
echo "api/test-mail.php?key=YOUR_KEY&to=your@email.com\n";
