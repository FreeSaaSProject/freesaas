<?php
declare(strict_types=1);

Assets::register('updates', [
    'css' => ['updates.css'],
    'js' => ['updates.js'],
]);

$pages = [
    '/updates' => ['permission' => 'updates.view', 'methods' => ['GET', 'POST'], 'file' => 'index.php'],
    '/updates/settings' => ['permission' => 'updates.view', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
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

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check() || !Auth::can('updates.view')) {
        return $notices;
    }
    try {
        $st = UpdateEngine::status();
        $n = (int)($st['available_count'] ?? 0);
        if ($n <= 0) {
            return $notices;
        }
        $notices[] = [
            'id' => 'updates.available',
            'tone' => 'warn',
            'href' => '/updates',
            'title' => t('updates.notice.title', ['n' => (string)$n]),
            'short' => t('updates.notice.short', ['n' => (string)$n]),
        ];
    } catch (Throwable $e) {
        // ignore
    }
    return $notices;
});
