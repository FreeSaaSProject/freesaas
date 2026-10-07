<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/LikespaConfig.php';
require_once __DIR__ . '/lib/TermScansRepository.php';
require_once __DIR__ . '/lib/PromoterBonus.php';
require_once __DIR__ . '/lib/DopsStats.php';
require_once __DIR__ . '/lib/CalendarOverlay.php';
require_once __DIR__ . '/lib/ProcedureRestoresRepository.php';
require_once __DIR__ . '/lib/PromopageModules.php';
require_once __DIR__ . '/lib/PromopagesRepository.php';
require_once __DIR__ . '/lib/PageCategoriesRepository.php';
require_once __DIR__ . '/lib/RedirectsRepository.php';
require_once __DIR__ . '/lib/SystemFlyerPromo.php';
require_once __DIR__ . '/lib/SystemSite.php';

Assets::register('likespa', [
    'css' => ['likespa.css'],
]);

ModuleManager::registerMenuGroup('likespa.menu.group', 36, '/likespa/site');

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
    '/likespa/site' => [
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'site.php',
    ],
    '/likespa/promopages' => [
        'permission' => 'likespa.promopages',
        'methods' => ['GET', 'POST'],
        'file' => 'promopages.php',
    ],
    '/likespa/promopages/new' => [
        'permission' => 'likespa.promopages',
        'methods' => ['GET', 'POST'],
        'file' => 'promopage_edit.php',
    ],
    '/likespa/promopages/{id}/edit' => [
        'permission' => 'likespa.promopages',
        'methods' => ['GET', 'POST'],
        'file' => 'promopage_edit.php',
    ],
    '/likespa/pages' => [
        'permission' => 'likespa.pages',
        'methods' => ['GET', 'POST'],
        'file' => 'pages_list.php',
    ],
    '/likespa/pages/new' => [
        'permission' => 'likespa.pages',
        'methods' => ['GET', 'POST'],
        'file' => 'page_edit.php',
    ],
    '/likespa/pages/{id}/edit' => [
        'permission' => 'likespa.pages',
        'methods' => ['GET', 'POST'],
        'file' => 'page_edit.php',
    ],
    '/likespa/redirects' => [
        'permission' => 'likespa.redirects',
        'methods' => ['GET', 'POST'],
        'file' => 'redirects.php',
    ],
    '/likespa/redirects/new' => [
        'permission' => 'likespa.redirects',
        'methods' => ['GET', 'POST'],
        'file' => 'redirect_edit.php',
    ],
    '/likespa/redirects/{id}/edit' => [
        'permission' => 'likespa.redirects',
        'methods' => ['GET', 'POST'],
        'file' => 'redirect_edit.php',
    ],
    '/likespa/settings' => [
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
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
    if (!empty($def['capability'])) {
        $route['capability'] = $def['capability'];
    }
    if (!empty($def['permission'])) {
        $route['permission'] = $def['permission'];
    }
    ModuleManager::registerRoute($path, $route);
}

BulkActions::register('likespa_restores', 'delete', [
    'label' => 'core.bulk.delete',
    'capability' => 'SUPERADMIN_ACCESS',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ProcedureRestoresRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('likespa_promopages', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'likespa.promopages',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        $skipped = [];
        $allowed = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if (PromopagesRepository::cloneCount($id) > 0) {
                $skipped[] = ['id' => $id, 'reason' => t('likespa.promopages.has_clones_hint')];
                continue;
            }
            $allowed[] = $id;
        }
        return [
            'done' => PromopagesRepository::deleteByIds($allowed),
            'skipped' => $skipped,
        ];
    },
]);

BulkActions::register('likespa_pages', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'likespa.pages',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        $skipped = [];
        $allowed = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if (PageCategoriesRepository::childCount($id) > 0) {
                $skipped[] = ['id' => $id, 'reason' => t('likespa.pages.has_children_hint')];
                continue;
            }
            $allowed[] = $id;
        }
        return [
            'done' => PageCategoriesRepository::deleteByIds($allowed),
            'skipped' => $skipped,
        ];
    },
]);

BulkActions::register('likespa_redirects', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'likespa.redirects',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RedirectsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('audio_reports.calendar.cell', static function ($payload) {
    return CalendarOverlay::onCell($payload);
});

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
