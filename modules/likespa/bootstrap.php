<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/LikespaConfig.php';
require_once __DIR__ . '/lib/TermScansRepository.php';
require_once __DIR__ . '/lib/PromoterBonus.php';
require_once __DIR__ . '/lib/DopsStats.php';
require_once __DIR__ . '/lib/CalendarOverlay.php';
require_once __DIR__ . '/lib/ProcedureRestoresRepository.php';
require_once __DIR__ . '/lib/SystemFlyerPromo.php';
require_once __DIR__ . '/lib/FreeTicket.php';
require_once __DIR__ . '/lib/LikespaLegacyApi.php';
require_once __DIR__ . '/lib/ProcedureFloorLinks.php';
require_once __DIR__ . '/lib/StaffDebtsRunner.php';

Assets::register('likespa', [
    'css' => ['likespa.css'],
]);

ModuleManager::registerMenuGroup('likespa.menu.group', 36, '/likespa/term-scans');

$pages = [
    '/likespa/term-scans' => [
        'permission' => 'likespa.view',
        'methods' => ['GET'],
        'file' => 'term_scans.php',
    ],
    '/likespa/promo/bonus' => [
        'permission' => 'likespa.promo',
        'methods' => ['GET', 'POST'],
        'file' => 'promo_bonus.php',
    ],
    '/likespa/restores' => [
        'permission' => 'likespa.restores',
        'methods' => ['GET', 'POST'],
        'file' => 'restores.php',
    ],
    '/likespa/restores/new' => [
        'permission' => 'likespa.restores',
        'methods' => ['GET', 'POST'],
        'file' => 'restore_edit.php',
    ],
    '/likespa/restores/{id}/edit' => [
        'permission' => 'likespa.restores',
        'methods' => ['GET', 'POST'],
        'file' => 'restore_edit.php',
    ],
    '/likespa/staff-debts' => [
        'permission' => 'likespa.edit',
        'methods' => ['GET', 'POST'],
        'file' => 'staff_debts.php',
    ],
    '/likespa/settings' => [
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
    '/likespa/legacy-api/status' => [
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET'],
        'file' => 'legacy_api_status.php',
    ],
    // Always registered; handlers 503 when legacy_api_enabled=0 (check-routes / cutover).
    '/api_termy.php' => [
        'public' => true,
        'methods' => ['GET'],
        'file' => 'api_termy.php',
    ],
    '/api.php' => [
        'public' => true,
        'methods' => ['GET'],
        'file' => 'api_full.php',
    ],
    '/api1c.php' => [
        'public' => true,
        'methods' => ['GET', 'POST', 'PUT', 'PATCH'],
        'file' => 'api1c.php',
    ],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'likespa',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/likespa/pages/' . $file);
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

Cron::register('likespa.staff_debts', [
    'module' => 'likespa',
    'label' => 'likespa.cron.staff_debts',
    'daily_at' => LikespaConfig::staffDebtsDailyAt(),
    'handler' => static function (): array {
        $r = StaffDebtsRunner::run();
        return [
            'ok' => !empty($r['ok']),
            'processed' => (int)($r['posted'] ?? 0),
            'skipped' => (int)($r['skipped'] ?? 0),
            'message' => (string)($r['message'] ?? ''),
            'scanned' => (int)($r['scanned'] ?? 0),
            'with_debt' => (int)($r['with_debt'] ?? 0),
            'errors' => (int)($r['errors'] ?? 0),
        ];
    },
]);

EventBus::on('sites.diagnose', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $diag = LikespaLegacyApi::diagnose();
    $payload['likespa_legacy_api'] = $diag;
    if (!empty($diag['issues']) && is_array($payload['issues'] ?? null)) {
        foreach ($diag['issues'] as $issue) {
            $payload['issues'][] = 'likespa_legacy:' . $issue;
        }
    }
    return $payload;
});

BulkActions::register('likespa_restores', 'delete', [
    'label' => 'core.bulk.delete',
    'capability' => 'SUPERADMIN_ACCESS',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ProcedureRestoresRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('audio_reports.calendar.cell', static function ($payload) {
    return CalendarOverlay::onCell($payload);
});

EventBus::on('procedures.list.row', static function ($payload) {
    return ProcedureFloorLinks::onListRow($payload);
});

EventBus::on('sites.cart.booking', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    return FreeTicket::onCartBooking($payload);
});

EventBus::on('sites.diagnose', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    return FreeTicket::onDiagnose($payload);
});

EventBus::on('sites.host_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    return FreeTicket::onHostEditSections($payload);
});

EventBus::on('sites.host_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    return FreeTicket::onHostEditSave($payload);
});

EventBus::on('booking.book.before', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    return FreeTicket::onBookBefore($payload);
});

Health::register('likespa.free_ticket', 'likespa', static function (): array {
    $th = FreeTicket::threshold();
    $hostsWith = 0;
    if (class_exists(SiteHostsRepository::class, false)
        && SiteHostsRepository::tableReady()
        && Database::columnExists('base_site_hosts', 'free_ticket')
    ) {
        foreach (SiteHostsRepository::list(['limit' => 200])['items'] as $h) {
            if (!empty($h['free_ticket'])) {
                $hostsWith++;
            }
        }
    }
    $meta = [
        'threshold' => $th,
        'hosts_with_flag' => $hostsWith,
        'diagnose' => '/sites/status?format=json',
    ];
    if ($th < 1) {
        return ['status' => 'ok', 'message' => 'FREE_TICKET off', 'meta' => $meta];
    }
    if ($hostsWith < 1) {
        return [
            'status' => 'degraded',
            'message' => 'threshold set but no host free_ticket=1',
            'meta' => $meta,
        ];
    }
    return ['status' => 'ok', 'message' => 'FREE_TICKET ready', 'meta' => $meta];
}, 'FREE_TICKET threshold / hosts');

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_edit_site.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $data['link_ok'] = trim((string)($post['link_ok'] ?? ''));
    $data['photo'] = trim((string)($post['photo'] ?? ''));
    $data['site_descr'] = trim((string)($post['site_descr'] ?? ''));
    $payload['data'] = $data;
    return $payload;
});

EventBus::on('core.user_edit.profile', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_profile_site.php';
    $payload['html_site'] = ($payload['html_site'] ?? '') . (string)ob_get_clean();
    return $payload;
});
