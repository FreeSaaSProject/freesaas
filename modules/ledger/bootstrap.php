<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/LedgerAccountsRepository.php';
require_once __DIR__ . '/lib/AccountMoveTypesRepository.php';
require_once __DIR__ . '/lib/LedgerReconciliationsRepository.php';
require_once __DIR__ . '/lib/LedgerMovesRepository.php';

Assets::register('ledger', [
    'css' => ['ledger.css'],
    'js' => ['ledger.js'],
]);

$pages = [
    '/accounts' => ['permission' => 'ledger.view', 'methods' => ['GET', 'POST'], 'file' => 'accounts_list.php'],
    '/accounts/new' => ['permission' => 'ledger.edit', 'methods' => ['GET', 'POST'], 'file' => 'accounts_edit.php'],
    '/accounts/{id}/edit' => ['permission' => 'ledger.edit', 'methods' => ['GET', 'POST'], 'file' => 'accounts_edit.php'],
    '/account-move-types' => ['permission' => 'ledger.types', 'methods' => ['GET', 'POST'], 'file' => 'types_list.php'],
    '/account-move-types/new' => ['permission' => 'ledger.types', 'methods' => ['GET', 'POST'], 'file' => 'types_edit.php'],
    '/account-move-types/{id}/edit' => ['permission' => 'ledger.types', 'methods' => ['GET', 'POST'], 'file' => 'types_edit.php'],
    '/ledger/moves' => ['permission' => 'ledger.view', 'methods' => ['GET', 'POST'], 'file' => 'moves_list.php'],
    '/ledger/moves/new' => ['permission' => 'ledger.edit', 'methods' => ['GET', 'POST'], 'file' => 'moves_edit.php'],
    '/ledger/moves/attach-act' => ['permission' => 'ledger.edit', 'methods' => ['GET', 'POST'], 'file' => 'attach_act.php'],
    '/ledger/moves/{id}/edit' => ['permission' => 'ledger.edit', 'methods' => ['GET', 'POST'], 'file' => 'moves_edit.php'],
    '/ledger/transfers/new' => ['permission' => 'ledger.edit', 'methods' => ['GET', 'POST'], 'file' => 'transfers_edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'ledger',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/ledger/pages/' . $file);
        },
    ]);
}

BulkActions::register('ledger_accounts', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'ledger.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => LedgerAccountsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('ledger_types', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'ledger.types',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => AccountMoveTypesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('ledger_moves', 'confirm', [
    'label' => 'ledger.moves.bulk.confirm',
    'permission' => 'ledger.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => LedgerMovesRepository::setUnconfirmedByIds($ids, 0), 'skipped' => []];
    },
]);

BulkActions::register('ledger_moves', 'unconfirm', [
    'label' => 'ledger.moves.bulk.unconfirm',
    'permission' => 'ledger.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => LedgerMovesRepository::setUnconfirmedByIds($ids, 1), 'skipped' => []];
    },
]);

BulkActions::register('ledger_moves', 'soft_delete', [
    'label' => 'ledger.moves.bulk.soft_delete',
    'permission' => 'ledger.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return LedgerMovesRepository::softDeleteByIds($ids);
    },
]);

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check() || !Auth::can('ledger.view')) {
        return $notices;
    }
    $n = LedgerMovesRepository::countOverduePromises();
    if ($n > 0) {
        $notices[] = [
            'short' => t('ledger.moves.notice.overdue_action') . ':' . $n,
            'href' => '/ledger/moves?promise=3',
            'title' => t('ledger.moves.notice.overdue'),
            'tone' => 'warn',
        ];
    }
    return $notices;
});

Health::register('ledger.fot_types', 'ledger', static function (): array {
    $keys = ['fot_bank_type', 'fot_cash_type', 'fot_cash_transfer_type'];
    $empty = [];
    foreach ($keys as $k) {
        if (trim((string)Config::get('ledger', $k, '')) === '') {
            $empty[] = $k;
        }
    }
    if ($empty !== []) {
        return [
            'status' => 'degraded',
            'message' => 'empty ledger FOT type config: ' . implode(', ', $empty),
            'meta' => ['empty' => $empty],
        ];
    }
    return ['status' => 'ok', 'message' => 'FOT type config present'];
}, 'Ledger FOT type names');
