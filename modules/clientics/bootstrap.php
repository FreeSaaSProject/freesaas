<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ClienticsConfig.php';
require_once __DIR__ . '/lib/ClienticsJobsAuth.php';
require_once __DIR__ . '/lib/ClienticsPhone.php';
require_once __DIR__ . '/lib/ClienticsIdMapRepository.php';
require_once __DIR__ . '/lib/ClienticsSyncStateRepository.php';
require_once __DIR__ . '/lib/ClienticsApiClient.php';
require_once __DIR__ . '/lib/ClienticsClientsImporter.php';
require_once __DIR__ . '/lib/ClienticsWebhookIngest.php';

ModuleManager::registerMenuGroup('clientics.menu.group', 1250, '/clientics');

$pages = [
    '/clientics' => ['permission' => 'clientics.view', 'methods' => ['GET', 'POST'], 'file' => 'status.php'],
    '/clientics/import' => ['permission' => 'clientics.import', 'methods' => ['GET', 'POST'], 'file' => 'import.php'],
    '/clientics/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/clientics/jobs/ping' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_ping.php'],
    '/clientics/jobs/import-clients' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_import_clients.php'],
    '/clientics/webhook' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'webhook.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'clientics',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/clientics/pages/' . $file);
        },
    ];
    if (!empty($def['public'])) {
        $route['public'] = true;
    }
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    if (!empty($def['permission'])) {
        $route['permission'] = $def['permission'];
    }
    ModuleManager::registerRoute($path, $route);
}

Cron::register('clientics.import_clients', [
    'module' => 'clientics',
    'label' => 'clientics.cron.import_clients',
    'every' => 60,
    'handler' => static function (): array {
        if (!ClienticsConfig::pullEnabled()) {
            return ['ok' => true, 'skipped' => 1, 'message' => 'pull disabled'];
        }
        $from = date('Y-m-d', strtotime('-1 day'));
        $to = date('Y-m-d');
        $report = ClienticsClientsImporter::run($from, $to, false);
        return [
            'ok' => !empty($report['ok']),
            'processed' => (int)($report['created'] ?? 0) + (int)($report['updated'] ?? 0),
            'skipped' => (int)($report['skipped'] ?? 0),
            'message' => (string)($report['error'] ?? ($from . '..' . $to)),
        ];
    },
]);

Health::register('clientics.worker_token', 'clientics', static function (): array {
    if (ClienticsConfig::workerTokenConfigured()) {
        return ['status' => 'ok', 'message' => 'worker_token configured'];
    }
    return [
        'status' => 'degraded',
        'message' => 'clientics.worker_token missing or CHANGE_ME — jobs will reject',
        'meta' => ['hint' => '/clientics/settings'],
    ];
}, 'Clientics job worker token');

Health::register('clientics.api', 'clientics', static function (): array {
    if (!ClienticsConfig::pullEnabled()) {
        return ['status' => 'ok', 'message' => 'pull disabled'];
    }
    if (ClienticsConfig::apiReady()) {
        return ['status' => 'ok', 'message' => 'account/user/token present'];
    }
    return [
        'status' => 'degraded',
        'message' => 'account_id / user_id / access_token incomplete — pull jobs will fail',
        'meta' => ['hint' => '/clientics/settings'],
    ];
}, 'Clientics API config');
