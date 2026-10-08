<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/DepoTagParser.php';
require_once __DIR__ . '/lib/TinvestApiClient.php';
require_once __DIR__ . '/lib/TinvestAccountsRepository.php';
require_once __DIR__ . '/lib/BrokerSnapshotsRepository.php';
require_once __DIR__ . '/lib/TinvestSyncService.php';
require_once __DIR__ . '/lib/LiquidAssetsReport.php';

Assets::register('liquid_assets', [
    'css' => ['liquid_assets.css'],
]);

$pages = [
    '/liquid-assets' => ['permission' => 'liquid_assets.view', 'methods' => ['GET', 'POST'], 'file' => 'report.php'],
    '/liquid-assets/accounts' => ['permission' => 'liquid_assets.view', 'methods' => ['GET', 'POST'], 'file' => 'accounts.php'],
    '/liquid-assets/accounts/new' => ['permission' => 'liquid_assets.edit', 'methods' => ['GET', 'POST'], 'file' => 'account_edit.php'],
    '/liquid-assets/accounts/{id}/edit' => ['permission' => 'liquid_assets.edit', 'methods' => ['GET', 'POST'], 'file' => 'account_edit.php'],
];
foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'liquid_assets',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/liquid_assets/pages/' . $file);
        },
    ]);
}

BulkActions::register('liquid_assets_accounts', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'liquid_assets.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => TinvestAccountsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
