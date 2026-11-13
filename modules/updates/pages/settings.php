<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$message = '';
$error = '';
$canSave = Auth::can('updates.apply') && Auth::capability('SUPERADMIN_ACCESS');

/** Quick remote probe of one package after its source changed (version or error, never the token). */
$probe = static function (string $package): array {
    $res = UpdateEngine::check($package);
    $row = is_array($res['targets'][0] ?? null) ? $res['targets'][0] : [];
    return [
        'ok' => !empty($res['ok']) && empty($row['check_error']),
        'package' => $package,
        'local_version' => (string)($row['local_version'] ?? ''),
        'remote_version' => (string)($row['remote_version'] ?? ''),
        'update_available' => !empty($row['update_available']),
        'error' => (string)($row['check_error'] ?? ($res['error'] ?? '')),
    ];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!$canSave) {
            throw new RuntimeException(t('updates.err_superadmin'));
        }
        if ($action === 'source_save') {
            $saved = UpdateEngine::saveExternalSource([
                'kind' => (string)($_POST['kind'] ?? ''),
                'id' => (string)($_POST['id'] ?? ''),
                'provider' => (string)($_POST['provider'] ?? 'github'),
                'repo' => (string)($_POST['repo'] ?? ''),
                'channel' => (string)($_POST['channel'] ?? 'main'),
                'ref' => (string)($_POST['ref'] ?? ''),
                'path_prefix' => (string)($_POST['path_prefix'] ?? ''),
                'manifest_url' => (string)($_POST['manifest_url'] ?? ''),
                'token' => (string)($_POST['token'] ?? ''),
                'clear_token' => !empty($_POST['clear_token']),
            ]);
            $check = $probe((string)$saved['package']);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'saved' => true, 'source' => $saved, 'check' => $check]);
            }
            $message = $check['ok']
                ? t('updates.sources.saved_ok', ['package' => $saved['package'], 'version' => $check['remote_version']])
                : '';
            if (!$check['ok']) {
                $error = t('updates.sources.saved_check_failed', ['package' => $saved['package'], 'error' => $check['error']]);
            }
        } elseif ($action === 'source_delete') {
            $deleted = UpdateEngine::deleteExternalSource((string)($_POST['kind'] ?? ''), (string)($_POST['id'] ?? ''));
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'deleted' => $deleted, 'sources' => UpdateEngine::externalSources()]);
            }
            $message = t('updates.sources.deleted');
        } else {
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
                    'sources' => UpdateEngine::externalSources(),
                ]);
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
        }
    }
}

$cfg = UpdateEngine::coreSettings();
$sources = UpdateEngine::externalSources();

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
        'sources' => $sources,
        'can_save' => $canSave,
    ]);
}

$themeIds = class_exists('SiteTheme') ? SiteTheme::listIds() : [];

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
    'sources' => $sources,
    'themeIds' => $themeIds,
    'canSave' => $canSave,
    'freezeOptions' => $freezeOptions,
], 'layout', 'updates');
