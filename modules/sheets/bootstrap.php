<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/SheetAccountsRepository.php';
require_once __DIR__ . '/lib/SheetMovesRepository.php';

$pages = [
    '/sheets/accounts' => ['permission' => 'sheets.view', 'methods' => ['GET', 'POST'], 'file' => 'accounts_list.php'],
    '/sheets/accounts/new' => ['permission' => 'sheets.edit', 'methods' => ['GET', 'POST'], 'file' => 'account_edit.php'],
    '/sheets/accounts/{id}/edit' => ['permission' => 'sheets.edit', 'methods' => ['GET', 'POST'], 'file' => 'account_edit.php'],
    '/sheets/moves' => ['permission' => 'sheets.view', 'methods' => ['GET', 'POST'], 'file' => 'moves_list.php'],
    '/sheets/moves/new' => ['permission' => 'sheets.edit', 'methods' => ['GET', 'POST'], 'file' => 'move_edit.php'],
    '/sheets/moves/{id}/edit' => ['permission' => 'sheets.edit', 'methods' => ['GET', 'POST'], 'file' => 'move_edit.php'],
    '/sheets/transfers/new' => ['permission' => 'sheets.edit', 'methods' => ['GET', 'POST'], 'file' => 'transfer_edit.php'],
];
foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'sheets',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/sheets/pages/' . $file);
        },
    ]);
}

BulkActions::register('sheet_accounts', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'sheets.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => SheetAccountsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
