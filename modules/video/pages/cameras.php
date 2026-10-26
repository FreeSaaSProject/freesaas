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
            VideoJobsRepository::enqueue(
                VideoJobsRepository::TYPE_MONITOR_TICK,
                VideoCamerasRepository::jobPayload($cam)
            );
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
            $payload = VideoCamerasRepository::jobPayload($cam);
            $payload['annotate'] = true;
            $jobId = VideoJobsRepository::enqueue(VideoJobsRepository::TYPE_ON_DEMAND, $payload);
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
$publicRows = array_map([VideoCamerasRepository::class, 'forPublicView'], $rows);
if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'cameras' => $publicRows]);
    return;
}

View::render('cameras', [
    'title' => t('video.cameras.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.cameras'), 'current' => true],
    ]),
    'rows' => $publicRows,
    'q' => $q,
    'canEdit' => Auth::can('video.edit'),
], 'layout', 'video');
