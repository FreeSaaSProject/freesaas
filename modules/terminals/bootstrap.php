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
require_once __DIR__ . '/lib/TermOrdersRepository.php';
require_once __DIR__ . '/lib/OnecClient.php';
require_once __DIR__ . '/lib/TermOrdersImporter.php';

Assets::register('terminals', [
    'css' => ['terminals.css'],
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
    '/terminals/orders' => ['permission' => 'terminals.view', 'methods' => ['GET'], 'file' => 'orders_list.php'],
    '/terminals/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/terminals/jobs/load' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_load.php'],
    '/terminals/jobs/tickets' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_tickets.php'],
    '/terminals/jobs/orders' => ['public' => true, 'methods' => ['POST', 'GET'], 'file' => 'jobs_orders.php'],
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
        if (!TerminalsConfig::onecReady()) {
            return ['ok' => true, 'skipped' => 1, 'message' => '1c config incomplete'];
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
        if (!TerminalsConfig::onecReady()) {
            return ['ok' => true, 'skipped' => 1, 'message' => '1c config incomplete'];
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

Cron::register('terminals.orders', [
    'module' => 'terminals',
    'label' => 'terminals.cron.orders',
    'every' => 15,
    'handler' => static function (): array {
        if (!TerminalsConfig::syncEnabled()) {
            return ['ok' => true, 'skipped' => 1, 'message' => 'sync disabled'];
        }
        $res = TermOrdersImporter::run();
        if (empty($res['ok'])) {
            return ['ok' => false, 'message' => (string)($res['error'] ?? 'import failed')];
        }
        return [
            'ok' => true,
            'processed' => (int)($res['imported'] ?? $res['processed'] ?? 0),
            'message' => (string)($res['message'] ?? ''),
        ];
    },
]);

Health::register('terminals.worker_token', 'terminals', static function (): array {
    if (TerminalsConfig::workerTokenConfigured()) {
        return ['status' => 'ok', 'message' => 'worker_token configured'];
    }
    return [
        'status' => 'degraded',
        'message' => 'terminals.worker_token missing or CHANGE_ME — jobs will reject',
        'meta' => ['hint' => '/terminals/settings'],
    ];
}, 'Terminals job worker token');

Health::register('terminals.onec', 'terminals', static function (): array {
    if (!TerminalsConfig::syncEnabled()) {
        return ['status' => 'ok', 'message' => 'sync disabled'];
    }
    if (TerminalsConfig::onecReady()) {
        return ['status' => 'ok', 'message' => '1C config present'];
    }
    return [
        'status' => 'degraded',
        'message' => '1c_url/auth/apikey/terminal incomplete — load/tickets jobs will fail',
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
