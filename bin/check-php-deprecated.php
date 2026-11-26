#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Fail if first-party PHP calls functions deprecated in PHP 8.x (no-ops since 8.0, E_DEPRECATED on 8.5):
 * curl_close, curl_share_close, finfo_close, imagedestroy, xml_parser_free, libxml_disable_entity_loader,
 * and friends. Handles are freed by refcount — just drop the call.
 * Skips vendor/ and bundled phpqrcode/. Exit: 0 ok, 1 fail
 */
$root = dirname(__DIR__);

$deprecated = [
    'curl_close' => '8.5 (no-op since 8.0)',
    'curl_share_close' => '8.5 (no-op since 8.0)',
    'finfo_close' => '8.5 (no-op since 8.1)',
    'imagedestroy' => '8.5 (no-op since 8.0)',
    'xml_parser_free' => '8.5 (no-op since 8.0)',
    'libxml_disable_entity_loader' => '8.0',
    'odbc_result_all' => '8.4',
    'mysqli_ping' => '8.4',
    'mysqli_kill' => '8.4',
    'mysqli_refresh' => '8.4',
];
$re = '/(?<![\w>:$])(' . implode('|', array_map('preg_quote', array_keys($deprecated))) . ')\s*\(/i';

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
    $rel = substr($file->getPathname(), strlen($root) + 1);
    if (preg_match('#(^|/)(vendor|phpqrcode|node_modules|\.git)/#', $rel)) {
        continue;
    }
    if (!preg_match('#^(app|modules|workers|bin)/|^[^/]+\.php$#', $rel) || $rel === 'bin/check-php-deprecated.php') {
        continue;
    }
    $src = (string)file_get_contents($file->getPathname());
    $checked++;
    if (!preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($m[1] as $hit) {
        $pos = (int)$hit[1];
        // Skip comment-only mentions on the same line.
        $lineStart = strrpos(substr($src, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $prefix = ltrim(substr($src, $lineStart, $pos - $lineStart));
        if (preg_match('#^(//|\*|\#)#', $prefix)) {
            continue;
        }
        $line = substr_count(substr($src, 0, $pos), "\n") + 1;
        $fn = strtolower((string)$hit[0]);
        echo "FAIL {$rel}:{$line} {$fn}() deprecated since PHP " . ($deprecated[$fn] ?? '8.x') . "\n";
        $failed++;
    }
}

if ($failed > 0) {
    echo "FAILED ({$failed}) files={$checked}\n";
    exit(1);
}
echo "OK  php-deprecated guard ({$checked} files)\n";
exit(0);
