#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Export active elos/oper document templates (base_docs + base_docs_datas of a legacy schema)
 * into importable files under modules/docs/library/<pack>/ — templates only, no print instances.
 *
 * - only ENABLED=1 and not disabled (DT_DISABLED=0); identical texts collapse to the newest copy
 * - company requisites → {org.*} placeholders (pattern detection, no company literals in code)
 * - legacy variables → engine placeholders, gender forms [f|m|x] → {g:f|m|x}
 * - a template with residual company traces is not written unless --force
 *
 * Usage: php bin/docs-export-legacy.php --schema=elos63 [--pack=elos63] [--name="…"] [--dry-run] [--force] [--report]
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
foreach (['DocsSchema', 'DocsSeed', 'DocsDefsRepository', 'DocsLegacy', 'DocsLibrary'] as $class) {
    if (!class_exists($class, false)) {
        require_once dirname(__DIR__) . '/modules/docs/lib/' . $class . '.php';
    }
}

$opts = getopt('', ['schema:', 'pack:', 'name:', 'dry-run', 'force', 'report']);
$schema = (string)($opts['schema'] ?? '');
if (!preg_match('/^[A-Za-z0-9_]+$/', $schema)) {
    fwrite(STDERR, "Usage: php bin/docs-export-legacy.php --schema=<legacy_db> [--pack=id] [--name=title] [--dry-run] [--force] [--report]\n");
    exit(2);
}
$pack = (string)($opts['pack'] ?? strtolower($schema));
if (!preg_match('/^[a-z0-9_\-]+$/', $pack)) {
    fwrite(STDERR, "Invalid --pack\n");
    exit(2);
}
$dryRun = isset($opts['dry-run']);
$force = isset($opts['force']);
$pdo = Database::pdo();

$probe = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (\'base_docs\', \'base_docs_datas\')');
$probe->execute([$schema]);
if ((int)$probe->fetchColumn() < 2) {
    fwrite(STDERR, "No base_docs / base_docs_datas in schema {$schema}\n");
    exit(1);
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM `{$schema}`.base_docs")->fetchColumn();
$rows = $pdo->query(
    "SELECT d.*, dd.CONTENT, dd.ENABLED AS DATA_ENABLED, dd.main_font AS MAIN_FONT
     FROM `{$schema}`.base_docs d
     JOIN `{$schema}`.base_docs_datas dd ON dd.ID = d.ID_DATA
     WHERE d.ENABLED = 1 AND d.DT_DISABLED = 0
     ORDER BY d.ID DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$texts = array_map(static fn(array $r): string => DocsLegacy::plainText((string)$r['CONTENT']), $rows);
$detected = DocsLegacy::detectEnterprise($texts);
if (isset($opts['report'])) {
    foreach ($detected['found'] as $key => $literals) {
        echo "{$key}: " . implode(' | ', array_map(static fn(string $s): string => mb_substr($s, 0, 80), $literals)) . "\n";
    }
}

$seenText = [];
$codes = [];
$files = [];
$merged = 0;
$blocked = [];
$droppedImages = 0;
foreach ($rows as $r) {
    $html = DocsLegacy::scrubImages((string)$r['CONTENT'], $droppedImages);
    $html = DocsLegacy::convertPlaceholders(DocsLegacy::scrub($html, $detected['map']));
    $font = (float)($r['MAIN_FONT'] ?? 0);
    if ($font > 0) {
        $html = '<div style="font-size:' . rtrim(rtrim(number_format($font, 1, '.', ''), '0'), '.') . 'pt">' . $html . '</div>';
    }
    $hash = sha1((string)preg_replace('/\s+/u', ' ', DocsLegacy::plainText($html)));
    if (isset($seenText[$hash])) {
        $files[$seenText[$hash]]['source']['merged_codes'][] = (string)$r['CODE'];
        $merged++;
        continue;
    }
    $residual = DocsLegacy::residual($html, $detected['needles']);
    if ($residual !== [] && !$force) {
        $blocked[(string)$r['CODE']] = $residual;
        continue;
    }

    $code = DocsLegacy::slug((string)$r['CODE']);
    $base = $code;
    for ($i = 2; isset($codes[$code]); $i++) {
        $code = substr($base, 0, 60) . '_' . $i;
    }
    $codes[$code] = true;

    $expire = (int)$r['EXPIRE_DAYS'];
    // 9999 days = "forever" (one per client); 1 day = per visit.
    if ($expire >= 3650) {
        [$scope, $expire] = ['client', 0];
    } elseif ($expire <= 1) {
        [$scope, $expire] = ['event', 0];
    } else {
        $scope = 'client';
    }
    $trigger = DocsLegacy::trigger($r);
    $triggerNote = $trigger['mode'];
    if ($trigger['procs'] !== []) {
        $triggerNote .= ': ' . implode(',', $trigger['procs']);
    } elseif ($trigger['types'] !== []) {
        $triggerNote .= ': ' . implode(',', $trigger['types']);
    }
    $seenText[$hash] = $code;
    $files[$code] = [
        'format' => DocsLibrary::FORMAT,
        'format_version' => DocsLibrary::FORMAT_VERSION,
        'code' => $code,
        'kind' => DocsLegacy::kindForCode((string)$r['CODE']),
        'name' => trim((string)$r['DESCR']) !== '' ? trim((string)$r['DESCR']) : (string)$r['CODE'],
        'scope' => $scope,
        'trigger_mode' => $trigger['mode'],
        'trigger_types' => $trigger['types'],
        'trigger_procs' => $trigger['procs'],
        'expire_days' => $expire,
        'require_medcard' => 0,
        'orientation' => !empty($r['ORIENTATION']) ? 'landscape' : 'portrait',
        'paper' => (int)$r['FORMAT'] === 5 ? 'A5' : 'A4',
        'copies' => max(1, min(9, (int)$r['COPIES'])),
        'schema' => ['sections' => []],
        'print_html' => $html,
        'source' => [
            'system' => 'elos',
            'legacy_code' => (string)$r['CODE'],
            'legacy_version' => (float)$r['VERSION'],
            'trigger' => $triggerNote,
            'text_approved' => (int)$r['DATA_ENABLED'] === 1,
        ],
    ];
}

$placeholders = [];
foreach ($files as $f) {
    preg_match_all('/\{(org\.[a-z_]+)\}/', (string)$f['print_html'], $m);
    foreach ($m[1] as $p) {
        $placeholders[$p] = ($placeholders[$p] ?? 0) + 1;
    }
}
ksort($placeholders);

echo "legacy templates: {$total}, active: " . count($rows) . ", identical merged: {$merged}, exported: " . count($files)
    . ', blocked: ' . count($blocked) . ", local images dropped: {$droppedImages}\n";
echo 'org placeholders: ' . implode(', ', array_map(static fn(string $k, int $n): string => "{$k}×{$n}", array_keys($placeholders), $placeholders)) . "\n";
foreach ($blocked as $code => $residual) {
    echo "BLOCKED {$code}:\n  " . implode("\n  ", $residual) . "\n";
}
if ($dryRun) {
    echo "dry run — nothing written\n";
    exit($blocked !== [] ? 1 : 0);
}

$dir = DocsLibrary::dir() . '/' . $pack;
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}
foreach (glob($dir . '/*.json') ?: [] as $old) {
    unlink($old);
}
foreach ($files as $code => $data) {
    file_put_contents($dir . '/' . $code . '.json', DocsLibrary::encode($data));
}
file_put_contents($dir . '/pack.json', DocsLibrary::encode([
    'format' => DocsLibrary::FORMAT . '-pack',
    'name' => (string)($opts['name'] ?? ('elos: ' . $pack)),
    'description' => 'Active templates exported from legacy elos/oper; company requisites replaced with {org.*} placeholders.',
    'exported_at' => date('c'),
    'count' => count($files),
    'placeholders' => $placeholders,
]));
echo 'written: ' . count($files) . " files → {$dir}\n";
exit($blocked !== [] ? 1 : 0);
