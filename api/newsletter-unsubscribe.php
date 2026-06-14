<?php
/**
 * Newsletter Unsubscribe Handler
 * Allows subscribers to unsubscribe via token
 */

require_once __DIR__ . '/secure-config.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$token = $_GET['token'] ?? '';

if (empty($token) || strlen($token) < 16) {
    showResult('Invalid unsubscribe link', false);
    exit;
}

try {
    $pdo = getNewsletterDatabase(); // Use correct function from secure-config.php
    
    // Update status to 'unsubscribed' (matches your schema)
    $stmt = $pdo->prepare("
        UPDATE newsletter_subscribers
        SET status = 'unsubscribed', 
            updated_at = NOW()
        WHERE unsubscribe_token = ? 
        AND status != 'unsubscribed'
    ");
    $stmt->execute([$token]);

    if ($stmt->rowCount() >= 1) {
        showResult('Unsubscribed Successfully', true, 'You have been removed from our newsletter. Sorry to see you go!');
    } else {
        // Check if already unsubscribed
        $checkStmt = $pdo->prepare("SELECT status FROM newsletter_subscribers WHERE unsubscribe_token = ?");
        $checkStmt->execute([$token]);
        $existing = $checkStmt->fetch();
        
        if ($existing && $existing['status'] === 'unsubscribed') {
            showResult('Already Unsubscribed', true, 'You are already unsubscribed from our newsletter.');
        } else {
            showResult('Invalid Link', false, 'This unsubscribe link is invalid or has expired.');
        }
    }

} catch (Exception $e) {
    error_log("Unsubscribe error: " . $e->getMessage());
    showResult('Error', false, 'An error occurred. Please try again later.');
}

function showResult($title, $success, $message = '') {
    $icon = $success ? '✅' : '❌';
    $color = $success ? '#8b6914' : '#dc2626';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - Bedda Skincare</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f9f5f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; padding: 50px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); text-align: center; max-width: 500px; width: 100%; }
        .icon { font-size: 64px; margin-bottom: 20px; }
        h1 { color: <?= $color ?>; margin-bottom: 15px; font-size: 28px; }
        p { color: #666; margin-bottom: 30px; line-height: 1.6; }
        .btn { display: inline-block; background: #8b6914; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: 600; }
        .btn:hover { background: #6b5110; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon"><?= $icon ?></div>
        <h1><?= htmlspecialchars($title) ?></h1>
        <?php if ($message): ?><p><?= htmlspecialchars($message) ?></p><?php endif; ?>
        <a href="https://bedda.ca" class="btn">Return to Home</a>
    </div>
    <script>if (window.self !== window.top) { window.top.location.href = window.location.href; }</script>
</body>
</html>
    <?php
}