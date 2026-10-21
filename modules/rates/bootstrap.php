<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/RatesRepository.php';
require_once __DIR__ . '/lib/ServiceAccessParity.php';
require_once __DIR__ . '/lib/RatesSkill.php';
require_once __DIR__ . '/lib/RatesAssistantPreview.php';

Assets::register('rates', [
    'css' => ['assets/css/rates.css'],
]);

$pages = [
    '/rates' => ['permission' => 'rates.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/rates/new' => ['permission' => 'rates.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/rates/{id}/edit' => ['permission' => 'rates.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/rates/skill' => ['permission' => 'rates.edit', 'methods' => ['GET', 'POST'], 'file' => 'skill.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'rates',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/rates/pages/' . $file);
        },
    ]);
}

EventBus::on('assistant.write.preview', static function ($payload) {
    if (!is_array($payload)) {
        return is_array($payload) ? $payload : [];
    }
    return RatesAssistantPreview::onWritePreview($payload);
});

EventBus::on('assistant.system.hints', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    $opIds = is_array($payload['op_ids'] ?? null) ? $payload['op_ids'] : [];
    $want = false;
    foreach ($opIds as $id) {
        $id = (string)$id;
        if ($id === 'rates.skill.grant' || $id === 'rates.skill.revoke'
            || $id === 'rates.skill.diagnose' || $id === 'rates.skill.search'
            || strpos($id, 'rates.skill.') === 0
        ) {
            $want = true;
            break;
        }
    }
    if (!$want) {
        return $payload;
    }
    $hints = is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
    $hints[] = t('rates.assistant.hint');
    $payload['hints'] = $hints;
    return $payload;
});

BulkActions::register('rates', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'rates.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RatesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('rates', 'copy', [
    'label' => 'rates.bulk.copy',
    'permission' => 'rates.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RatesRepository::copyByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('rates', 'zerofill', [
    'label' => 'rates.bulk.zerofill',
    'permission' => 'rates.edit',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RatesRepository::zerofillByIds($ids), 'skipped' => []];
    },
]);
