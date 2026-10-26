<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/BanksConfig.php';
require_once __DIR__ . '/lib/JobsAuth.php';
require_once __DIR__ . '/lib/BankHttp.php';
require_once __DIR__ . '/lib/BankConnectionsRepository.php';
require_once __DIR__ . '/lib/BanksIntegrationRepository.php';
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
require_once __DIR__ . '/lib/BankLedgerSyncUi.php';
require_once __DIR__ . '/lib/BanksCertStorage.php';
require_once __DIR__ . '/lib/BanksAlfaCsr.php';
require_once __DIR__ . '/lib/BanksEgressIp.php';
require_once __DIR__ . '/lib/BanksSetupWizard.php';
require_once __DIR__ . '/lib/BanksAgentOps.php';

Assets::register('banks', [
    'css' => ['banks.css'],
]);

BanksAgentOps::register();

$pages = [
    '/banks' => ['permission' => 'banks.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/banks/integrations/{id}' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'integration.php'],
    '/banks/setup/{provider}' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'setup.php'],
    '/banks/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/banks/settings/certs' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['POST'], 'file' => 'certs_upload.php'],
    '/banks/settings/csr' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'csr.php'],
    '/banks/settings/probe' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['POST', 'GET'], 'file' => 'probe.php'],
    '/banks/oauth/{provider}/start' => ['permission' => 'banks.connect', 'methods' => ['GET'], 'file' => 'oauth_start.php'],
    '/banks/oauth/{provider}/callback' => ['permission' => 'banks.connect', 'methods' => ['GET'], 'file' => 'oauth_callback.php'],
    '/banks/connections/{id}' => ['permission' => 'banks.view', 'methods' => ['GET', 'POST'], 'file' => 'connection.php'],
    '/banks/connections/{id}/sync' => ['permission' => 'banks.sync', 'methods' => ['POST', 'GET'], 'file' => 'sync.php'],
    '/banks/sync-account' => ['permission' => 'banks.sync', 'methods' => ['POST', 'GET'], 'file' => 'sync_account.php'],
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

Cron::register('banks.sync', [
    'module' => 'banks',
    'label' => 'banks.cron.sync',
    'every' => 30,
    'handler' => static function (): array {
        if (!BanksConfig::autoSyncEnabled()) {
            return [
                'ok' => true,
                'processed' => 0,
                'skipped' => 0,
                'message' => 'auto_sync_enabled=0',
            ];
        }
        $days = BanksConfig::autoSyncLookbackDays();
        $dateFrom = date('Y-m-d', strtotime('-' . $days . ' days'));
        $dateTo = date('Y-m-d');
        try {
            $stats = BankSyncService::syncAllEnabled($dateFrom, $dateTo);
            $errors = (int)($stats['errors'] ?? 0);
            return [
                'ok' => $errors === 0,
                'processed' => (int)($stats['created'] ?? 0),
                'skipped' => (int)($stats['skipped'] ?? 0),
                'message' => $dateFrom . '..' . $dateTo
                    . ' created=' . (int)($stats['created'] ?? 0)
                    . ' errors=' . $errors,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    },
]);

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
        'status' => 'skipped',
        'error_code' => 'banks_worker_token_unconfigured',
        'message' => 'banks.worker_token missing or CHANGE_ME — jobs will reject until set',
        'meta' => ['hint' => 'cfg/modules/banks.ini'],
    ];
}, 'Banks job worker token');

Health::register('banks.auto_sync', 'banks', static function (): array {
    if (!BanksConfig::autoSyncEnabled()) {
        return ['status' => 'ok', 'message' => 'auto sync disabled'];
    }
    $links = BankAccountLinksRepository::syncEnabledLinks();
    if ($links === []) {
        return ['status' => 'ok', 'message' => 'no sync-enabled links'];
    }
    $blocked = [];
    $seen = [];
    foreach ($links as $link) {
        $cid = (int)($link['connection_id'] ?? 0);
        if ($cid < 1 || isset($seen[$cid])) {
            continue;
        }
        $seen[$cid] = true;
        $conn = BankConnectionsRepository::find($cid, true);
        if (!$conn) {
            $blocked[] = '#' . $cid . ' missing';
            continue;
        }
        $provider = (string)($conn['provider'] ?? '');
        $access = trim((string)($conn['access_token'] ?? ''));
        $refresh = trim((string)($conn['refresh_token'] ?? ''));
        $expires = (int)($conn['expires_at'] ?? 0);
        $fresh = $access !== '' && ($expires === 0 || $expires > time() + 30);
        if ($fresh) {
            continue;
        }
        $canRefresh = $refresh !== '' && BanksConfig::providerConfigured($provider);
        if (!$canRefresh) {
            $blocked[] = BanksConfig::providerLabel($provider) . ' #' . $cid;
        }
    }
    if ($blocked !== []) {
        return [
            'status' => 'degraded',
            'message' => 'token expired and cannot refresh (need OAuth keys): ' . implode(', ', $blocked),
            'meta' => ['hint' => '/banks/settings'],
        ];
    }
    $cron = BanksConfig::autoSyncCronStatus();
    $msg = 'auto sync on, lookback=' . BanksConfig::autoSyncLookbackDays() . 'd';
    if (is_array($cron) && ($cron['last_status'] ?? '') !== '') {
        $msg .= ', last=' . (string)$cron['last_status'];
    }
    return ['status' => 'ok', 'message' => $msg];
}, 'Banks auto sync tokens');

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

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check() || !Auth::can('ledger.view')) {
        return $notices;
    }
    $n = BankTxMapRepository::countUnclassified();
    if ($n > 0) {
        $notices[] = [
            'short' => t('banks.notice.review_action') . ':' . $n,
            'href' => '/ledger/moves?bank_review=1',
            'title' => t('banks.notice.review'),
            'tone' => 'warn',
        ];
    }
    return $notices;
});

EventBus::on('ledger.moves.list.toolbar', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $accountId = (int)($payload['account_id'] ?? 0);
    $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
    $html = BankLedgerSyncUi::toolbarHtml($accountId, $filters);
    if ($html !== '') {
        Assets::need('banks');
        if (!isset($payload['html']) || !is_array($payload['html'])) {
            $payload['html'] = [];
        }
        $payload['html'][] = $html;
    }
    return $payload;
});
