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
$assert(Csrf::isExemptPath('/assistant-groups/webhook/max'), 'exempt assistant_groups webhook');
$assert(Csrf::isExemptPath('/oauth/token'), 'exempt oauth token');
$assert(Csrf::isExemptPath('/oauth/revoke'), 'exempt oauth revoke');
$assert(Csrf::isExemptPath('/oauth/register'), 'exempt oauth register');
$assert(!Csrf::isExemptPath('/oauth/authorize'), 'oauth authorize needs CSRF');
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
unset($_SERVER['HTTP_X_CSRF_TOKEN']);

// Mid-POST Auth::check(false) must not rotate CSRF (Router calls can() before verify).
// No DB user: skip guard by leaving user_id empty — only assert rotate() is explicit.
$tStable = Csrf::token();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['_regen_at'] = time() - 2000;
$_SESSION['user_id'] = 0;
$assert(!Auth::check(false), 'empty session check fails');
$assert(Csrf::token() === $tStable, 'failed check does not rotate csrf');
unset($_SERVER['REQUEST_METHOD']);
$_SESSION['_regen_at'] = time();

// JSON body csrf (shared hosts may strip X-CSRF-Token).
$_SERVER['CONTENT_TYPE'] = 'application/json';
$GLOBALS['FREESAAS_TEST_RAW_BODY'] = json_encode(['csrf' => $t, 'message' => 'hi'], JSON_UNESCAPED_UNICODE);
$assert(Csrf::verify(), 'verify json body csrf');
$GLOBALS['FREESAAS_TEST_RAW_BODY'] = json_encode(['csrf' => 'deadbeef'], JSON_UNESCAPED_UNICODE);
$assert(!Csrf::verify(), 'reject bad json body csrf');
unset($_SERVER['CONTENT_TYPE'], $GLOBALS['FREESAAS_TEST_RAW_BODY']);

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

$jobsLoad = $routes['/terminals/jobs/load'] ?? null;
$jobsTickets = $routes['/terminals/jobs/tickets'] ?? null;
if (is_array($jobsLoad)) {
    $assert(($jobsLoad['methods'] ?? []) === ['POST'], 'terminals jobs/load POST-only');
}
if (is_array($jobsTickets)) {
    $assert(($jobsTickets['methods'] ?? []) === ['POST'], 'terminals jobs/tickets POST-only');
}
$tFloor = $routes['/t'] ?? null;
if (is_array($tFloor)) {
    $assert(!Csrf::isExemptPath('/t'), '/t not csrf-exempt');
    $methods = $tFloor['methods'] ?? [];
    $assert(in_array('GET', $methods, true) && in_array('POST', $methods, true), '/t allows GET+POST');
}

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
