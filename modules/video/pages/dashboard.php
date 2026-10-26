<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$workers = VideoWorkersRepository::listAll();
$cameras = VideoCamerasRepository::listForPublicView();
$posts = array_map(
    [VideoPostsRepository::class, 'card'],
    VideoPostsRepository::listAll()
);
$jobs = VideoJobsRepository::recent(30);
$alerts = VideoAlertsRepository::recent(20);
$online = VideoWorkersRepository::onlineCount();
$diagnose = VideoDiagnose::report();

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'workers_online' => $online,
        'workers' => $workers,
        'posts' => $posts,
        'cameras' => $cameras,
        'jobs' => $jobs,
        'alerts' => $alerts,
        'storage_bytes' => VideoStorage::monitoringBytes(),
        'diagnose' => $diagnose,
    ]);
    return;
}

View::render('dashboard', [
    'title' => t('video.dashboard.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.dashboard'), 'current' => true],
    ]),
    'workers' => $workers,
    'posts' => $posts,
    'cameras' => $cameras,
    'jobs' => $jobs,
    'alerts' => $alerts,
    'online' => $online,
    'storageBytes' => VideoStorage::monitoringBytes(),
    'diagnose' => $diagnose,
], 'layout', 'video');
