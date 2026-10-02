<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/FundMovementsRepository.php';
require_once __DIR__ . '/lib/FundsRepository.php';
require_once __DIR__ . '/lib/FundsAccrualRepository.php';

$pages = [
    '/funds' => ['permission' => 'funds.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/funds/new' => ['permission' => 'funds.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/funds/{id}/edit' => ['permission' => 'funds.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/fund-movements' => ['permission' => 'funds.view', 'methods' => ['GET', 'POST'], 'file' => 'moves_list.php'],
    '/fund-movements/new' => ['permission' => 'funds.edit', 'methods' => ['GET', 'POST'], 'file' => 'moves_edit.php'],
    '/fund-movements/{id}/edit' => ['permission' => 'funds.edit', 'methods' => ['GET', 'POST'], 'file' => 'moves_edit.php'],
];
foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'funds',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/funds/pages/' . $file);
        },
    ]);
}
BulkActions::register('funds', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'funds.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => FundsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
BulkActions::register('fund_movements', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'funds.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => FundMovementsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
