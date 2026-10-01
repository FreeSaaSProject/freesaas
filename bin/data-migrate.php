#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$report = DataMigrator::runAll(true);
foreach ($report as $r) {
    $extra = '';
    if (!empty($r['stats']['copied'])) $extra = ' copied=' . $r['stats']['copied'];
    if (!empty($r['message'])) $extra .= ' — ' . $r['message'];
    echo sprintf("[%s] %s/%s%s\n", $r['status'], $r['module'], $r['id'], $extra);
}
