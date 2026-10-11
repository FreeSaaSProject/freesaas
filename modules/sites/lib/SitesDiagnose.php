<?php
declare(strict_types=1);

/**
 * Machine-readable storefront diagnosis for agents (admin session JSON).
 * Product overlays (e.g. LikeSpa FREE_TICKET) subscribe to EventBus `sites.diagnose`.
 */
final class SitesDiagnose
{
    /**
     * @return array<string,mixed>
     */
    public static function report(): array
    {
        // Soft deps owned by sites optionalRequires + booking slot stack (not product packs).
        $soft = [
            'catalog', 'orders', 'clients', 'booking',
            'workweeks', 'calendar', 'procedures', 'rates',
        ];
        $modules = [];
        foreach ($soft as $id) {
            $cfg = class_exists(ModuleManager::class, false) && ModuleManager::isConfigEnabled($id);
            $boot = class_exists(ModuleManager::class, false) && ModuleManager::isEnabled($id);
            $modules[$id] = [
                'config_enabled' => $cfg,
                'booted' => $boot,
            ];
        }

        $hosts = [];
        $hostsTotal = 0;
        $hasHostFtCol = SiteHostsRepository::tableReady()
            && Database::columnExists('base_site_hosts', 'free_ticket');
        if (SiteHostsRepository::tableReady()) {
            $list = SiteHostsRepository::list(['limit' => 200, 'order' => 'is_default DESC, num ASC, host ASC']);
            $hostsTotal = (int)$list['total'];
            foreach ($list['items'] as $h) {
                $theme = trim((string)($h['theme'] ?? 'default'));
                if ($theme === '') {
                    $theme = 'default';
                }
                $row = [
                    'id' => (int)$h['id'],
                    'host' => (string)$h['host'],
                    'enabled' => !empty($h['enabled']),
                    'is_default' => !empty($h['is_default']),
                    'theme' => $theme,
                    'theme_ok' => is_dir(base_path('themes/' . $theme)),
                    'promo_id' => (int)($h['promo_id'] ?? 0),
                    'catalog_root_ids' => is_array($h['catalog_root_ids'] ?? null)
                        ? array_values(array_map('intval', $h['catalog_root_ids']))
                        : [],
                    'tel' => (string)($h['tel'] ?? ''),
                    'name' => (string)($h['name'] ?? ''),
                ];
                // Opaque host column (sites schema); meaning filled by product modules via EventBus.
                if ($hasHostFtCol) {
                    $row['free_ticket'] = !empty($h['free_ticket']);
                }
                $hosts[] = $row;
            }
        }

        $bookingClasses = [
            'BookingAvailability' => class_exists(BookingAvailability::class, false),
            'BookingBook' => class_exists(BookingBook::class, false),
            'BookingCatalog' => class_exists(BookingCatalog::class, false),
            'BookingSlots' => class_exists(BookingSlots::class, false),
        ];
        $bookingActive = class_exists(SitesBooking::class, false) && SitesBooking::active();
        $bookableCount = 0;
        if ($bookingClasses['BookingCatalog']) {
            try {
                $bookableCount = count(BookingCatalog::listServices());
            } catch (Throwable $e) {
                $bookableCount = -1;
            }
        }

        $tables = [
            'base_site_hosts' => Database::tableExists('base_site_hosts'),
            'base_page_categories' => Database::tableExists('base_page_categories'),
            'base_promopages' => Database::tableExists('base_promopages'),
            'base_redirects' => Database::tableExists('base_redirects'),
            'catalog_root_ids_col' => Database::tableExists('base_site_hosts')
                && Database::columnExists('base_site_hosts', 'catalog_root_ids'),
            'free_ticket_col' => $hasHostFtCol,
        ];

        $issues = [];
        if (!$tables['base_site_hosts']) {
            $issues[] = ['level' => 'fail', 'code' => 'hosts_table_missing', 'message' => 'base_site_hosts missing — run sites migrations'];
        } elseif ($hostsTotal < 1) {
            $issues[] = ['level' => 'warn', 'code' => 'no_hosts', 'message' => 'No host rows; storefront falls back to ini public_host'];
        }
        foreach ($hosts as $h) {
            if (empty($h['theme_ok'])) {
                $issues[] = [
                    'level' => 'fail',
                    'code' => 'theme_missing',
                    'message' => 'Theme missing for host ' . $h['host'] . ': ' . $h['theme'],
                    'host_id' => $h['id'],
                ];
            }
        }
        if (!empty($modules['booking']['config_enabled']) && !$bookingActive) {
            $issues[] = [
                'level' => 'warn',
                'code' => 'booking_not_active',
                'message' => 'booking config on but SitesBooking::active() is false (boot slot deps: workweeks/calendar/procedures/rates)',
            ];
        }
        if ($bookingActive && $bookableCount === 0) {
            $issues[] = [
                'level' => 'warn',
                'code' => 'no_bookable_services',
                'message' => 'Booking active but BookingCatalog has zero services',
            ];
        }
        foreach (['catalog', 'orders', 'clients'] as $need) {
            if (empty($modules[$need]['config_enabled'])) {
                $issues[] = [
                    'level' => 'warn',
                    'code' => 'soft_dep_off',
                    'message' => $need . ' disabled — shop/cart/checkout incomplete',
                    'module' => $need,
                ];
            }
        }

        $report = [
            'ok' => true,
            'status' => 'ok',
            'role' => class_exists(AppRuntime::class, false) ? AppRuntime::role() : 'unknown',
            'admin_host' => class_exists(AppRuntime::class, false) ? AppRuntime::adminHost() : '',
            'public_host' => class_exists(AppRuntime::class, false) ? AppRuntime::publicHost() : '',
            'site_php' => is_file(base_path('site.php')),
            'tables' => $tables,
            'modules' => $modules,
            'hosts_total' => $hostsTotal,
            'hosts' => $hosts,
            'booking' => [
                'active' => $bookingActive,
                'classes' => $bookingClasses,
                'bookable_services' => $bookableCount,
                'public_routes' => [
                    'cart' => '/cart',
                    'availability' => '/cart/availability?mode=dates|day|masters',
                    'checkout' => 'POST /cart/checkout',
                ],
                'staff_ops' => [
                    'availability' => '/booking/availability',
                    'book' => 'POST /booking/book',
                ],
            ],
            'themes' => class_exists(SiteTheme::class, false) ? SiteTheme::listIds() : [],
            'issues' => $issues,
            'hints' => [
                'docs' => 'docs/agents/sites.md',
                'health' => 'sites.storefront (php bin/freesaas.php health)',
                'cart_dates' => 'GET /cart/availability?mode=dates&product_ids=… (storefront Host)',
            ],
        ];

        if (class_exists(EventBus::class, false)) {
            $report = EventBus::emit('sites.diagnose', $report);
            if (!is_array($report)) {
                $report = [];
            }
        }

        $issues = is_array($report['issues'] ?? null) ? $report['issues'] : [];
        $status = 'ok';
        foreach ($issues as $iss) {
            if (($iss['level'] ?? '') === 'fail') {
                $status = 'fail';
                break;
            }
            if (($iss['level'] ?? '') === 'warn') {
                $status = 'degraded';
            }
        }
        $report['status'] = $status;
        $report['ok'] = $status !== 'fail';
        $report['issues'] = $issues;

        return $report;
    }
}
