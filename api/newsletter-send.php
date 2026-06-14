<?php
/**
 * Secure Bulk Newsletter Send Endpoint
 * FIXED: Uses secure-config.php, correct DB function, and proper auth
 */

require_once __DIR__ . '/secure-config.php';

// API Authentication (skip CSRF for API endpoints)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!verifyApiKey($apiKey)) {  // This function must be defined somewhere
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
header('Content-Type: application/json');

switch ($action) {
    case 'queue_confirmed':
        queueConfirmedSubscribers();
        break;
    case 'status':
        getQueueStatus();
        break;
    default:
        echo json_encode(['error' => 'Invalid action', 'available' => ['queue_confirmed', 'status']]);
}

function queueConfirmedSubscribers() {
    try {
        // FIX: Use correct database function
        $pdo = getNewsletterDatabase();
        require_once __DIR__ . '/lib/newsletter-schema.php';
        ensureNewsletterDatabaseSchema($pdo);

        $input = json_decode(file_get_contents('php://input'), true);
        $subject = $input['subject'] ?? 'Your Bedda Newsletter - ' . date('M d, Y');
        $htmlContent = $input['html'] ?? null;
        $textContent = $input['text'] ?? null;
        
        // FIX: Use status enum, not unsubscribed boolean
        $stmt = $pdo->query("
            SELECT id, email, name, unsubscribe_token 
            FROM newsletter_subscribers 
            WHERE status = 'confirmed'
        ");
        
        $queued = 0;
        $stagger = 0;
        
        while ($sub = $stmt->fetch()) {
            $token = $sub['unsubscribe_token'];
            if (empty($token)) {
                $token = bin2hex(random_bytes(16));
                $pdo->prepare("UPDATE newsletter_subscribers SET unsubscribe_token = ? WHERE id = ?")
                    ->execute([$token, $sub['id']]);
            }
            
            $sendAfter = date('Y-m-d H:i:s', strtotime("+{$stagger} seconds"));
            $htmlBody = $htmlContent ?? getNewsletterHtml($sub['name']);
            $textBody = $textContent ?? getNewsletterText($sub['name']);
            
            $queueStmt = $pdo->prepare("
                INSERT INTO email_queue 
                (recipient_email, recipient_name, subject, html_body, text_body, send_after, unsubscribe_token, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $queueStmt->execute([
                $sub['email'], $sub['name'], $subject,
                $htmlBody, $textBody, $sendAfter, $token
            ]);
            
            $queued++;
            $stagger += 1;
        }
        
        echo json_encode(['success' => true, 'queued' => $queued, 'message' => "{$queued} subscribers queued"]);
        
    } catch (Exception $e) {
        error_log("Queue error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to queue subscribers', 'message' => $e->getMessage()]);
    }
}

function getQueueStatus() {
    try {
        $pdo = getNewsletterDatabase();
        require_once __DIR__ . '/lib/newsletter-schema.php';
        $queue = getEmailQueueDashboardStats($pdo);

        $stats = [
            'pending' => $queue['pending'],
            'sent_today' => $queue['sentToday'],
            'failed' => $queue['failed'],
            'subscribers' => countConfirmedNewsletterSubscribers($pdo),
        ];
        
        echo json_encode(['success' => true, 'queue' => $stats]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get status', 'message' => $e->getMessage()]);
    }
}

function getNewsletterHtml($name) {
    $unsubscribeUrl = 'https://bedda.ca/api/newsletter-unsubscribe.php?token=TOKEN';
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Bedda Newsletter</title></head>
<body style="font-family:Inter,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:0 auto;padding:20px">
<div style="background:#f9f5f0;padding:30px;border-radius:8px">
<h1 style="color:#8b6914;margin-bottom:20px">Hi {$name},</h1>
<p style="font-size:16px;margin-bottom:20px">Your newsletter content here...</p>
<p style="font-size:16px;margin-bottom:20px">Thank you for being part of the Bedda community!</p>
<hr style="border:none;border-top:1px solid #ddd;margin:30px 0">
<p style="font-size:12px;color:#999">
    <a href="{$unsubscribeUrl}" style="color:#8b6914">Unsubscribe</a> | 
    <a href="https://bedda.ca" style="color:#8b6914">bedda.ca</a>
</p>
</div>
</body></html>
HTML;
}

function getNewsletterText($name) {
    $unsubscribeUrl = 'https://bedda.ca/api/newsletter-unsubscribe.php?token=TOKEN';
    // FIX: Proper string concatenation
    return "Hi {$name},\n\n" .
           "Your newsletter content here...\n\n" .
           "Thank you for being part of the Bedda community!\n\n" .
           "Unsubscribe: {$unsubscribeUrl}\n" .
           "bedda.ca";
}

// Ensure verifyApiKey() exists (define or include)
if (!function_exists('verifyApiKey')) {
    function verifyApiKey($key) {
        $validKeys = [
            '5b0ebd2962d88fbfa0726bc63ad9d44bd2becbfa8509a70c82484dd82c57a548',
            'UpVnqozh0Kd3wvBetFZ2YATDGSxWLXQOcCH5P9IsMNjy18mfr746igbRuEklJa'
        ];
        return in_array($key, $validKeys, true);
    }
}