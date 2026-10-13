<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!LikespaConfig::staffDebtsEnabled()) {
    if ($wantsJson) {
        AgentHttp::error(t('likespa.staff_debts.err.disabled'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => t('core.common.not_found'),
        'message' => t('likespa.staff_debts.err.disabled'),
    ]);
    return;
}

$result = null;
$error = '';
$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = StaffDebtsRunner::run();
    if (!empty($result['ok'])) {
        $message = (string)($result['message'] ?? '');
    } else {
        $error = (string)($result['message'] ?? t('likespa.staff_debts.err.run'));
        if ((int)($result['scanned'] ?? 0) > 0) {
            $message = (string)($result['message'] ?? '');
            $error = '';
        }
    }
    if ($wantsJson) {
        if (!empty($result['ok']) || (int)($result['scanned'] ?? 0) > 0) {
            AgentHttp::emit([
                'ok' => !empty($result['ok']),
                'staff_debts' => $result,
            ]);
            return;
        }
        AgentHttp::error((string)($result['message'] ?? 'failed'), 400);
        return;
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'enabled' => true,
        'news_id' => LikespaConfig::staffDebtsNewsId(),
        'comment_user_id' => LikespaConfig::staffDebtsCommentUserId(),
        'daily_at' => LikespaConfig::staffDebtsDailyAt(),
        'terminals' => ModuleManager::isEnabled('terminals'),
        'comments' => ModuleManager::isEnabled('comments'),
        'onec_live' => class_exists(TerminalsConfig::class, false) && TerminalsConfig::onecLive(),
        'bracelets' => class_exists(StaffBraceletsRepository::class, false) && StaffBraceletsRepository::tableReady()
            ? count(StaffBraceletsRepository::listAll())
            : 0,
    ]);
    return;
}

View::render('staff_debts', [
    'title' => t('likespa.menu.staff_debts'),
    'message' => $message,
    'error' => $error,
    'result' => $result,
    'newsId' => LikespaConfig::staffDebtsNewsId(),
    'commentUserId' => LikespaConfig::staffDebtsCommentUserId(),
    'dailyAt' => LikespaConfig::staffDebtsDailyAt(),
    'braceletCount' => class_exists(StaffBraceletsRepository::class, false) && StaffBraceletsRepository::tableReady()
        ? count(StaffBraceletsRepository::listAll())
        : 0,
    'onecLive' => class_exists(TerminalsConfig::class, false) && TerminalsConfig::onecLive(),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('likespa.menu.staff_debts'), 'current' => true],
    ]),
], 'layout', 'likespa');
