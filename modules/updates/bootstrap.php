<?php
declare(strict_types=1);

$pages = [
    '/updates' => ['permission' => 'updates.view', 'methods' => ['GET', 'POST'], 'file' => 'index.php'],
    '/updates/check' => ['permission' => 'updates.view', 'methods' => ['GET', 'POST'], 'file' => 'api.php'],
    '/updates/apply' => ['permission' => 'updates.apply', 'methods' => ['POST'], 'file' => 'api.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'updates',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/updates/pages/' . $file);
        },
    ]);
}
