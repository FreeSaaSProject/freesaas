<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');
$host = AppRuntime::publicHost();
if ($host === '') {
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
}
$base = 'https://' . $host;
$urls = [$base . '/'];
if (ModuleManager::isEnabled('catalog')) {
    $urls[] = $base . '/shop';
}
$urls = array_merge($urls, SitesFront::sitemapPageUrls($base));
// Product modules add their public routes (e.g. a landing /modules/).
$extra = EventBus::emit('sites.sitemap', ['base' => $base, 'urls' => []]);
foreach ((is_array($extra) ? ($extra['urls'] ?? []) : []) as $u) {
    if (is_string($u) && $u !== '') {
        $urls[] = $u[0] === '/' ? $base . $u : $u;
    }
}
$urls = array_values(array_unique($urls));
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url><loc>' . htmlspecialchars($u, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>' . "\n";
}
echo '</urlset>';
