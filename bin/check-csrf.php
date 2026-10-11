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

$assert(Csrf::isExemptPath('/agent/bootstrap'), 'exempt agent');
$assert(Csrf::isExemptPath('/bots/webhook/telegram/x'), 'exempt bots webhook');
$assert(Csrf::isExemptPath('/oauth/token'), 'exempt oauth');
$assert(Csrf::isExemptPath('/llm/mcp'), 'exempt mcp');
$assert(Csrf::isExemptPath('/cron/tick'), 'exempt cron tick');
$assert(Csrf::isExemptPath('/audio/jobs/ingest'), 'exempt audio jobs');
$assert(!Csrf::isExemptPath('/users/new'), 'users not exempt');
$assert(!Csrf::isExemptPath('/audio/llm-studies/work'), 'llm work not exempt');

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
$work = $routes['/audio/llm-studies/work'] ?? null;
$assert(is_array($work) && ($work['methods'] ?? []) === ['POST'], 'llm work POST-only');
$logout = $routes['/logout'] ?? null;
$assert(is_array($logout) && ($logout['methods'] ?? []) === ['POST'], 'logout POST-only');

exit($failed > 0 ? 1 : 0);
