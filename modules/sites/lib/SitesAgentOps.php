<?php
declare(strict_types=1);

/**
 * Surface A (/agent/sites/*) — agent_token: list storefront hosts + installed themes,
 * switch a host's theme. CLI twin: `php bin/freesaas.php sites hosts|theme`.
 * Other host fields stay on the session surface (sites.hosts.update).
 */
final class SitesAgentOps
{
    public static function register(): void
    {
        AgentOps::registerModuleOp('GET', '/agent/sites/hosts', 'sites.agent.hosts', [self::class, 'hosts'], [
            'description' => 'Storefront hosts (id, host, enabled, theme, locale, is_default) + installed themes',
        ]);
        AgentOps::registerModuleOp('POST', '/agent/sites/hosts/theme', 'sites.agent.hosts.theme', [self::class, 'themeFromRequest'], [
            'description' => 'Switch theme: theme=<id> and host=<domain|id> or all=1',
            'body' => ['theme', 'host', 'all'],
        ]);

        $routes = [
            '/agent/sites/hosts' => ['GET'],
            '/agent/sites/hosts/theme' => ['POST'],
        ];
        foreach ($routes as $path => $methods) {
            ModuleManager::registerRoute($path, [
                'module' => 'sites',
                'public' => true,
                'csrf' => false,
                'methods' => $methods,
                'handler' => static function (): void {
                    require_once base_path('app/agent_http.php');
                },
            ]);
        }
    }

    /** @return array<string,mixed> */
    public static function hosts(): array
    {
        if (!SiteHostsRepository::tableReady()) {
            return ['ok' => false, 'error' => 'base_site_hosts missing — run migrations for sites', 'http' => 409];
        }

        return [
            'ok' => true,
            'hosts' => self::hostRows(),
            'themes' => self::themes(),
            'fallback_theme' => trim((string)Config::get('sites', 'theme', 'default')),
            'http' => 200,
        ];
    }

    /** @return array<string,mixed> */
    public static function themeFromRequest(): array
    {
        return self::setTheme(AgentOps::requestInput());
    }

    /**
     * @param array<string,mixed> $in theme + (host | all)
     * @return array<string,mixed>
     */
    public static function setTheme(array $in): array
    {
        if (!SiteHostsRepository::tableReady()) {
            return ['ok' => false, 'error' => 'base_site_hosts missing — run migrations for sites', 'http' => 409];
        }
        $theme = trim((string)($in['theme'] ?? ''));
        if ($theme === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $theme)) {
            return ['ok' => false, 'error' => 'theme is required (letters, digits, _ and -)', 'http' => 400];
        }
        $themes = self::themes();
        $known = null;
        foreach ($themes as $row) {
            if ($row['id'] === $theme) {
                $known = $row;
                break;
            }
        }
        if ($known === null) {
            return [
                'ok' => false,
                'error' => 'theme not installed: ' . $theme,
                'themes' => array_column($themes, 'id'),
                'http' => 404,
            ];
        }
        if ($known['parent'] !== '' && !$known['parent_ok']) {
            return [
                'ok' => false,
                'error' => 'theme ' . $theme . ' needs parent theme ' . $known['parent'] . ', which is not installed',
                'http' => 409,
            ];
        }

        $all = self::truthy($in['all'] ?? false);
        $hostArg = trim((string)($in['host'] ?? ''));
        $rows = self::hostRows();
        if ($all) {
            $targets = $rows;
        } elseif ($hostArg !== '') {
            $targets = [];
            $byId = ctype_digit($hostArg) ? (int)$hostArg : 0;
            $byHost = SiteHostsRepository::normalizeHost($hostArg);
            foreach ($rows as $row) {
                if (($byId > 0 && $row['id'] === $byId) || ($byId === 0 && $row['host'] === $byHost)) {
                    $targets[] = $row;
                }
            }
            if ($targets === []) {
                return [
                    'ok' => false,
                    'error' => 'host not found: ' . $hostArg,
                    'hosts' => array_column($rows, 'host'),
                    'http' => 404,
                ];
            }
        } else {
            return ['ok' => false, 'error' => 'pass host=<domain|id> or all=1', 'http' => 400];
        }
        if ($targets === []) {
            return ['ok' => false, 'error' => 'no hosts configured', 'http' => 404];
        }

        $result = [];
        try {
            foreach ($targets as $row) {
                $changed = $row['theme'] !== $theme;
                if ($changed) {
                    SiteHostsRepository::setTheme($row['id'], $theme);
                }
                $result[] = [
                    'id' => $row['id'],
                    'host' => $row['host'],
                    'from' => $row['theme'],
                    'to' => $theme,
                    'changed' => $changed,
                ];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'hosts' => $result, 'http' => 500];
        }

        return ['ok' => true, 'theme' => $theme, 'hosts' => $result, 'http' => 200];
    }

    /** @return list<array{id:int,host:string,enabled:bool,name:string,theme:string,locale:string,is_default:bool}> */
    private static function hostRows(): array
    {
        $out = [];
        foreach (SiteHostsRepository::list(['limit' => 0])['items'] as $row) {
            $out[] = [
                'id' => (int)($row['id'] ?? 0),
                'host' => (string)($row['host'] ?? ''),
                'enabled' => !empty($row['enabled']),
                'name' => (string)($row['name'] ?? ''),
                'theme' => (string)($row['theme'] ?? ''),
                'locale' => (string)($row['locale'] ?? ''),
                'is_default' => !empty($row['is_default']),
            ];
        }

        return $out;
    }

    /** @return list<array{id:string,name:string,version:string,parent:string,parent_ok:bool}> */
    private static function themes(): array
    {
        $out = [];
        foreach (SiteTheme::listIds() as $id) {
            $data = json_decode((string)@file_get_contents(base_path('themes/' . $id . '/theme.json')), true);
            $data = is_array($data) ? $data : [];
            $parent = trim((string)($data['parent'] ?? ''));
            if ($parent === $id || !preg_match('/^[a-zA-Z0-9_-]+$/', $parent)) {
                $parent = '';
            }
            $out[] = [
                'id' => $id,
                'name' => (string)($data['name'] ?? $id),
                'version' => (string)($data['version'] ?? ''),
                'parent' => $parent,
                'parent_ok' => $parent === '' || is_file(base_path('themes/' . $parent . '/theme.json')),
            ];
        }

        return $out;
    }

    /** @param mixed $v */
    private static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        return !in_array(strtolower(trim((string)$v)), ['', '0', 'false', 'no', 'off'], true);
    }
}
