<?php
/**
 * Mail Queue Processor - Bulletproof Email Queue
 * FIXED: Uses secure-config.php, proper constants, and error handling
 */

// Force UTF-8 and error handling
mb_internal_encoding('UTF-8');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/secure-config.php';

class MailQueue {
    // Configuration - adjust these as needed
    public $batchSize = 30;
    public $batchDelay = 70;
    public $microDelay = 100000;
    public $maxRetries = 3;
    public $retryBackoff = [5, 15, 30];
    public $dailyCap = 1800;
    public $pauseOnBounceRate = 5.0;
    
    private $pdo;
    private $logs = [];
    
    public function __construct() {
        $this->pdo = getDatabase();
        $this->log("Queue processor started");
    }
    
    /**
     * Main processing loop
     */
    public function processQueue($limit = null) {
        $sent = 0;
        $failed = 0;
        $wave = 0;
        
        // Check daily cap
        if ($this->hasReachedDailyCap()) {
            $this->log("DAILY CAP REACHED - Stopping");
            return 0;
        }
        
        while (true) {
            $wave++;
            $this->log("=== WAVE $wave STARTING ===");
            
            $batch = $this->getNextBatch();
            if (empty($batch)) {
                $this->log("No more emails in queue");
                break;
            }
            
            foreach ($batch as $job) {
                try {
                    if ($this->sendEmail($job)) {
                        $this->markSent($job['id']);
                        $sent++;
                        $this->log("Sent: {$job['recipient_email']}");
                    } else {
                        $this->retryOrFail($job);
                        $failed++;
                        $this->log("Failed: {$job['recipient_email']}");
                    }
                    
                    usleep($this->microDelay);
                    
                } catch (Exception $e) {
                    $this->log("Exception: " . $e->getMessage());
                    $this->retryOrFail($job);
                    $failed++;
                }
                
                if ($limit && ($sent + $failed) >= $limit) {
                    $this->log("Execution limit reached: $limit");
                    break 2;
                }
            }
            
            $this->log("Wave $wave complete: Sent $sent, Failed $failed");
            
            if (!empty($this->getNextBatch())) {
                $this->log("Waiting {$this->batchDelay} seconds before next wave");
                sleep($this->batchDelay);
            }
            
            if ($this->hasReachedDailyCap()) {
                $this->log("DAILY CAP REACHED mid-run");
                break;
            }
        }
        
        $this->log("Processing complete. Total: Sent=$sent, Failed=$failed");
        $this->saveLog();
        
        return $sent;
    }
    
    /**
     * Get next batch of emails
     */
    private function getNextBatch() {
        $stmt = $this->pdo->prepare("
            SELECT * FROM email_queue 
            WHERE status = 'pending' 
            AND send_after <= NOW()
            AND retry_count < :maxRetries
            ORDER BY priority ASC, created_at ASC
            LIMIT :batchSize
        ");
        
        $stmt->bindValue(':maxRetries', $this->maxRetries, PDO::PARAM_INT);
        $stmt->bindValue(':batchSize', $this->batchSize, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Send email using PHPMailer
     */
    private function sendEmail($job) {
        loadPHPMailer();
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // SMTP Configuration
        $mail->isSMTP();
        $mail->SMTPDebug = 0;
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        
        // Encryption
        $encryption = strtolower(SMTP_ENCRYPTION);
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        }
        
        $mail->Port = SMTP_PORT;
        $mail->Timeout = 30;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false
            ]
        ];
        
        // From
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addReplyTo('orders@bedda.ca', 'Bedda Support');
        
        // Recipient
        $mail->addAddress($job['recipient_email'], $job['recipient_name']);
        
        // Replace token in content
        $htmlBody = $job['html_body'];
        $textBody = $job['text_body'];
        
        if (!empty($job['unsubscribe_token'])) {
            $htmlBody = str_replace('TOKEN', $job['unsubscribe_token'], $htmlBody);
            $textBody = str_replace('TOKEN', $job['unsubscribe_token'], $textBody);
        }
        
        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $job['subject'];
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;
        
        // Bulk headers
        $mail->addCustomHeader('Precedence', 'bulk');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
        
        if (!empty($job['list_unsubscribe_url'])) {
            $mail->addCustomHeader('List-Unsubscribe', '<' . $job['list_unsubscribe_url'] . '>');
        }
        
        return $mail->send();
    }
    
    /**
     * Mark email as sent
     */
    private function markSent($id) {
        $stmt = $this->pdo->prepare("
            UPDATE email_queue 
            SET status='sent', sent_at=NOW() 
            WHERE id=?
        ");
        $stmt->execute([$id]);
    }
    
    /**
     * Retry or fail email
     */
    private function retryOrFail($job) {
        $retryCount = $job['retry_count'] + 1;
        
        if ($retryCount >= $this->maxRetries) {
            $status = 'failed';
            $nextRetry = null;
            $this->log("MARKED AS FAILED: {$job['recipient_email']}");
        } else {
            $status = 'pending';
            $delay = $this->retryBackoff[$retryCount - 1] ?? 30;
            $nextRetry = date('Y-m-d H:i:s', strtotime("+{$delay} minutes"));
            $this->log("Will retry {$job['recipient_email']} after {$delay}m");
        }
        
        $stmt = $this->pdo->prepare("
            UPDATE email_queue 
            SET retry_count=?, status=?, send_after=?, last_error=? 
            WHERE id=?
        ");
        
        $stmt->execute([
            $retryCount,
            $status,
            $nextRetry,
            substr($job['last_error'] ?? 'SMTP Error', 0, 255),
            $job['id']
        ]);
    }
    
    /**
     * Check if daily cap reached
     */
    private function hasReachedDailyCap() {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as sent_today 
            FROM email_queue 
            WHERE status='sent' 
            AND DATE(sent_at) = CURDATE()
        ");
        
        $result = $stmt->fetch();
        $count = (int) ($result['sent_today'] ?? 0);
        
        return $count >= $this->dailyCap;
    }
    
    /**
     * Log message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";
        $this->logs[] = $logEntry;
        error_log("[MailQueue] $message");
    }
    
    /**
     * Save log to file
     */
    private function saveLog() {
        if (empty($this->logs)) return;
        
        $logDir = __DIR__ . '/logs/';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . 'mail-queue-' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, implode('', $this->logs), FILE_APPEND);
    }
}

// CLI Execution
if (php_sapi_name() === 'cli') {
    $queue = new MailQueue();
    $sent = $queue->processQueue();
    echo "Processing complete. Emails sent: $sent\n";
}
