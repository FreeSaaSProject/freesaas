<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/YclientsConfig.php';
require_once __DIR__ . '/lib/YclientsJobsAuth.php';
require_once __DIR__ . '/lib/YclientsPhone.php';
require_once __DIR__ . '/lib/YclientsIdMapRepository.php';
require_once __DIR__ . '/lib/YclientsSyncStateRepository.php';
require_once __DIR__ . '/lib/YclientsApiClient.php';
require_once __DIR__ . '/lib/YclientsClientsImporter.php';

ModuleManager::registerMenuGroup('yclients.menu.group', 74, '/yclients');

$pages = [
    '/yclients' => ['permission' => 'yclients.view', 'methods' => ['GET', 'POST'], 'file' => 'status.php'],
    '/yclients/import' => ['permission' => 'yclients.import', 'methods' => ['GET', 'POST'], 'file' => 'import.php'],
    '/yclients/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/yclients/jobs/ping' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_ping.php'],
    '/yclients/jobs/import-clients' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_import_clients.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'yclients',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/yclients/pages/' . $file);
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

Cron::register('yclients.import_clients', [
    'module' => 'yclients',
    'label' => 'yclients.cron.import_clients',
    'every' => 60,
    'handler' => static function (): array {
        if (!YclientsConfig::pullEnabled()) {
            return ['ok' => true, 'skipped' => 1, 'message' => 'pull disabled'];
        }
        $report = YclientsClientsImporter::run(false);
        return [
            'ok' => !empty($report['ok']),
            'processed' => (int)($report['created'] ?? 0) + (int)($report['updated'] ?? 0),
            'skipped' => (int)($report['skipped'] ?? 0),
            'message' => (string)($report['error'] ?? (
                'fetched=' . (int)($report['fetched'] ?? 0)
                . ' created=' . (int)($report['created'] ?? 0)
                . ' updated=' . (int)($report['updated'] ?? 0)
            )),
        ];
    },
]);

Health::register('yclients.worker_token', 'yclients', static function (): array {
    if (YclientsConfig::workerTokenConfigured()) {
        return ['status' => 'ok', 'message' => 'worker_token configured'];
    }
    return [
        'status' => 'skipped',
        'error_code' => 'yclients_worker_token_unconfigured',
        'message' => 'yclients.worker_token missing or CHANGE_ME — jobs will reject until set',
        'meta' => ['hint' => '/yclients/settings'],
    ];
}, 'YClients job worker token');

Health::register('yclients.api', 'yclients', static function (): array {
    if (!YclientsConfig::pullEnabled()) {
        return ['status' => 'ok', 'message' => 'pull disabled'];
    }
    if (YclientsConfig::apiReady()) {
        return ['status' => 'ok', 'message' => 'company_id + tokens present'];
    }
    return [
        'status' => 'degraded',
        'message' => 'company_id / partner_token / user_token incomplete — pull jobs will fail',
        'meta' => ['hint' => '/yclients/settings'],
    ];
}, 'YClients API config');
