#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Minimal agent/CI smoke: system status + module validate + locale validate + health.
 * Does not fail the whole run on module validate warnings-as-errors from legacy hooks prose —
 * validate errors fail; health fail fails; locale fail fails.
 *
 * Exit: 0 ok, 1 fail
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$failed = false;
$sections = [];

// system
$dbOk = false;
try {
    Database::pdo()->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    $sections['system'] = ['ok' => false, 'error' => $e->getMessage()];
    $failed = true;
}
if ($dbOk) {
    $sections['system'] = [
        'ok' => true,
        'php' => PHP_VERSION,
        'modules' => count(ModuleManager::all()),
        'locale' => I18n::locale(),
    ];
}

// locale
$locale = I18n::validateCatalogs(null);
$sections['locale'] = [
    'ok' => !empty($locale['ok']),
    'totals' => $locale['totals'] ?? [],
    'only_in_counts' => array_map('count', $locale['only_in'] ?? []),
    'missing_used_counts' => array_map('count', $locale['missing_used'] ?? []),
    'module_json_missing' => count($locale['module_json_missing'] ?? []),
];
if (empty($locale['ok'])) {
    $failed = true;
}

// module validate (errors only; collect count)
$val = ModuleValidator::validateAll();
$errModules = [];
$warnModules = [];
foreach ($val as $row) {
    if (!empty($row['errors'])) {
        $errModules[] = $row['module'];
    }
    if (!empty($row['warnings'])) {
        $warnModules[] = $row['module'];
    }
}
$sections['module_validate'] = [
    'ok' => $errModules === [],
    'modules_with_errors' => $errModules,
    'modules_with_warnings' => $warnModules,
];
if ($errModules !== []) {
    $failed = true;
}

// health
$health = Health::run(null);
$sections['health'] = [
    'ok' => ($health['status'] ?? '') !== 'fail',
    'status' => $health['status'] ?? 'unknown',
    'summary' => $health['summary'] ?? [],
];
if (($health['status'] ?? '') === 'fail') {
    $failed = true;
}

// safe_html XSS regressions (bin/check-safe-html.php)
$safeHtmlLines = [];
$safeHtmlCode = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/check-safe-html.php') . ' 2>&1',
    $safeHtmlLines,
    $safeHtmlCode
);
$sections['safe_html'] = [
    'ok' => $safeHtmlCode === 0,
    'exit' => $safeHtmlCode,
];
if ($safeHtmlCode !== 0) {
    $failed = true;
    $sections['safe_html']['output'] = implode("\n", $safeHtmlLines);
}

// CSRF helpers (bin/check-csrf.php)
$csrfLines = [];
$csrfCode = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/check-csrf.php') . ' 2>&1',
    $csrfLines,
    $csrfCode
);
$sections['csrf'] = [
    'ok' => $csrfCode === 0,
    'exit' => $csrfCode,
];
if ($csrfCode !== 0) {
    $failed = true;
    $sections['csrf']['output'] = implode("\n", $csrfLines);
}

// Password hash / policy regressions (bin/check-password.php)
$pwLines = [];
$pwCode = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/check-password.php') . ' 2>&1',
    $pwLines,
    $pwCode
);
$sections['password'] = [
    'ok' => $pwCode === 0,
    'exit' => $pwCode,
];
if ($pwCode !== 0) {
    $failed = true;
    $sections['password']['output'] = implode("\n", $pwLines);
}

// Session guard / auth_epoch (bin/check-session.php)
$sessionLines = [];
$sessionCode = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/check-session.php') . ' 2>&1',
    $sessionLines,
    $sessionCode
);
$sections['session'] = [
    'ok' => $sessionCode === 0,
    'exit' => $sessionCode,
];
if ($sessionCode !== 0) {
    $failed = true;
    $sections['session']['output'] = implode("\n", $sessionLines);
}

$out = ['ok' => !$failed, 'sections' => $sections];
if (in_array('--format=json', $argv, true) || in_array('--json', $argv, true)) {
    Cli::setJson(true);
}
Cli::emit($out, $failed ? Cli::EXIT_FAIL : Cli::EXIT_OK);
