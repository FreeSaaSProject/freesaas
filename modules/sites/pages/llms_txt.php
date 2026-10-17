<?php
declare(strict_types=1);

/**
 * /llms.txt for the current storefront host (https://llmstxt.org): site card for LLM readers.
 * Modules append sections via EventBus sites.llms_txt (title + markdown lines).
 */
$meta = SitesFront::siteMeta();
$host = SitesHostContext::host();
if ($host === '') {
    $host = AppRuntime::publicHost();
}
$base = 'https://' . $host;

$name = trim((string)($meta['name'] ?? ''));
$summary = trim((string)($meta['meta_descr'] ?? ''));
if ($summary === '') {
    $summary = trim((string)($meta['title'] ?? ''));
}
$oneLine = static function (string $s): string {
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
};

$sections = [];
$contacts = ['- ' . t('sites.llms.website') . ': ' . $base];
$tel = $oneLine((string)($meta['tel'] ?? ''));
if ($tel !== '') {
    $contacts[] = '- ' . t('sites.llms.phone') . ': ' . $tel;
}
$sections[] = ['title' => t('sites.llms.contacts'), 'lines' => $contacts];
$sections[] = ['title' => t('sites.llms.pages'), 'lines' => [
    '- [' . t('sites.llms.shop') . '](' . $base . '/shop): ' . t('sites.llms.shop_note'),
    '- [' . t('sites.llms.cart') . '](' . $base . '/cart): ' . t('sites.llms.cart_note'),
    '- [Sitemap](' . $base . '/sitemap.xml)',
]];

$payload = EventBus::emit('sites.llms_txt', [
    'host' => $host,
    'base' => $base,
    'sections' => $sections,
]);

$out = '# ' . ($name !== '' ? $oneLine($name) : $host) . "\n\n";
if ($summary !== '') {
    $out .= '> ' . $oneLine($summary) . "\n\n";
}
foreach ((array)($payload['sections'] ?? []) as $sec) {
    if (!is_array($sec) || trim((string)($sec['title'] ?? '')) === '') {
        continue;
    }
    $out .= '## ' . $oneLine((string)$sec['title']) . "\n\n";
    foreach ((array)($sec['lines'] ?? []) as $line) {
        $out .= rtrim((string)$line) . "\n";
    }
    $out .= "\n";
}

header('Content-Type: text/markdown; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');
echo rtrim($out) . "\n";
