#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * FreeSaaS agent/ops CLI (canonical protocol).
 * HTTP twin for shared hosting: /agent/* + X-FreeSaaS-Agent-Token (same AgentOps).
 *
 * Exit: 0 ok, 1 fail, 2 usage
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

[$args, $opts] = Cli::parseArgv($argv);
$cmd = $args[0] ?? '';
$sub = $args[1] ?? '';

if ($cmd === '' || $cmd === 'help' || $cmd === '-h' || $cmd === '--help') {
    Cli::usage(freesaas_cli_help());
}

try {
    switch ($cmd) {
        case 'system':
            if ($sub !== 'status') {
                Cli::usage('Usage: system status [--format=json]');
            }
            $r = AgentOps::systemStatus();
            freesaas_emit_ops($r);
            break;
        case 'bootstrap':
            freesaas_cmd_bootstrap($opts);
            break;
        case 'module':
            freesaas_cmd_module($sub, $args);
            break;
        case 'migrate':
            freesaas_cmd_migrate($sub, $opts);
            break;
        case 'db':
            freesaas_cmd_db($sub, $opts);
            break;
        case 'locale':
            freesaas_cmd_locale($sub, $opts);
            break;
        case 'health':
            $module = isset($opts['module']) ? trim((string)$opts['module']) : null;
            freesaas_emit_ops(AgentOps::health($module));
            break;
        case 'i18n':
            if ($sub !== 'check') {
                Cli::usage('Usage: i18n check [--locale=xx] [--format=json]');
            }
            $loc = isset($opts['locale']) ? (string)$opts['locale'] : null;
            $report = AgentOps::localeValidate($loc);
            if (!Cli::isJson()) {
                freesaas_i18n_text($report);
            }
            freesaas_emit_ops($report);
            break;
        default:
            Cli::usage("Unknown command: {$cmd}\n\n" . freesaas_cli_help());
    }
} catch (Throwable $e) {
    Cli::fail($e->getMessage(), Cli::EXIT_FAIL);
}

function freesaas_cli_help(): string
{
    return <<<TXT
FreeSaaS CLI (HTTP: GET/POST /agent/* + header X-FreeSaaS-Agent-Token)

  system status [--format=json]
  bootstrap --login=… --password=… [--name=…] [--agent-token=…] [--modules=a,b] [--format=json]
  module list|inspect|enable|disable|validate …
  migrate status|run [--module=id] [--format=json]
  db status|dumps|dump|restore|delete-dump|check|repair|optimize [--file=…] [--label=…] [--format=json]
  locale list|validate [--locale=xx] [--format=json]
  health [--module=id] [--format=json]
  i18n check [--locale=xx] [--format=json]
TXT;
}

/** @param array<string,mixed> $r */
function freesaas_emit_ops(array $r): void
{
    $ok = !empty($r['ok']);
    // health uses status field
    if (isset($r['status']) && !isset($r['ok'])) {
        $ok = ($r['status'] !== 'fail');
    }
    unset($r['http']);
    Cli::emit($r, $ok ? Cli::EXIT_OK : Cli::EXIT_FAIL);
}

/** @param array<string, string|bool> $opts */
function freesaas_cmd_bootstrap(array $opts): void
{
    $payload = [
        'login' => isset($opts['login']) ? (string)$opts['login'] : '',
        'password' => isset($opts['password']) ? (string)$opts['password'] : '',
        'name' => isset($opts['name']) ? (string)$opts['name'] : '',
        'mail' => isset($opts['mail']) ? (string)$opts['mail'] : '',
        'locale' => isset($opts['locale']) ? (string)$opts['locale'] : '',
        'agent_token' => isset($opts['agent-token']) ? (string)$opts['agent-token'] : (isset($opts['agent_token']) ? (string)$opts['agent_token'] : ''),
        'migrate' => !isset($opts['no-migrate']),
        'grant_all_permissions' => !isset($opts['no-grant-perms']),
    ];
    if (isset($opts['modules']) && is_string($opts['modules']) && $opts['modules'] !== '') {
        $payload['modules'] = array_values(array_filter(array_map('trim', explode(',', $opts['modules']))));
    }
    if ($payload['login'] === '' || $payload['password'] === '') {
        // Allow migrate-only refresh when users already exist
        if (AgentOps::isVirginInstall()) {
            Cli::usage('Usage: bootstrap --login=admin --password=secret [--name=…] [--agent-token=…] [--modules=a,b]');
        }
    }
    freesaas_emit_ops(AgentOps::bootstrap($payload));
}

/** @param list<string> $args */
function freesaas_cmd_module(string $sub, array $args): void
{
    if ($sub === 'list') {
        freesaas_emit_ops(AgentOps::moduleList());
    }
    if ($sub === 'inspect') {
        $id = $args[2] ?? '';
        if ($id === '') {
            Cli::usage('Usage: module inspect <id>');
        }
        freesaas_emit_ops(AgentOps::moduleInspect($id));
    }
    if ($sub === 'enable' || $sub === 'disable') {
        $id = $args[2] ?? '';
        if ($id === '') {
            Cli::usage("Usage: module {$sub} <id>");
        }
        freesaas_emit_ops(AgentOps::moduleSetEnabled($id, $sub === 'enable'));
    }
    if ($sub === 'validate') {
        $id = $args[2] ?? '';
        freesaas_emit_ops(AgentOps::moduleValidate($id !== '' ? $id : null));
    }
    Cli::usage('Usage: module list|inspect|enable|disable|validate …');
}

/** @param array<string, string|bool> $opts */
function freesaas_cmd_migrate(string $sub, array $opts): void
{
    $module = isset($opts['module']) ? trim((string)$opts['module']) : null;
    if ($sub === 'status') {
        freesaas_emit_ops(AgentOps::migrateStatus($module));
    }
    if ($sub === 'run') {
        freesaas_emit_ops(AgentOps::migrateRun($module));
    }
    Cli::usage('Usage: migrate status|run [--module=id] [--format=json]');
}

/** @param array<string, string|bool> $opts */
function freesaas_cmd_db(string $sub, array $opts): void
{
    if ($sub === 'status') {
        freesaas_emit_ops(AgentOps::dbStatus());
    }
    if ($sub === 'dumps' || $sub === 'list') {
        freesaas_emit_ops(AgentOps::dbListDumps());
    }
    if ($sub === 'dump') {
        $label = isset($opts['label']) ? (string)$opts['label'] : null;
        freesaas_emit_ops(AgentOps::dbDump($label));
    }
    if ($sub === 'restore') {
        $file = isset($opts['file']) ? (string)$opts['file'] : '';
        if ($file === '') {
            Cli::usage('Usage: db restore --file=NAME.sql[.gz] [--format=json]');
        }
        freesaas_emit_ops(AgentOps::dbRestore($file));
    }
    if ($sub === 'delete-dump' || $sub === 'dump-delete') {
        $file = isset($opts['file']) ? (string)$opts['file'] : '';
        if ($file === '') {
            Cli::usage('Usage: db delete-dump --file=NAME.sql[.gz] [--format=json]');
        }
        freesaas_emit_ops(AgentOps::dbDeleteDump($file));
    }
    if ($sub === 'check') {
        freesaas_emit_ops(AgentOps::dbCheck());
    }
    if ($sub === 'repair') {
        freesaas_emit_ops(AgentOps::dbRepair());
    }
    if ($sub === 'optimize') {
        freesaas_emit_ops(AgentOps::dbOptimize());
    }
    Cli::usage('Usage: db status|dumps|dump|restore|delete-dump|check|repair|optimize [--file=…] [--label=…] [--format=json]');
}

/** @param array<string, string|bool> $opts */
function freesaas_cmd_locale(string $sub, array $opts): void
{
    if ($sub === 'list') {
        freesaas_emit_ops(AgentOps::localeList());
    }
    if ($sub === 'validate') {
        $loc = isset($opts['locale']) ? (string)$opts['locale'] : null;
        freesaas_emit_ops(AgentOps::localeValidate($loc));
    }
    Cli::usage('Usage: locale list|validate [--locale=xx] [--format=json]');
}

/** @param array<string,mixed> $report */
function freesaas_i18n_text(array $report): void
{
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
}
