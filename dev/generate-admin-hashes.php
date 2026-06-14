<?php
/**
 * Run locally: php dev/generate-admin-hashes.php "YourPassword" "YourAccessKey"
 * Paste output into email-admin/config.php — never commit real passwords.
 */
declare(strict_types=1);

$password = $argv[1] ?? '';
$accessKey = $argv[2] ?? '';

if ($password === '' || $accessKey === '') {
    fwrite(STDERR, "Usage: php dev/generate-admin-hashes.php <admin-password> <access-key>\n");
    exit(1);
}

echo "ADMIN_PASSWORD_HASH\n";
echo password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]) . "\n\n";
echo "SECRET_KEY_HASH\n";
echo password_hash($accessKey, PASSWORD_BCRYPT, ['cost' => 12]) . "\n";
