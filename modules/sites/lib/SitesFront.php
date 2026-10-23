<?php
declare(strict_types=1);

/**
 * Storefront request helpers: redirects, CMS resolve, nav, site meta.
 */
final class SitesFront
{
    /** Resolve host + apply enabled base_redirects before Router::dispatch. */
    public static function beforeDispatch(): void
    {
        if (!AppRuntime::isStorefront()) {
            return;
        }
        if (class_exists(SitesHostContext::class)) {
            SitesHostContext::boot();
        }
        $path = request_path();
        $target = self::matchRedirect($path);
        if ($target === null && isset($_SERVER['REQUEST_URI'])) {
            $uriPath = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (is_string($uriPath) && $uriPath !== $path) {
                $target = self::matchRedirect($uriPath);
            }
        }
        if ($target !== null && $target !== '' && $target !== $path) {
            header('Location: ' . $target, true, 301);
            exit;
        }

        EventBus::emit('sites.request', [
            'path' => $path,
            'method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            'host_id' => class_exists(SitesHostContext::class) ? SitesHostContext::id() : 0,
            'host' => class_exists(SitesHostContext::class) ? SitesHostContext::host() : '',
        ]);
    }

    public static function matchRedirect(string $path): ?string
    {
        if (!class_exists('RedirectsRepository', false) || !Database::tableExists('base_redirects')) {
            return null;
        }
        $hostId = class_exists(SitesHostContext::class) ? SitesHostContext::id() : 0;
        $row = RedirectsRepository::findEnabledByOldUrl($path, $hostId);
        if ($row === null) {
            return null;
        }
        $new = trim((string)($row['url_new'] ?? ''));
        return $new !== '' ? $new : null;
    }

    /** @return array{name:string,title:string,keywords:string,tel:string,meta_descr:string} */
    public static function siteMeta(): array
    {
        $name = (string)Config::get('connect', 'sitename', 'FreeSaaS');
        $title = $name;
        $keywords = '';
        $tel = '';
        $metaDescr = '';
        $seoName = trim((string)Config::get('sites', 'seo_name', ''));
        if ($seoName !== '') {
            $name = $seoName;
        }
        $seoTitle = trim((string)Config::get('sites', 'seo_title', ''));
        if ($seoTitle !== '') {
            $title = $seoTitle;
        }
        $keywords = (string)Config::get('sites', 'seo_keywords', '');
        $tel = (string)Config::get('sites', 'tel', '');
        if (class_exists(SitesHostContext::class)) {
            $hn = trim(SitesHostContext::name());
            if ($hn !== '') {
                $name = $hn;
            }
            $ht = trim(SitesHostContext::tel());
            if ($ht !== '') {
                $tel = $ht;
            }
            $promoId = SitesHostContext::promoId();
            if ($promoId > 0 && class_exists(PromopagesRepository::class)) {
                $promo = PromopagesRepository::find($promoId);
                if ($promo) {
                    $mt = trim((string)($promo['meta_title'] ?? ''));
                    if ($mt !== '') {
                        $title = $mt;
                    } elseif (trim((string)($promo['descr'] ?? '')) !== '') {
                        $title = (string)$promo['descr'];
                    }
                    $metaDescr = (string)($promo['meta_descr'] ?? '');
                }
            }
        }
        return [
            'name' => $name,
            'title' => $title,
            'keywords' => $keywords,
            'tel' => $tel,
            'meta_descr' => $metaDescr,
        ];
    }

    /**
     * Public URL for a CMS page.
     * Hierarchy: /page/vacancies/masseur/.
     * link_rw kept for rewrites (/contacts/, /lk/, #anchors, external).
     * Note: /p/… is reserved for client poll/SMS short links — not CMS.
     */
    public static function pageHref(array $page): string
    {
        $link = trim((string)($page['link'] ?? ''));
        if ($link !== '' && ($link[0] === '#' || strpos($link, '/#') === 0)) {
            return $link[0] === '#' ? $link : $link;
        }
        $rw = trim((string)($page['link_rw'] ?? ''));
        if ($rw !== '') {
            if ($rw[0] === '#' || preg_match('#^https?://#i', $rw)) {
                return $rw;
            }
            // Explicit public rewrite (e.g. /contacts/, /lk/) — keep old bookmarks.
            if ($rw[0] === '/' && strpos($rw, '/page/') !== 0) {
                return $rw;
            }
        }
        return self::pageHierarchicalPath($page);
    }

    /** /page/{parent-link}/…/{link}/ from the article tree. */
    public static function pageHierarchicalPath(array $page): string
    {
        $segments = [];
        $id = (int)($page['id'] ?? 0);
        $guard = 0;
        while ($id > 0 && $guard++ < 20) {
            $row = $id === (int)($page['id'] ?? 0) && isset($page['link'])
                ? $page
                : (class_exists(PageCategoriesRepository::class) ? PageCategoriesRepository::find($id) : null);
            if ($row === null) {
                break;
            }
            $seg = trim((string)($row['link'] ?? ''), '/');
            if ($seg !== '' && $seg[0] !== '#') {
                array_unshift($segments, rawurlencode($seg));
            }
            $parent = (int)($row['parent_to'] ?? 0);
            if ($parent === PageCategoriesRepository::PARENT_ROOT
                || $parent === PageCategoriesRepository::PARENT_HOME) {
                break;
            }
            $id = $parent;
        }
        if ($segments === []) {
            return '#';
        }
        return '/page/' . implode('/', $segments) . '/';
    }

    /**
     * @return list<array{label:string,href:string,current?:bool,children?:list<array{label:string,href:string,current?:bool}>}>
     */
    public static function nav(string $current = '', string $currentPath = ''): array
    {
        if ($currentPath === '') {
            $currentPath = request_path();
        }
        $items = [];
        $hostId = class_exists(SitesHostContext::class) ? SitesHostContext::id() : 0;
        if (class_exists(PageCategoriesRepository::class) && Database::tableExists('base_page_categories')) {
            foreach (PageCategoriesRepository::menuTree($hostId) as $page) {
                $href = (string)$page['href'];
                $children = [];
                foreach ($page['children'] as $child) {
                    $ch = (string)$child['href'];
                    $children[] = [
                        'label' => (string)$child['name'],
                        'href' => $ch,
                        'current' => self::pathMatches($currentPath, $ch),
                    ];
                }
                $isCurrent = self::pathMatches($currentPath, $href);
                if (!$isCurrent) {
                    foreach ($children as $c) {
                        if (!empty($c['current'])) {
                            $isCurrent = true;
                            break;
                        }
                    }
                }
                $item = [
                    'label' => (string)$page['name'],
                    'href' => $href,
                    'current' => $isCurrent || ($current === 'page' && $isCurrent),
                ];
                if ($children !== []) {
                    $item['children'] = $children;
                }
                $items[] = $item;
            }
        }
        if ($items === []) {
            $items[] = ['label' => t('sites.nav.home'), 'href' => '/', 'current' => $current === 'home'];
            if (ModuleManager::isEnabled('catalog') && class_exists('ProductsRepository', false)) {
                $items[] = [
                    'label' => t('sites.nav.shop'),
                    'href' => '/shop',
                    'current' => $current === 'shop',
                ];
            }
        }
        $cartCount = SitesCart::count();
        $items[] = [
            'label' => t('sites.nav.cart') . ($cartCount > 0 ? ' (' . $cartCount . ')' : ''),
            'href' => '/cart',
            'current' => $current === 'cart',
            'cart' => true,
            'cart_count' => $cartCount,
        ];
        $payload = EventBus::emit('sites.nav', [
            'items' => $items,
            'current' => $current,
            'path' => $currentPath,
        ]);
        $out = $payload['items'] ?? $items;
        return is_array($out) ? $out : $items;
    }

    /**
     * Root CMS pages for footer columns (no children nesting).
     *
     * @return list<array{label:string,href:string}>
     */
    public static function footerPages(): array
    {
        $hostId = class_exists(SitesHostContext::class) ? SitesHostContext::id() : 0;
        if (!class_exists(PageCategoriesRepository::class) || !Database::tableExists('base_page_categories')) {
            return [];
        }
        $out = [];
        foreach (PageCategoriesRepository::menuTree($hostId) as $page) {
            $out[] = [
                'label' => (string)$page['name'],
                'href' => (string)$page['href'],
            ];
        }
        return $out;
    }

    /** @return list<array{label:string,href?:string,current?:bool}> */
    public static function pageBreadcrumbs(array $page): array
    {
        $hostId = class_exists(SitesHostContext::class) ? SitesHostContext::id() : 0;
        $trail = PageCategoriesRepository::breadcrumbTrail((int)($page['id'] ?? 0), $hostId);
        $crumbs = [
            ['label' => t('sites.nav.home'), 'href' => '/'],
        ];
        $last = count($trail) - 1;
        foreach ($trail as $i => $item) {
            if ($i === $last) {
                $crumbs[] = ['label' => (string)$item['name'], 'current' => true];
            } else {
                $crumbs[] = ['label' => (string)$item['name'], 'href' => (string)$item['href']];
            }
        }
        return $crumbs;
    }

    private static function pathMatches(string $path, string $href): bool
    {
        if ($href === '' || $href === '#' || $href[0] === '#') {
            return false;
        }
        if (preg_match('#^https?://#i', $href)) {
            return false;
        }
        $hrefPath = parse_url($href, PHP_URL_PATH);
        if (!is_string($hrefPath) || $hrefPath === '') {
            $hrefPath = $href;
        }
        $a = rtrim($path, '/') ?: '/';
        $b = rtrim($hrefPath, '/') ?: '/';
        return $a === $b;
    }

    public static function findPageBySlug(string $slug): ?array
    {
        $slug = trim($slug, '/');
        if ($slug === '' || !class_exists('PageCategoriesRepository', false)) {
            return null;
        }
        $hostId = class_exists(SitesHostContext::class) ? SitesHostContext::id() : 0;
        $segments = array_values(array_filter(explode('/', $slug), static function ($s) {
            return $s !== '';
        }));
        if ($segments === []) {
            return null;
        }
        return PageCategoriesRepository::findByLinkPath($segments, $hostId);
    }

    public static function findPageByPath(string $path): ?array
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return null;
        }
        if (!class_exists('PageCategoriesRepository', false)) {
            return null;
        }
        $hostId = class_exists(SitesHostContext::class) ? SitesHostContext::id() : 0;

        // Canonical hierarchy: /page/vacancies/masseur/
        if (preg_match('#^/page/(.+)$#i', $path, $m)) {
            $segments = array_values(array_filter(
                explode('/', rawurldecode(trim($m[1], '/'))),
                static function ($s) {
                    return $s !== '';
                }
            ));
            if ($segments !== []) {
                $row = PageCategoriesRepository::findByLinkPath($segments, $hostId);
                if ($row !== null) {
                    return $row;
                }
            }
        }

        // Rewrites / short paths: /contacts/, /vacancies/, …
        $candidates = [
            $path,
            ltrim($path, '/'),
            rtrim($path, '/') . '/',
        ];
        foreach ($candidates as $c) {
            $row = PageCategoriesRepository::findByLink($c, $hostId);
            if ($row !== null) {
                return $row;
            }
        }
        return null;
    }

    public static function renderPage(array $page): void
    {
        $payload = EventBus::emit('sites.page.render', [
            'page' => $page,
            'html' => (string)($page['content'] ?? ''),
        ]);
        $html = (string)($payload['html'] ?? ($page['content'] ?? ''));
        $meta = self::siteMeta();
        $path = self::pageHref($page);
        $hostId = class_exists(SitesHostContext::class) ? SitesHostContext::id() : 0;
        $children = PageCategoriesRepository::listChildrenForStorefront((int)($page['id'] ?? 0), $hostId);
        SiteTheme::render('page', [
            'site' => $meta,
            'nav' => self::nav('page', $path),
            'page' => $page,
            'html' => $html,
            'children' => $children,
            'title' => (string)($page['name'] ?? $meta['title']),
            'breadcrumbs' => self::pageBreadcrumbs($page),
        ]);
    }

    public static function renderNotFound(string $path = ''): void
    {
        http_response_code(404);
        $meta = self::siteMeta();
        SiteTheme::render('error', [
            'site' => $meta,
            'nav' => self::nav(''),
            'title' => t('sites.error.not_found_title'),
            'message' => t('sites.error.not_found_message'),
            'hint' => $path,
        ]);
    }

    /** Redirect staff login to admin host when configured. */
    public static function redirectLoginToAdmin(): void
    {
        $base = AppRuntime::adminBaseUrl();
        if ($base === '') {
            http_response_code(404);
            echo t('sites.login.redirect');
            exit;
        }
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        $url = $base . '/login' . ($qs !== '' ? ('?' . $qs) : '');
        header('Location: ' . $url, true, 302);
        exit;
    }
}
