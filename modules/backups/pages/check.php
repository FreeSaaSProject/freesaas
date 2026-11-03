<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!Auth::capability('SUPERADMIN_ACCESS')) {
    if ($wantsJson) {
        AgentHttp::error(t('backups.err.superadmin'), 403);
        return;
    }
    http_response_code(403);
    echo t('backups.err.superadmin');
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (class_exists('Csrf', false) && !Csrf::verify()) {
        Csrf::deny('csrf');
        return;
    }
}

$report = BackupDoctor::run();

if ($wantsJson) {
    AgentHttp::emit(array_merge(['ok' => !empty($report['ok'])], $report));
    return;
}

View::render('check', [
    'title' => t('backups.doctor.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('backups.menu.list'), 'href' => '/backups'],
        ['label' => t('backups.menu.settings'), 'href' => '/backups/settings'],
        ['label' => t('backups.doctor.title'), 'current' => true],
    ]),
    'report' => $report,
], 'layout', 'backups');
