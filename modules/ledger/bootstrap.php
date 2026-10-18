<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/LedgerAccountsRepository.php';
require_once __DIR__ . '/lib/AccountMoveTypesRepository.php';
require_once __DIR__ . '/lib/LedgerReconciliationsRepository.php';
require_once __DIR__ . '/lib/LedgerMovesRepository.php';
require_once __DIR__ . '/lib/LedgerAssistantPreview.php';

EventBus::on('assistant.write.preview', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    return LedgerAssistantPreview::onWritePreview($payload);
});

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
    '/ledger/transfers/{id}/edit' => ['permission' => 'ledger.edit', 'methods' => ['GET', 'POST'], 'file' => 'transfers_edit.php'],
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
    'permission' => 'ledger.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => LedgerAccountsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('ledger_types', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'ledger.types.delete',
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
    'permission' => 'ledger.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return LedgerMovesRepository::softDeleteByIds($ids);
    },
]);
BulkActions::register('ledger_moves', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'ledger.delete',
    'confirm' => 'ledger.moves.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return LedgerMovesRepository::softDeleteByIds($ids);
    },
]);

BulkActions::register('ledger_moves', 'copy_enter', [
    'label' => 'ledger.moves.bulk.copy_enter',
    'permission' => 'ledger.edit',
    'max_selection' => 1,
    'run' => static function (array $ids, array $params): array {
        $id = (int)($ids[0] ?? 0);
        if ($id < 1) {
            return ['done' => 0, 'skipped' => []];
        }
        try {
            $newId = LedgerMovesRepository::copyAndEnter($id);
            return [
                'done' => 1,
                'skipped' => [],
                'redirect' => '/ledger/moves/' . $newId . '/edit',
            ];
        } catch (Throwable $e) {
            return [
                'done' => 0,
                'skipped' => [['id' => $id, 'reason' => $e->getMessage()]],
            ];
        }
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
    if (!Database::tableExists('base_account_move_types')
        || !Database::columnExists('base_account_move_types', 'FOT_KIND')) {
        return [
            'status' => 'degraded',
            'message' => 'FOT_KIND column missing — run ledger migrations',
        ];
    }
    $missing = [];
    foreach ([1 => 'bank', 2 => 'cash'] as $kind => $label) {
        $st = Database::pdo()->prepare(
            'SELECT 1 FROM base_account_move_types WHERE FOT_KIND = ? AND ENABLED > 0 LIMIT 1'
        );
        $st->execute([$kind]);
        if (!(bool)$st->fetchColumn()) {
            $missing[] = $label . '(FOT_KIND=' . $kind . ')';
        }
    }
    if ($missing !== []) {
        return [
            'status' => 'degraded',
            'message' => 'no enabled move type for: ' . implode(', ', $missing),
            'meta' => ['missing' => $missing],
        ];
    }
    return ['status' => 'ok', 'message' => 'FOT_KIND bank+cash types present'];
}, 'Ledger FOT_KIND on move types');
