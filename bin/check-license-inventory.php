#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Lightweight license / third-party inventory check (SBOM-lite without Composer).
 * - THIRD_PARTY.md must exist and mention known vendored components
 * - Bundled trees must ship a LICENSE* file
 * Exit: 0 ok, 1 fail
 */
$root = dirname(__DIR__);
$failed = 0;

$third = $root . '/THIRD_PARTY.md';
if (!is_file($third)) {
    echo "FAIL missing THIRD_PARTY.md\n";
    exit(1);
}
$md = (string)file_get_contents($third);
$mustMention = [
    'phpMorphy' => 'modules/audio_pipeline/vendor/phpmorphy',
    'phpqrcode' => 'modules/stickers/lib/phpqrcode',
];
foreach ($mustMention as $name => $path) {
    if (stripos($md, $name) === false) {
        echo "FAIL THIRD_PARTY.md missing mention of {$name}\n";
        $failed++;
    } else {
        echo "OK  THIRD_PARTY.md mentions {$name}\n";
    }
    $abs = $root . '/' . $path;
    if (!is_dir($abs)) {
        echo "FAIL missing tree {$path}\n";
        $failed++;
        continue;
    }
    $licenses = glob($abs . '/LICENSE*') ?: [];
    $licenses = array_merge($licenses, glob($abs . '/**/LICENSE*') ?: []);
    if ($licenses === []) {
        // phpMorphy has LICENSE under vendor root
        $licenses = glob($abs . '/*.txt') ?: [];
    }
    $found = false;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        $bn = $f->getBasename();
        if (preg_match('/^LICENSE/i', $bn) || preg_match('/^COPYING/i', $bn)) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        echo "FAIL {$path}: no LICENSE/COPYING file\n";
        $failed++;
    } else {
        echo "OK  {$path}: LICENSE present\n";
    }
}

// Emit a tiny CycloneDX-ish stub for artifacts (optional inventory file in CI).
$components = [];
foreach ($mustMention as $name => $path) {
    $components[] = [
        'type' => 'library',
        'name' => $name,
        'path' => $path,
    ];
}
$sbom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'version' => 1,
    'metadata' => [
        'timestamp' => gmdate('c'),
        'component' => ['type' => 'application', 'name' => 'freesaas'],
    ],
    'components' => $components,
];
$outDir = $root . '/var';
if (is_dir($outDir) && is_writable($outDir)) {
    @file_put_contents($outDir . '/sbom-inventory.json', json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
echo 'OK  inventory components=' . count($components) . "\n";

if ($failed > 0) {
    echo "FAILED ({$failed})\n";
    exit(1);
}
exit(0);
