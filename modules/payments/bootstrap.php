<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/PaymentTypesRepository.php';
require_once __DIR__ . '/lib/PaymentsRepository.php';
require_once __DIR__ . '/lib/PaymentMoveLinksRepository.php';
require_once __DIR__ . '/lib/PaymentMoveAllocUi.php';
require_once __DIR__ . '/lib/FreelancePayout.php';
require_once __DIR__ . '/lib/ShiftSalaryBotPlugin.php';
require_once __DIR__ . '/lib/FreelancePayoutBotPlugin.php';

Assets::register('payments', [
    'css' => ['payments.css'],
    'js' => ['payments_alloc.js'],
]);

$pages = [
    '/payments' => ['permission' => 'payments.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/payments/new' => ['permission' => 'payments.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/payments/{id}/edit' => ['permission' => 'payments.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/payments/move-links/{moveId}' => ['permission' => 'payments.edit', 'methods' => ['POST'], 'file' => 'move_links.php'],
    '/payment-types' => ['permission' => 'payments.types', 'methods' => ['GET', 'POST'], 'file' => 'types_list.php'],
    '/payment-types/new' => ['permission' => 'payments.types', 'methods' => ['GET', 'POST'], 'file' => 'types_edit.php'],
    '/payment-types/{id}/edit' => ['permission' => 'payments.types', 'methods' => ['GET', 'POST'], 'file' => 'types_edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'payments',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/payments/pages/' . $file);
        },
    ]);
}

BulkActions::register('payments', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'payments.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        $res = PaymentsRepository::deleteByIds($ids);
        return ['done' => $res['done'], 'skipped' => $res['skipped']];
    },
]);

BulkActions::register('payments', 'account0', [
    'label' => 'payments.bulk.set_account_0',
    'permission' => 'payments.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => PaymentsRepository::setAccountByIds($ids, 0), 'skipped' => []];
    },
]);

BulkActions::register('payments', 'account1', [
    'label' => 'payments.bulk.set_account_1',
    'permission' => 'payments.edit',
    'run' => static function (array $ids, array $params): array {
        if (!PaymentsRepository::ledgerEnabled()) {
            $skipped = [];
            foreach ($ids as $id) {
                $skipped[] = ['id' => (int)$id, 'reason' => t('payments.flash.dps_stub')];
            }
            return ['done' => 0, 'skipped' => $skipped];
        }
        return ['done' => PaymentsRepository::setAccountByIds($ids, 1), 'skipped' => []];
    },
]);

BulkActions::register('payments', 'account2', [
    'label' => 'payments.bulk.set_account_2',
    'permission' => 'payments.edit',
    'run' => static function (array $ids, array $params): array {
        if (!PaymentsRepository::ledgerEnabled()) {
            $skipped = [];
            foreach ($ids as $id) {
                $skipped[] = ['id' => (int)$id, 'reason' => t('payments.flash.dps_stub')];
            }
            return ['done' => 0, 'skipped' => $skipped];
        }
        return ['done' => PaymentsRepository::setAccountByIds($ids, 2), 'skipped' => []];
    },
]);

BulkActions::register('payments', 'accept', [
    'label' => 'payments.bulk.accept',
    'permission' => 'payments.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => PaymentsRepository::acceptByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('payments', 'create_dps', [
    'label' => 'payments.bulk.create_dps',
    'permission' => 'payments.edit',
    'run' => static function (array $ids, array $params): array {
        return PaymentsRepository::createDpsByIds($ids);
    },
]);

BulkActions::register('payment_types', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'payments.types',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => PaymentTypesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('ledger.moves.edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $moveId = (int)($payload['id'] ?? 0);
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
    $type = is_array($payload['type'] ?? null) ? $payload['type'] : null;
    $html = PaymentMoveAllocUi::sectionHtml($moveId, $row, $type);
    if ($html !== '') {
        Assets::need('payments');
        if (!isset($payload['html']) || !is_array($payload['html'])) {
            $payload['html'] = [];
        }
        $payload['html'][] = $html;
    }
    return $payload;
});

EventBus::on('ledger.moves.deleted', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $ids = $payload['ids'] ?? [];
    if (is_array($ids) && $ids !== []) {
        PaymentMoveLinksRepository::unlinkByMoveIds($ids);
    }
    return $payload;
});

EventBus::on('ledger.moves.badges', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $ids = $payload['ids'] ?? [];
    if (!is_array($ids) || $ids === []) {
        return $payload;
    }
    $staff = PaymentMoveLinksRepository::staffBadgesHtmlByMoveIds($ids);
    if ($staff === []) {
        return $payload;
    }
    Assets::need('payments');
    $byId = is_array($payload['by_id'] ?? null) ? $payload['by_id'] : [];
    foreach ($staff as $mid => $html) {
        $mid = (int)$mid;
        $prev = (string)($byId[$mid] ?? '');
        $byId[$mid] = trim($prev . ' ' . $html);
    }
    $payload['by_id'] = $byId;
    return $payload;
});

$registerPaymentsBots = static function (): void {
    ShiftSalaryBotPlugin::register();
    FreelancePayoutBotPlugin::register();
};
if (ModuleManager::isEnabled('bots')) {
    $registerPaymentsBots();
}
EventBus::on('bots.ready', static function ($payload) use ($registerPaymentsBots) {
    $registerPaymentsBots();
    return is_array($payload) ? $payload : [];
});
