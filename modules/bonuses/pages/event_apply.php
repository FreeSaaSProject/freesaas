<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);

if (!Auth::capability('EVT_BONUS') && !Auth::capability('SUPERADMIN_ACCESS')) {
    if ($wantsJson) {
        AgentHttp::error(t('bonuses.err.forbidden'), 403);
        return;
    }
    http_response_code(403);
    View::render('error', [
        'title' => t('bonuses.err.forbidden'),
        'message' => t('bonuses.event.no_capability'),
    ]);
    return;
}

$result = BonusProcessor::applyForEvent($id);

$returnUrl = NavContext::sanitize((string)($_POST['ret'] ?? $_GET['ret'] ?? ''));
if ($returnUrl === '') {
    $returnUrl = '/calendar/events/' . $id . '/edit';
}

if ($wantsJson) {
    if (empty($result['ok'])) {
        AgentHttp::error((string)($result['error'] ?? t('bonuses.err.apply_failed')), 400, $result);
        return;
    }
    AgentHttp::emit(array_merge(['ok' => true], $result));
    return;
}

$qs = empty($result['ok'])
    ? ['bonus_err' => 1, 'msg' => (string)($result['error'] ?? '')]
    : [
        'bonus_ok' => 1,
        'spend' => (string)$result['spend'],
        'accrue' => (string)$result['accrue'],
    ];

$target = '/calendar/events/' . $id . '/edit';
redirect(ListQuery::url($target, array_merge($qs, [
    'ret' => $returnUrl !== $target ? $returnUrl : '',
])));
