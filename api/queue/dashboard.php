<?php
// /api/queue/dashboard.php - Control Panel

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$action = $_GET['action'] ?? 'status';
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

if (!verifyApiKey($apiKey)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

switch ($action) {
    case 'status':
        showStatus();
        break;
        
    case 'pause':
        file_put_contents(__DIR__ . '/queue.paused', 'Manual pause');
        echo json_encode(['status' => 'paused']);
        break;
        
    case 'resume':
        @unlink(__DIR__ . '/queue.paused');
        echo json_encode(['status' => 'resumed']);
        break;
        
    case 'stats':
        showStats();
        break;
}

function showStatus() {
    $pdo = getDatabase();
    
    // Queue status
    $status = $pdo->query("
        SELECT 
            status,
            COUNT(*) as count,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed
        FROM email_queue
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ")->fetch();
    
    // Daily count
    $daily = $pdo->query("
        SELECT COUNT(*) as count 
        FROM email_queue 
        WHERE status='sent' 
        AND DATE(sent_at) = CURDATE()
    ")->fetch();
    
    echo json_encode([
        'queue' => $status,
        'daily_sent' => $daily['count'],
        'config' => [
            'batch_size' => 30,
            'batch_delay' => 70,
            'daily_cap' => 1800
        ]
    ]);
}

function showStats() {
    $pdo = getDatabase();
    $stats = $pdo->query("
        SELECT 
            DATE(created_at) as date,
            SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed
        FROM email_queue
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
    ")->fetchAll();
    
    echo json_encode($stats);
}