<?php
declare(strict_types=1);

/** Update engine: UpdateConfig. */
final class UpdateConfig
{
    public static function updatesIniPath(): string
    {
        return base_path('cfg/updates.ini');
    }

    public static function exampleIniPath(): string
    {
        return base_path('cfg/updates.example.ini');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function sources(): array
    {
        $path = UpdateConfig::updatesIniPath();
        if (!is_file($path)) {
            return [];
        }
        $parsed = @parse_ini_file($path, true, INI_SCANNER_RAW);
        if (!is_array($parsed)) {
            return [];
        }
        $out = [];
        foreach ($parsed as $section => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $sec = strtolower(trim((string)$section));
            if ($sec === '' || $sec === 'updates') {
                continue;
            }
            $kind = 'core';
            $module = null;
            $theme = null;
            $id = $sec;
            if ($sec === 'core') {
                $kind = 'core';
                $id = 'core';
            } elseif (strpos($sec, 'module.') === 0) {
                $kind = 'module';
                $module = substr($sec, strlen('module.'));
                if ($module === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $module)) {
                    continue;
                }
                $id = $module;
            } elseif (strpos($sec, 'theme.') === 0) {
                // Storefront theme from its own (usually private) repo → package id theme:<id>.
                $kind = 'theme';
                $theme = substr($sec, strlen('theme.'));
                if ($theme === '' || !preg_match(UpdateVersions::THEME_ID_RE, $theme)) {
                    continue;
                }
                $id = 'theme:' . $theme;
            } else {
                continue;
            }
            $provider = strtolower(trim((string)($rows['provider'] ?? 'github')));
            if ($provider !== 'github' && $provider !== 'http') {
                $provider = 'github';
            }
            $token = trim((string)($rows['token'] ?? ''));
            $channel = trim((string)($rows['channel'] ?? 'main'));
            if ($channel === '') {
                $channel = 'main';
            }
            $out[] = [
                'id' => $id,
                'kind' => $kind,
                'module' => $module,
                'theme' => $theme,
                'provider' => $provider,
                'repo' => trim((string)($rows['repo'] ?? '')),
                'url' => trim((string)($rows['url'] ?? '')),
                'manifest_url' => trim((string)($rows['manifest_url'] ?? '')),
                'channel' => $channel,
                'ref' => trim((string)($rows['ref'] ?? '')),
                'asset' => trim((string)($rows['asset'] ?? '')),
                'path_prefix' => trim((string)($rows['path_prefix'] ?? ''), '/'),
                'token_set' => $token !== '',
                'token' => $token,
            ];
        }
        return $out;
    }

    /** @return list<string> */
    public static function privateModuleIds(): array
    {
        $ids = [];
        foreach (UpdateConfig::sources() as $s) {
            if ($s['kind'] === 'module') {
                $ids[(string)$s['id']] = true;
            }
        }
        return array_keys($ids);
    }

    /** Theme ids with their own [theme.<id>] source (full-tree overlays skip themes/<id>/). @return list<string> */
    public static function privateThemeIds(): array
    {
        $ids = [];
        foreach (UpdateConfig::sources() as $s) {
            if ($s['kind'] === 'theme' && is_string($s['theme'] ?? null)) {
                $ids[(string)$s['theme']] = true;
            }
        }
        return array_keys($ids);
    }

    /** @return array<string,mixed>|null */
    public static function coreSource(): ?array
    {
        foreach (UpdateConfig::sources() as $s) {
            if (($s['id'] ?? '') === 'core') {
                return $s;
            }
        }
        return null;
    }

    /** @return array<string,string> [updates] section of cfg/updates.ini */
    private static function updatesSection(): array
    {
        $path = UpdateConfig::updatesIniPath();
        if (!is_file($path)) {
            return [];
        }
        $parsed = @parse_ini_file($path, true, INI_SCANNER_RAW);
        if (!is_array($parsed) || !isset($parsed['updates']) || !is_array($parsed['updates'])) {
            return [];
        }
        return $parsed['updates'];
    }

    /** Opt-in full DB dump before migrate (slow on large tables). Default off. */
    public static function dbBackupBeforeMigrate(): bool
    {
        $v = strtolower(trim((string)(self::updatesSection()['db_backup_before_migrate'] ?? '0')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Modules the instance froze: updates never write their files.
     *
     * @return list<string>
     */
    public static function frozenModules(): array
    {
        return self::parseModuleList((string)(self::updatesSection()['frozen_modules'] ?? ''));
    }

    public static function isFrozen(string $id): bool
    {
        return in_array($id, self::frozenModules(), true);
    }

    /**
     * @param string|list<mixed> $raw comma-separated string or list of ids
     * @return list<string>
     */
    private static function parseModuleList($raw): array
    {
        $items = is_array($raw) ? $raw : explode(',', (string)$raw);
        $out = [];
        foreach ($items as $id) {
            if (!is_scalar($id)) {
                continue;
            }
            $id = strtolower(trim((string)$id));
            if ($id !== '' && $id !== 'core' && preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                $out[$id] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Form values for /updates/settings (secrets masked).
     *
     * @return array<string,mixed>
     */
    public static function coreSettings(): array
    {
        $core = null;
        foreach (UpdateConfig::sources() as $s) {
            if ($s['id'] === 'core') {
                $core = $s;
                break;
            }
        }
        return [
            'provider' => (string)($core['provider'] ?? 'github'),
            'repo' => (string)($core['repo'] ?? ''),
            'channel' => (string)($core['channel'] ?? 'main'),
            'ref' => (string)($core['ref'] ?? ''),
            'asset' => (string)($core['asset'] ?? ''),
            'token_set' => !empty($core['token_set']),
            'configured' => is_file(UpdateConfig::updatesIniPath()),
            'db_backup_before_migrate' => UpdateConfig::dbBackupBeforeMigrate(),
            'frozen_modules' => UpdateConfig::frozenModules(),
        ];
    }

    /**
     * Save [core] section of cfg/updates.ini (preserves [module.*] / [theme.*]).
     *
     * @param array<string,mixed> $in
     */
    public static function saveCoreSettings(array $in): void
    {
        $provider = strtolower(trim((string)($in['provider'] ?? 'github')));
        if ($provider !== 'github' && $provider !== 'http') {
            $provider = 'github';
        }
        $repo = trim((string)($in['repo'] ?? ''));
        if ($provider === 'github' && $repo !== '' && !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new InvalidArgumentException(t('updates.settings.err_repo'));
        }
        $channel = trim((string)($in['channel'] ?? 'main'));
        if ($channel === '') {
            $channel = 'main';
        }
        $ref = trim((string)($in['ref'] ?? ''));
        $asset = trim((string)($in['asset'] ?? ''));
        $tokenNew = (string)($in['token'] ?? '');
        $clearToken = !empty($in['clear_token']);

        $path = UpdateConfig::updatesIniPath();
        $parsed = is_file($path) ? @parse_ini_file($path, true, INI_SCANNER_RAW) : [];
        if (!is_array($parsed)) {
            $parsed = [];
        }
        $existingToken = '';
        if (isset($parsed['core']) && is_array($parsed['core'])) {
            $existingToken = trim((string)($parsed['core']['token'] ?? ''));
        }
        $token = $existingToken;
        if ($clearToken) {
            $token = '';
        } elseif (trim($tokenNew) !== '') {
            $token = trim($tokenNew);
        }

        $core = [
            'provider' => $provider,
            'repo' => $repo,
            'channel' => $channel,
        ];
        if ($ref !== '') {
            $core['ref'] = $ref;
        }
        if ($asset !== '') {
            $core['asset'] = $asset;
        }
        if ($token !== '') {
            $core['token'] = $token;
        }

        $dbBackup = !empty($in['db_backup_before_migrate']);
        if (!isset($parsed['updates']) || !is_array($parsed['updates'])) {
            $parsed['updates'] = [];
        }
        $parsed['updates']['db_backup_before_migrate'] = $dbBackup ? '1' : '0';
        if (array_key_exists('frozen_modules', $in)) {
            $frozen = is_array($in['frozen_modules']) ? $in['frozen_modules'] : (string)$in['frozen_modules'];
            $parsed['updates']['frozen_modules'] = implode(',', self::parseModuleList($frozen));
        }
        // [updates] first, then [core], then private [module.*] / [theme.*] in their existing order.
        $ordered = ['updates' => $parsed['updates'], 'core' => $core];
        foreach ($parsed as $section => $rows) {
            if (!isset($ordered[$section])) {
                $ordered[$section] = $rows;
            }
        }
        self::writeIni($ordered);
    }

    /**
     * Private / external sources ([module.<id>], [theme.<id>]) for settings UI and agents. Tokens masked.
     *
     * @return list<array{kind:string,id:string,package:string,provider:string,repo:string,channel:string,ref:string,path_prefix:string,manifest_url:string,token_set:bool}>
     */
    public static function externalSources(): array
    {
        $out = [];
        foreach (UpdateConfig::sources() as $s) {
            if ($s['kind'] !== 'module' && $s['kind'] !== 'theme') {
                continue;
            }
            $out[] = [
                'kind' => (string)$s['kind'],
                'id' => (string)($s['kind'] === 'theme' ? $s['theme'] : $s['module']),
                'package' => (string)$s['id'],
                'provider' => (string)$s['provider'],
                'repo' => (string)$s['repo'],
                'channel' => (string)$s['channel'],
                'ref' => (string)$s['ref'],
                'path_prefix' => (string)$s['path_prefix'],
                'manifest_url' => (string)$s['manifest_url'],
                'token_set' => !empty($s['token_set']),
            ];
        }
        return $out;
    }

    /**
     * Create or update one [module.<id>] / [theme.<id>] section. Empty token keeps the saved one
     * (clear_token=1 removes it). Other sections are preserved.
     *
     * @param array<string,mixed> $in kind, id, provider?, repo, channel?, ref?, path_prefix?, manifest_url?, token?, clear_token?
     * @return array<string,mixed> the saved source (masked)
     */
    public static function saveExternalSource(array $in): array
    {
        [$kind, $id, $section] = self::externalSectionKey((string)($in['kind'] ?? ''), (string)($in['id'] ?? ''));
        $provider = strtolower(trim((string)($in['provider'] ?? 'github')));
        if ($provider !== 'github' && $provider !== 'http') {
            $provider = 'github';
        }
        $repo = trim((string)($in['repo'] ?? ''));
        $manifest = trim((string)($in['manifest_url'] ?? ''));
        if ($provider === 'github' && !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new InvalidArgumentException(t('updates.settings.err_repo'));
        }
        if ($provider === 'http' && !preg_match('#^https://#i', $manifest)) {
            throw new InvalidArgumentException(t('updates.sources.err_manifest'));
        }
        $prefix = trim(str_replace('\\', '/', (string)($in['path_prefix'] ?? '')), '/');
        if ($prefix !== '' && (!preg_match('#^[A-Za-z0-9_./-]+$#', $prefix) || strpos($prefix, '..') !== false)) {
            throw new InvalidArgumentException(t('updates.sources.err_prefix'));
        }
        $channel = trim((string)($in['channel'] ?? 'main'));

        $parsed = self::readIni();
        $existing = is_array($parsed[$section] ?? null) ? $parsed[$section] : [];
        $token = trim((string)($existing['token'] ?? ''));
        if (!empty($in['clear_token'])) {
            $token = '';
        } elseif (trim((string)($in['token'] ?? '')) !== '') {
            $token = trim((string)$in['token']);
        }
        $row = [
            'provider' => $provider,
            'repo' => $repo,
            'channel' => $channel !== '' ? $channel : 'main',
            'ref' => trim((string)($in['ref'] ?? '')),
            'path_prefix' => $prefix,
            'manifest_url' => $provider === 'http' ? $manifest : '',
            'token' => $token,
        ];
        $parsed[$section] = $row;
        self::writeIni($parsed);
        foreach (self::externalSources() as $s) {
            if ($s['kind'] === $kind && $s['id'] === $id) {
                return $s;
            }
        }
        throw new RuntimeException(t('updates.settings.err_write'));
    }

    public static function deleteExternalSource(string $kind, string $id): bool
    {
        [, , $section] = self::externalSectionKey($kind, $id);
        $parsed = self::readIni();
        if (!isset($parsed[$section])) {
            return false;
        }
        unset($parsed[$section]);
        self::writeIni($parsed);
        return true;
    }

    /** @return array{0:string,1:string,2:string} kind, id, ini section */
    private static function externalSectionKey(string $kind, string $id): array
    {
        $kind = strtolower(trim($kind));
        $id = strtolower(trim($id));
        if ($kind === 'module') {
            if ($id === 'core' || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                throw new InvalidArgumentException(t('updates.sources.err_id'));
            }
        } elseif ($kind === 'theme') {
            if (!preg_match(UpdateVersions::THEME_ID_RE, $id)) {
                throw new InvalidArgumentException(t('updates.sources.err_id'));
            }
        } else {
            throw new InvalidArgumentException(t('updates.sources.err_kind'));
        }
        return [$kind, $id, $kind . '.' . $id];
    }

    /** @return array<string,array<string,string>> */
    private static function readIni(): array
    {
        $path = UpdateConfig::updatesIniPath();
        $parsed = is_file($path) ? @parse_ini_file($path, true, INI_SCANNER_RAW) : [];
        return is_array($parsed) ? $parsed : [];
    }

    /** @param array<string,mixed> $parsed */
    private static function writeIni(array $parsed): void
    {
        $path = UpdateConfig::updatesIniPath();
        $lines = [
            '; FreeSaaS self-update (written by Updates settings UI). Do not commit.',
            '; Check: modules/<id>/module.json on git ref. Apply: zipball or full release zip.',
            '; db_backup_before_migrate=1 dumps all owned tables before migrate (slow on large DBs).',
            '; frozen_modules=a,b — updates never write files of these modules.',
            '; [module.<id>] / [theme.<id>] — private sources (own repo + token), edited on the same screen.',
            '',
        ];
        foreach ($parsed as $section => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $lines[] = '[' . $section . ']';
            foreach ($rows as $k => $v) {
                $k = (string)$k;
                $v = trim((string)$v);
                if ($v === '') {
                    continue;
                }
                $lines[] = $k . ' = ' . $v;
            }
            $lines[] = '';
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create cfg/');
            }
        }
        if (@file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new RuntimeException(t('updates.settings.err_write'));
        }
        @chmod($path, 0600);
    }

}
