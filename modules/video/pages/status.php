<?php
declare(strict_types=1);

$report = VideoDiagnose::report();

if (AgentHttp::wantsJson()) {
    AgentHttp::emit($report);
    return;
}

View::render('status', [
    'title' => t('video.status.title'),
    'report' => $report,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video.menu.dashboard'), 'href' => '/video'],
        ['label' => t('video.status.title'), 'current' => true],
    ]),
], 'layout', 'video');
