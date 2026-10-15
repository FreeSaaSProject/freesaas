<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/PollsRepository.php';

ModuleManager::registerMenuGroup('polls.menu.group', 900, '/polls');

$pages = [
    '/polls' => ['permission' => 'polls.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/polls/new' => ['permission' => 'polls.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/polls/{id}/edit' => ['permission' => 'polls.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'polls',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/polls/pages/' . $file);
        },
    ]);
}

BulkActions::register('polls', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'polls.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => PollsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
