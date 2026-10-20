<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$q = trim((string)($_GET['q'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'enqueue_monitor') {
        $id = (int)($_POST['id'] ?? 0);
        $cam = VideoCamerasRepository::find($id);
        if ($cam) {
            VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_MONITOR_TICK, [
                'camera_id' => $id,
                'rtsp_url' => (string)$cam['RTSP_URL'],
                'model_id' => (int)$cam['MODEL_ID'],
                'conf_threshold' => (float)$cam['CONF_THRESHOLD'],
                'iou_threshold' => (float)$cam['IOU_THRESHOLD'],
                'mode' => (string)$cam['MODE'],
                'settings' => json_decode((string)($cam['SETTINGS_JSON'] ?? ''), true) ?: [],
            ]);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'enqueued' => true]);
                return;
            }
            flash_set('ok', t('video.flash.job_enqueued'));
            redirect('/video/cameras');
        }
    }
    if ($action === 'enqueue_ondemand') {
        $id = (int)($_POST['id'] ?? 0);
        $cam = VideoCamerasRepository::find($id);
        if ($cam) {
            $jobId = VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_ON_DEMAND, [
                'camera_id' => $id,
                'rtsp_url' => (string)$cam['RTSP_URL'],
                'model_id' => (int)$cam['MODEL_ID'],
                'conf_threshold' => (float)$cam['CONF_THRESHOLD'],
                'iou_threshold' => (float)$cam['IOU_THRESHOLD'],
                'annotate' => true,
            ]);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'job_id' => $jobId]);
                return;
            }
            flash_set('ok', t('video.flash.job_enqueued'));
            redirect('/video/cameras');
        }
    }
}

$rows = VideoCamerasRepository::list($q !== '' ? $q : null);
if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'cameras' => $rows]);
    return;
}

View::render('cameras', [
    'title' => t('video.cameras.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.cameras'), 'current' => true],
    ]),
    'rows' => $rows,
    'q' => $q,
], 'layout', 'video');
