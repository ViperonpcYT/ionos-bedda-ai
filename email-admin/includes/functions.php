<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

function requireLogin() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: index.php');
        exit;
    }
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function getSmtpConfig() {
    return [
        'host' => SMTP_HOST,
        'username' => SMTP_USERNAME,
        'password' => SMTP_PASSWORD,
        'port' => SMTP_PORT,
        'encryption' => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls',
        'from_email' => SMTP_FROM_EMAIL,
        'from_name' => 'OnlyBikes',
    ];
}

/**
 * Get PDO connection to mail-in orders database (dbs15097373)
 */
function getOrdersDatabase() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=db5019243400.hosting-data.io;port=3306;dbname=dbs15097373;charset=utf8mb4',
                'dbu2789080',
                'Fir3ward3n!',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('Orders DB connection failed: ' . $e->getMessage());
            die('<p style="padding:2rem;color:red;font-family:monospace;">DB Error: ' . htmlspecialchars($e->getMessage()) . '</p>');
        }
    }
    return $pdo;
}

function sendEmail($to, $toName, $subject, $htmlBody, $textBody = null) {
    require_once __DIR__ . '/../../PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../../PHPMailer/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $smtp = getSmtpConfig();

    try {
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->SMTPSecure = $smtp['encryption'];
        $mail->Port = $smtp['port'];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->addAddress($to, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody ?? strip_tags($htmlBody);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email send failed: " . $e->getMessage());
        return false;
    }
}

function renderNav($active = 'dashboard') {
    ?>
    <nav class="bg-white shadow-sm border-b border-stone-200">
        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
            <h1 class="text-xl font-bold text-stone-800">OnlyBikes Email Admin</h1>
            <div class="space-x-4">
                <a href="dashboard.php" class="<?= $active === 'dashboard' ? 'text-sage-600 font-medium' : 'text-stone-600 hover:text-sage-600' ?>">Dashboard</a>
                <a href="queue.php" class="<?= $active === 'queue' ? 'text-sage-600 font-medium' : 'text-stone-600 hover:text-sage-600' ?>">Queue</a>
                <a href="logs.php" class="<?= $active === 'logs' ? 'text-sage-600 font-medium' : 'text-stone-600 hover:text-sage-600' ?>">Logs</a>
                <a href="settings.php" class="<?= $active === 'settings' ? 'text-sage-600 font-medium' : 'text-stone-600 hover:text-sage-600' ?>">Settings</a>
                <a href="stock.php" class="<?= $active === 'stock' ? 'text-sage-600 font-medium' : 'text-stone-600 hover:text-sage-600' ?>">Stock</a>
                <a href="shipping.php" class="<?= $active === 'shipping' ? 'text-sage-600 font-medium' : 'text-stone-600 hover:text-sage-600' ?>">Shipping</a>
                <a href="analytics.php" class="<?= $active === 'analytics' ? 'text-sage-600 font-medium' : 'text-stone-600 hover:text-sage-600' ?>">Analytics</a>
                <a href="coupons.php" class="px-3 py-2 rounded-md text-sm font-medium <?= $active === 'coupons' ? 'text-sage-600 font-medium' : 'text-stone-600 hover:text-sage-600' ?>">Coupons</a>
                <a href="points.php" class="<?= $active === 'points' ? 'text-sage-600 font-medium' : 'text-stone-600 hover:text-sage-600' ?>">Points</a>
                <a href="index.php?logout=1" class="text-red-600 hover:text-red-800">Logout</a>
            </div>
        </div>
    </nav>
    <?php
}

function queueConfirmedSubscribers(): array {
    $subject = trim($_POST['subject'] ?? '');
    $html    = trim($_POST['html']    ?? '');
    $text    = trim($_POST['text']    ?? '') ?: strip_tags($html);
 
    if (empty($subject) || empty($html)) {
        return ['error' => true, 'message' => 'Subject and HTML content are required.'];
    }
 
    try {
        // Load secure-config.php if getNewsletterDatabase() isn't available
        if (!function_exists('getNewsletterDatabase')) {
            $apiPath = dirname(__DIR__) . '/api/secure-config.php';
            if (file_exists($apiPath)) {
                require_once $apiPath;
            } else {
                throw new Exception('secure-config.php not found at ' . $apiPath);
            }
        }
        
        $pdo = getNewsletterDatabase();
        require_once API_PATH . '/lib/newsletter-schema.php';
        ensureNewsletterDatabaseSchema($pdo);

        // Fetch all confirmed subscribers
        $stmt = $pdo->query("
            SELECT id, email, name, unsubscribe_token
            FROM newsletter_subscribers
            WHERE status = 'confirmed'
            ORDER BY id ASC
        ");
        $subscribers = $stmt->fetchAll();
 
        if (empty($subscribers)) {
            return ['error' => true, 'message' => 'No confirmed subscribers found.'];
        }
 
        // Prepare insert using YOUR ACTUAL email_queue column names
        $insert = $pdo->prepare("
            INSERT INTO email_queue
                (recipient_email, recipient_name, subject, html_body, text_body, 
                 unsubscribe_token, send_after, status, retry_count, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW())
        ");
 
        $queued = 0;
        $stagger = 0;
 
        foreach ($subscribers as $sub) {
            $unsubToken = $sub['unsubscribe_token'] ?? bin2hex(random_bytes(16));
            $unsubUrl = 'https://onlybikes.example/api/newsletter-unsubscribe.php?token=' . urlencode($unsubToken);
            $greeting = !empty($sub['name']) ? htmlspecialchars($sub['name'], ENT_QUOTES, 'UTF-8') : 'there';
 
            // Personalize content
            $personalHtml = str_replace(
                ['{{name}}', '{{unsubscribe_url}}'],
                [$greeting, $unsubUrl],
                $html
            );
            $personalText = str_replace(
                ['{{name}}', '{{unsubscribe_url}}'],
                [$greeting, $unsubUrl],
                $text
            );
 
            // Stagger send times to avoid rate limiting
            $sendAfter = date('Y-m-d H:i:s', strtotime("+{$stagger} seconds"));
 
            // Execute with CORRECT column order matching the INSERT above
            $insert->execute([
                $sub['email'],              // recipient_email
                $sub['name'] ?? '',         // recipient_name  
                $subject,                   // subject
                $personalHtml,              // html_body
                $personalText,              // text_body
                $unsubToken,                // unsubscribe_token
                $sendAfter,                 // send_after
                // status, retry_count, created_at are hardcoded in SQL
            ]);
 
            $queued++;
            $stagger += 1; // 1 second stagger
        }
 
        return ['queued' => $queued];
 
    } catch (Throwable $e) {
        error_log('[BEDDA] queueConfirmedSubscribers error: ' . $e->getMessage());
        return ['error' => true, 'message' => 'Database error: ' . $e->getMessage()];
    }
}