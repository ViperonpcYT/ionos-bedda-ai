<?php
declare(strict_types=1);

require_once __DIR__ . '/email-admin/config.php';
session_name(EMAIL_ADMIN_SESSION);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$loggedIn = !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
if (!$loggedIn) {
    header('Location: /email-admin/index.php');
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
readfile(__DIR__ . '/analytics-backend.html');
