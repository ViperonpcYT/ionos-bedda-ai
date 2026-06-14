<?php
/**
 * Router for PHP built-in dev server: php -S localhost:8080 dev/router.php
 */
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__);
$file = $root . urldecode($uri);

if ($uri !== '/' && is_file($file)) {
    if (str_ends_with($file, '.php')) {
        require $file;
        return true;
    }
    return false;
}

if ($uri === '/' || $uri === '/index.html') {
    return false;
}

return false;
