<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * Process Email Queue
 */

require_once __DIR__ . '/config.php';  // Loads secure-config.php
require_once __DIR__ . '/includes/functions.php';

function processQueue($limit = null) {
    // FIX: Use correct function
    $pdo = getNewsletterDatabase();
    require_once API_PATH . '/lib/newsletter-schema.php';
    ensureNewsletterDatabaseSchema($pdo);

    $batchSize = $limit ?? BATCH_SIZE;
    $maxRetries = MAX_RETRIES;
    $dailyCap = DAILY_CAP;

    // Check daily cap
    $stmt = $pdo->query("SELECT COUNT(*) FROM email_queue WHERE status='sent' AND DATE(sent_at)=CURDATE()");
    $sentToday = $stmt->fetchColumn();
    if ($sentToday >= $dailyCap) {
        error_log("Daily cap reached ($sentToday/$dailyCap)");
        return ['sent' => 0, 'failed' => 0, 'message' => 'Daily cap reached'];
    }

    $remainingToday = $dailyCap - $sentToday;
    $actualBatchSize = min($batchSize, $remainingToday);

    // Fetch pending emails ready to send
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

    $sent = 0;
    $failed = 0;
    
    foreach ($emails as $email) {
        // FIX: Use sendSmtpEmail from secure-config.php
        $success = sendSmtpEmail(
            $email['recipient_email'],
            $email['recipient_name'] ?? '',
            $email['subject'],
            $email['html_body'],
            $email['text_body'] ?? strip_tags($email['html_body']),
            ['listUnsubscribe' => 'https://onlybikes.example/api/newsletter-unsubscribe.php?token=' . ($email['unsubscribe_token'] ?? '')]
        );
        
        if ($success) {
            $pdo->prepare("UPDATE email_queue SET status='sent', sent_at=NOW() WHERE id=?")->execute([$email['id']]);
            $sent++;
        } else {
            $retryCount = $email['retry_count'] + 1;
            $status = ($retryCount >= $maxRetries) ? 'failed' : 'pending';
            $nextSendAfter = date('Y-m-d H:i:s', strtotime('+' . ($retryCount * 10) . ' minutes'));
            $pdo->prepare("UPDATE email_queue SET retry_count=?, status=?, last_error=?, send_after=? WHERE id=?")
                ->execute([$retryCount, $status, 'SMTP send failed', $nextSendAfter, $email['id']]);
            $failed++;
        }
        usleep(100000); // 0.1 second delay
    }

    return ['sent' => $sent, 'failed' => $failed];
}

// If called directly via web request
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    session_name(EMAIL_ADMIN_SESSION);
    session_start();
    requireLogin();
    
    header('Content-Type: application/json');
    $result = processQueue();
    echo json_encode($result);
    exit;
}