#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Lightweight license / third-party inventory check (SBOM-lite without Composer).
 * - THIRD_PARTY.md must exist and mention known vendored components + worker deps + Feather
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

// Docs-only mentions (no LICENSE tree under workers — pip install on operator host).
$docsOnly = ['Feather', 'Ultralytics', 'requests', 'urllib3'];
foreach ($docsOnly as $name) {
    if (stripos($md, $name) === false) {
        echo "FAIL THIRD_PARTY.md missing mention of {$name}\n";
        $failed++;
    } else {
        echo "OK  THIRD_PARTY.md mentions {$name}\n";
    }
}

// Every top-level package in workers requirements must appear in THIRD_PARTY.md
// (core + plugins/<id>/ + legacy workers/<id>/).
$reqFiles = array_merge(
    glob($root . '/workers/core/requirements.txt') ?: [],
    glob($root . '/workers/plugins/*/requirements.txt') ?: [],
    glob($root . '/workers/*/requirements.txt') ?: []
);
$reqFiles = array_values(array_unique($reqFiles));
$workerPackages = [];
foreach ($reqFiles as $reqFile) {
    $rel = ltrim(str_replace('\\', '/', substr($reqFile, strlen($root))), '/');
    $lines = file($reqFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('/^([A-Za-z0-9_.-]+)/', $line, $m)) {
            continue;
        }
        $pkg = strtolower($m[1]);
        $workerPackages[$pkg] = $rel;
    }
}
foreach ($workerPackages as $pkg => $reqRel) {
    // Normalize common aliases for doc search.
    $aliases = [$pkg];
    if ($pkg === 'opencv-python-headless') {
        $aliases[] = 'opencv';
        $aliases[] = 'OpenCV';
    }
    if ($pkg === 'pillow') {
        $aliases[] = 'Pillow';
    }
    if ($pkg === 'ultralytics') {
        $aliases[] = 'Ultralytics';
    }
    $hit = false;
    foreach ($aliases as $a) {
        if (stripos($md, $a) !== false) {
            $hit = true;
            break;
        }
    }
    if (!$hit) {
        echo "FAIL THIRD_PARTY.md missing worker dep {$pkg} (from {$reqRel})\n";
        $failed++;
    } else {
        echo "OK  worker dep {$pkg} documented\n";
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
foreach ($workerPackages as $pkg => $reqRel) {
    $components[] = [
        'type' => 'library',
        'name' => $pkg,
        'path' => $reqRel,
    ];
}
$components[] = [
    'type' => 'library',
    'name' => 'Feather Icons',
    'path' => 'assets/css/core.css',
];
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
