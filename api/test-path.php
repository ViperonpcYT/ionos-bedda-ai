<?php
// DELETE AFTER USE
echo "<pre>";
$dir = '/homepages/6/d4299539843/htdocs/llama-cli';
echo "=== Contents of llama-cli dir ===\n";
if (is_dir($dir)) {
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        $size = round(filesize($p)/1024, 1) . ' KB';
        echo "$f  ($size)\n";
    }
} else {
    echo "Directory does not exist!\n";
}

echo shell_exec('ls /usr/lib/x86_64-linux-gnu/libssl* 2>&1') . "\n";
echo shell_exec('ls /usr/lib/x86_64-linux-gnu/libcrypto* 2>&1') . "\n";

echo "\n=== Searching htdocs for .so files ===\n";
$root = '/homepages/6/d4299539843/htdocs';
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iter as $f) {
    if (str_ends_with($f->getFilename(), '.so') || str_contains($f->getFilename(), '.so.')) {
        echo $f->getPathname() . " (" . round($f->getSize()/1024,1) . " KB)\n";
    }
}
echo "</pre>";