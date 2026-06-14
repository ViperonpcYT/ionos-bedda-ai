<?php
require_once __DIR__ . '/config.php';

// Add a test email to your queue
$pdo = getDatabase();
$stmt = $pdo->prepare("
    INSERT INTO email_queue 
    (recipient_email, recipient_name, subject, html_body, text_body)
    VALUES (?, ?, ?, ?, ?)
");

$stmt->execute([
    'josiechaves03@gmail.com',
    'Josie',
    'Test: Your Queue is Working!',
    '<h1>✅ Success!</h1><p>If you receive this, your email queue is working perfectly.</p>',
    'Success! If you receive this, your email queue is working perfectly.'
]);

echo "✅ Test email added to queue. Wait 5 minutes or run manually.";