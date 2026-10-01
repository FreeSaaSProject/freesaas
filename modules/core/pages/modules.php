<?php
declare(strict_types=1);

$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['err'] ?? ''));
$schemaReport = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $moduleId = trim((string)($_POST['module'] ?? ''));
    try {
        if ($moduleId === '' || ModuleManager::get($moduleId) === null) {
            throw new RuntimeException(t('core.modules.err.no_module'));
        }
        if ($action === 'enable') {
            ModuleManager::setEnabled($moduleId, true);
            // Redirect so bootstrap loads routes/menu of the newly enabled module
            redirect('/modules?msg=' . rawurlencode(t('core.modules.msg.enabled', ['module' => $moduleId])));
        }
        if ($action === 'disable') {
            ModuleManager::setEnabled($moduleId, false);
            redirect('/modules?msg=' . rawurlencode(t('core.modules.msg.disabled', ['module' => $moduleId])));
        }
        if ($action === 'migrate') {
            $schemaReport = SchemaMigrator::runModule($moduleId);
            foreach ($schemaReport as $row) {
                if (($row['status'] ?? '') === 'error') {
                    throw new RuntimeException(
                        t('core.modules.err.migration', [
                            'module' => (string)$row['module'],
                            'id' => (string)$row['id'],
                            'message' => (string)($row['message'] ?? 'error'),
                        ])
                    );
                }
            }
            $applied = 0;
            foreach ($schemaReport as $row) {
                if (($row['status'] ?? '') === 'ok') {
                    $applied++;
                }
            }
            $msg = $applied > 0
                ? t('core.modules.msg.migrated', ['module' => $moduleId, 'n' => $applied])
                : t('core.modules.msg.no_migrations', ['module' => $moduleId]);
            ModuleManager::syncRegistry();
            redirect('/modules?msg=' . rawurlencode($msg));
        }
        throw new RuntimeException(t('core.modules.err.unknown_action'));
    } catch (Throwable $e) {
        redirect('/modules?err=' . rawurlencode($e->getMessage()));
    }
}

$rows = [];
foreach (ModuleManager::all() as $id => $meta) {
    $rows[$id] = [
        'meta' => $meta,
        'status' => ModuleManager::status($id),
    ];
}

View::render('modules', [
    'title' => t('core.menu.modules'),
    'rows' => $rows,
    'message' => $message,
    'error' => $error,
    'schemaReport' => $schemaReport,
]);
