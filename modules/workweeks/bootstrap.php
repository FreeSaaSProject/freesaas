<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/WorkweeksRepository.php';

Assets::register('workweeks', [
    'css' => ['workweeks.css'],
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
