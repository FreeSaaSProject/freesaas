#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Data migrators under modules/<id>/migrators/.
 *
 *   php bin/data-migrate.php --module=core --id=remap_oper_user_permissions
 *   php bin/data-migrate.php --i-understand-replace   # ALL migrators (destructive replace)
 *   php bin/data-migrate.php --list                   # preview catalog (no run)
 *
 * Prefer --module + --id: runAll() truncates target base_* tables (mode=replace).
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$module = '';
$id = '';
$forceAll = false;
$listOnly = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--module=(.+)$/', $arg, $m)) {
        $module = trim($m[1]);
    } elseif (preg_match('/^--id=(.+)$/', $arg, $m)) {
        $id = trim($m[1]);
    } elseif ($arg === '--i-understand-replace') {
        $forceAll = true;
    } elseif ($arg === '--list') {
        $listOnly = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage:\n"
            . "  php bin/data-migrate.php --list\n"
            . "  php bin/data-migrate.php --module=id --id=migrator_id\n"
            . "  php bin/data-migrate.php --i-understand-replace\n");
        exit(0);
    }
}

if ($listOnly) {
    $catalog = DataMigrator::catalog();
    if ($catalog === []) {
        fwrite(STDOUT, "No data migrators in enabled modules.\n");
        exit(0);
    }
    fwrite(STDOUT, 'Data migrators (' . count($catalog) . "):\n");
    foreach ($catalog as $row) {
        $src = $row['source'] !== [] ? implode(',', $row['source']) : '—';
        $dst = $row['target'] !== [] ? implode(',', $row['target']) : '—';
        fwrite(STDOUT, sprintf(
            "  %s/%s [%s] %s → %s\n    %s\n",
            $row['module'],
            $row['id'],
            $row['mode'],
            $src,
            $dst,
            $row['description']
        ));
    }
    exit(0);
}

$printProgress = static function (string $phase, array $row): void {
    $mod = (string)($row['module'] ?? '?');
    $mid = (string)($row['id'] ?? '?');
    if ($phase === 'start') {
        $desc = (string)($row['description'] ?? '');
        fwrite(STDOUT, sprintf("→ %s/%s\n", $mod, $mid));
        if ($desc !== '') {
            fwrite(STDOUT, '  ' . $desc . "\n");
        }
        fwrite(STDOUT, "  … running\n");
        if (function_exists('flush')) {
            flush();
        }
        return;
    }
    $status = (string)($row['status'] ?? '?');
    $summary = DataMigrator::formatSummary($row);
    fwrite(STDOUT, sprintf(
        "[%s] %s/%s%s\n",
        $status,
        $mod,
        $mid,
        $summary !== '' ? ' — ' . $summary : ''
    ));
    if (function_exists('flush')) {
        flush();
    }
};

DataMigrator::setProgress($printProgress);

if ($module !== '' || $id !== '') {
    if ($module === '' || $id === '') {
        fwrite(STDERR, "Both --module= and --id= are required for a single migrator.\n");
        exit(1);
    }
    $report = [DataMigrator::runOne($module, $id)];
} elseif ($forceAll) {
    $catalog = DataMigrator::catalog();
    fwrite(STDERR, 'Warning: running ALL ' . count($catalog)
        . " enabled-module migrator(s) (replace may TRUNCATE base_* tables).\n");
    $report = DataMigrator::runAll(true);
} else {
    fwrite(STDERR, "Refusing to run ALL data migrators without --i-understand-replace.\n");
    fwrite(STDERR, "Use --list, --module=… --id=…, or pass --i-understand-replace.\n");
    exit(2);
}

DataMigrator::setProgress(null);

$failed = 0;
$ok = 0;
foreach ($report as $r) {
    if ((string)($r['status'] ?? '') !== 'ok') {
        $failed++;
    } else {
        $ok++;
    }
}
fwrite(STDOUT, sprintf("Done: ok=%d error=%d total=%d\n", $ok, $failed, count($report)));
exit($failed > 0 ? 1 : 0);
