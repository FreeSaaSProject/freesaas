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
        case 'demo':
            freesaas_cmd_demo($sub, $args, $opts);
            break;
        case 'bot':
            freesaas_cmd_bot($sub, $args, $opts);
            break;
        case 'assistant':
            freesaas_cmd_assistant($sub, $opts);
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
  module inspect|install|uninstall --confirm|enable [--accept-risk]|disable|validate|bump|create|add-migration …
  module create <id> [--title=…]
  module add-migration <id> <slug>
  module bump <id> [--patch|--minor|--major|--set=x.y.z]
  migrate status|run [--module=id] [--format=json]
  db status|dumps|dump|restore|delete-dump|check|repair|optimize [--file=…] [--label=…] [--format=json]
  locale list|validate [--locale=xx] [--format=json]
  health [--module=id] [--format=json]
  doctor [--format=json] [--issue-body]
  i18n check [--locale=xx] [--format=json]
  update status|check|apply [--target=core|moduleId|all] [--no-migrate] [--db-backup] [--format=json]
  core habits-reminders [--simulate] [--format=json]
  cron tick [--job=id] [--force] [--format=json]
  user unlock <login> [--format=json]
  user login-link <login> [--ttl=15] [--format=json]
  user logout-all <login> [--format=json]
  user hash-audit [--format=json]
  demo provision [--login=demo] [--password=demodemo] [--name=…] [--format=json]
  demo seed [--locale=ru|en] [--reset] [--format=json]
  demo claim [--existing] [--format=json]
  demo status [--format=json]
  bot unlink <login|client:<id>> [--platform=telegram|max] [--format=json]
  bot link-code <login|client:<id>> [--ttl=600] [--format=json]
  bot subscribe --audience=staff|client --platform=telegram|max [--url=https://…] [--format=json]
  assistant eval [--live=0|1] [--format=json]
TXT;
}

/**
 * Demo stand ops (module `demo` + app.demo_readonly=1).
 *
 * @param array<int,string> $args
 * @param array<string, string|bool> $opts
 */
function freesaas_cmd_demo(string $sub, array $args, array $opts): void
{
    if (!in_array($sub, ['seed', 'provision', 'claim', 'status'], true)) {
        Cli::usage(
            "Usage:\n  demo provision [--login=demo] [--password=demodemo] [--name=Demo] [--format=json]\n"
            . "  demo seed [--locale=ru|en] [--reset] [--format=json]\n"
            . "  demo claim [--existing] [--format=json]\n"
            . '  demo status [--format=json]'
        );
    }
    if (!class_exists('DemoMode', false) || !DemoMode::readonly()) {
        Cli::fail(
            'demo ' . $sub . ' refused: set app.demo_readonly=1 in cfg/config.ini first (see docs/agents/demo.md)',
            Cli::EXIT_FAIL
        );
    }
    if (!ModuleManager::isEnabled('demo')) {
        Cli::fail(
            'demo ' . $sub . ' refused: enable module demo=1 in cfg/config.ini (modules/demo)',
            Cli::EXIT_FAIL
        );
    }
    try {
        if ($sub === 'claim') {
            freesaas_emit_ops(DemoStand::claim(
                I18n::normalize((string)Config::get('app', 'locale', 'ru')) ?? 'ru',
                !empty($opts['existing'])
            ));
            return;
        }
        if ($sub === 'status') {
            $schedule = DemoSchedule::load();
            freesaas_emit_ops([
                'ok' => true,
                'stand_id' => DemoStand::configuredId(),
                'marker' => DemoStand::get(),
                'module_version' => DemoStand::moduleVersion(),
                'domain_counts' => DemoStand::domainCounts(),
                'preflight' => DemoSeed::preflight(),
                'schedule' => $schedule,
                'cron_token_configured' => AgentAuth::isCronTokenConfigured(),
            ]);
            return;
        }
        if ($sub === 'seed') {
            freesaas_emit_ops(DemoSeed::run([
                'locale' => isset($opts['locale']) ? (string)$opts['locale'] : I18n::locale(),
                'reset' => !empty($opts['reset']),
            ]));
            return;
        }
        freesaas_emit_ops(DemoProvision::run([
            'login' => (string)($opts['login'] ?? 'demo'),
            'password' => (string)($opts['password'] ?? 'demodemo'),
            'name' => isset($opts['name']) ? (string)$opts['name'] : '',
        ]));
    } catch (Throwable $e) {
        Cli::fail($e->getMessage(), Cli::EXIT_FAIL);
    }
}

/**
 * @param array<string, string|bool> $opts
 */
function freesaas_cmd_assistant(string $sub, array $opts): void
{
    if ($sub !== 'eval') {
        Cli::usage('Usage: assistant eval [--live=0|1] [--format=json]');
    }
    if (!ModuleManager::isEnabled('assistant')) {
        Cli::fail('assistant module disabled', Cli::EXIT_FAIL);
    }
    require_once base_path('modules/assistant/lib/AssistantLinks.php');
    require_once base_path('modules/assistant/lib/AssistantTools.php');
    require_once base_path('modules/assistant/lib/AssistantHarness.php');
    require_once base_path('modules/assistant/lib/AssistantEval.php');
    require_once base_path('modules/assistant/lib/AssistantEvalCases.php');

    $live = true;
    if (isset($opts['live'])) {
        $v = $opts['live'];
        if ($v === false || $v === '0' || $v === 'false' || $v === 'no') {
            $live = false;
        }
    }
    $result = AssistantEval::run(['live' => $live]);
    if (!Cli::isJson()) {
        $sum = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        echo 'pass=' . (int)($sum['pass'] ?? 0)
            . ' fail=' . (int)($sum['fail'] ?? 0)
            . ' skip=' . (int)($sum['skip'] ?? 0)
            . ' ms=' . (int)($sum['ms'] ?? 0)
            . "\n";
        foreach (($result['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo sprintf(
                "[%s] %s %s — %s (%dms)\n",
                (string)($row['status'] ?? ''),
                (string)($row['layer'] ?? ''),
                (string)($row['id'] ?? ''),
                (string)($row['detail'] ?? ''),
                (int)($row['ms'] ?? 0)
            );
        }
        if (($result['error'] ?? '') !== '') {
            echo 'error: ' . (string)$result['error'] . "\n";
        }
    } else {
        freesaas_emit_ops([
            'ok' => !empty($result['ok']),
            'summary' => $result['summary'] ?? [],
            'rows' => $result['rows'] ?? [],
            'profile' => $result['profile'] ?? null,
            'error' => (string)($result['error'] ?? ''),
        ]);
    }
    exit(!empty($result['ok']) ? Cli::EXIT_OK : Cli::EXIT_FAIL);
}

/**
 * @param list<string> $args
 * @param array<string, string|bool> $opts
 */
function freesaas_cmd_bot(string $sub, array $args, array $opts): void
{
    if (!ModuleManager::isEnabled('bots')) {
        Cli::fail('bots module disabled', Cli::EXIT_FAIL);
    }
    require_once base_path('modules/bots/lib/BotsConfig.php');
    require_once base_path('modules/bots/lib/BotClient.php');
    require_once base_path('modules/bots/lib/BotLinksRepository.php');
    require_once base_path('modules/bots/lib/BotIdentity.php');
    require_once base_path('modules/bots/lib/BotLinkCodesRepository.php');
    require_once base_path('modules/bots/lib/BotsAgentOps.php');
    require_once base_path('modules/core/lib/UsersRepository.php');

    if ($sub === 'subscribe') {
        $audience = isset($opts['audience']) ? strtolower(trim((string)$opts['audience'])) : 'staff';
        $platform = isset($opts['platform']) ? strtolower(trim((string)$opts['platform'])) : '';
        if ($platform === '' || !in_array($platform, BotsConfig::PLATFORMS, true)) {
            Cli::usage('Usage: bot subscribe --audience=staff|client --platform=telegram|max [--url=https://…]');
        }
        if (!in_array($audience, BotsConfig::AUDIENCES, true)) {
            Cli::fail('audience must be staff|client', Cli::EXIT_FAIL);
        }
        $url = isset($opts['url']) ? trim((string)$opts['url']) : '';
        if ($url === '') {
            $origin = AgentOps::publicOrigin();
            if ($origin === '') {
                Cli::fail('pass --url=https://… or set app.admin_host', Cli::EXIT_FAIL);
            }
            $url = $origin . '/bots/webhook/' . $platform . '/' . $audience;
        }
        $res = BotClient::subscribeWebhook($audience, $platform, $url);
        if (!$res['ok']) {
            Cli::fail($res['error'] !== '' ? $res['error'] : 'subscribe failed', Cli::EXIT_FAIL);
        }
        freesaas_emit_ops([
            'ok' => true,
            'platform' => $res['platform'],
            'audience' => $res['audience'],
            'url' => $res['url'],
        ]);
        return;
    }

    $target = trim((string)($args[2] ?? ''));
    if ($sub === 'unlink' || $sub === 'link-code') {
        if ($target === '') {
            Cli::usage('Usage: bot ' . $sub . ' <login|client:<id>> …');
        }
        $source = BotLinksRepository::SOURCE_STAFF;
        $entityId = 0;
        if (stripos($target, 'client:') === 0) {
            $source = BotLinksRepository::SOURCE_CLIENT;
            $entityId = (int)substr($target, 7);
        } else {
            $row = UsersRepository::findByLogin($target);
            if ($row === null) {
                Cli::fail('staff login not found: ' . $target, Cli::EXIT_FAIL);
            }
            $entityId = (int)$row['id'];
        }
        if ($entityId < 1) {
            Cli::fail('invalid target', Cli::EXIT_FAIL);
        }

        if ($sub === 'unlink') {
            $platform = isset($opts['platform']) ? strtolower(trim((string)$opts['platform'])) : '';
            if ($platform !== '' && !in_array($platform, ['telegram', 'max'], true)) {
                Cli::fail('platform must be telegram|max', Cli::EXIT_FAIL);
            }
            BotLinksRepository::unbindPlatform($source, $entityId, $platform);
            freesaas_emit_ops([
                'ok' => true,
                'unlinked' => true,
                'bot_source' => $source,
                'id_entity' => $entityId,
                'platform' => $platform === '' ? 'all' : $platform,
            ]);
            return;
        }

        $ttl = isset($opts['ttl']) ? (int)$opts['ttl'] : BotLinkCodesRepository::TTL_SECONDS;
        try {
            $code = BotLinkCodesRepository::create($source, $entityId, $ttl);
        } catch (Throwable $e) {
            Cli::fail($e->getMessage(), Cli::EXIT_FAIL);
        }
        freesaas_emit_ops([
            'ok' => true,
            'code' => $code['code'],
            'expires_at' => $code['expires_at'],
            'ttl_seconds' => $code['ttl_seconds'],
            'bot_source' => $source,
            'id_entity' => $entityId,
            'hint' => 'Send this 10-character code to the bot from the chat to link',
        ]);
        return;
    }

    Cli::usage('Usage: bot unlink|link-code|subscribe …');
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

    if ($sub === 'logout-all') {
        $login = trim((string)($args[2] ?? ''));
        if ($login === '') {
            Cli::usage('Usage: user logout-all <login> [--format=json]');
        }
        $row = UsersRepository::findByLogin($login);
        if ($row === null) {
            Cli::fail('user not found: ' . $login, Cli::EXIT_FAIL);
        }
        $epoch = Auth::bumpAuthEpoch((int)$row['id']);
        freesaas_emit_ops([
            'ok' => true,
            'login' => $login,
            'logout_all' => true,
            'auth_epoch' => $epoch,
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

    Cli::usage('Usage: user unlock|login-link|logout-all|hash-audit …');
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
        $dbBackup = !empty($opts['db-backup']) || !empty($opts['db_backup']);
        freesaas_emit_ops(AgentOps::updateApply($target, $migrate, $dbBackup));
        return;
    }
    Cli::usage('Usage: update status|check|apply [--target=core|moduleId|all] [--no-migrate] [--db-backup] [--format=json]');
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
    if ($sub === 'uninstall') {
        $id = $args[2] ?? '';
        if ($id === '') {
            Cli::usage('Usage: module uninstall <id> --confirm');
        }
        $confirm = !empty($opts['confirm']) || !empty($opts['confirm-uninstall']) || !empty($opts['confirm_uninstall']);
        freesaas_emit_ops(AgentOps::moduleUninstall($id, $confirm));
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
    Cli::usage('Usage: module list|inspect|install|uninstall --confirm|enable|disable|validate|bump|create|add-migration …');
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
