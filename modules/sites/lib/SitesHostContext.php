<?php
declare(strict_types=1);

/**
 * Current public storefront host (HTTP_HOST → base_site_hosts).
 */
final class SitesHostContext
{
    /** @var array<string,mixed>|null */
    private static $row = null;

    private static $resolved = false;

    public static function boot(?string $httpHost = null): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;

        if (!class_exists(SiteHostsRepository::class) || !SiteHostsRepository::tableReady()) {
            self::$row = self::iniFallbackRow($httpHost);
            return;
        }

        $raw = $httpHost;
        if ($raw === null || $raw === '') {
            $raw = (string)($_SERVER['HTTP_HOST'] ?? '');
        }
        $host = SiteHostsRepository::normalizeHost($raw);
        $found = $host !== '' ? SiteHostsRepository::findByHost($host) : null;
        if ($found === null) {
            $found = SiteHostsRepository::findDefault();
        }
        if ($found === null) {
            self::$row = self::iniFallbackRow($raw);
            return;
        }
        self::$row = $found;
    }

    public static function reset(): void
    {
        self::$row = null;
        self::$resolved = false;
    }

    /** @return array<string,mixed> */
    public static function current(): array
    {
        if (!self::$resolved) {
            self::boot();
        }
        return self::$row ?? self::iniFallbackRow(null);
    }

    public static function id(): int
    {
        return (int)(self::current()['id'] ?? 0);
    }

    public static function host(): string
    {
        return (string)(self::current()['host'] ?? '');
    }

    public static function theme(): string
    {
        $t = trim((string)(self::current()['theme'] ?? ''));
        return $t !== '' ? $t : 'default';
    }

    public static function promoId(): int
    {
        return (int)(self::current()['promo_id'] ?? 0);
    }

    public static function catalogRootId(): int
    {
        $ids = self::catalogRootIds();
        return $ids[0] ?? 0;
    }

    /** @return list<int> empty = entire catalog */
    public static function catalogRootIds(): array
    {
        $row = self::current();
        if (isset($row['catalog_root_ids']) && is_array($row['catalog_root_ids'])) {
            $ids = [];
            foreach ($row['catalog_root_ids'] as $id) {
                $id = (int)$id;
                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            return $ids;
        }
        $legacy = (int)($row['catalog_root_id'] ?? 0);
        return $legacy > 0 ? [$legacy] : [];
    }

    public static function tel(): string
    {
        return (string)(self::current()['tel'] ?? '');
    }

    public static function name(): string
    {
        return (string)(self::current()['name'] ?? '');
    }

    /**
     * SQL fragment: column IN (0, current) — shared + this host.
     * When no host id (ini fallback), no filter (legacy all content).
     *
     * @return array{0:string,1:list<mixed>} [sqlFragment, params]
     */
    public static function hostScopeSql(string $column = 'site_host_id'): array
    {
        $id = self::id();
        if ($id < 1) {
            return ['1=1', []];
        }
        return ['(' . $column . ' = 0 OR ' . $column . ' = ?)', [$id]];
    }

    /** @return array<string,mixed> */
    private static function iniFallbackRow(?string $httpHost): array
    {
        $host = '';
        if (class_exists(SiteHostsRepository::class)) {
            $host = SiteHostsRepository::normalizeHost((string)$httpHost);
        }
        if ($host === '') {
            foreach (
                [
                    (string)Config::get('app', 'public_host', ''),
                    (string)Config::get('sites', 'public_host', ''),
                    (string)Config::get('likespa', 'mothersite', ''),
                ] as $cand
            ) {
                $host = class_exists(SiteHostsRepository::class)
                    ? SiteHostsRepository::normalizeHost($cand)
                    : trim($cand);
                if ($host !== '') {
                    break;
                }
            }
        }
        $theme = trim((string)Config::get('sites', 'theme', 'default'));
        if ($theme === '') {
            $theme = 'default';
        }
        return [
            'id' => 0,
            'host' => $host,
            'enabled' => 1,
            'name' => (string)Config::get('connect', 'sitename', ''),
            'theme' => $theme,
            'promo_id' => 0,
            'catalog_root_id' => 0,
            'catalog_root_ids' => [],
            'tel' => '',
            'is_default' => 1,
            'num' => 0,
        ];
    }
}
