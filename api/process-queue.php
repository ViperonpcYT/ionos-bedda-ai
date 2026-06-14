<?php
/**
 * Process Email Queue
 * This script can be included or run via CLI/cron
 * FIXED: Uses secure-config.php and proper error handling
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/secure-config.php';
require_once __DIR__ . '/includes/functions.php';

// Sending limits
define('BATCH_SIZE', 30);
define('MAX_RETRIES', 3);
define('DAILY_CAP', 1800);
define('BATCH_DELAY', 70);

/**
 * Process the email queue
 */
function processQueue($limit = null) {
    $pdo = getDatabase();
    require_once __DIR__ . '/lib/newsletter-schema.php';
    ensureNewsletterDatabaseSchema($pdo);
    $batchSize = $limit ?? BATCH_SIZE;
    $maxRetries = MAX_RETRIES;
    $dailyCap = DAILY_CAP;

    // Check daily cap
    $stmt = $pdo->query("SELECT COUNT(*) FROM email_queue WHERE status='sent' AND DATE(sent_at)=CURDATE()");
    $sentToday = (int) $stmt->fetchColumn();
    
    if ($sentToday >= $dailyCap) {
        error_log("Daily cap reached ($sentToday/$dailyCap)");
        return ['sent' => 0, 'failed' => 0, 'message' => 'Daily cap reached'];
    }

    // Calculate remaining capacity for today
    $remainingToday = $dailyCap - $sentToday;
    $actualBatchSize = min($batchSize, $remainingToday);

    // Fetch pending emails that are ready to send
    $stmt = $pdo->prepare("
        SELECT * FROM email_queue
        WHERE status='pending'
        AND send_after <= NOW()
        AND retry_count < ?
        ORDER BY send_after ASC
        LIMIT ?
    ");
    $stmt->execute([$maxRetries, $actualBatchSize]);
    $emails = $stmt->fetchAll();

    if (empty($emails)) {
        return ['sent' => 0, 'failed' => 0, 'message' => 'No pending emails'];
    }

    $sent = 0;
    $failed = 0;
    
    foreach ($emails as $email) {
        // Generate unsubscribe link if needed
        $htmlBody = $email['html_body'];
        $textBody = $email['text_body'];
        
        // Replace TOKEN placeholder with actual token if available
        if (!empty($email['unsubscribe_token'])) {
            $unsubscribeUrl = 'https://bedda.ca/api/newsletter-unsubscribe.php?token=' . $email['unsubscribe_token'];
            $htmlBody = str_replace('TOKEN', $email['unsubscribe_token'], $htmlBody);
            $textBody = str_replace('TOKEN', $email['unsubscribe_token'], $textBody);
        }
        
        // Send email via SMTP
        $success = sendSmtpEmail(
            $email['recipient_email'],
            $email['recipient_name'],
            $email['subject'],
            $htmlBody,
            $textBody,
            [
                'isBulk' => true,
                'listUnsubscribe' => !empty($email['unsubscribe_token']) ? $unsubscribeUrl : null
            ]
        );
        
        if ($success) {
            $pdo->prepare("UPDATE email_queue SET status='sent', sent_at=NOW() WHERE id=?")
                ->execute([$email['id']]);
            $sent++;
            logMessage("Sent to {$email['recipient_email']}", 'queue');
        } else {
            $retryCount = $email['retry_count'] + 1;
            $status = ($retryCount >= $maxRetries) ? 'failed' : 'pending';
            $nextSendAfter = date('Y-m-d H:i:s', strtotime('+' . ($retryCount * 10) . ' minutes'));
            
            $pdo->prepare("UPDATE email_queue SET retry_count=?, status=?, last_error=?, send_after=? WHERE id=?")
                ->execute([$retryCount, $status, 'SMTP send failed', $nextSendAfter, $email['id']]);
            
            $failed++;
            logMessage("Failed to send to {$email['recipient_email']} (retry {$retryCount})", 'queue');
        }
        
        // Small delay between emails to avoid rate limiting
        usleep(100000); // 0.1 second
    }

    return ['sent' => $sent, 'failed' => $failed];
}

// If called directly from web (AJAX)
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    session_name('bedda_email_admin');
    session_start();
    requireLogin();
    
    $result = processQueue();
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
