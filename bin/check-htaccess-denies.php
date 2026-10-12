#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Static assert that Apache templates deny sensitive paths (var/log/.git/app/…).
 * PHP built-in server ignores .htaccess — this is the CI substitute for “close internal files”.
 * Exit: 0 ok, 1 fail
 */
$root = dirname(__DIR__);
$files = [
    $root . '/.htaccess',
    $root . '/cfg/htaccess.storefront.example',
];
$needles = [
    'var|log' => 'must deny var/ and log/',
    '\.git' => 'must deny .git/',
    'app|cfg|bin|docs' => 'must deny app/cfg/bin/docs',
];
$failed = 0;

foreach ($files as $path) {
    $rel = substr($path, strlen($root) + 1);
    if (!is_file($path)) {
        echo "FAIL missing {$rel}\n";
        $failed++;
        continue;
    }
    $body = (string)file_get_contents($path);
    foreach ($needles as $frag => $why) {
        if (strpos($body, $frag) === false) {
            echo "FAIL {$rel}: {$why} (missing fragment {$frag})\n";
            $failed++;
        } else {
            echo "OK  {$rel}: {$why}\n";
        }
    }
}

// nginx example in install.md
$install = $root . '/docs/agents/install.md';
if (is_file($install)) {
    $md = (string)file_get_contents($install);
    if (strpos($md, 'var|log') === false || strpos($md, '.git') === false) {
        echo "FAIL docs/agents/install.md nginx deny example missing var|log or .git\n";
        $failed++;
    } else {
        echo "OK  docs/agents/install.md nginx deny example\n";
    }
}

if ($failed > 0) {
    echo "FAILED ({$failed})\n";
    exit(1);
}
echo "OK\n";
exit(0);
