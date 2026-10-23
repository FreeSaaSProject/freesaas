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
     * Save [core] section of cfg/updates.ini (preserves [module.*]).
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

        $parsed['core'] = [
            'provider' => $provider,
            'repo' => $repo,
            'channel' => $channel,
        ];
        if ($ref !== '') {
            $parsed['core']['ref'] = $ref;
        }
        if ($asset !== '') {
            $parsed['core']['asset'] = $asset;
        }
        if ($token !== '') {
            $parsed['core']['token'] = $token;
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

        $lines = [
            '; FreeSaaS self-update (written by Updates settings UI). Do not commit.',
            '; Check: modules/<id>/module.json on git ref. Apply: zipball or full release zip.',
            '; db_backup_before_migrate=1 dumps all owned tables before migrate (slow on large DBs).',
            '; frozen_modules=a,b — updates never write files of these modules.',
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
