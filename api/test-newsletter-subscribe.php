<?php
/**
 * Safe newsletter signup test — POST JSON or ?email= for dry-run diagnostics.
 * Does not expose secrets. Optional send with ?send=1&email=you@example.com
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/newsletter-schema.php';
require_once __DIR__ . '/lib/newsletter-subscribe-lib.php';

$out = [
    'smtpConfigured' => onlybikes_smtp_configured(),
    'newsletterDb' => function_exists('getNewsletterDatabase'),
    'siteOrigin' => onlybikes_site_origin(),
];

try {
    if (function_exists('getNewsletterDatabase')) {
        $pdo = getNewsletterDatabase();
        ensureNewsletterDatabaseSchema($pdo);
        $out['newsletterDbOk'] = true;
        $cols = $pdo->query('SHOW COLUMNS FROM newsletter_subscribers')->fetchAll(PDO::FETCH_COLUMN);
        $out['subscriberColumns'] = $cols;
    }
} catch (Throwable $e) {
    $out['newsletterDbOk'] = false;
    $out['newsletterDbError'] = $e->getMessage();
}

$send = isset($_GET['send']) && $_GET['send'] === '1';
$email = trim((string) ($_GET['email'] ?? ''));
$name = trim((string) ($_GET['name'] ?? 'Test'));

if ($send && $email !== '') {
    $result = onlybikes_newsletter_subscribe($email, $name);
    $out['subscribeResult'] = $result;
} else {
    $out['hint'] = 'Add ?send=1&email=your@email.com to run a live signup + confirmation email test.';
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
