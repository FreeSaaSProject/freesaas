<?php
declare(strict_types=1);

/**
 * Absence report of one camera for a day: 30-min intervals of present / absent minutes (oper filter applied).
 */
$wantsJson = AgentHttp::wantsJson();
$cameras = VideoCamerasRepository::list();
$cameraId = (int)($_GET['camera_id'] ?? 0);
if ($cameraId < 1 && $cameras !== []) {
    $cameraId = (int)$cameras[0]['ID'];
}
$day = (string)($_GET['day'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    $day = date('Y-m-d');
}

$cam = $cameraId > 0 ? VideoCamerasRepository::find($cameraId) : null;
$report = $cam !== null ? VideoRules::dayReport($cam, $day) : VideoRules::dayReport(['ID' => 0], $day);
$classToken = (string)$report['class'];

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'camera_id' => $cameraId, 'day' => $day] + $report);
    return;
}

View::render('presence', [
    'title' => t('video.presence.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.presence'), 'current' => true],
    ]),
    'cameras' => $cameras,
    'cameraId' => $cameraId,
    'day' => $day,
    'classToken' => $classToken,
    'report' => $report,
], 'layout', 'video');
