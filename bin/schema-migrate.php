#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$report = SchemaMigrator::runAll();
ModuleManager::syncRegistry();
foreach ($report as $r) {
    echo sprintf("[%s] %s/%s%s\n", $r['status'], $r['module'], $r['id'], isset($r['message']) ? ' — '.$r['message'] : '');
}
