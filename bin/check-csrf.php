#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CSRF helper regressions (no HTTP server).
 * Exit: 0 ok, 1 fail
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';

$failed = 0;
$assert = static function (bool $ok, string $msg) use (&$failed): void {
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $failed++;
    }
};
$skip = static function (string $msg): void {
    echo "SKIP $msg\n";
};

$assert(Csrf::isExemptPath('/agent'), 'exempt /agent exact');
$assert(Csrf::isExemptPath('/agent/bootstrap'), 'exempt agent');
$assert(!Csrf::isExemptPath('/agents'), 'not exempt /agents');
$assert(!Csrf::isExemptPath('/agent-ops'), 'not exempt /agent-ops');
$assert(Csrf::isExemptPath('/bots/webhook/telegram/x'), 'exempt bots webhook');
$assert(Csrf::isExemptPath('/oauth/token'), 'exempt oauth');
$assert(Csrf::isExemptPath('/llm/mcp'), 'exempt mcp');
$assert(Csrf::isExemptPath('/cron/tick'), 'exempt cron tick');
$assert(Csrf::isExemptPath('/audio/jobs/ingest'), 'exempt audio jobs');
$assert(!Csrf::isExemptPath('/users/new'), 'users not exempt');
$assert(!Csrf::isExemptPath('/audio/llm-studies/work'), 'llm work path not csrf-exempt');

Auth::startSession();
Csrf::rotate();
$t = Csrf::token();
$assert(strlen($t) === 64, 'token length');
$_POST['csrf'] = $t;
$assert(Csrf::verify(), 'verify post field');
$_POST['csrf'] = 'deadbeef';
$assert(!Csrf::verify(), 'reject bad token');
unset($_POST['csrf']);
$_SERVER['HTTP_X_CSRF_TOKEN'] = $t;
$assert(Csrf::verify(), 'verify header');

$html = '<form method="post" action="/x"><input name="a" value="1"></form>'
    . '<form method="get" action="/y"><input name="b"></form>';
$inj = Csrf::injectForms($html);
$assert(substr_count($inj, 'name="csrf"') === 1, 'inject only POST forms');
$assert(preg_match('/<form method="get"[^>]*>\s*<input name="b"/', $inj) === 1, 'GET form untouched');

$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
$assert(!Csrf::isSameSiteRequest(), 'cross-site denied');
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
$assert(Csrf::isSameSiteRequest(), 'same-origin ok');
unset($_SERVER['HTTP_SEC_FETCH_SITE']);
$_SERVER['HTTP_HOST'] = 'app.example';
$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
$assert(!Csrf::isSameSiteRequest(), 'evil origin denied');
$_SERVER['HTTP_ORIGIN'] = 'https://app.example';
$assert(Csrf::isSameSiteRequest(), 'good origin ok');

$routes = ModuleManager::routes();
$db = $routes['/db'] ?? null;
$assert(is_array($db) && empty($db['public']), '/db not public');
$logout = $routes['/logout'] ?? null;
$assert(is_array($logout) && ($logout['methods'] ?? []) === ['POST'], 'logout POST-only');

// audio_llm may be disabled in config.example.ini — prefer runtime route, else manifest agent.ops.
$work = $routes['/audio/llm-studies/work'] ?? null;
if (is_array($work)) {
    $assert(($work['methods'] ?? []) === ['POST'], 'llm work POST-only (runtime)');
} elseif (!ModuleManager::isEnabled('audio_llm')) {
    $manifestPath = base_path('modules/audio_llm/module.json');
    $opsMethod = null;
    if (is_file($manifestPath)) {
        $meta = json_decode((string)file_get_contents($manifestPath), true);
        foreach (($meta['agent']['ops'] ?? []) as $op) {
            if (!is_array($op)) {
                continue;
            }
            if (($op['path'] ?? '') === '/audio/llm-studies/work' || ($op['id'] ?? '') === 'audio_llm.studies.work') {
                $opsMethod = strtoupper((string)($op['method'] ?? ''));
                break;
            }
        }
    }
    if ($opsMethod !== null) {
        $assert($opsMethod === 'POST', 'llm work POST-only (manifest agent.ops; module disabled)');
    } else {
        $skip('llm work POST-only (audio_llm disabled, no agent.ops entry)');
    }
} else {
    $assert(false, 'llm work route missing while audio_llm enabled');
}

exit($failed > 0 ? 1 : 0);
