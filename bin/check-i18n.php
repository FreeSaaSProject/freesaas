#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * i18n catalog check (wrapper). Prefer: php bin/freesaas.php i18n check
 * Exit: 0 ok, 1 fail, 2 usage
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

[$args, $opts] = Cli::parseArgv($argv);
$loc = isset($opts['locale']) ? (string)$opts['locale'] : null;
$report = I18n::validateCatalogs($loc);

if (Cli::isJson()) {
    Cli::emit($report, !empty($report['ok']) ? Cli::EXIT_OK : Cli::EXIT_FAIL);
}

$lines = [];
$lines[] = 'locales: ' . implode(', ', $report['locales'] ?? []);
foreach ($report['totals'] ?? [] as $l => $n) {
    $lines[] = "total {$l}={$n}";
}
foreach ($report['only_in'] ?? [] as $label => $keys) {
    $lines[] = "{$label}: " . count($keys);
    foreach (array_slice($keys, 0, 40) as $k) {
        $lines[] = '  ' . $k;
    }
}
foreach ($report['missing_used'] ?? [] as $l => $map) {
    $lines[] = "missing used in {$l}: " . count($map);
    $i = 0;
    foreach ($map as $k => $f) {
        $lines[] = "  {$k} ({$f})";
        if (++$i >= 40) {
            break;
        }
    }
}
$mj = $report['module_json_missing'] ?? [];
$lines[] = 'module.json missing labels: ' . count($mj);
foreach (array_slice($mj, 0, 40) as $row) {
    $lines[] = '  ' . $row['module'] . ' / ' . $row['locale'] . ' / ' . $row['key'];
}
$lines[] = 'ok: ' . (!empty($report['ok']) ? 'true' : 'false');
Cli::emit(implode("\n", $lines) . "\n", !empty($report['ok']) ? Cli::EXIT_OK : Cli::EXIT_FAIL);
