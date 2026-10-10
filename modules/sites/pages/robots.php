<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
$host = AppRuntime::publicHost();
if ($host === '') {
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
}
echo "User-agent: *\nAllow: /\nSitemap: https://" . $host . "/sitemap.xml\n";
