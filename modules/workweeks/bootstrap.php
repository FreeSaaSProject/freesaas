<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/WorkweeksRepository.php';

Assets::register('workweeks', [
    'css' => ['workweeks.css'],
]);

StatusesRepository::registerFieldGroup([
    'title' => 'workweeks.statuses.group',
    'when' => 'workweeks',
    'fields' => [
        ['key' => 'SHOW_LOAD_LEVEL', 'label' => 'workweeks.statuses.field.SHOW_LOAD_LEVEL', 'type' => 'checkbox'],
        ['key' => 'SHOW_IN_LOADS', 'label' => 'workweeks.statuses.field.SHOW_IN_LOADS', 'type' => 'checkbox'],
        ['key' => 'CAN_BE_ASSIGNED', 'label' => 'workweeks.statuses.field.CAN_BE_ASSIGNED', 'type' => 'checkbox'],
        ['key' => 'CAN_BE_ASSIGNED_ASANY', 'label' => 'workweeks.statuses.field.CAN_BE_ASSIGNED_ASANY', 'type' => 'checkbox'],
        ['key' => 'EVT_ASSIGN', 'label' => 'workweeks.statuses.field.EVT_ASSIGN', 'type' => 'checkbox'],
        ['key' => 'EVT_ASSIGN_MY', 'label' => 'workweeks.statuses.field.EVT_ASSIGN_MY', 'type' => 'checkbox'],
    ],
]);

$pages = [
    '/workweeks' => ['permission' => 'workweeks.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/workweeks/new' => ['permission' => 'workweeks.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/workweeks/{id}/edit' => ['permission' => 'workweeks.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/workweeks/nazop' => ['permission' => 'workweeks.view', 'methods' => ['GET', 'POST'], 'file' => 'nazop.php'],
    '/workweeks/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    // Legacy day URL → list filtered by date
    '/workweeks/day/{date}' => ['permission' => 'workweeks.view', 'methods' => ['GET'], 'file' => 'day_redirect.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'workweeks',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/workweeks/pages/' . $file);
        },
    ];
    $perm = (string)($def['permission'] ?? '');
    if ($perm !== '') {
        $route['permission'] = $perm;
    }
    $capability = (string)($def['capability'] ?? '');
    if ($capability !== '') {
        $route['capability'] = $capability;
    }
    ModuleManager::registerRoute($path, $route);
}

BulkActions::register('workweeks', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'workweeks.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => WorkweeksRepository::deleteByIds($ids), 'skipped' => []];
    },
]);
