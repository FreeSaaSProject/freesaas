#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Docs drift guard (filesystem only, no DB):
 *   - every modules/<id> has a row in the AGENTS.md «Existing modules» table (and no stale rows);
 *   - every table in module.json `tables[]` is mentioned in docs/TABLE_MAP.md
 *     (exact `base_x` or a `base_prefix*` wildcard).
 *
 * Instance packs (modules that live in their own repo and are absent from modules-catalog.json)
 * are skipped, so `bin/smoke.php` stays green on an instance. `--strict` (CI of the main repo)
 * turns a module missing from the catalog into a failure.
 *
 * Usage: php bin/check-docs-modules.php [--strict] [--format=json]
 * Exit: 0 ok, 1 fail
 */
$root = dirname(__DIR__);
$json = in_array('--format=json', $argv, true) || in_array('--json', $argv, true);
$strict = in_array('--strict', $argv, true);

// Modules shipped by the main repo: core + everything in modules-catalog.json.
$shipped = null;
$catalog = json_decode((string)@file_get_contents($root . '/modules-catalog.json'), true);
if (is_array($catalog) && is_array($catalog['modules'] ?? null)) {
    $shipped = ['core' => true];
    foreach ($catalog['modules'] as $key => $row) {
        $cid = is_array($row) && isset($row['id']) ? (string)$row['id'] : (string)$key;
        if ($cid !== '') {
            $shipped[$cid] = true;
        }
    }
}

$agents = (string)@file_get_contents($root . '/AGENTS.md');
$tableMap = (string)@file_get_contents($root . '/docs/TABLE_MAP.md');
$errors = [];
if ($agents === '') {
    $errors[] = 'AGENTS.md not readable';
}
if ($tableMap === '') {
    $errors[] = 'docs/TABLE_MAP.md not readable';
}

// Rows of the «Existing modules» section: | `id` | role |
$documented = [];
if (preg_match('/^## Existing modules.*?$(.*?)(?=^## )/ms', $agents, $m)) {
    if (preg_match_all('/^\|\s*`([a-z0-9_]+)`\s*\|/m', $m[1], $mm)) {
        $documented = array_fill_keys($mm[1], true);
    }
} elseif ($agents !== '') {
    $errors[] = 'AGENTS.md: section «Existing modules» not found';
}

// TABLE_MAP tokens: exact names and `prefix*` wildcards.
$exact = [];
$prefixes = [];
if (preg_match_all('/`(base_[a-z0-9_]*)(\*?)`/', $tableMap, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $tok) {
        if ($tok[2] === '*') {
            $prefixes[$tok[1]] = true;
        } else {
            $exact[$tok[1]] = true;
        }
    }
}

$modules = [];
$onDisk = [];
$missingModules = [];
$missingTables = [];
$skipped = [];
foreach (glob($root . '/modules/*/module.json') ?: [] as $file) {
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data) || empty($data['id'])) {
        continue;
    }
    $id = (string)$data['id'];
    $onDisk[$id] = true;
    if ($shipped !== null && !isset($shipped[$id])) {
        if ($strict) {
            $errors[] = "modules-catalog.json: no entry for `{$id}` — run php bin/build-modules-catalog.php";
        } else {
            $skipped[] = $id;
            // Instance pack: still require TABLE_MAP for owned tables when documented.
            if ($documented !== [] && !isset($documented[$id])) {
                // Optional AGENTS row for private packs — not required.
            }
            foreach ((array)($data['tables'] ?? []) as $table) {
                $table = (string)$table;
                if ($table === '' || isset($exact[$table])) {
                    continue;
                }
                $covered = false;
                foreach ($prefixes as $prefix => $_) {
                    if (strpos($table, (string)$prefix) === 0) {
                        $covered = true;
                        break;
                    }
                }
                if (!$covered) {
                    $missingTables[$id][] = $table;
                }
            }
            continue;
        }
    }
    $modules[$id] = true;
    if ($documented !== [] && !isset($documented[$id])) {
        $missingModules[] = $id;
    }
    foreach ((array)($data['tables'] ?? []) as $table) {
        $table = (string)$table;
        if ($table === '' || isset($exact[$table])) {
            continue;
        }
        $covered = false;
        foreach ($prefixes as $prefix => $_) {
            if (strpos($table, (string)$prefix) === 0) {
                $covered = true;
                break;
            }
        }
        if (!$covered) {
            $missingTables[$id][] = $table;
        }
    }
}
// Stale AGENTS rows: documented but neither shipped nor present on disk as an instance pack.
$stale = [];
foreach (array_keys($documented) as $id) {
    if (isset($modules[$id]) || isset($onDisk[$id])) {
        continue;
    }
    $stale[] = $id;
}

foreach ($missingModules as $id) {
    $errors[] = "AGENTS.md: no row for module `{$id}` in «Existing modules»";
}
foreach ($stale as $id) {
    $errors[] = "AGENTS.md: row `{$id}` has no modules/{$id}/module.json";
}
foreach ($missingTables as $id => $tables) {
    $errors[] = "docs/TABLE_MAP.md: tables of `{$id}` not mentioned: " . implode(', ', $tables);
}

$ok = $errors === [];
if ($json) {
    echo json_encode([
        'ok' => $ok,
        'modules' => count($modules),
        'skipped' => $skipped,
        'missing_modules' => $missingModules,
        'stale_rows' => $stale,
        'missing_tables' => $missingTables,
        'errors' => $errors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($ok ? 0 : 1);
}
if (!$ok) {
    foreach ($errors as $e) {
        fwrite(STDERR, "FAIL {$e}\n");
    }
    fwrite(STDERR, 'docs-modules check failed (' . count($errors) . ")\n");
    exit(1);
}
if ($skipped !== []) {
    echo 'NOTE skipped (not in modules-catalog.json, instance pack): ' . implode(', ', $skipped) . "\n";
}
echo 'docs-modules check ok (' . count($modules) . " modules)\n";
exit(0);
