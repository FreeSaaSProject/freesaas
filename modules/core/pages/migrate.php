<?php
declare(strict_types=1);

$schemaReport = [];
$dataReport = [];
$message = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'schema') {
        $schemaReport = SchemaMigrator::runAll();
        ModuleManager::syncRegistry();
        $message = t('core.migrate.msg.schema_done');
    } elseif ($action === 'data') {
        if (!Database::tableExists('base_users')) {
            $message = t('core.migrate.msg.schema_first');
        } else {
            $dataReport = DataMigrator::runAll(true);
            $message = t('core.migrate.msg.data_done');
        }
    }
}

View::render('migrate', [
    'title' => t('core.migrate.title'),
    'message' => $message,
    'schemaReport' => $schemaReport,
    'dataReport' => $dataReport,
    'hasUsers' => Database::tableExists('base_users'),
    'hasSystem' => Database::tableExists('base_system'),
    'userCount' => Database::tableExists('base_users')
        ? (int)Database::pdo()->query('SELECT COUNT(*) FROM base_users')->fetchColumn()
        : 0,
]);
