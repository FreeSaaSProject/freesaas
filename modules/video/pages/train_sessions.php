<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        $sid = VideoTrainSessionsRepository::create([
            'post_id' => (int)($_POST['post_id'] ?? 0),
            'camera_id' => (int)($_POST['camera_id'] ?? 0),
            'project_id' => (int)($_POST['project_id'] ?? 0),
            'phase' => (string)($_POST['phase'] ?? VideoTrainSessionsRepository::PHASE_SOLO),
            'target_class_id' => (int)($_POST['target_class_id'] ?? 0),
            'source_model' => (string)($_POST['source_model'] ?? 'stock_yolo'),
            'person_class' => (string)($_POST['person_class'] ?? 'person'),
            'interval_ms' => (int)($_POST['interval_ms'] ?? 500),
        ]);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $sid]);
            return;
        }
        flash_set('ok', t('video.train.flash.started'));
        redirect('/video/train/' . $sid);
    }
}

$rows = VideoTrainSessionsRepository::listRecent(50);
$posts = VideoPostsRepository::listAll();
$cameras = VideoCamerasRepository::list();
$projects = class_exists(VideoLabelProjectsRepository::class, false)
    ? VideoLabelProjectsRepository::listAll()
    : [];

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'sessions' => $rows]);
    return;
}

View::render('train_sessions', [
    'title' => t('video.train.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.train'), 'current' => true],
    ]),
    'rows' => $rows,
    'posts' => $posts,
    'cameras' => $cameras,
    'projects' => $projects,
], 'layout', 'video');
