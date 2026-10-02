<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Acts2InRepository.php';
require_once __DIR__ . '/lib/Acts2OutRepository.php';
require_once __DIR__ . '/lib/StickerInsRepository.php';
require_once __DIR__ . '/lib/StickerOutsRepository.php';
require_once __DIR__ . '/lib/StickerWriteoff.php';

$pages = [
    '/stickers/receipts' => ['permission' => 'stickers.view', 'methods' => ['GET', 'POST'], 'file' => 'receipts_list.php'],
    '/stickers/receipts/new' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'receipt_edit.php'],
    '/stickers/receipts/{id}/edit' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'receipt_edit.php'],
    '/stickers/ins' => ['permission' => 'stickers.view', 'methods' => ['GET', 'POST'], 'file' => 'ins_list.php'],
    '/stickers/ins/new' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'ins_edit.php'],
    '/stickers/ins/{id}/edit' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'ins_edit.php'],
    '/stickers/writeoffs' => ['permission' => 'stickers.view', 'methods' => ['GET', 'POST'], 'file' => 'writeoffs_list.php'],
    '/stickers/writeoffs/new' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'writeoff_edit.php'],
    '/stickers/writeoffs/{id}/edit' => ['permission' => 'stickers.edit', 'methods' => ['GET', 'POST'], 'file' => 'writeoff_edit.php'],
    '/stickers/outs' => ['permission' => 'stickers.view', 'methods' => ['GET', 'POST'], 'file' => 'outs_list.php'],
    '/stickers/writeoff' => ['permission' => 'stickers.writeoff', 'methods' => ['GET', 'POST'], 'file' => 'writeoff_form.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'stickers',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/stickers/pages/' . $file);
        },
    ]);
}

BulkActions::register('stickers_receipts', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'stickers.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        $r = Acts2InRepository::deleteByIds($ids);
        return ['done' => $r['done'], 'skipped' => $r['skipped']];
    },
]);

BulkActions::register('stickers_ins', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'stickers.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        $r = StickerInsRepository::deleteByIds($ids);
        return ['done' => $r['done'], 'skipped' => $r['skipped']];
    },
]);
