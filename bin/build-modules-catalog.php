#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Build modules-catalog.json for remote install discovery (UpdateEngine).
 *
 * Usage:
 *   php bin/build-modules-catalog.php
 *   PRIVATE_MODULES=secretpack php bin/build-modules-catalog.php
 *   php bin/build-modules-catalog.php --out=dist/freesaas-catalog.json
 *
 * Writes repo-root modules-catalog.json by default (Contents API path).
 * Default: no private modules (likespa product module is public; themes are separate).
 */

$root = dirname(__DIR__);
$privateEnv = getenv('PRIVATE_MODULES');
$privateList = is_string($privateEnv) ? $privateEnv : '';
$private = [];
foreach (explode(',', $privateList) as $p) {
    $p = trim($p);
    if ($p !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $p)) {
        $private[$p] = true;
    }
}

$outPath = $root . '/modules-catalog.json';
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (strpos($arg, '--out=') === 0) {
        $outPath = substr($arg, strlen('--out='));
        if ($outPath !== '' && $outPath[0] !== '/') {
            $outPath = $root . '/' . $outPath;
        }
    }
}

$langCache = ['en' => [], 'ru' => []];
$loadLang = static function (string $modId, string $locale) use ($root, &$langCache): array {
    $key = $modId . ':' . $locale;
    if (isset($langCache[$locale][$modId])) {
        return $langCache[$locale][$modId];
    }
    $path = $root . '/modules/' . $modId . '/lang/' . $locale . '.php';
    $map = [];
    if (is_file($path)) {
        $data = require $path;
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $map[$k] = $v;
                }
            }
        }
    }
    $langCache[$locale][$modId] = $map;
    return $map;
};

$bilocale = static function (string $modId, string $key) use ($loadLang): array {
    if ($key === '') {
        return ['en' => '', 'ru' => ''];
    }
    $en = $loadLang($modId, 'en');
    $ru = $loadLang($modId, 'ru');
    return [
        'en' => (string)($en[$key] ?? ''),
        'ru' => (string)($ru[$key] ?? ''),
    ];
};

$modules = [];
foreach (glob($root . '/modules/*/module.json') ?: [] as $manifestPath) {
    $raw = file_get_contents($manifestPath);
    $meta = json_decode((string)$raw, true);
    if (!is_array($meta) || empty($meta['id'])) {
        continue;
    }
    $id = (string)$meta['id'];
    if ($id === 'core' || isset($private[$id])) {
        continue;
    }
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
        continue;
    }
    $type = (string)($meta['type'] ?? 'product');
    if ($type === 'platform') {
        continue;
    }

    $catalog = is_array($meta['catalog'] ?? null) ? $meta['catalog'] : [];
    $categories = [];
    if (isset($catalog['categories']) && is_array($catalog['categories'])) {
        foreach ($catalog['categories'] as $c) {
            if (is_string($c) && $c !== '') {
                $categories[] = $c;
            }
        }
    }
    $legacy = trim((string)($catalog['category'] ?? ''));
    if ($legacy !== '' && !in_array($legacy, $categories, true)) {
        $categories[] = $legacy;
    }
    $tags = [];
    if (isset($catalog['tags']) && is_array($catalog['tags'])) {
        foreach ($catalog['tags'] as $tg) {
            if (is_string($tg) && $tg !== '') {
                $tags[] = $tg;
            }
        }
    }
    $maturity = strtolower(trim((string)($catalog['maturity'] ?? 'beta')));
    if (!in_array($maturity, ['alpha', 'beta', 'release'], true)) {
        $maturity = 'beta';
    }

    $nameKey = (string)($meta['name'] ?? '');
    $descKey = (string)($meta['description'] ?? '');
    $summaryKey = $id . '.module.summary';

    $entry = [
        'id' => $id,
        'version' => (string)($meta['version'] ?? '0.0.0'),
        'type' => $type,
        'name' => $bilocale($id, $nameKey),
        'description' => $bilocale($id, $descKey),
        'summary' => $bilocale($id, $summaryKey),
        'requires' => is_array($meta['requires'] ?? null) ? $meta['requires'] : [],
        'optionalRequires' => is_array($meta['optionalRequires'] ?? null) ? $meta['optionalRequires'] : [],
        'catalog' => [
            'categories' => array_values(array_unique($categories)),
            'maturity' => $maturity,
            'tags' => array_values(array_unique($tags)),
        ],
    ];

    // Public help path for catalog / marketing (same allowlist as HelpDocs).
    $docsRel = trim((string)(($meta['agent'] ?? [])['docs'] ?? ''));
    if ($docsRel !== '' && preg_match('#^docs/agents/[a-z0-9][a-z0-9._-]*\.md$#i', $docsRel)
        && is_file($root . '/' . $docsRel)) {
        $entry['agent'] = ['docs' => $docsRel];
    }

    $modules[] = $entry;
}

usort($modules, static function (array $a, array $b): int {
    return strcmp($a['id'], $b['id']);
});

$payload = [
    'schema' => 1,
    'generated_at' => gmdate('c'),
    'modules' => $modules,
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "Failed to encode catalog\n");
    exit(1);
}

$dir = dirname($outPath);
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}
if (@file_put_contents($outPath, $json . "\n") === false) {
    fwrite(STDERR, "Cannot write {$outPath}\n");
    exit(1);
}

echo 'Wrote ' . $outPath . ' (' . count($modules) . " modules)\n";
exit(0);
