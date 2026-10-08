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

Health::register('yclients.worker_token', 'yclients', static function (): array {
    if (YclientsConfig::workerTokenConfigured()) {
        return ['status' => 'ok', 'message' => 'worker_token configured'];
    }
    return [
        'status' => 'degraded',
        'message' => 'yclients.worker_token missing or CHANGE_ME — jobs will reject',
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
