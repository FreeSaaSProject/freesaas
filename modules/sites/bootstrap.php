<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/SiteTheme.php';
require_once __DIR__ . '/lib/SiteHostsRepository.php';
require_once __DIR__ . '/lib/SitesHostContext.php';
require_once __DIR__ . '/lib/SitesCart.php';
require_once __DIR__ . '/lib/SitesGuest.php';
require_once __DIR__ . '/lib/SitesBooking.php';
require_once __DIR__ . '/lib/SitesCatalog.php';
require_once __DIR__ . '/lib/SitesDiagnose.php';
require_once __DIR__ . '/lib/SitesFront.php';
require_once __DIR__ . '/lib/PageCategoriesRepository.php';
require_once __DIR__ . '/lib/PromopageModules.php';
require_once __DIR__ . '/lib/PromopageRenderer.php';
require_once __DIR__ . '/lib/PromopagesRepository.php';
require_once __DIR__ . '/lib/RedirectsRepository.php';
require_once __DIR__ . '/lib/SystemSite.php';

Assets::register('sites', [
    'css' => ['sites.css'],
]);

ModuleManager::registerMenuGroup('sites.menu.group', 34, '/sites/hosts');

$adminPages = [
    '/sites/hosts' => [
        'permission' => 'sites.view',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'hosts.php',
    ],
    '/sites/hosts/new' => [
        'permission' => 'sites.edit',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'host_edit.php',
    ],
    '/sites/hosts/{id}/edit' => [
        'permission' => 'sites.view',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'host_edit.php',
    ],
    '/sites/settings' => [
        'permission' => 'sites.view',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'settings.php',
    ],
    '/sites/status' => [
        'permission' => 'sites.view',
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET'],
        'file' => 'status.php',
    ],
    '/sites/seo' => [
        'capability' => 'SUPERADMIN_ACCESS',
        'methods' => ['GET', 'POST'],
        'file' => 'seo.php',
    ],
    '/sites/promopages' => [
        'permission' => 'sites.promopages',
        'methods' => ['GET', 'POST'],
        'file' => 'promopages.php',
    ],
    '/sites/promopages/new' => [
        'permission' => 'sites.promopages',
        'methods' => ['GET', 'POST'],
        'file' => 'promopage_edit.php',
    ],
    '/sites/promopages/{id}/edit' => [
        'permission' => 'sites.promopages',
        'methods' => ['GET', 'POST'],
        'file' => 'promopage_edit.php',
    ],
    '/sites/pages' => [
        'permission' => 'sites.pages',
        'methods' => ['GET', 'POST'],
        'file' => 'pages_list.php',
    ],
    '/sites/pages/new' => [
        'permission' => 'sites.pages',
        'methods' => ['GET', 'POST'],
        'file' => 'page_edit.php',
    ],
    '/sites/pages/{id}/edit' => [
        'permission' => 'sites.pages',
        'methods' => ['GET', 'POST'],
        'file' => 'page_edit.php',
    ],
    '/sites/redirects' => [
        'permission' => 'sites.redirects',
        'methods' => ['GET', 'POST'],
        'file' => 'redirects.php',
    ],
    '/sites/redirects/new' => [
        'permission' => 'sites.redirects',
        'methods' => ['GET', 'POST'],
        'file' => 'redirect_edit.php',
    ],
    '/sites/redirects/{id}/edit' => [
        'permission' => 'sites.redirects',
        'methods' => ['GET', 'POST'],
        'file' => 'redirect_edit.php',
    ],
];
foreach ($adminPages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'sites',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/sites/pages/' . $file);
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

BulkActions::register('sites_hosts', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'sites.edit',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => SiteHostsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('sites_promopages', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'sites.promopages',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        $skipped = [];
        $allowed = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if (PromopagesRepository::cloneCount($id) > 0) {
                $skipped[] = ['id' => $id, 'reason' => t('sites.promopages.has_clones_hint')];
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

BulkActions::register('sites_pages', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'sites.pages',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        $skipped = [];
        $allowed = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if (PageCategoriesRepository::childCount($id) > 0) {
                $skipped[] = ['id' => $id, 'reason' => t('sites.pages.has_children_hint')];
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

BulkActions::register('sites_redirects', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'sites.redirects',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RedirectsRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

// Public storefront routes (available on admin too for preview, except `/` and `/login`).
$publicPages = [
    // CMS articles: /page/… resolved in router.not_found (hierarchical paths).
    // /p/… reserved for client poll/SMS short links — do not register here.
    '/shop' => ['methods' => ['GET', 'POST'], 'file' => 'shop.php'],
    '/shop/c/{id}' => ['methods' => ['GET', 'POST'], 'file' => 'shop_category.php'],
    '/shop/{id}' => ['methods' => ['GET', 'POST'], 'file' => 'product.php'],
    '/cart' => ['methods' => ['GET', 'POST'], 'file' => 'cart.php'],
    '/cart/availability' => ['methods' => ['GET'], 'file' => 'cart_availability.php'],
    '/cart/checkout' => ['methods' => ['POST'], 'file' => 'checkout.php'],
    '/promo/{code}' => ['methods' => ['GET'], 'file' => 'promo.php'],
    '/sitemap.xml' => ['methods' => ['GET'], 'file' => 'sitemap.php'],
    '/robots.txt' => ['methods' => ['GET'], 'file' => 'robots.php'],
];
foreach ($publicPages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'sites',
        'methods' => $def['methods'],
        'public' => true,
        'handler' => static function () use ($file): void {
            require base_path('modules/sites/pages/' . $file);
        },
    ]);
}

if (AppRuntime::isStorefront()) {
    ModuleManager::registerRoute('/login', [
        'module' => 'sites',
        'methods' => ['GET', 'POST'],
        'public' => true,
        'handler' => static function (): void {
            SitesFront::redirectLoginToAdmin();
        },
    ]);

    ModuleManager::registerRoute('/', [
        'module' => 'sites',
        'methods' => ['GET'],
        'public' => true,
        'handler' => static function (): void {
            require base_path('modules/sites/pages/home.php');
        },
    ]);

    EventBus::on('router.not_found', static function (array $payload): array {
        if (!empty($payload['handled'])) {
            return $payload;
        }
        $path = (string)($payload['path'] ?? '');
        $page = SitesFront::findPageByPath($path);
        if ($page !== null) {
            SitesFront::renderPage($page);
            $payload['handled'] = true;
            return $payload;
        }
        SitesFront::renderNotFound($path);
        $payload['handled'] = true;
        return $payload;
    });
}

Health::register('sites.storefront', 'sites', static function (): array {
    if (AppRuntime::isStorefront()) {
        SitesHostContext::boot();
    }
    $theme = SiteTheme::id();
    $themeOk = is_dir(base_path('themes/' . $theme));
    $hostsCount = 0;
    if (SiteHostsRepository::tableReady()) {
        $hostsCount = SiteHostsRepository::list(['limit' => 1])['total'];
    }
    $bookingActive = class_exists(SitesBooking::class, false) && SitesBooking::active();
    $meta = [
        'role' => AppRuntime::role(),
        'theme' => $theme,
        'theme_ok' => $themeOk,
        'public_host' => AppRuntime::publicHost(),
        'host_id' => SitesHostContext::id(),
        'admin_host' => AppRuntime::adminHost(),
        'hosts_count' => $hostsCount,
        'site_php' => is_file(base_path('site.php')),
        'booking_active' => $bookingActive,
        'diagnose' => '/sites/status?format=json',
        'tables' => [
            'hosts' => Database::tableExists('base_site_hosts'),
            'pages' => Database::tableExists('base_page_categories'),
            'promopages' => Database::tableExists('base_promopages'),
            'redirects' => Database::tableExists('base_redirects'),
        ],
    ];
    if (!$themeOk) {
        return [
            'status' => 'fail',
            'message' => 'theme missing: ' . $theme,
            'meta' => $meta,
        ];
    }
    if (AppRuntime::isStorefront() && !ModuleManager::isEnabled('sites')) {
        return [
            'status' => 'fail',
            'message' => 'sites module not booted in storefront role',
            'meta' => $meta,
        ];
    }
    if ($hostsCount < 1 && AppRuntime::publicHost() === '') {
        return [
            'status' => 'degraded',
            'message' => 'no base_site_hosts and no public_host fallback',
            'meta' => $meta,
        ];
    }
    return [
        'status' => 'ok',
        'message' => 'storefront ready',
        'meta' => $meta,
    ];
}, 'Storefront / theme');
