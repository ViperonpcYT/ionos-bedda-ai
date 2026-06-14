<?php
/**
 * Test Email Script - CORRECTED
 * # Run test (replace with actual API key from secure-config.php) curl "https://bedda.ca/api/test-mail.php?key=5b0ebd2962d88fbfa0726bc63ad9d44bd2becbfa8509a70c82484dd82c57a548&to=your@email.com"
 */


ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/secure-config.php';

header('Content-Type: text/plain');

echo "========================================\n";
echo "Bedda SMTP Test Script\n";
echo "========================================\n\n";

// Validate inputs
$apiKey = $_GET['key'] ?? '';
$testEmail = $_GET['to'] ?? '';

// Simple API key check (define your keys here or in secure-config.php)
$VALID_API_KEYS = [
    '5b0ebd2962d88fbfa0726bc63ad9d44bd2becbfa8509a70c82484dd82c57a548',
    'UpVnqozh0Kd3wvBetFZ2YATDGSxWLXQOcCH5P9IsMNjy18mfr746igbRuEklJa'
];

if (empty($apiKey) || !in_array($apiKey, $VALID_API_KEYS, true)) {
    echo "ERROR: Valid API key required\n";
    echo "Usage: test-mail.php?key=YOUR_API_KEY&to=your@email.com\n";
    exit;
}

if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    echo "ERROR: Valid email address required\n";
    exit;
}

// Test 1: Configuration
echo "TEST 1: Configuration Check\n";
echo "----------------------------\n";
echo "SMTP Host: " . SMTP_HOST . "\n";
echo "SMTP Port: " . SMTP_PORT . "\n";
echo "SMTP User: " . SMTP_USERNAME . "\n";
echo "SMTP Encryption: " . SMTP_ENCRYPTION . "\n";
echo "SMTP Password: " . (empty(SMTP_PASSWORD) ? 'NOT SET' : substr(SMTP_PASSWORD, 0, 3) . '***') . "\n\n";

if (empty(SMTP_PASSWORD)) {
    echo "ERROR: SMTP_PASSWORD is empty. Check .env file.\n";
    exit;
}

// Test 2: PHPMailer
echo "TEST 2: PHPMailer Check\n";
echo "------------------------\n";
if (loadPHPMailer()) {
    echo "PHPMailer: LOADED\n\n";
} else {
    echo "ERROR: PHPMailer not found. Install via Composer or place files in /vendor/ or /PHPMailer/src/\n\n";
    exit;
}

try {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    
    // ADD SMTPOptions HERE, after $mail is created:
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]
    ];

    $enc = strtolower(SMTP_ENCRYPTION);
    if ($enc === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }
    
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($testEmail, 'Test');
    $mail->Subject = 'Direct SMTP Debug Test';
    $mail->Body    = 'If you receive this, SMTP works.';
    
    $mail->send();
    echo "SUCCESS: Email sent via direct PHPMailer call\n";
    
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    if (isset($mail)) {
        echo "PHPMailer ErrorInfo: " . $mail->ErrorInfo . "\n";
    }
}

// Test 3: Database Connections
echo "TEST 3: Database Connections\n";
echo "----------------------------\n";

try {
    $pdo = getNewsletterDatabase();
    $pdo->query("SELECT 1");
    echo "Newsletter DB: CONNECTED\n";
    
    $tables = $pdo->query("SHOW TABLES LIKE 'newsletter_subscribers'")->fetchAll(PDO::FETCH_COLUMN);
    echo "newsletter_subscribers table: " . (!empty($tables) ? 'EXISTS' : 'MISSING') . "\n";
} catch (Exception $e) {
    echo "Newsletter DB: FAILED - " . $e->getMessage() . "\n";
}

try {
    $pdo = getOrderDatabase();
    $pdo->query("SELECT 1");
    echo "Orders DB: CONNECTED\n";
    
    $tables = $pdo->query("SHOW TABLES LIKE 'orders'")->fetchAll(PDO::FETCH_COLUMN);
    echo "orders table: " . (!empty($tables) ? 'EXISTS' : 'MISSING') . "\n\n";
} catch (Exception $e) {
    echo "Orders DB: FAILED - " . $e->getMessage() . "\n\n";
}

// Test 4: Send Email
echo "TEST 4: Sending Test Email\n";
echo "---------------------------\n";
echo "To: {$testEmail}\n";

$subject = 'Bedda SMTP Test - ' . date('Y-m-d H:i:s');
$htmlBody = <<<HTML
<!DOCTYPE html>
<html><body style="font-family:Arial,sans-serif;padding:20px">
<h1 style="color:#8b6914">SMTP Test Successful!</h1>
<p>Configuration verified at {$subject}</p>
<hr><p style="color:#666;font-size:12px">Bedda Skincare • bedda.ca</p>
</body></html>
HTML;
$textBody = "SMTP Test Successful!\n\nTime: {$subject}\n\nBedda Skincare - https://bedda.ca";

try {
    $result = sendSmtpEmail($testEmail, 'Test Recipient', $subject, $htmlBody, $textBody);
    
    if ($result) {
        echo "SUCCESS: Email sent. Check inbox and spam folder.\n\n";
    } else {
        echo "FAILED: Email not sent. Check /api/php-errors.log\n\n";
    }
} catch (Exception $e) {
    error_log('[BEDDA] Test email failed: ' . $e->getMessage());
    echo "\n=== SMTP ERROR ===\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "==================\n";
    exit;
}

echo "========================================\n";
echo "Test Complete\n";
echo "========================================\n";