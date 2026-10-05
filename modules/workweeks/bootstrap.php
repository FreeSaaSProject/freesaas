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
    '/workweeks' => ['permission' => 'workweeks.view', 'methods' => ['GET', 'POST'], 'file' => 'day.php'],
    '/workweeks/day/{date}' => ['permission' => 'workweeks.view', 'methods' => ['GET', 'POST'], 'file' => 'day.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'workweeks',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/workweeks/pages/' . $file);
        },
    ]);
}
