<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$rows = VideoLabelProjectsRepository::listAll();

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'projects' => $rows]);
    return;
}

View::render('projects', [
    'title' => t('video_label.projects.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video_label.menu.projects'), 'current' => true],
    ]),
    'rows' => $rows,
], 'layout', 'video_label');
