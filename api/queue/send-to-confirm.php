<?php
require_once __DIR__ . '/config.php';

// Only sends to CONFIRMED subscribers
$pdo = getDatabase();

$stmt = $pdo->query("
    SELECT email, name 
    FROM newsletter_subscribers 
    WHERE status = 'confirmed' 
    AND unsubscribed = 0
");

$queued = 0;
while ($sub = $stmt->fetch()) {
    $sendAfter = date('Y-m-d H:i:s', strtotime("+{$queued} seconds"));
    
    $queueStmt = $pdo->prepare("
        INSERT INTO email_queue 
        (recipient_email, recipient_name, subject, html_body, text_body, send_after)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $queueStmt->execute([
        $sub['email'],
        $sub['name'],
        'Bedda Newsletter - ' . date('M d, Y'),
        getNewsletterHtml($sub['name']),
        getNewsletterText($sub['name']),
        $sendAfter
    ]);
    
    $queued++;
}

echo "✅ Queued $queued confirmed subscribers";

function getNewsletterHtml($name) {
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family:Inter; line-height:1.6; color:#333;'>
        <h2>Hi $name,</h2>
        <p>Your newsletter content here...</p>
        <p><a href='https://bedda.ca/api/newsletter-unsubscribe.php?token=TOKEN'>Unsubscribe</a></p>
    </body>
    </html>";
}

function getNewsletterText($name) {
    return "Hi $name,\n\nYour newsletter content here...\n\nUnsubscribe: https://bedda.ca/api/newsletter-unsubscribe.php?token=TOKEN";
}