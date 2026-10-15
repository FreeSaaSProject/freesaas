<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/NormsRepository.php';

$pages = [
    '/norms' => ['permission' => 'norms.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/norms/new' => ['permission' => 'norms.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/norms/{id}/edit' => ['permission' => 'norms.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];
foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'norms',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/norms/pages/' . $file);
        },
    ]);
}

BulkActions::register('norms', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'norms.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => NormsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('catalog.product_edit.after', static function (array $payload): array {
    $pid = (int)($payload['product_id'] ?? 0);
    if ($pid < 1 || !empty($payload['is_new'])) {
        return $payload;
    }
    $payload['html'] = ($payload['html'] ?? '') . NormsRepository::embedHtmlForProc($pid);
    return $payload;
});
