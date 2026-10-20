<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$cameraId = (int)($_GET['camera_id'] ?? 0);
$rows = VideoAlertsRepository::recent(100, $cameraId > 0 ? $cameraId : null);

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'alerts' => $rows]);
    return;
}

View::render('alerts', [
    'title' => t('video.alerts.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.alerts'), 'current' => true],
    ]),
    'rows' => $rows,
    'cameraId' => $cameraId,
], 'layout', 'video');
