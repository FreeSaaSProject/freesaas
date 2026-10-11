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
        case 'setup-key':
            freesaas_cmd_setup_key($sub, $opts);
            break;
        case 'rescue':
            freesaas_cmd_rescue($opts);
            break;
        case 'module':
            freesaas_cmd_module($sub, $args, $opts);
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
        case 'doctor':
            $doc = AgentOps::doctor();
            if (!empty($opts['issue-body']) || !empty($opts['issue_body'])) {
                echo (string)($doc['issue_body'] ?? '');
                exit(!empty($doc['ok']) ? Cli::EXIT_OK : Cli::EXIT_FAIL);
            }
            freesaas_emit_ops($doc);
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
        case 'update':
            freesaas_cmd_update($sub, $opts);
            break;
        case 'core':
            freesaas_cmd_core($sub, $opts);
            break;
        case 'cron':
            freesaas_cmd_cron($sub, $opts);
            break;
        case 'user':
            freesaas_cmd_user($sub, $args, $opts);
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
  setup-key [generate|clear|path] [--format=json]
  rescue --login=… --password=… [--name=…] [--clear-setup-key] [--no-migrate] [--format=json]
  module list [--q=] [--category=] [--tag=] [--status=enabled|disabled|blocked|available] [--maturity=alpha|beta|release]
  module inspect|install|enable [--accept-risk]|disable|validate|bump|create|add-migration …
  module create <id> [--title=…]
  module add-migration <id> <slug>
  module bump <id> [--patch|--minor|--major|--set=x.y.z]
  migrate status|run [--module=id] [--format=json]
  db status|dumps|dump|restore|delete-dump|check|repair|optimize [--file=…] [--label=…] [--format=json]
  locale list|validate [--locale=xx] [--format=json]
  health [--module=id] [--format=json]
  doctor [--format=json] [--issue-body]
  i18n check [--locale=xx] [--format=json]
  update status|check|apply [--target=core|moduleId|all] [--no-migrate] [--format=json]
  core habits-reminders [--simulate] [--format=json]
  cron tick [--job=id] [--force] [--format=json]
  user unlock <login> [--format=json]
  user login-link <login> [--ttl=15] [--format=json]
  user hash-audit [--format=json]
TXT;
}

/**
 * @param list<string> $args
 * @param array<string, string|bool> $opts
 */
function freesaas_cmd_user(string $sub, array $args, array $opts): void
{
    require_once base_path('modules/core/lib/UsersRepository.php');
    require_once base_path('modules/core/lib/LoginLinksRepository.php');

    if ($sub === 'unlock') {
        $login = trim((string)($args[2] ?? ''));
        if ($login === '') {
            Cli::usage('Usage: user unlock <login> [--format=json]');
        }
        $row = UsersRepository::findByLogin($login);
        if ($row === null) {
            Cli::fail('user not found: ' . $login, Cli::EXIT_FAIL);
        }
        Auth::unlockUser((int)$row['id']);
        freesaas_emit_ops([
            'ok' => true,
            'login' => $login,
            'unlocked' => true,
        ]);
        return;
    }

    if ($sub === 'login-link') {
        $login = trim((string)($args[2] ?? ''));
        if ($login === '') {
            Cli::usage('Usage: user login-link <login> [--ttl=15] [--format=json]');
        }
        $row = UsersRepository::findByLogin($login);
        if ($row === null) {
            Cli::fail('user not found: ' . $login, Cli::EXIT_FAIL);
        }
        $ttl = isset($opts['ttl']) ? (int)$opts['ttl'] : LoginLinksRepository::DEFAULT_TTL_MINUTES;
        try {
            $link = LoginLinksRepository::create((int)$row['id'], 0, $ttl);
        } catch (Throwable $e) {
            Cli::fail($e->getMessage(), Cli::EXIT_FAIL);
        }
        freesaas_emit_ops([
            'ok' => true,
            'login' => $login,
            'url' => $link['url'],
            'expires_at' => $link['expires_at'],
            'ttl_minutes' => $link['ttl_minutes'],
        ]);
        return;
    }

    if ($sub === 'hash-audit') {
        if (!Database::tableExists('base_users')) {
            Cli::fail('base_users missing', Cli::EXIT_FAIL);
        }
        $st = Database::pdo()->query(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN password REGEXP \'^[0-9a-fA-F]{40}$\' THEN 1 ELSE 0 END) AS legacy,
                SUM(CASE WHEN password LIKE \'$2y$%\' OR password LIKE \'$2a$%\' THEN 1 ELSE 0 END) AS bcrypt,
                SUM(CASE WHEN password LIKE \'$argon2%\' THEN 1 ELSE 0 END) AS argon2
             FROM base_users'
        );
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : [];
        $total = (int)($row['total'] ?? 0);
        $legacy = (int)($row['legacy'] ?? 0);
        freesaas_emit_ops([
            'ok' => true,
            'total' => $total,
            'legacy' => $legacy,
            'bcrypt' => (int)($row['bcrypt'] ?? 0),
            'argon2' => (int)($row['argon2'] ?? 0),
            'modern' => max(0, $total - $legacy),
        ]);
        return;
    }

    Cli::usage('Usage: user unlock|login-link|hash-audit …');
}

/** @param array<string, string|bool> $opts */
function freesaas_cmd_cron(string $sub, array $opts): void
{
    if ($sub !== 'tick') {
        Cli::usage('Usage: cron tick [--job=id] [--force] [--format=json]');
    }
    $job = isset($opts['job']) ? trim((string)$opts['job']) : '';
    $force = !empty($opts['force']);
    $r = Cron::tick($force, $job !== '' ? $job : null);
    freesaas_emit_ops($r);
}

/** @param array<string, string|bool> $opts */
function freesaas_cmd_core(string $sub, array $opts): void
{
    if ($sub === 'habits-reminders') {
        $simulate = !empty($opts['simulate']);
        $r = MyTasksHabits::cronSendDueReminders($simulate);
        freesaas_emit_ops([
            'ok' => true,
            'job' => 'habits-reminders',
            'ymd' => $r['ymd'],
            'checked' => $r['checked'],
            'sent' => $r['sent'],
            'simulate' => $simulate,
            'log' => $r['log'],
        ]);
        return;
    }
    Cli::usage('Usage: core habits-reminders [--simulate] [--format=json]');
}

/** @param array<string, string|bool> $opts */
function freesaas_cmd_update(string $sub, array $opts): void
{
    $target = isset($opts['target']) ? trim((string)$opts['target']) : null;
    if ($target === '') {
        $target = null;
    }
    if ($sub === 'status' || $sub === '') {
        freesaas_emit_ops(AgentOps::updateStatus());
        return;
    }
    if ($sub === 'check') {
        freesaas_emit_ops(AgentOps::updateCheck($target));
        return;
    }
    if ($sub === 'apply') {
        $migrate = empty($opts['no-migrate']);
        freesaas_emit_ops(AgentOps::updateApply($target, $migrate));
        return;
    }
    Cli::usage('Usage: update status|check|apply [--target=core|moduleId|all] [--no-migrate] [--format=json]');
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

/** @param array<string, string|bool> $opts */
function freesaas_cmd_setup_key(string $sub, array $opts): void
{
    $op = $sub !== '' ? $sub : 'generate';
    if ($op === 'path') {
        freesaas_emit_ops(['ok' => true, 'path' => SetupKey::path(), 'exists' => SetupKey::exists()]);
        return;
    }
    if ($op === 'clear') {
        SetupKey::clear();
        freesaas_emit_ops(['ok' => true, 'cleared' => true, 'path' => SetupKey::path()]);
        return;
    }
    if ($op === 'generate' || $op === '') {
        $key = SetupKey::generate();
        freesaas_emit_ops([
            'ok' => true,
            'path' => SetupKey::path(),
            'setup_key' => $key,
            'ttl_seconds' => SetupKey::TTL_SECONDS,
            'hint' => 'HTTP: header X-FreeSaaS-Setup-Key or JSON body setup_key (not query). Auto-cleared on rescue/bootstrap; TTL 24h; or setup-key clear',
        ]);
        return;
    }
    Cli::usage('Usage: setup-key [generate|clear|path] [--format=json]');
}

/** @param array<string, string|bool> $opts */
function freesaas_cmd_rescue(array $opts): void
{
    $payload = [
        'login' => isset($opts['login']) ? (string)$opts['login'] : '',
        'password' => isset($opts['password']) ? (string)$opts['password'] : '',
        'name' => isset($opts['name']) ? (string)$opts['name'] : '',
        'mail' => isset($opts['mail']) ? (string)$opts['mail'] : '',
        'migrate' => !isset($opts['no-migrate']),
        'clear_setup_key' => !empty($opts['clear-setup-key']) || !empty($opts['clear_setup_key']),
    ];
    if ($payload['login'] === '' || $payload['password'] === '') {
        Cli::usage('Usage: rescue --login=admin --password=secret [--name=…] [--clear-setup-key] [--no-migrate]');
    }
    freesaas_emit_ops(AgentOps::rescue($payload));
}

/** @param list<string> $args */
/**
 * @param array<string, string|bool> $opts
 */
function freesaas_cmd_module(string $sub, array $args, array $opts = []): void
{
    if ($sub === 'list') {
        freesaas_emit_ops(AgentOps::moduleList([
            'q' => isset($opts['q']) ? (string)$opts['q'] : '',
            'category' => isset($opts['category']) ? (string)$opts['category'] : '',
            'tag' => isset($opts['tag']) ? (string)$opts['tag'] : '',
            'status' => isset($opts['status']) ? (string)$opts['status'] : '',
            'maturity' => isset($opts['maturity']) ? (string)$opts['maturity'] : '',
        ]));
    }
    if ($sub === 'inspect') {
        $id = $args[2] ?? '';
        if ($id === '') {
            Cli::usage('Usage: module inspect <id>');
        }
        freesaas_emit_ops(AgentOps::moduleInspect($id));
    }
    if ($sub === 'install') {
        $id = $args[2] ?? '';
        if ($id === '') {
            Cli::usage('Usage: module install <id>');
        }
        freesaas_emit_ops(AgentOps::moduleInstall($id));
    }
    if ($sub === 'enable' || $sub === 'disable') {
        $id = $args[2] ?? '';
        if ($id === '') {
            Cli::usage("Usage: module {$sub} <id> [--accept-risk]");
        }
        $acceptRisk = !empty($opts['accept-risk']) || !empty($opts['accept_risk']);
        freesaas_emit_ops(AgentOps::moduleSetEnabled($id, $sub === 'enable', $acceptRisk));
    }
    if ($sub === 'validate') {
        $id = $args[2] ?? '';
        freesaas_emit_ops(AgentOps::moduleValidate($id !== '' ? $id : null));
    }
    if ($sub === 'bump') {
        $id = $args[2] ?? '';
        if ($id === '') {
            Cli::usage('Usage: module bump <id> [--patch|--minor|--major|--set=x.y.z]');
        }
        $mode = 'patch';
        $set = null;
        if (!empty($opts['major'])) {
            $mode = 'major';
        } elseif (!empty($opts['minor'])) {
            $mode = 'minor';
        } elseif (isset($opts['set']) && (string)$opts['set'] !== '') {
            $mode = 'set';
            $set = (string)$opts['set'];
        } elseif (!empty($opts['patch'])) {
            $mode = 'patch';
        }
        freesaas_emit_ops(AgentOps::moduleBump($id, $mode, $set));
    }
    if ($sub === 'create') {
        $id = $args[2] ?? '';
        if ($id === '') {
            Cli::usage('Usage: module create <id> [--title=…]');
        }
        $title = isset($opts['title']) ? (string)$opts['title'] : '';
        freesaas_emit_ops(AgentOps::moduleCreate($id, $title));
    }
    if ($sub === 'add-migration') {
        $id = $args[2] ?? '';
        $slug = $args[3] ?? '';
        if ($id === '' || $slug === '') {
            Cli::usage('Usage: module add-migration <id> <slug>');
        }
        freesaas_emit_ops(AgentOps::moduleAddMigration($id, $slug));
    }
    Cli::usage('Usage: module list|inspect|install|enable|disable|validate|bump|create|add-migration …');
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
