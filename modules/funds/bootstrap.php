<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/FundMovementsRepository.php';
require_once __DIR__ . '/lib/FundsRepository.php';
require_once __DIR__ . '/lib/FundsAccrualRepository.php';
require_once __DIR__ . '/lib/FundRulesRepository.php';
require_once __DIR__ . '/lib/FundTypeMapRepository.php';
require_once __DIR__ . '/lib/FundAccrualRunner.php';
require_once __DIR__ . '/lib/FundTransferService.php';
require_once __DIR__ . '/lib/FundsStatement.php';
require_once __DIR__ . '/lib/FundsLedgerHooks.php';
require_once __DIR__ . '/lib/FundExpenseBackfill.php';
require_once __DIR__ . '/lib/FundsAgentOps.php';

Assets::register('funds', [
    'css' => ['funds.css'],
]);

$pages = [
    '/funds' => ['permission' => 'funds.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/funds/new' => ['permission' => 'funds.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/funds/rules' => ['permission' => 'funds.view', 'methods' => ['GET', 'POST'], 'file' => 'rules_list.php'],
    '/funds/rules/new' => ['permission' => 'funds.edit', 'methods' => ['GET', 'POST'], 'file' => 'rules_edit.php'],
    '/funds/rules/{id}/edit' => ['permission' => 'funds.edit', 'methods' => ['GET', 'POST'], 'file' => 'rules_edit.php'],
    '/funds/type-map' => ['permission' => 'funds.view', 'methods' => ['GET', 'POST'], 'file' => 'type_map.php'],
    '/funds/statement' => ['permission' => 'funds.view', 'methods' => ['GET'], 'file' => 'statement.php'],
    '/funds/transfer' => ['permission' => 'funds.edit', 'methods' => ['GET', 'POST'], 'file' => 'transfer.php'],
    '/funds/{id}' => ['permission' => 'funds.view', 'methods' => ['GET'], 'file' => 'view.php'],
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
    'permission' => 'funds.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => FundsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
BulkActions::register('fund_movements', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'funds.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => FundMovementsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
BulkActions::register('fund_rules', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'funds.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => FundRulesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

FundsLedgerHooks::register();
FundsAgentOps::register();

// Daily accrual of finished periods (idempotent, recalculates the recent window).
Cron::register('funds.accrue', [
    'module' => 'funds',
    'label' => 'funds.cron.accrue',
    'daily_at' => '03:30',
    'handler' => static function (): array {
        $r = FundAccrualRunner::run([]);
        return [
            'ok' => !empty($r['ok']),
            'processed' => (int)$r['created'] + (int)$r['updated'] + (int)$r['deleted'],
            'message' => 'created=' . (int)$r['created'] . ' updated=' . (int)$r['updated']
                . ' unchanged=' . (int)$r['unchanged'] . ' deleted=' . (int)$r['deleted'],
        ];
    },
]);

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check() || !Auth::can('funds.view') || !FundRulesRepository::tableReady()) {
        return $notices;
    }
    $over = FundsStatement::overdrawn();
    if ($over !== []) {
        $notices[] = [
            'short' => t('funds.notice.overdrawn_short', ['n' => count($over)]),
            'href' => '/funds/statement',
            'title' => t('funds.notice.overdrawn_title'),
            'tone' => 'warn',
        ];
    }
    return $notices;
});
