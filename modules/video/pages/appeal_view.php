<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)Router::param('id');
$ap = VideoAppealsRepository::find($id);
if ($ap === null) {
    http_response_code(404);
    if ($wantsJson) {
        AgentHttp::error('not_found', 404);
    } else {
        echo e(t('video.appeal.err.not_found'));
    }
    return;
}
$canDecide = Auth::can(VideoAppealsRepository::PERM_DECIDE);
if (!$canDecide && !Auth::capability('SUPERADMIN_ACCESS') && (int)$ap['STAFF_ID'] !== Auth::userId()) {
    http_response_code(403);
    if ($wantsJson) {
        AgentHttp::error('forbidden', 403);
    } else {
        echo e(t('core.common.forbidden'));
    }
    return;
}
$frames = [];
foreach (VideoAlertsRepository::framePaths(['FRAME_PATHS_JSON' => $ap['FRAME_PATHS_JSON'] ?? '']) as $rel) {
    $frames[] = $rel;
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'appeal' => $ap, 'frames' => $frames]);
    return;
}

View::render('appeal_view', [
    'title' => t('video.appeal.card_title', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.appeals'), 'href' => '/video/appeals'],
        ['label' => '#' . $id, 'current' => true],
    ]),
    'ap' => $ap,
    'frames' => $frames,
    'canDecide' => $canDecide,
], 'layout', 'video');
