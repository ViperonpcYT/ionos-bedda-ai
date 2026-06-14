<?php
/**
 * Cron Job Script - Email Queue Processor
 * 
 * Run this via cron every 5-10 minutes:
 * */5 * * * * /usr/bin/php /path/to/email-admin/cron-send.php >> /path/to/email-admin/cron.log 2>&1
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/process-queue.php';   // <-- add this line

// Log start time
$startTime = date('Y-m-d H:i:s');
echo "[$startTime] Starting email queue processor...\n";

try {
    $result = processQueue();
    $endTime = date('Y-m-d H:i:s');
    
    echo "[$endTime] Completed: {$result['sent']} sent, {$result['failed']} failed\n";
    
    // If there are more pending emails, suggest running again
    $pdo = getDatabase();
    require_once API_PATH . '/lib/newsletter-schema.php';
    ensureNewsletterDatabaseSchema($pdo);
    $pendingCount = $pdo->query("SELECT COUNT(*) FROM email_queue WHERE status='pending' AND send_after <= NOW()")->fetchColumn();
    
    if ($pendingCount > 0) {
        echo "[$endTime] Note: $pendingCount more emails pending\n";
    }
    
    exit(0);
} catch (Exception $e) {
    $errorTime = date('Y-m-d H:i:s');
    echo "[$errorTime] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
