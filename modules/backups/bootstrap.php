<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/BackupConfig.php';
require_once __DIR__ . '/lib/BackupRepository.php';
require_once __DIR__ . '/lib/BackupTarget.php';
require_once __DIR__ . '/lib/BackupFtpTarget.php';
require_once __DIR__ . '/lib/BackupTarWriter.php';
require_once __DIR__ . '/lib/BackupCrypto.php';
require_once __DIR__ . '/lib/BackupSources.php';
require_once __DIR__ . '/lib/BackupNotify.php';
require_once __DIR__ . '/lib/BackupRunner.php';
require_once __DIR__ . '/lib/BackupRestore.php';

$pages = [
    '/backups' => ['permission' => 'backups.view', 'methods' => ['GET'], 'file' => 'list.php'],
    '/backups/run' => ['permission' => 'backups.manage', 'methods' => ['POST'], 'file' => 'run.php'],
    '/backups/remote' => ['permission' => 'backups.view', 'methods' => ['GET'], 'file' => 'remote.php'],
    '/backups/notify/test' => ['permission' => 'backups.manage', 'methods' => ['POST'], 'file' => 'notify_test.php'],
    '/backups/settings' => [
        'permission' => 'backups.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
    '/backups/restore' => [
        'permission' => 'backups.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'restore.php',
    ],
];
foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $opts = [
        'module' => 'backups',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/backups/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $opts['capability'] = $def['capability'];
    }
    ModuleManager::registerRoute($path, $opts);
}

Cron::register('backups.tick', [
    'module' => 'backups',
    'label' => 'backups.cron.tick',
    'every' => 5,
    'handler' => static function (): array {
        return BackupRunner::cronTick();
    },
]);

EventBus::on('core.notices.collect', static function ($notices) {
    return BackupNotify::collectNotices($notices);
});

EventBus::on('backups.sources', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    // Own sources already seeded by BackupSources::catalog(); keep passthrough.
    return $payload;
});
