<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/BanksConfig.php';
require_once __DIR__ . '/lib/JobsAuth.php';
require_once __DIR__ . '/lib/BankHttp.php';
require_once __DIR__ . '/lib/BankConnectionsRepository.php';
require_once __DIR__ . '/lib/BankAccountLinksRepository.php';
require_once __DIR__ . '/lib/BankTxMapRepository.php';
require_once __DIR__ . '/lib/providers/TochkaClient.php';
require_once __DIR__ . '/lib/providers/AlfaUlClient.php';
require_once __DIR__ . '/lib/providers/AlfaFlClient.php';
require_once __DIR__ . '/lib/providers/TbankUlClient.php';
require_once __DIR__ . '/lib/providers/TbankFlClient.php';
require_once __DIR__ . '/lib/providers/SberUlClient.php';
require_once __DIR__ . '/lib/providers/SberFlClient.php';
require_once __DIR__ . '/lib/BankOAuth.php';
require_once __DIR__ . '/lib/BankClassifyService.php';
require_once __DIR__ . '/lib/BankSyncService.php';
require_once __DIR__ . '/lib/BankBadges.php';

Assets::register('banks', [
    'css' => ['banks.css'],
]);

$pages = [
    '/banks' => ['permission' => 'banks.view', 'methods' => ['GET'], 'file' => 'list.php'],
    '/banks/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/banks/oauth/{provider}/start' => ['permission' => 'banks.connect', 'methods' => ['GET'], 'file' => 'oauth_start.php'],
    '/banks/oauth/{provider}/callback' => ['permission' => 'banks.connect', 'methods' => ['GET'], 'file' => 'oauth_callback.php'],
    '/banks/connections/{id}' => ['permission' => 'banks.view', 'methods' => ['GET', 'POST'], 'file' => 'connection.php'],
    '/banks/connections/{id}/sync' => ['permission' => 'banks.sync', 'methods' => ['POST', 'GET'], 'file' => 'sync.php'],
    '/banks/jobs/sync' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_sync.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'banks',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/banks/pages/' . $file);
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

Health::register('banks.default_move_type', 'banks', static function (): array {
    $id = BanksConfig::defaultMoveTypeId();
    if ($id > 0) {
        return ['status' => 'ok', 'message' => 'default_move_type_id=' . $id];
    }
    return [
        'status' => 'degraded',
        'message' => 'banks.default_move_type_id not set — sync will pick first enabled type or fail',
        'meta' => ['hint' => 'cfg/modules/banks.ini'],
    ];
}, 'Banks default ledger move type');

Health::register('banks.worker_token', 'banks', static function (): array {
    if (BanksConfig::workerTokenConfigured()) {
        return ['status' => 'ok', 'message' => 'worker_token configured'];
    }
    return [
        'status' => 'degraded',
        'message' => 'banks.worker_token missing or CHANGE_ME — jobs will reject',
        'meta' => ['hint' => 'cfg/modules/banks.ini'],
    ];
}, 'Banks job worker token');

EventBus::on('ledger.accounts.badges', static function ($payload) {
    if (!is_array($payload)) {
        $payload = [];
    }
    $ids = $payload['ids'] ?? [];
    if (!is_array($ids) || $ids === []) {
        return $payload;
    }
    $byId = BankBadges::forAccounts($ids);
    $payload['by_id'] = array_replace(
        is_array($payload['by_id'] ?? null) ? $payload['by_id'] : [],
        $byId
    );
    return $payload;
});

EventBus::on('ledger.moves.badges', static function ($payload) {
    if (!is_array($payload)) {
        $payload = [];
    }
    $ids = $payload['ids'] ?? [];
    if (!is_array($ids) || $ids === []) {
        return $payload;
    }
    $typeByMove = is_array($payload['type_by_move'] ?? null) ? $payload['type_by_move'] : [];
    $byId = BankBadges::forMoves($ids, $typeByMove);
    $payload['by_id'] = array_replace(
        is_array($payload['by_id'] ?? null) ? $payload['by_id'] : [],
        $byId
    );
    return $payload;
});
