#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Fail if first-party PHP calls unserialize() without allowed_classes => false.
 * Skips vendor/ and bundled phpqrcode/.
 * Exit: 0 ok, 1 fail
 */
$root = dirname(__DIR__);
$failed = 0;
$checked = 0;

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iter as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $rel = substr($path, strlen($root) + 1);
    if (preg_match('#(^|/)(vendor|phpqrcode)/#', $rel)) {
        continue;
    }
    // First-party app/modules only (skip this checker and other bin scripts).
    if (!preg_match('#^(app|modules)/#', $rel)) {
        continue;
    }
    $src = (string)file_get_contents($path);
    if ($src === '' || stripos($src, 'unserialize') === false) {
        continue;
    }
    if (!preg_match_all('/\bunserialize\s*\(/i', $src, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($m[0] as $hit) {
        $pos = (int)$hit[1];
        $checked++;
        $window = substr($src, $pos, 240);
        // Match ['allowed_classes' => false] or ["allowed_classes" => false]
        if (preg_match('/[\'"]allowed_classes[\'"]\s*=>\s*false/', $window)) {
            continue;
        }
        $line = substr_count(substr($src, 0, $pos), "\n") + 1;
        echo "FAIL {$rel}:{$line} unserialize() without allowed_classes => false\n";
        $failed++;
    }
}

if ($failed > 0) {
    echo "FAILED ({$failed}) checked={$checked}\n";
    exit(1);
}
echo "OK  unserialize guard ({$checked} call sites)\n";
exit(0);
