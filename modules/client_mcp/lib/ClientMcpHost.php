<?php
declare(strict_types=1);

/**
 * Storefront host that serves the MCP request. Each base_site_hosts row is its own MCP server
 * (own settings, services via catalog roots, sessions). Unknown hosts (admin, aliases) get nothing.
 */
final class ClientMcpHost
{
    /** @var array<string,mixed>|null */
    private static $current = null;

    /** @var bool */
    private static $resolved = false;

    /**
     * @return array{id:int,host:string,name:string,base:string,settings:array<string,mixed>}|null
     */
    public static function current(): ?array
    {
        if (self::$resolved) {
            return self::$current;
        }
        self::$resolved = true;
        $reqHost = SiteHostsRepository::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($reqHost === '') {
            return null;
        }
        if (!self::multiHost()) {
            $meta = SitesFront::siteMeta();
            self::$current = self::build(0, $reqHost, (string)($meta['name'] ?? ''));
            return self::$current;
        }
        $row = SiteHostsRepository::findByHost($reqHost);
        if ($row === null && strpos($reqHost, 'www.') === 0) {
            $row = SiteHostsRepository::findByHost(substr($reqHost, 4));
        }
        if ($row === null) {
            return null;
        }
        self::$current = self::build((int)$row['id'], (string)$row['host'], (string)($row['name'] ?? ''));
        return self::$current;
    }

    /** Current host with MCP switched on, or null. */
    public static function currentEnabled(): ?array
    {
        $h = self::current();
        return $h !== null && !empty($h['settings']['enabled']) ? $h : null;
    }

    /**
     * Every host an MCP server can be configured for (settings UI).
     *
     * @return list<array{id:int,host:string,name:string,base:string,settings:array<string,mixed>,site_enabled:bool}>
     */
    public static function all(): array
    {
        if (!self::multiHost()) {
            $host = AppRuntime::publicHost();
            if ($host === '') {
                $host = SiteHostsRepository::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
            }
            $meta = SitesFront::siteMeta();
            return [self::build(0, $host, (string)($meta['name'] ?? '')) + ['site_enabled' => true]];
        }
        $out = [];
        foreach (SiteHostsRepository::list(['limit' => 0])['items'] as $row) {
            $out[] = self::build((int)$row['id'], (string)$row['host'], (string)($row['name'] ?? ''))
                + ['site_enabled' => !empty($row['enabled'])];
        }
        return $out;
    }

    /** @return array{id:int,host:string,name:string,base:string,settings:array<string,mixed>}|null */
    public static function byId(int $id): ?array
    {
        foreach (self::all() as $h) {
            if ($h['id'] === $id) {
                return $h;
            }
        }
        return null;
    }

    /** @param array{base:string} $h */
    public static function mcpUrl(array $h): string
    {
        return $h['base'] . '/mcp';
    }

    /** @param array{base:string} $h */
    public static function serverCardUrl(array $h): string
    {
        return self::mcpUrl($h) . '/server-card';
    }

    /** @param array{base:string} $h */
    public static function aiCatalogUrl(array $h): string
    {
        return $h['base'] . '/.well-known/ai-catalog.json';
    }

    /**
     * Reverse-DNS server id, e.g. ru.example/booking.
     *
     * @param array{host:string} $h
     */
    public static function serverId(array $h): string
    {
        return implode('.', array_reverse(explode('.', $h['host']))) . '/booking';
    }

    /** @param array{host:string,name:string,settings:array<string,mixed>} $h */
    public static function serverName(array $h): string
    {
        foreach ([(string)($h['settings']['server_name'] ?? ''), $h['name'], $h['host']] as $cand) {
            if (trim($cand) !== '') {
                return trim($cand);
            }
        }
        return 'FreeSaaS';
    }

    /** @param array{host:string,name:string,settings:array<string,mixed>} $h */
    public static function description(array $h): string
    {
        $d = trim((string)($h['settings']['description'] ?? ''));
        return $d !== '' ? $d : t('client_mcp.server.description_default', ['name' => self::serverName($h)]);
    }

    /** @param array{base:string,settings:array<string,mixed>} $h */
    public static function consentUrl(array $h): string
    {
        $url = trim((string)($h['settings']['consent_url'] ?? ''));
        if ($url !== '' && $url[0] === '/') {
            return $h['base'] . $url;
        }
        return $url;
    }

    private static function multiHost(): bool
    {
        return SiteHostsRepository::tableReady() && SiteHostsRepository::list(['limit' => 1])['total'] > 0;
    }

    /** @return array{id:int,host:string,name:string,base:string,settings:array<string,mixed>} */
    private static function build(int $id, string $host, string $name): array
    {
        return [
            'id' => $id,
            'host' => $host,
            'name' => $name,
            'base' => self::baseUrl($host),
            'settings' => ClientMcpHostsRepository::get($id),
        ];
    }

    private static function baseUrl(string $host): string
    {
        $local = (bool)preg_match('/^(localhost|127\.|.*\.(local|hm|test))$/i', $host);
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return (($https || !$local) ? 'https' : 'http') . '://' . $host;
    }
}
