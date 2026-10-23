<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$canSave = Auth::can('updates.apply') && Auth::capability('SUPERADMIN_ACCESS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$canSave) {
            throw new RuntimeException(t('updates.err_superadmin'));
        }
        $in = [
            'provider' => (string)($_POST['provider'] ?? 'github'),
            'repo' => (string)($_POST['repo'] ?? ''),
            'channel' => (string)($_POST['channel'] ?? 'main'),
            'ref' => (string)($_POST['ref'] ?? ''),
            'asset' => (string)($_POST['asset'] ?? ''),
            'token' => (string)($_POST['token'] ?? ''),
            'clear_token' => !empty($_POST['clear_token']),
            'db_backup_before_migrate' => !empty($_POST['db_backup_before_migrate']),
        ];
        // Unchecked boxes send nothing: the marker tells "form had the list" from "field omitted".
        if (isset($_POST['frozen_modules']) || !empty($_POST['frozen_modules_present'])) {
            $in['frozen_modules'] = $_POST['frozen_modules'] ?? [];
        }
        UpdateEngine::saveCoreSettings($in);
        $message = t('core.common.saved');
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'settings' => UpdateEngine::coreSettings(),
            ]);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
        }
    }
}

$cfg = UpdateEngine::coreSettings();

$freezeOptions = [];
foreach (ModuleManager::all() as $mid => $meta) {
    $mid = (string)$mid;
    if ($mid === 'core') {
        continue;
    }
    $freezeOptions[] = ['id' => $mid, 'label' => UpdateEngine::packageLabel($mid)];
}
usort($freezeOptions, static function (array $a, array $b): int {
    return strcasecmp($a['label'], $b['label']);
});

if ($wantsJson && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    AgentHttp::emit([
        'ok' => true,
        'settings' => $cfg,
        'can_save' => $canSave,
    ]);
}

View::render('settings', [
    'title' => t('updates.menu.settings'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('updates.menu.list'), 'href' => '/updates'],
        ['label' => t('updates.menu.settings'), 'current' => true],
    ]),
    'breadcrumbs_aria' => t('updates.folder_aria'),
    'message' => $message,
    'error' => $error,
    'cfg' => $cfg,
    'canSave' => $canSave,
    'freezeOptions' => $freezeOptions,
], 'layout', 'updates');
