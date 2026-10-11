<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$canEdit = Auth::can('sites.edit') && Auth::capability('SUPERADMIN_ACCESS');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    Config::set('sites', 'admin_host', trim((string)($_POST['admin_host'] ?? '')));
    $appAdmin = trim((string)($_POST['admin_host'] ?? ''));
    if ($appAdmin !== '') {
        Config::set('app', 'admin_host', $appAdmin);
    }
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'admin_host' => AppRuntime::adminHost(),
            'saved' => true,
        ]);
        return;
    }
    flash_set('ok', t('sites.settings.saved'));
    redirect('/sites/settings');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $wantsJson) {
    AgentHttp::error('forbidden', 403);
    return;
}

$adminHost = AppRuntime::adminHost();
$defaultHost = SiteHostsRepository::findDefault();
$preview = '';
if ($defaultHost && trim((string)$defaultHost['host']) !== '') {
    $preview = 'https://' . $defaultHost['host'] . '/';
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'admin_host' => $adminHost,
        'preview' => $preview,
        'default_host' => $defaultHost,
        'can_edit' => $canEdit,
    ]);
    return;
}

View::render('settings', [
    'title' => t('sites.settings.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('sites.settings.title'), 'current' => true],
    ]),
    'canEdit' => $canEdit,
    'error' => $error,
    'admin_host' => $adminHost,
    'preview' => $preview,
    'default_host' => $defaultHost,
], 'layout', 'sites');
