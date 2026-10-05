#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Module structural validate wrapper. Prefer: php bin/freesaas.php module validate
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

[$args, $opts] = Cli::parseArgv($argv);
$id = $args[0] ?? '';
if ($id !== '') {
    $one = ModuleValidator::validate($id);
    Cli::emit($one, $one['ok'] ? Cli::EXIT_OK : Cli::EXIT_FAIL);
}
$all = ModuleValidator::validateAll();
$ok = true;
foreach ($all as $row) {
    if (empty($row['ok'])) {
        $ok = false;
        break;
    }
}
Cli::emit(['ok' => $ok, 'results' => $all], $ok ? Cli::EXIT_OK : Cli::EXIT_FAIL);
