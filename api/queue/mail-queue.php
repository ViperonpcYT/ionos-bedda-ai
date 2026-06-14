<?php
// /api/queue/mail-queue.php - Bulletproof Mail Queue

// Force UTF-8 and error handling
mb_internal_encoding('UTF-8');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

class MailQueue {
    // === CONFIGURATION - YOU CONTROL THESE ===
    public $batchSize = 30;          // Emails per wave (Ionos limit: 200/hour)
    public $batchDelay = 70;         // Seconds between waves (safe: ~51/hour)
    public $microDelay = 100000;     // Microseconds between emails (0.1s)
    public $maxRetries = 3;
    public $retryBackoff = [5, 15, 30]; // Minutes to wait before retry
    public $dailyCap = 1800;         // Stop after this many (well under Ionos limit)
    public $pauseOnBounceRate = 5.0; // Pause if bounce rate >5%
    // =========================================
    
    private $pdo;
    private $smtpConfig;
    private $logs = [];
    
    public function __construct() {
        require_once __DIR__ . '/../config.php';
        $this->pdo = getDatabase();
        $this->smtpConfig = [
            'host' => SMTP_HOST,
            'username' => SMTP_USERNAME,
            'password' => SMTP_PASSWORD,
            'port' => SMTP_PORT,
            'encryption' => SMTP_ENCRYPTION,
            'from_email' => SMTP_FROM_EMAIL,
            'from_name' => SMTP_FROM_NAME
        ];
        
        // Log initialization
        $this->log("Queue processor started");
    }
    
    // === MAIN PROCESSING LOOP ===
    public function processQueue($limit = null) {
        $sent = 0;
        $failed = 0;
        $wave = 0;
        
        // Check daily cap
        if ($this->hasReachedDailyCap()) {
            $this->log("DAILY CAP REACHED - Stopping");
            return 0;
        }
        
        // Check bounce rate
        if ($this->getBounceRate() > $this->pauseOnBounceRate) {
            $this->log("BOUNCE RATE TOO HIGH - Pausing queue");
            $this->pauseQueue();
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
                // Individual email sending with try-catch
                try {
                    if ($this->sendEmail($job)) {
                        $this->markSent($job['id']);
                        $sent++;
                        $this->log("✅ Sent: {$job['recipient_email']}");
                    } else {
                        $this->retryOrFail($job);
                        $failed++;
                        $this->log("❌ Failed: {$job['recipient_email']}");
                    }
                    
                    // Micro-delay between emails
                    usleep($this->microDelay);
                    
                } catch (Exception $e) {
                    $this->log("⚠️ Exception: " . $e->getMessage());
                    $this->retryOrFail($job);
                    $failed++;
                }
                
                // Check per-execution limit
                if ($limit && ($sent + $failed) >= $limit) {
                    $this->log("Execution limit reached: $limit");
                    break 2;
                }
            }
            
            $this->log("Wave $wave complete: Sent $sent, Failed $failed");
            
            // Delay between waves (except after last wave)
            if (!empty($this->getNextBatch())) {
                $this->log("Waiting {$this->batchDelay} seconds before next wave");
                sleep($this->batchDelay);
            }
            
            // Recheck daily cap
            if ($this->hasReachedDailyCap()) {
                $this->log("DAILY CAP REACHED mid-run");
                break;
            }
        }
        
        $this->log("Processing complete. Total: Sent=$sent, Failed=$failed");
        $this->saveLog();
        
        return $sent;
    }
    
    // === BATCH RETRIEVAL ===
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
    
    // === SMTP SENDING ===
    private function sendEmail($job) {
        require_once __DIR__ . '/../../PHPMailer/Exception.php';
        require_once __DIR__ . '/../../PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/../../PHPMailer/SMTP.php';
        
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // SMTP Configuration
        $mail->isSMTP();
        $mail->SMTPDebug = 0; // Disable debug output
        $mail->Host = $this->smtpConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->smtpConfig['username'];
        $mail->Password = $this->smtpConfig['password'];
        $mail->SMTPSecure = $this->smtpConfig['encryption'];
        $mail->Port = $this->smtpConfig['port'];
        
        // Timeouts (important for queue)
        $mail->Timeout = 30;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false
            ]
        ];
        
        // From
        $mail->setFrom(
            $this->smtpConfig['from_email'], 
            $this->smtpConfig['from_name']
        );
        $mail->addReplyTo('josiechaves03@gmail.com', 'Bedda Support');
        
        // Recipient
        $mail->addAddress($job['recipient_email'], $job['recipient_name']);
        
        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $job['subject'];
        $mail->Body = $job['html_body'];
        $mail->AltBody = $job['text_body'];
        
        // Headers for deliverability
        $mail->addCustomHeader('Precedence', 'bulk');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
        
        // List-unsubscribe
        if (!empty($job['list_unsubscribe_url'])) {
            $mail->addCustomHeader('List-Unsubscribe', 
                '<' . $job['list_unsubscribe_url'] . '>');
        }
        
        // Send and track
        $result = $mail->send();
        
        if ($result) {
            // Track SMTP transaction ID if available
            $this->trackDelivery($job['id'], $mail->getSMTPInstance()->getLastTransactionID());
        }
        
        return $result;
    }
    
    // === TRACKING & MONITORING ===
    private function trackDelivery($queueId, $smtpId) {
        $stmt = $this->pdo->prepare("
            INSERT INTO email_delivery_tracking (queue_id, smtp_transaction_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$queueId, $smtpId]);
    }
    
    private function markSent($id) {
        $stmt = $this->pdo->prepare("
            UPDATE email_queue 
            SET status='sent', sent_at=NOW() 
            WHERE id=?
        ");
        $stmt->execute([$id]);
    }
    
    private function retryOrFail($job) {
        $retryCount = $job['retry_count'] + 1;
        
        if ($retryCount >= $this->maxRetries) {
            $status = 'failed';
            $nextRetry = null;
            $this->log("MARKED AS FAILED: {$job['recipient_email']}");
        } else {
            $status = 'pending';
            $delay = $this->retryBackoff[$retryCount - 1];
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
    
    // === SAFETY LIMITS ===
    private function hasReachedDailyCap() {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as sent_today 
            FROM email_queue 
            WHERE status='sent' 
            AND DATE(sent_at) = CURDATE()
        ");
        
        $result = $stmt->fetch();
        $count = $result['sent_today'] ?? 0;
        
        if ($count >= $this->dailyCap) {
            $this->log("Daily cap check: $count >= {$this->dailyCap}");
            return true;
        }
        
        return false;
    }
    
    private function getBounceRate() {
        $stmt = $this->pdo->query("
            SELECT 
                SUM(CASE WHEN bounce_type IS NOT NULL THEN 1 ELSE 0 END) as bounces,
                COUNT(*) as total
            FROM email_delivery_tracking
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $result = $stmt->fetch();
        if ($result['total'] == 0) return 0;
        
        return ($result['bounces'] / $result['total']) * 100;
    }
    
    private function pauseQueue() {
        // Create a flag file to pause processing
        file_put_contents(__DIR__ . '/queue.paused', 
            "Paused due to high bounce rate at " . date('Y-m-d H:i:s'));
    }
    
    // === LOGGING ===
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] $message\n";
        $this->logs[] = $logEntry;
        
        // Also write to error log for monitoring
        error_log("[MailQueue] $message");
    }
    
    private function saveLog() {
        if (empty($this->logs)) return;
        
        $logFile = __DIR__ . '/../../logs/mail-queue-' . date('Y-m-d') . '.log';
        file_put_contents($logFile, implode('', $this->logs), FILE_APPEND);
    }
}

// === CLI EXECUTION ===
if (php_sapi_name() === 'cli') {
    $queue = new MailQueue();
    $sent = $queue->processQueue();
    echo "✅ Processing complete. Emails sent: $sent\n";
}