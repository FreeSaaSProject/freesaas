#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Inventory mutating routes + optional live 403 check against a demo stand.
 *
 * Usage:
 *   php bin/check-demo-mutations.php
 *   php bin/check-demo-mutations.php --url=https://demoru.freesaas.ru --cookie-jar=/tmp/demoru.ck
 *
 * Exit: 0 ok, 1 failures when --url is used.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    } elseif (preg_match('/^--([^=]+)$/', $a, $m)) {
        $opts[$m[1]] = true;
    }
}

$unsafe = ['POST', 'PUT', 'PATCH', 'DELETE'];
$rows = [];
foreach (ModuleManager::routes() as $path => $route) {
    if (!is_array($route)) {
        continue;
    }
    $methods = $route['methods'] ?? ['GET', 'POST'];
    if (!is_array($methods)) {
        continue;
    }
    foreach ($methods as $m) {
        $m = strtoupper((string)$m);
        if (!in_array($m, $unsafe, true)) {
            continue;
        }
        $allowed = class_exists('DemoMode', false) && DemoMode::mutationAllowed((string)$path);
        $rows[] = [
            'path' => (string)$path,
            'method' => $m,
            'module' => (string)($route['module'] ?? ''),
            'permission' => (string)($route['permission'] ?? ''),
            'public' => !empty($route['public']),
            'allowlisted' => $allowed,
        ];
    }
}

usort($rows, static function (array $a, array $b): int {
    return [$a['path'], $a['method']] <=> [$b['path'], $b['method']];
});

$base = rtrim((string)($opts['url'] ?? ''), '/');
$jar = (string)($opts['cookie-jar'] ?? '');
$live = [];
$fail = 0;

if ($base !== '') {
    foreach ($rows as $r) {
        if ($r['allowlisted']) {
            $live[] = $r + ['http' => 'skip', 'note' => 'allowlisted'];
            continue;
        }
        // Replace {id} with 1 for probe
        $path = preg_replace('/\{[^}]+\}/', '1', $r['path']) ?? $r['path'];
        $url = $base . $path;
        $cmd = [
            'curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}',
            '-X', $r['method'],
            '--connect-timeout', '8', '--max-time', '20',
            '-k',
            '-H', 'Content-Type: application/x-www-form-urlencoded',
            '--data', 'x=1',
        ];
        if ($jar !== '') {
            $cmd[] = '-b';
            $cmd[] = $jar;
            $cmd[] = '-c';
            $cmd[] = $jar;
        }
        // Host resolve optional
        if (!empty($opts['resolve'])) {
            $cmd[] = '--resolve';
            $cmd[] = (string)$opts['resolve'];
        }
        $cmd[] = $url;
        $out = [];
        $code = 0;
        exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>/dev/null', $out, $code);
        $http = (int)trim(implode('', $out));
        $ok = $http === 403;
        if (!$ok) {
            $fail++;
        }
        $live[] = $r + [
            'http' => $http,
            'note' => $ok ? '403 ok' : 'expected 403',
        ];
    }
}

$md = "# Demo mutation audit\n\n";
$md .= 'Generated: ' . date('c') . "\n\n";
$md .= 'Mutating routes inventoried: **' . count($rows) . "**\n\n";
if ($base !== '') {
    $md .= 'Live base: `' . $base . "` — failures expecting 403: **{$fail}**\n\n";
    $md .= "| Method | Path | HTTP | Note |\n|---|---|---|---|\n";
    foreach ($live as $r) {
        $md .= '| ' . $r['method'] . ' | `' . $r['path'] . '` | ' . $r['http'] . ' | ' . $r['note'] . " |\n";
    }
} else {
    $md .= "| Method | Path | Module | Allowlisted |\n|---|---|---|---|\n";
    foreach ($rows as $r) {
        $md .= '| ' . $r['method'] . ' | `' . $r['path'] . '` | ' . $r['module']
            . ' | ' . ($r['allowlisted'] ? 'yes' : '') . " |\n";
    }
    $md .= "\nRe-run with `--url=https://demoru.freesaas.ru --cookie-jar=…` for live 403 checks.\n";
}

$outPath = base_path('docs/demo-audit.md');
file_put_contents($outPath, $md);
fwrite(STDOUT, "Wrote {$outPath} (" . count($rows) . " mutating routes)\n");
if ($base !== '' && $fail > 0) {
    fwrite(STDERR, "FAIL: {$fail} routes did not return 403\n");
    exit(1);
}
exit(0);
