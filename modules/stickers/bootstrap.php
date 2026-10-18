<?php
declare(strict_types=1);

// Storefront boots stickers without core; QR scanner still needs staff repos + auth layout.
foreach ([
    'UsersRepository.php',
    'StatusesRepository.php',
    'SystemRepository.php',
] as $coreLib) {
    $path = base_path('modules/core/lib/' . $coreLib);
    if (is_file($path)) {
        require_once $path;
    }
}

require_once __DIR__ . '/lib/Acts2InRepository.php';
require_once __DIR__ . '/lib/Acts2InDps.php';
require_once __DIR__ . '/lib/Acts2OutRepository.php';
require_once __DIR__ . '/lib/StickerInsRepository.php';
require_once __DIR__ . '/lib/StickerInsOps.php';
require_once __DIR__ . '/lib/StickerOutsRepository.php';
require_once __DIR__ . '/lib/StickerWriteoff.php';
require_once __DIR__ . '/lib/WriteoffBotPlugin.php';
require_once __DIR__ . '/lib/QrScanGate.php';
require_once __DIR__ . '/lib/QrScanToken.php';
require_once __DIR__ . '/lib/QrScanService.php';
require_once __DIR__ . '/lib/StickerQr.php';
require_once __DIR__ . '/lib/StickerPrint.php';
require_once __DIR__ . '/lib/StickersDiagnose.php';

$pages = [
    '/stickers/receipts' => ['permission' => 'stickers.view', 'methods' => ['GET', 'POST'], 'file' => 'receipts_list.php'],
    '/stickers/receipts/new' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'receipt_edit.php'],
    '/stickers/receipts/{id}/edit' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'receipt_edit.php'],
    '/stickers/ins' => ['permission' => 'stickers.view', 'methods' => ['GET', 'POST'], 'file' => 'ins_list.php'],
    '/stickers/ins/new' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'ins_edit.php'],
    '/stickers/ins/{id}/edit' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'ins_edit.php'],
    '/stickers/ins/print' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'print.php'],
    '/stickers/writeoffs' => ['permission' => 'stickers.view', 'methods' => ['GET', 'POST'], 'file' => 'writeoffs_list.php'],
    '/stickers/writeoffs/new' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'writeoff_edit.php'],
    '/stickers/writeoffs/{id}/edit' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'writeoff_edit.php'],
    '/stickers/outs' => ['permission' => 'stickers.view', 'methods' => ['GET', 'POST'], 'file' => 'outs_list.php'],
    '/stickers/writeoff' => ['permission' => 'stickers.writeoff', 'methods' => ['GET', 'POST'], 'file' => 'writeoff_form.php'],
    '/stickers/status' => ['permission' => 'stickers.status', 'methods' => ['GET'], 'file' => 'status.php'],
    '/stickers/settings' => [
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'stickers',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/stickers/pages/' . $file);
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

// Legacy printed QR entry (public; staff gate inside handler — no route permission).
ModuleManager::registerRoute('/qr2.php', [
    'module' => 'stickers',
    'methods' => ['GET', 'POST'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/stickers/pages/qr2.php');
    },
]);

// Optional anonymous A4 print (cfg public_print_enabled; GET-only sheet in handler).
ModuleManager::registerRoute('/stickers/printstickers', [
    'module' => 'stickers',
    'methods' => ['GET'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/stickers/pages/printstickers.php');
    },
]);

BulkActions::register('stickers_receipts', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'stickers.delete',
        'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        $r = Acts2InRepository::deleteByIds($ids);
        return ['done' => $r['done'], 'skipped' => $r['skipped']];
    },
]);

// recalc_summ bulk removed: list/find heal SUMM=0 and update() recalcs automatically.

// Register when ledger module is on — do NOT probe tables here (static CI / no DB).
// ledgerReady() runs inside options/run when a session actually uses the action.
if (ModuleManager::isEnabled('ledger')) {
    BulkActions::register('stickers_receipts', 'create_dps', [
        'label' => 'stickers.bulk.create_dps',
        'group' => 'stickers.bulk.group.dps',
        'permission' => 'ledger.edit',
        'confirm' => 'stickers.bulk.confirm_create_dps',
        'fields' => [
            [
                'name' => 'type_id',
                'label' => 'stickers.bulk.dps_type',
                'type' => 'select',
                'options' => static function (): array {
                    return Acts2InDps::typeOptions();
                },
            ],
        ],
        'run' => static function (array $ids, array $params): array {
            if (!Acts2InDps::ledgerReady()) {
                $skipped = [];
                foreach ($ids as $id) {
                    $skipped[] = ['id' => (int)$id, 'reason' => t('stickers.dps.err.ledger_off')];
                }
                return ['done' => 0, 'skipped' => $skipped];
            }
            $typeId = (int)($params['type_id'] ?? 0);
            if ($typeId < 1) {
                $skipped = [];
                foreach ($ids as $id) {
                    $skipped[] = ['id' => (int)$id, 'reason' => t('stickers.dps.err.bad_type')];
                }
                return ['done' => 0, 'skipped' => $skipped];
            }
            $r = Acts2InDps::createForActs($ids, $typeId, Auth::userId());
            return ['done' => $r['done'], 'skipped' => $r['skipped']];
        },
    ]);
}

BulkActions::register('stickers_ins', 'print', [
    'label' => 'stickers.bulk.print',
    'group' => 'stickers.bulk.group.print',
    'permission' => 'stickers.edit',
    'run' => static function (array $ids, array $params): array {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        $clean = array_values($clean);
        if ($clean === []) {
            return ['done' => 0, 'skipped' => []];
        }
        $pending = StickerInsRepository::listForPrintByIds($clean);
        $pendingIds = [];
        foreach ($pending as $row) {
            $pendingIds[] = (int)$row['id'];
        }
        $skipped = [];
        foreach ($clean as $id) {
            if (!in_array($id, $pendingIds, true)) {
                $skipped[] = ['id' => $id, 'reason' => t('stickers.bulk.err.not_pending_print')];
            }
        }
        if ($pendingIds === []) {
            return ['done' => 0, 'skipped' => $skipped];
        }
        return [
            'done' => count($pendingIds),
            'skipped' => $skipped,
            'redirect' => '/stickers/ins/print?ids=' . implode(',', $pendingIds),
        ];
    },
]);

BulkActions::register('stickers_ins', 'reprint', [
    'label' => 'stickers.bulk.reprint',
    'group' => 'stickers.bulk.group.print',
    'permission' => 'stickers.edit',
    'confirm' => 'stickers.bulk.confirm_reprint',
    'run' => static function (array $ids, array $params): array {
        return StickerInsOps::markReprint($ids, Auth::userId());
    },
]);

BulkActions::register('stickers_ins', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'stickers.delete',
    'group' => 'stickers.bulk.group.delete',
        'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        $r = StickerInsRepository::deleteByIds($ids);
        $skipped = [];
        foreach ($r['skipped'] as $id) {
            $skipped[] = ['id' => (int)$id, 'reason' => t('stickers.bulk.err.used')];
        }
        return ['done' => $r['done'], 'skipped' => $skipped];
    },
]);

$registerStickersBots = static function (): void {
    WriteoffBotPlugin::register();
};
if (ModuleManager::isEnabled('bots')) {
    $registerStickersBots();
}
EventBus::on('bots.ready', static function ($payload) use ($registerStickersBots) {
    $registerStickersBots();
    return is_array($payload) ? $payload : [];
});
