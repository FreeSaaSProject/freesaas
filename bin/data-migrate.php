#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Data migrators under modules/<id>/migrators/.
 *
 *   php bin/data-migrate.php --module=audio --id=export_record_scores
 *   php bin/data-migrate.php --i-understand-replace   # ALL migrators (destructive replace)
 *
 * Prefer --module + --id: runAll() truncates target base_* tables (mode=replace).
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$module = '';
$id = '';
$forceAll = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--module=(.+)$/', $arg, $m)) {
        $module = trim($m[1]);
    } elseif (preg_match('/^--id=(.+)$/', $arg, $m)) {
        $id = trim($m[1]);
    } elseif ($arg === '--i-understand-replace') {
        $forceAll = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage:\n  php bin/data-migrate.php --module=id --id=migrator_id\n  php bin/data-migrate.php --i-understand-replace\n");
        exit(0);
    }
}

if ($module !== '' || $id !== '') {
    if ($module === '' || $id === '') {
        fwrite(STDERR, "Both --module= and --id= are required for a single migrator.\n");
        exit(1);
    }
    $report = [DataMigrator::runOne($module, $id)];
} elseif ($forceAll) {
    fwrite(STDERR, "Warning: running ALL enabled-module migrators (replace may TRUNCATE base_* tables).\n");
    $report = DataMigrator::runAll(true);
} else {
    fwrite(STDERR, "Refusing to run ALL data migrators without --i-understand-replace.\n");
    fwrite(STDERR, "Use --module=… --id=… for one migrator, or pass --i-understand-replace.\n");
    exit(2);
}

$failed = 0;
foreach ($report as $r) {
    $extra = '';
    if (!empty($r['stats']) && is_array($r['stats'])) {
        $bits = [];
        foreach ($r['stats'] as $k => $v) {
            if (is_scalar($v)) {
                $bits[] = $k . '=' . $v;
            }
        }
        if ($bits !== []) {
            $extra = ' ' . implode(' ', $bits);
        }
    }
    if (!empty($r['message'])) {
        $extra .= ' — ' . $r['message'];
    }
    $status = (string)($r['status'] ?? '?');
    if ($status !== 'ok') {
        $failed++;
    }
    echo sprintf("[%s] %s/%s%s\n", $status, $r['module'] ?? '?', $r['id'] ?? '?', $extra);
}
exit($failed > 0 ? 1 : 0);
