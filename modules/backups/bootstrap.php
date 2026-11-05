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
require_once __DIR__ . '/lib/BackupDoctor.php';
require_once __DIR__ . '/lib/BackupCdc.php';
require_once __DIR__ . '/lib/BackupStandby.php';
require_once __DIR__ . '/lib/BackupReplica.php';
require_once __DIR__ . '/lib/BackupAgentOps.php';

Assets::register('backups', [
    'css' => ['backups.css'],
    'js' => ['backups.js'],
]);

$pages = [
    '/backups' => ['permission' => 'backups.view', 'methods' => ['GET'], 'file' => 'list.php'],
    '/backups/run' => ['permission' => 'backups.manage', 'methods' => ['POST'], 'file' => 'run.php'],
    '/backups/cancel' => ['permission' => 'backups.manage', 'methods' => ['POST'], 'file' => 'cancel.php'],
    '/backups/remote' => ['permission' => 'backups.view', 'methods' => ['GET'], 'file' => 'remote.php'],
    '/backups/notify/test' => ['permission' => 'backups.manage', 'methods' => ['POST'], 'file' => 'notify_test.php'],
    '/backups/check' => [
        'permission' => 'backups.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'check.php',
    ],
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
    '/backups/replica' => [
        'permission' => 'backups.manage',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'replica.php',
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

// After wipe the session user no longer exists — ticks continue with job tick_token only.
ModuleManager::registerRoute('/backups/replica/tick', [
    'module' => 'backups',
    'methods' => ['POST'],
    'public' => true,
    'handler' => static function (): void {
        require base_path('modules/backups/pages/replica_tick.php');
    },
]);

BackupAgentOps::register();

Cron::register('backups.tick', [
    'module' => 'backups',
    'label' => 'backups.cron.tick',
    // 1 min while a run is in progress — shared hosting still advances via /cron/tick.
    'every' => 1,
    'handler' => static function (): array {
        $replica = BackupReplica::cronAdvance();
        if ((int)($replica['processed'] ?? 0) > 0) {
            return $replica;
        }
        return BackupRunner::cronTick();
    },
]);

Cron::register('backups.cdc_refresh', [
    'module' => 'backups',
    'label' => 'backups.cron.cdc_refresh',
    'daily_at' => '04:15',
    'handler' => static function (): array {
        if (!BackupConfig::cdcEnabled()) {
            return ['ok' => true, 'processed' => 0, 'message' => 'cdc_off'];
        }
        $r = BackupCdc::install();
        return [
            'ok' => !empty($r['ok']),
            'processed' => (int)($r['triggers'] ?? 0),
            'message' => 'tables=' . (int)($r['tables'] ?? 0),
            'errors' => $r['errors'] ?? [],
        ];
    },
]);

Cron::register('backups.standby_health', [
    'module' => 'backups',
    'label' => 'backups.cron.standby_health',
    'every' => 5,
    'handler' => static function (): array {
        return BackupStandby::healthProbe();
    },
]);

// Passive PC insurance when workers plugin is off — host still needs /cron/tick or CLI cron tick.
Cron::register('backups.standby_sync', [
    'module' => 'backups',
    'label' => 'backups.cron.standby_sync',
    'every' => 15,
    'handler' => static function (): array {
        if (!StandbyMode::isPassive()) {
            return [
                'ok' => true,
                'processed' => 0,
                'message' => StandbyMode::isActive() ? 'standby_active_no_pull' : 'not_standby',
            ];
        }
        $r = BackupStandby::sync(true);
        $applied = is_array($r['applied'] ?? null) ? $r['applied'] : [];
        return [
            'ok' => !empty($r['ok']),
            'processed' => count($applied),
            'message' => (string)($r['error'] ?? $r['message'] ?? 'synced'),
            'errors' => is_array($r['errors'] ?? null) ? $r['errors'] : [],
        ];
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
