<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$canDecide = Auth::can(VideoAppealsRepository::PERM_DECIDE);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $res = ['ok' => false, 'error' => 'bad_action'];
    $back = '/video/appeals';
    if ($action === 'create') {
        // Only the attributed staff member can appeal (checked in the repository).
        $r = VideoAppealsRepository::create((int)($_POST['alert_id'] ?? 0), Auth::userId(), (string)($_POST['reason'] ?? ''));
        $res = ['ok' => $r['ok'], 'error' => $r['error'], 'id' => $r['id']];
        $back = '/video/alerts';
    } elseif ($action === 'accept' || $action === 'reject') {
        if (!$canDecide) {
            http_response_code(403);
            if ($wantsJson) {
                AgentHttp::error('forbidden', 403);
            } else {
                echo e(t('core.common.forbidden'));
            }
            return;
        }
        $res = VideoAppealsRepository::decide(
            (int)($_POST['id'] ?? 0),
            $action === 'accept' ? VideoAppealsRepository::ACCEPTED : VideoAppealsRepository::REJECTED,
            Auth::userId(),
            (string)($_POST['comment'] ?? '')
        );
    }
    if ($wantsJson) {
        AgentHttp::emit($res);
        return;
    }
    flash_set($res['ok'] ? 'ok' : 'error', $res['ok'] ? t('video.appeal.flash.saved') : t('video.appeal.err.' . (string)$res['error']));
    redirect($back);
}

$status = isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : -1;
$filter = ['status' => $status];
if (!$canDecide && !Auth::capability('SUPERADMIN_ACCESS')) {
    // Without the decide right only own appeals are listed.
    $filter['staff_id'] = Auth::userId();
}
$rows = VideoAppealsRepository::list($filter, 200);

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'appeals' => $rows]);
    return;
}

View::render('appeals', [
    'title' => t('video.appeal.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.appeals'), 'current' => true],
    ]),
    'rows' => $rows,
    'status' => $status,
    'canDecide' => $canDecide,
], 'layout', 'video');
