<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/TerminalsConfig.php';
require_once __DIR__ . '/lib/JobsAuth.php';
require_once __DIR__ . '/lib/TerminalsRepository.php';
require_once __DIR__ . '/lib/BraceletsRepository.php';
require_once __DIR__ . '/lib/ClientOnecBindingsRepository.php';
require_once __DIR__ . '/lib/StaffBraceletsRepository.php';
require_once __DIR__ . '/lib/TermloadsRepository.php';
require_once __DIR__ . '/lib/FullstatsRepository.php';
require_once __DIR__ . '/lib/OnecClient.php';
require_once __DIR__ . '/lib/TerminalBooking.php';
require_once __DIR__ . '/lib/TerminalFloorHome.php';
require_once __DIR__ . '/lib/TerminalFloorCatalog.php';
require_once __DIR__ . '/lib/TerminalFloor.php';

Assets::register('terminals', [
    'css' => ['terminals.css'],
    'js' => ['phone-ru.js'],
]);

ModuleManager::registerMenuGroup('terminals.menu.group', 38, '/terminals');

$pages = [
    '/terminals' => ['permission' => 'terminals.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/terminals/new' => ['permission' => 'terminals.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/terminals/{id}/edit' => ['permission' => 'terminals.view', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/terminals/bracelets' => ['permission' => 'terminals.view', 'methods' => ['GET', 'POST'], 'file' => 'bracelets_list.php'],
    '/terminals/bracelets/new' => ['permission' => 'terminals.edit', 'methods' => ['GET', 'POST'], 'file' => 'bracelets_edit.php'],
    '/terminals/bracelets/{id}/edit' => ['permission' => 'terminals.view', 'methods' => ['GET', 'POST'], 'file' => 'bracelets_edit.php'],
    '/terminals/termloads' => ['permission' => 'terminals.view', 'methods' => ['GET'], 'file' => 'termloads.php'],
    '/terminals/fullstats' => ['permission' => 'terminals.view', 'methods' => ['GET'], 'file' => 'fullstats_list.php'],
    '/terminals/fullstats/{id}/edit' => ['permission' => 'terminals.edit', 'methods' => ['GET', 'POST'], 'file' => 'fullstats_edit.php'],
    '/terminals/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/terminals/t/status' => ['permission' => 'terminals.view', 'methods' => ['GET'], 'file' => 't_status.php'],
    '/terminals/floor' => ['permission' => 'terminals.edit', 'methods' => ['GET', 'POST'], 'file' => 'floor_book.php'],
    '/t' => ['public' => true, 'methods' => ['GET', 'POST'], 'file' => 't.php'],
    '/terminals/jobs/load' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_load.php'],
    '/terminals/jobs/tickets' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_tickets.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'terminals',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/terminals/pages/' . $file);
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

BulkActions::register('terminals', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'terminals.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => TerminalsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('terminals_bracelets', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'terminals.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => BraceletsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

Cron::register('terminals.load', [
    'module' => 'terminals',
    'label' => 'terminals.cron.load',
    'every' => 5,
    'handler' => static function (): array {
        if (!TerminalsConfig::syncEnabled()) {
            return ['ok' => true, 'skipped' => 1, 'message' => 'sync disabled'];
        }
        if (!TerminalsConfig::onecLive()) {
            return [
                'ok' => true,
                'skipped' => 1,
                'message' => TerminalsConfig::onecEnabled() ? '1c config incomplete' : '1c disabled',
            ];
        }
        $res = OnecClient::workload();
        if (empty($res['ok'])) {
            return ['ok' => false, 'message' => (string)($res['error'] ?? '1c failed')];
        }
        $count = (int)($res['count'] ?? 0);
        $written = 0;
        if ($count > 0) {
            $minute = (int)mktime((int)date('H'), (int)date('i'), 0);
            TermloadsRepository::upsertMinute($minute, $count);
            $written = 1;
        }
        return [
            'ok' => true,
            'processed' => $written,
            'written' => $written,
            'message' => 'count=' . $count,
        ];
    },
]);

Cron::register('terminals.tickets', [
    'module' => 'terminals',
    'label' => 'terminals.cron.tickets',
    'every' => 15,
    'handler' => static function (): array {
        if (!TerminalsConfig::syncEnabled()) {
            return ['ok' => true, 'skipped' => 1, 'message' => 'sync disabled'];
        }
        if (!TerminalsConfig::onecLive()) {
            return [
                'ok' => true,
                'skipped' => 1,
                'message' => TerminalsConfig::onecEnabled() ? '1c config incomplete' : '1c disabled',
            ];
        }
        $day = date('Y-m-d');
        $res = OnecClient::workloadDetail($day);
        if (empty($res['ok'])) {
            return ['ok' => false, 'message' => (string)($res['error'] ?? '1c failed')];
        }
        $rows = $res['rows'] ?? [];
        $dayStart = (int)strtotime($day . ' 00:00:00');
        $n = FullstatsRepository::replaceDay($dayStart, is_array($rows) ? $rows : []);
        return [
            'ok' => true,
            'processed' => $n,
            'imported' => $n,
            'message' => 'day=' . $day,
        ];
    },
]);

Health::register('terminals.worker_token', 'terminals', static function (): array {
    if (TerminalsConfig::workerTokenConfigured()) {
        return ['status' => 'ok', 'message' => 'worker_token configured'];
    }
    return [
        'status' => 'skipped',
        'error_code' => 'terminals_worker_token_unconfigured',
        'message' => 'terminals.worker_token missing or CHANGE_ME — jobs will reject until set',
        'meta' => ['hint' => '/terminals/settings'],
    ];
}, 'Terminals job worker token');

Health::register('terminals.onec', 'terminals', static function (): array {
    if (!TerminalsConfig::onecEnabled()) {
        return [
            'status' => 'ok',
            'message' => '1C disabled — stub/debug mode on /t (no live sale)',
            'meta' => ['hint' => '/terminals/settings', '1c_enabled' => false],
        ];
    }
    if (!TerminalsConfig::syncEnabled()) {
        return ['status' => 'ok', 'message' => 'sync disabled; 1C floor still live if credentials set'];
    }
    if (TerminalsConfig::onecReady()) {
        return ['status' => 'ok', 'message' => '1C enabled + config present'];
    }
    return [
        'status' => 'degraded',
        'message' => '1c_enabled but 1c_url/auth/apikey/terminal incomplete',
        'meta' => ['hint' => '/terminals/settings'],
    ];
}, '1C club API config');

EventBus::on('catalog.product_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (!Database::tableExists('base_products') || !Database::columnExists('base_products', 'onec_id')) {
        return $payload;
    }
    if (!class_exists(ProductsRepository::class)) {
        return $payload;
    }
    $row = is_array($payload['row'] ?? null) ? $payload['row'] : [];
    $productOnecId = (string)($row['onec_id'] ?? '');
    ob_start();
    require __DIR__ . '/views/_product_edit_onec.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('catalog.product_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $id = (int)($payload['product_id'] ?? 0);
    if ($id < 1 || !class_exists(ProductsRepository::class)) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    // Posted only while terminals section is on the form; empty clears the id.
    if (!array_key_exists('onec_id', $post)) {
        return $payload;
    }
    ProductsRepository::setOnecId($id, (string)$post['onec_id']);
    return $payload;
});

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user) || !StaffBraceletsRepository::tableReady()) {
        return $payload;
    }
    $row = StaffBraceletsRepository::findByUserId((int)($user['id'] ?? 0));
    $staffBraceletUid = $row !== null ? (string)$row['uid'] : '';
    ob_start();
    require __DIR__ . '/views/_user_edit_bracelet.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (!StaffBraceletsRepository::tableReady()) {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);
    if ($id < 1) {
        return $payload;
    }
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    // Persist into terminals table only — do not patch UsersRepository $data.
    StaffBraceletsRepository::setForUser($id, trim((string)($post['staff_bracelet_uid'] ?? '')));
    return $payload;
});

EventBus::on('core.user_edit.profile', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user) || !StaffBraceletsRepository::tableReady()) {
        return $payload;
    }
    $row = StaffBraceletsRepository::findByUserId((int)($user['id'] ?? 0));
    $staffBraceletUid = $row !== null ? (string)$row['uid'] : '';
    ob_start();
    require __DIR__ . '/views/_user_profile_bracelet.php';
    $payload['html_channels'] = ($payload['html_channels'] ?? '') . (string)ob_get_clean();
    return $payload;
});
