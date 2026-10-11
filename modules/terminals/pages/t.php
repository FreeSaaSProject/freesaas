<?php
declare(strict_types=1);

$tFloor = microtime(true);
$state = TerminalFloor::process();
if (class_exists('StageTiming', false)) {
    StageTiming::mark('TerminalFloor', microtime(true) - $tFloor);
}
$q = is_array($state['query'] ?? null) ? $state['query'] : [];

if (!empty($state['need_redirect']) || !empty($q['need_redirect'])) {
    $token = (string)($state['redirect_token'] ?? $q['redirect_token'] ?? $q['token'] ?? '');
    $params = [
        'terminalid' => (string)($state['terminalid'] ?? $q['terminalid'] ?? ''),
        'braceletid' => (string)($state['braceletid'] ?? $q['braceletid'] ?? ''),
        'token' => $token,
    ];
    if (!empty($state['disable1C']) || !empty($q['disable1C'])) {
        $params['disable1C'] = '1';
    }
    $adminId = (int)($state['adminId'] ?? $q['adminId'] ?? 0);
    if ($adminId > 0) {
        $params['adminId'] = (string)$adminId;
    }
    $gids = (string)($state['gids'] ?? $q['gids'] ?? '');
    if ($gids !== '') {
        $params['gids'] = $gids;
    }
    redirect('/t/?' . http_build_query($params));
}

View::render('t', [
    'title' => t('terminals.t.title'),
    'state' => $state,
], '', 'terminals');
