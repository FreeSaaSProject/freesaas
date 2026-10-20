<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/PaymentsRepository.php';
require_once __DIR__ . '/lib/PaymentMoveLinksRepository.php';
require_once __DIR__ . '/lib/PaymentMoveAllocUi.php';
require_once __DIR__ . '/lib/DayPayroll.php';
require_once __DIR__ . '/lib/ShiftSalaryBotPlugin.php';
require_once __DIR__ . '/lib/DayPayrollBotPlugin.php';
require_once __DIR__ . '/lib/PaymentsAssistantPreview.php';

EventBus::on('assistant.write.preview', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    return PaymentsAssistantPreview::onWritePreview($payload);
});

EventBus::on('assistant.system.hints', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    $ids = is_array($payload['op_ids'] ?? null) ? $payload['op_ids'] : [];
    $hints = is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
    foreach ($ids as $id => $_true) {
        if ($id === 'payments' || strpos((string)$id, 'payments.') === 0) {
            $hints[] = t('payments.assistant.hint_fot');
            break;
        }
    }
    $payload['hints'] = $hints;
    return $payload;
});

StatusesRepository::registerFieldGroup([
    'title' => 'payments.statuses.group',
    'when' => 'payments',
    'fields' => [
        ['key' => 'PAY_DAILY', 'label' => 'payments.statuses.field.PAY_DAILY', 'type' => 'checkbox'],
    ],
]);

Assets::register('payments', [
    'css' => ['payments.css'],
    'js' => ['payments_alloc.js'],
]);

$pages = [
    '/payments' => ['permission' => 'payments.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/payments/settings' => [
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
    '/payments/new' => ['permission' => 'payments.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/payments/{id}/edit' => ['permission' => 'payments.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/payments/move-links/{moveId}' => ['permission' => 'payments.edit', 'methods' => ['POST'], 'file' => 'move_links.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'payments',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/payments/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    if (!empty($def['permission'])) {
        $route['permission'] = $def['permission'];
    }
    ModuleManager::registerRoute($path, $route);
}

BulkActions::register('payments', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'payments.delete',
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
    DayPayrollBotPlugin::register();
};
if (ModuleManager::isEnabled('bots')) {
    $registerPaymentsBots();
}
EventBus::on('bots.ready', static function ($payload) use ($registerPaymentsBots) {
    $registerPaymentsBots();
    return is_array($payload) ? $payload : [];
});
