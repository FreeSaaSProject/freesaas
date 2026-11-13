#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Agent coverage lock (CI / pre-PR): UI routes and POST actions must stay reachable via agent.ops.
 *
 * Static scan of every modules/<id>/ on disk (no DB):
 * - route coverage: each module.json routes.pages path has at least one agent.ops entry with that path;
 * - action coverage: each POST action literal in pages/*.php (`$action === 'x'`, `$_POST['action'] … === 'x'`)
 *   appears in an op of the same module (parameters.properties.action.enum or "action=x" in description);
 * - op retention: op ids listed in the baseline must still exist.
 *
 * Gaps already present are frozen in docs/agents/agent-coverage-baseline.json. A new gap or a removed op fails.
 *
 * Usage: php bin/check-agent-coverage.php [--update-baseline] [--format=json]
 * Exit: 0 ok, 1 fail
 */
$root = dirname(__DIR__);
$baselinePath = $root . '/docs/agents/agent-coverage-baseline.json';
$update = in_array('--update-baseline', $argv, true);
$json = in_array('--format=json', $argv, true);

/** @return array{ops:list<string>,uncovered_routes:list<string>,uncovered_actions:list<string>} */
function coverage_scan_module(string $dir): array
{
    $manifest = json_decode((string)@file_get_contents($dir . '/module.json'), true);
    if (!is_array($manifest)) {
        return ['ops' => [], 'uncovered_routes' => [], 'uncovered_actions' => []];
    }
    $ops = is_array($manifest['agent']['ops'] ?? null) ? $manifest['agent']['ops'] : [];
    $opIds = [];
    $opPaths = [];
    $opActions = [];
    foreach ($ops as $op) {
        if (!is_array($op)) {
            continue;
        }
        $opIds[] = (string)($op['id'] ?? '');
        $opPaths[(string)($op['path'] ?? '')] = true;
        $enum = $op['parameters']['properties']['action']['enum'] ?? [];
        foreach (is_array($enum) ? $enum : [] as $a) {
            $opActions[(string)$a] = true;
        }
        if (preg_match_all('/action=([a-z0-9_|]+)/i', (string)($op['description'] ?? ''), $m)) {
            foreach ($m[1] as $chunk) {
                foreach (explode('|', $chunk) as $a) {
                    $opActions[$a] = true;
                }
            }
        }
    }
    $pages = is_array($manifest['routes']['pages'] ?? null) ? $manifest['routes']['pages'] : [];
    $uncoveredRoutes = [];
    foreach ($pages as $p) {
        $p = (string)$p;
        if ($p !== '' && !isset($opPaths[$p])) {
            $uncoveredRoutes[] = $p;
        }
    }
    $actions = [];
    foreach (glob($dir . '/pages/*.php') ?: [] as $file) {
        $src = (string)file_get_contents($file);
        $patterns = [
            '/\$action\s*===\s*\'([a-z0-9_]+)\'/i',
            '/\$_POST\[\'action\'\]\s*\?\?\s*\'\'\)\s*===\s*\'([a-z0-9_]+)\'/i',
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, $src, $m)) {
                foreach ($m[1] as $a) {
                    $actions[$a] = true;
                }
            }
        }
    }
    $uncoveredActions = [];
    foreach (array_keys($actions) as $a) {
        if (!isset($opActions[$a])) {
            $uncoveredActions[] = (string)$a;
        }
    }
    sort($opIds);
    sort($uncoveredRoutes);
    sort($uncoveredActions);
    return [
        'ops' => array_values(array_filter($opIds, 'strlen')),
        'uncovered_routes' => $uncoveredRoutes,
        'uncovered_actions' => $uncoveredActions,
    ];
}

$current = [];
foreach (glob($root . '/modules/*/module.json') ?: [] as $mf) {
    $current[basename(dirname($mf))] = coverage_scan_module(dirname($mf));
}
ksort($current);

if ($update) {
    file_put_contents(
        $baselinePath,
        json_encode(['modules' => $current], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo "baseline updated: " . $baselinePath . "\n";
    exit(0);
}

$baseline = json_decode((string)@file_get_contents($baselinePath), true);
$baseModules = is_array($baseline['modules'] ?? null) ? $baseline['modules'] : [];

$errors = [];
$improved = [];
foreach ($current as $id => $cur) {
    $base = $baseModules[$id] ?? ['ops' => [], 'uncovered_routes' => [], 'uncovered_actions' => []];
    foreach (array_diff($base['ops'] ?? [], $cur['ops']) as $lost) {
        $errors[] = "$id: agent op removed: $lost";
    }
    foreach (array_diff($cur['uncovered_routes'], $base['uncovered_routes'] ?? []) as $r) {
        $errors[] = "$id: route without agent op: $r";
    }
    foreach (array_diff($cur['uncovered_actions'], $base['uncovered_actions'] ?? []) as $a) {
        $errors[] = "$id: POST action without agent op: $a";
    }
    $fixed = array_merge(
        array_diff($base['uncovered_routes'] ?? [], $cur['uncovered_routes']),
        array_diff($base['uncovered_actions'] ?? [], $cur['uncovered_actions'])
    );
    if ($fixed !== [] || array_diff($cur['ops'], $base['ops'] ?? []) !== []) {
        $improved[] = $id;
    }
}

$ok = $errors === [];
if ($json) {
    echo json_encode(['ok' => $ok, 'errors' => $errors, 'improved' => $improved], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    foreach ($errors as $e) {
        echo "FAIL  $e\n";
    }
    if ($improved !== []) {
        echo "NOTE  coverage grew in: " . implode(', ', $improved) . " — run with --update-baseline and commit the baseline\n";
    }
    $routes = array_sum(array_map(static fn(array $m): int => count($m['uncovered_routes']), $current));
    $acts = array_sum(array_map(static fn(array $m): int => count($m['uncovered_actions']), $current));
    $opsN = array_sum(array_map(static fn(array $m): int => count($m['ops']), $current));
    echo ($ok ? 'OK' : 'FAIL') . "  agent coverage: ops=$opsN, frozen gaps routes=$routes actions=$acts\n";
}
exit($ok ? 0 : 1);
