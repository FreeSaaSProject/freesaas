<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/PollsRepository.php';

ModuleManager::registerMenuGroup('polls.menu.group', 900, '/polls');

$pages = [
    '/polls' => ['permission' => 'polls.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/polls/new' => ['permission' => 'polls.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/polls/{id}/edit' => ['permission' => 'polls.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/polls/settings' => [
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'polls',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/polls/pages/' . $file);
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

BulkActions::register('polls', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'polls.delete',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => PollsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
