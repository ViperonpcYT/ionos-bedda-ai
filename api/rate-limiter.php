<?php
require_once __DIR__ . '/secure-config.php';

class RateLimiter {
    private $storageDir;
    private $ip;
    private $cookieId;
    private $enabled;
    
    public function __construct() {
        $this->enabled = RATE_LIMIT_ENABLED;
        $this->storageDir = RATE_LIMIT_DIR;
        $this->ip = getClientIP();
        $this->cookieId = $this->getCookieId();
        
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }
    }
    
    private function getCookieId() {
        $cookieName = 'bedda_visitor';
        if (isset($_COOKIE[$cookieName])) return $_COOKIE[$cookieName];
        
        $cookieId = 'bedda_' . bin2hex(random_bytes(16));
        setcookie($cookieName, $cookieId, [
            'expires' => time() + 2592000, 'path' => '/', 'secure' => beddaSessionCookieSecure(),
            'httponly' => true, 'samesite' => 'Strict'
        ]);
        return $cookieId;
    }
    
    public function canSubmitOrder($email, $phone) {
        if (!$this->enabled) return ['allowed' => true, 'score' => 0];
        $this->cleanOldEntries();
        $score = 0; $reasons = [];
        
        $checks = [
            $this->checkIPLimit(),
            $this->checkCookieLimit(),
            $this->checkEmailLimit($email),
            $this->checkPhoneLimit($phone)
        ];
        
        foreach ($checks as $check) {
            if (!$check['allowed']) return $check;
            $score += $check['score'] ?? 0;
            if (!empty($check['reason'])) $reasons[] = $check['reason'];
        }
        
        $suspicious = $this->checkSuspiciousPatterns($email, $phone);
        $score += $suspicious['score'] ?? 0;
        if (!empty($suspicious['reason'])) $reasons[] = $suspicious['reason'];
        
        return [
            'allowed' => true, 'score' => $score, 'reasons' => $reasons,
            'requiresCaptcha' => $score >= SPAM_SUSPICIOUS_THRESHOLD && $score < SPAM_BLOCK_THRESHOLD,
            'blocked' => $score >= SPAM_BLOCK_THRESHOLD
        ];
    }
    
    public function canValidateCoupon($ip, $code) {
        if (!$this->enabled) return true;
        $key = 'coupon_validate:' . md5($ip . ':' . strtoupper(trim($code)));
        $file = $this->storageDir . 'rate-' . md5($key) . '.json';
        $data = $this->readData($file);
        
        $windowStart = time() - COUPON_RATE_LIMIT_WINDOW;
        $recent = array_filter($data, function($entry) use ($windowStart) {
            return $entry['time'] > $windowStart;
        });
        
        if (count($recent) >= COUPON_RATE_LIMIT_PER_MINUTE) return false;
        
        $data[] = ['time' => time()];
        if (count($data) > 50) $data = array_slice($data, -50);
        $this->writeData($file, $data);
        return true;
    }
    
    public function recordOrder($email, $phone, $orderData = []) {
        if (!$this->enabled) return;
        $this->recordIPAttempt($orderData);
        $this->recordCookieAttempt($orderData);
        $this->recordEmailAttempt($email);
        $this->recordPhoneAttempt($phone);
    }

    /** Summary counts for /api/check-rate-limit.php */
    public function getStatus($email, $phone) {
        $hourAgo = time() - 3600;
        $dayAgo = time() - 86400;
        $ipData = $this->readData($this->storageDir . 'ip-' . md5($this->ip) . '.json');
        $emailData = $this->readData($this->storageDir . 'email-' . md5(strtolower(trim($email))) . '.json');
        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        $phoneData = $phoneDigits
            ? $this->readData($this->storageDir . 'phone-' . md5($phoneDigits) . '.json')
            : [];
        return [
            'ip_recent_hour'    => count(array_filter($ipData, fn($e) => ($e['time'] ?? 0) > $hourAgo)),
            'email_recent_day'  => count(array_filter($emailData, fn($e) => ($e['time'] ?? 0) > $dayAgo)),
            'phone_recent_day'  => count(array_filter($phoneData, fn($e) => ($e['time'] ?? 0) > $dayAgo)),
        ];
    }
    
    private function checkIPLimit() {
        $file = $this->storageDir . 'ip-' . md5($this->ip) . '.json';
        $data = $this->readData($file);
        $hourAgo = time() - 3600;
        $recent = array_filter($data, fn($e) => $e['time'] > $hourAgo);
        $count = count($recent);
        
        if ($count >= RATE_LIMIT_ORDERS_PER_IP_PER_HOUR) {
            $oldest = min(array_column($recent, 'time'));
            $retry = ceil((3600 - (time() - $oldest)) / 60);
            logSecurityEvent('rate_limit_ip_blocked', ['ip' => $this->ip, 'attempts' => $count]);
            return ['allowed' => false, 'reason' => 'rate_limit_ip', 'message' => "Too many orders from this location. Try again in {$retry} min(s).", 'retry_after' => $retry];
        }
        return ['allowed' => true, 'score' => $count * 0.5, 'reason' => $count > 0 ? "IP has {$count} recent orders" : ''];
    }
    
    private function checkCookieLimit() {
        $file = $this->storageDir . 'cookie-' . md5($this->cookieId) . '.json';
        $data = $this->readData($file);
        $hourAgo = time() - 3600;
        $recent = array_filter($data, fn($e) => $e['time'] > $hourAgo);
        $count = count($recent);
        
        if ($count >= RATE_LIMIT_ORDERS_PER_IP_PER_HOUR) {
            $oldest = min(array_column($recent, 'time'));
            $retry = ceil((3600 - (time() - $oldest)) / 60);
            logSecurityEvent('rate_limit_cookie_blocked', ['cookie_hash' => md5($this->cookieId), 'attempts' => $count]);
            return ['allowed' => false, 'reason' => 'rate_limit_cookie', 'message' => "Too many orders from this device. Try again in {$retry} min(s).", 'retry_after' => $retry];
        }
        return ['allowed' => true, 'score' => $count * 0.5, 'reason' => $count > 0 ? "Device has {$count} recent orders" : ''];
    }
    
    private function checkEmailLimit($email) {
        $email = strtolower(trim($email));
        $file = $this->storageDir . 'email-' . md5($email) . '.json';
        $data = $this->readData($file);
        $dayAgo = time() - 86400;
        $recent = array_filter($data, fn($e) => $e['time'] > $dayAgo);
        $count = count($recent);
        
        if ($count >= RATE_LIMIT_ORDERS_PER_EMAIL_PER_DAY) {
            $oldest = min(array_column($recent, 'time'));
            $retry = ceil((86400 - (time() - $oldest)) / 3600);
            logSecurityEvent('rate_limit_email_blocked', ['email_hash' => md5($email), 'attempts' => $count]);
            return ['allowed' => false, 'reason' => 'rate_limit_email', 'message' => "Too many orders from this email. Try again in {$retry} hour(s).", 'retry_after' => $retry * 60];
        }
        return ['allowed' => true, 'score' => $count * 0.5, 'reason' => $count > 0 ? "Email has {$count} orders today" : ''];
    }
    
    private function checkPhoneLimit($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (empty($phone)) return ['allowed' => true, 'score' => 0];
        $file = $this->storageDir . 'phone-' . md5($phone) . '.json';
        $data = $this->readData($file);
        $dayAgo = time() - 86400;
        $recent = array_filter($data, fn($e) => $e['time'] > $dayAgo);
        $count = count($recent);
        
        if ($count >= RATE_LIMIT_ORDERS_PER_PHONE_PER_DAY) {
            $oldest = min(array_column($recent, 'time'));
            $retry = ceil((86400 - (time() - $oldest)) / 3600);
            logSecurityEvent('rate_limit_phone_blocked', ['phone_hash' => md5($phone), 'attempts' => $count]);
            return ['allowed' => false, 'reason' => 'rate_limit_phone', 'message' => "Too many orders from this phone. Try again in {$retry} hour(s).", 'retry_after' => $retry * 60];
        }
        return ['allowed' => true, 'score' => $count * 0.5, 'reason' => $count > 0 ? "Phone has {$count} orders today" : ''];
    }
    
    private function checkSuspiciousPatterns($email, $phone) {
        $score = 0; $reasons = [];
        if (isDisposableEmail($email)) { $score += 3; $reasons[] = 'Disposable email'; }
        if (isLikelyBot($_SERVER['HTTP_USER_AGENT'] ?? '')) { $score += 4; $reasons[] = 'Suspicious UA'; }
        if (empty($_SERVER['HTTP_ACCEPT']) || empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) { $score += 2; $reasons[] = 'Missing headers'; }
        
        $ipFile = $this->storageDir . 'ip-' . md5($this->ip) . '.json';
        $ipData = $this->readData($ipFile);
        $recentIP = array_filter($ipData, fn($e) => $e['time'] > time() - 3600);
        if (count($recentIP) >= 2 && count(array_unique(array_column($recentIP, 'email'))) >= 2) {
            $score += 2; $reasons[] = 'Multiple emails per IP';
        }
        return ['allowed' => true, 'score' => $score, 'reason' => implode(', ', $reasons)];
    }
    
    private function recordIPAttempt($d) { $this->writeToFile('ip-' . md5($this->ip), ['time'=>time(), 'email'=>$d['customerEmail']??'', 'order'=>$d['orderNumber']??'']); }
    private function recordCookieAttempt($d) { $this->writeToFile('cookie-' . md5($this->cookieId), ['time'=>time(), 'email'=>$d['customerEmail']??'', 'order'=>$d['orderNumber']??'']); }
    private function recordEmailAttempt($e) { $this->writeToFile('email-' . md5(strtolower(trim($e))), ['time'=>time()]); }
    private function recordPhoneAttempt($p) { $p=preg_replace('/[^0-9]/','',$p); if($p) $this->writeToFile('phone-' . md5($p), ['time'=>time()]); }
    
    private function writeToFile($base, $entry) {
        $file = $this->storageDir . $base . '.json';
        $data = $this->readData($file);
        $data[] = $entry;
        if (count($data) > 100) $data = array_slice($data, -100);
        file_put_contents($file, json_encode($data), LOCK_EX);
    }
    
    private function readData($file) {
        if (!file_exists($file)) return [];
        $c = file_get_contents($file);
        if ($c === false) return [];
        $d = json_decode($c, true);
        return is_array($d) ? $d : [];
    }
    
    private function cleanOldEntries() {
        $files = glob($this->storageDir . '*.json');
        if (!$files) return;
        $cutoff = time() - 604800;
        foreach ($files as $file) {
            $d = $this->readData($file);
            $cleaned = array_filter($d, fn($e) => ($e['time'] ?? 0) > $cutoff);
            empty($cleaned) ? @unlink($file) : $this->writeData($file, array_values($cleaned));
        }
    }
    
    private function writeData($file, $data) {
        if (count($data) > 100) $data = array_slice($data, -100);
        file_put_contents($file, json_encode($data), LOCK_EX);
    }
}