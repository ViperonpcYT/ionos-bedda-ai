<?php
/**
 * Shared newsletter signup logic — used by newsletter-subscribe.php and checkout.
 */
declare(strict_types=1);

require_once __DIR__ . '/newsletter-schema.php';
require_once __DIR__ . '/mail.php';

if (!function_exists('onlybikes_send_newsletter_confirmation')) {
    function onlybikes_send_newsletter_confirmation(string $email, string $name, string $token): bool
    {
        $origin = onlybikes_site_origin();
        $confirmUrl = $origin . '/api/newsletter-confirm.php?token=' . urlencode($token);
        $safeName = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8');
        $subject = 'Confirm your OnlyBikes newsletter subscription';

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Confirm Subscription</title></head>
<body style="font-family:Inter,sans-serif;max-width:600px;margin:0 auto;padding:20px;background:#f5f5f4">
<div style="background:#fff;padding:30px;border-radius:8px;text-align:center">
<h1 style="color:#3d5a45">OnlyBikes</h1>
<p>Hi {$safeName},</p>
<p>Thanks for subscribing. Confirm your email to get product drops and deals:</p>
<a href="{$confirmUrl}" style="display:inline-block;background:#4a7c59;color:#fff;padding:14px 28px;text-decoration:none;border-radius:6px;font-weight:600;margin:16px 0">Confirm subscription</a>
<p style="font-size:13px;color:#888;margin-top:8px">Or copy this link:<br>
<a href="{$confirmUrl}" style="color:#4a7c59;word-break:break-all">{$confirmUrl}</a></p>
<p style="font-size:11px;color:#bbb;margin-top:16px">If you did not subscribe, ignore this email.</p>
</div>
</body>
</html>
HTML;

        $textBody = "OnlyBikes newsletter\n\nHi {$name},\n\nConfirm your subscription:\n{$confirmUrl}\n";

        return sendSmtpEmail(
            $email,
            $name !== '' ? $name : 'Subscriber',
            $subject,
            $htmlBody,
            $textBody,
            [
                'replyTo' => onlybikes_support_email(),
                'replyToName' => 'OnlyBikes Support',
            ]
        );
    }
}

if (!function_exists('onlybikes_send_newsletter_welcome')) {
    function onlybikes_send_newsletter_welcome(string $email, string $name, string $unsubToken): bool
    {
        $origin = onlybikes_site_origin();
        $safeName = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8');
        $unsubscribeUrl = $origin . '/api/newsletter-unsubscribe.php?token=' . urlencode($unsubToken);
        $subject = 'Welcome to OnlyBikes!';

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Welcome</title></head>
<body style="font-family:Inter,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:0 auto;padding:20px">
<div style="background:#f5f5f4;padding:30px;border-radius:8px">
<h1 style="color:#3d5a45;margin-bottom:20px">Welcome, {$safeName}!</h1>
<p style="font-size:16px;margin-bottom:20px">Thanks for confirming your OnlyBikes newsletter subscription.</p>
<p style="font-size:16px;margin-bottom:20px">You will hear about new parts, fitment drops, and deals first.</p>
<p style="font-size:14px;color:#666">Questions? Reply to this email or contact us at support@onlybikes.shop.</p>
<hr style="border:none;border-top:1px solid #ddd;margin:30px 0">
<p style="font-size:12px;color:#999;margin-top:20px">
<a href="{$unsubscribeUrl}" style="color:#999;text-decoration:underline">Unsubscribe</a>
</p>
</div>
</body></html>
HTML;

        $textBody = "Welcome to OnlyBikes, {$name}!\n\nThanks for confirming your subscription.\n\nUnsubscribe: {$unsubscribeUrl}\n";

        return sendSmtpEmail(
            $email,
            $name !== '' ? $name : 'Subscriber',
            $subject,
            $htmlBody,
            $textBody,
            [
                'replyTo' => onlybikes_support_email(),
                'replyToName' => 'OnlyBikes Support',
            ]
        );
    }
}

/**
 * @return array{success:bool,message:string,confirmationEmailSent?:bool,code?:string}
 */
if (!function_exists('onlybikes_newsletter_subscribe')) {
    function onlybikes_newsletter_subscribe(string $email, string $name = '', ?string $ip = null): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Please enter a valid email address.', 'code' => 'invalid_email'];
        }

        $name = htmlspecialchars(trim(substr($name, 0, 100)), ENT_QUOTES, 'UTF-8');
        $ip = $ip ?? (function_exists('getClientIP') ? getClientIP() : 'unknown');

        if (!function_exists('getNewsletterDatabase')) {
            return ['success' => false, 'message' => 'Newsletter is not configured on the server yet.', 'code' => 'no_db'];
        }

        try {
            $pdo = getNewsletterDatabase();
            ensureNewsletterDatabaseSchema($pdo);

            $stmt = $pdo->prepare('SELECT id, status FROM newsletter_subscribers WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            $confirmToken = bin2hex(random_bytes(32));
            $unsubToken = bin2hex(random_bytes(16));

            if ($existing) {
                $pdo->prepare(
                    "UPDATE newsletter_subscribers
                     SET status = 'pending', token = ?, unsubscribe_token = ?, updated_at = NOW()
                     WHERE id = ?"
                )->execute([$confirmToken, $unsubToken, $existing['id']]);
            } else {
                $pdo->prepare(
                    "INSERT INTO newsletter_subscribers
                     (email, name, token, unsubscribe_token, status, source, ip_address, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 'pending', 'website', ?, NOW(), NOW())"
                )->execute([$email, $name, $confirmToken, $unsubToken, $ip]);
            }

            $emailSent = onlybikes_send_newsletter_confirmation($email, $name, $confirmToken);
            if (!$emailSent) {
                error_log('[OnlyBikes] Newsletter confirmation email failed for ' . $email);
            }

            return [
                'success' => true,
                'message' => $emailSent
                    ? 'Please check your email to confirm your subscription.'
                    : 'You are on the list — confirmation email could not be sent yet (SMTP setup pending).',
                'confirmationEmailSent' => $emailSent,
            ];
        } catch (PDOException $e) {
            error_log('[OnlyBikes] Newsletter DB error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Newsletter signup is temporarily unavailable.', 'code' => 'db_error'];
        } catch (Throwable $e) {
            error_log('[OnlyBikes] Newsletter error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Newsletter signup failed. Please try again later.', 'code' => 'server_error'];
        }
    }
}
