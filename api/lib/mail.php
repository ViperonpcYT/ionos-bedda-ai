<?php
/**
 * Shared SMTP mail helpers for OnlyBikes (orders, newsletter, queue).
 */
declare(strict_types=1);

if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', 'tls');
}

if (!function_exists('onlybikes_admin_email')) {
    function onlybikes_admin_email(): string
    {
        if (defined('ADMIN_EMAIL') && ADMIN_EMAIL !== '') {
            return ADMIN_EMAIL;
        }
        return onlybikes_support_email();
    }
}

if (!function_exists('onlybikes_site_origin')) {
    function onlybikes_site_origin(): string
    {
        if (defined('SITE_ORIGIN') && SITE_ORIGIN !== '') {
            return rtrim(SITE_ORIGIN, '/');
        }
        $env = getenv('SITE_ORIGIN') ?: getenv('SITE_URL');
        if ($env !== false && $env !== '') {
            return rtrim((string) $env, '/');
        }
        return 'https://onlybikes.shop';
    }
}

if (!function_exists('loadPHPMailer')) {
    function loadPHPMailer(): bool
    {
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return true;
        }
        $base = dirname(__DIR__, 2) . '/PHPMailer/src';
        $files = [
            $base . '/Exception.php',
            $base . '/PHPMailer.php',
            $base . '/SMTP.php',
        ];
        foreach ($files as $file) {
            if (!is_readable($file)) {
                error_log('[OnlyBikes][mail] Missing PHPMailer file: ' . $file);
                return false;
            }
            require_once $file;
        }
        return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    }
}

if (!function_exists('onlybikes_smtp_configured')) {
    function onlybikes_smtp_configured(): bool
    {
        return defined('SMTP_HOST')
            && SMTP_HOST !== ''
            && defined('SMTP_USERNAME')
            && SMTP_USERNAME !== ''
            && defined('SMTP_PASSWORD')
            && SMTP_PASSWORD !== '';
    }
}

/**
 * @param array{replyTo?:string,replyToName?:string,cc?:string,ccName?:string} $options
 */
if (!function_exists('sendSmtpEmail')) {
    function sendSmtpEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array $options = []
    ): bool {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            error_log('[OnlyBikes][mail] Invalid recipient: ' . $toEmail);
            return false;
        }
        if (!onlybikes_smtp_configured()) {
            error_log('[OnlyBikes][mail] SMTP not configured — set SMTP_USERNAME and SMTP_PASSWORD in api/.env');
            return false;
        }
        if (!loadPHPMailer()) {
            return false;
        }

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->Port = (int) (defined('SMTP_PORT') ? SMTP_PORT : 587);
            $mail->CharSet = 'UTF-8';

            $enc = strtolower((string) SMTP_ENCRYPTION);
            if ($enc === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            $fromEmail = defined('SMTP_FROM_EMAIL') && SMTP_FROM_EMAIL !== ''
                ? SMTP_FROM_EMAIL
                : onlybikes_support_email();
            $fromName = defined('SMTP_FROM_NAME') && SMTP_FROM_NAME !== ''
                ? SMTP_FROM_NAME
                : 'OnlyBikes';

            $mail->setFrom($fromEmail, $fromName);

            if (!empty($options['replyTo'])) {
                $mail->addReplyTo(
                    (string) $options['replyTo'],
                    (string) ($options['replyToName'] ?? '')
                );
            }

            if (!empty($options['cc']) && filter_var($options['cc'], FILTER_VALIDATE_EMAIL)) {
                $mail->addCC((string) $options['cc'], (string) ($options['ccName'] ?? ''));
            }

            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

            return $mail->send();
        } catch (Throwable $e) {
            error_log('[OnlyBikes][mail] send failed to ' . $toEmail . ': ' . $e->getMessage());
            return false;
        }
    }
}
