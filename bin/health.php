#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Health checks wrapper. Prefer: php bin/freesaas.php health
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

[$args, $opts] = Cli::parseArgv($argv);
$module = isset($opts['module']) ? trim((string)$opts['module']) : null;
if ($module === '') {
    $module = null;
}
$report = Health::run($module);
$code = ($report['status'] ?? '') === 'fail' ? Cli::EXIT_FAIL : Cli::EXIT_OK;
Cli::emit($report, $code);
