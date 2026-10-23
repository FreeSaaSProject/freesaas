<?php
declare(strict_types=1);

/** Update engine: UpdateCatalog. */
final class UpdateCatalog
{
    /**
     * Fetch remote modules-catalog.json (or list modules/ + module.json fallback).
     * Cached in cfg/updates.state.json (TTL 1h unless $force).
     *
     * @return array{ok:bool,modules:list<array<string,mixed>>,ref:string,fetched_at:string,error?:string,http:int}
     */
    public static function fetchRemoteCatalog(bool $force = false): array
    {
        $core = UpdateConfig::coreSource();
        if ($core === null || trim((string)($core['repo'] ?? '')) === '') {
            return [
                'ok' => false,
                'error' => 'No update sources (configure /updates/settings)',
                'modules' => [],
                'ref' => '',
                'fetched_at' => '',
                'http' => 400,
            ];
        }
        $state = UpdateFs::readState();
        $cached = is_array($state['remote_catalog'] ?? null) ? $state['remote_catalog'] : null;
        if (!$force && is_array($cached)) {
            $fetchedAt = (string)($cached['fetched_at'] ?? '');
            $ts = $fetchedAt !== '' ? strtotime($fetchedAt) : false;
            if ($ts !== false && (time() - $ts) < 3600 && is_array($cached['modules'] ?? null)) {
                return [
                    'ok' => true,
                    'modules' => $cached['modules'],
                    'ref' => (string)($cached['ref'] ?? ''),
                    'fetched_at' => $fetchedAt,
                    'http' => 200,
                ];
            }
        }

        try {
            $ref = UpdateGithub::resolveGitRef($core);
            $token = (string)($core['token'] ?? '');
            $repo = (string)$core['repo'];
            $modules = [];
            try {
                $raw = UpdateGithub::githubFileContents($repo, 'modules-catalog.json', $ref, $token);
                $data = json_decode($raw, true);
                if (is_array($data) && isset($data['modules']) && is_array($data['modules'])) {
                    foreach ($data['modules'] as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $norm = UpdateCatalog::normalizeCatalogRow($row);
                        if ($norm !== null) {
                            $modules[] = $norm;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Fallback: list modules/ directory
                $modules = UpdateCatalog::catalogFromGithubListing($repo, $ref, $token);
            }
            if ($modules === []) {
                $modules = UpdateCatalog::catalogFromGithubListing($repo, $ref, $token);
            }

            $now = gmdate('c');
            $state['remote_catalog'] = [
                'fetched_at' => $now,
                'ref' => $ref,
                'modules' => $modules,
            ];
            UpdateFs::writeState($state);
            return [
                'ok' => true,
                'modules' => $modules,
                'ref' => $ref,
                'fetched_at' => $now,
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'modules' => is_array($cached['modules'] ?? null) ? $cached['modules'] : [],
                'ref' => (string)($cached['ref'] ?? ''),
                'fetched_at' => (string)($cached['fetched_at'] ?? ''),
                'http' => 400,
            ];
        }
    }

    /**
     * Modules present in remote catalog but not on local disk.
     * Hidden ("dismissed") modules are excluded unless $includeDismissed.
     *
     * @return list<array<string,mixed>>
     */
    public static function availableToInstall(bool $forceRefresh = false, bool $includeDismissed = false): array
    {
        $cat = UpdateCatalog::fetchRemoteCatalog($forceRefresh);
        if (empty($cat['ok']) && ($cat['modules'] ?? []) === []) {
            return [];
        }
        $private = array_fill_keys(UpdateConfig::privateModuleIds(), true);
        $dismissed = array_fill_keys(UpdateCatalog::dismissedIds(), true);
        $ref = (string)($cat['ref'] ?? '');
        $out = [];
        foreach ($cat['modules'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string)($row['id'] ?? '');
            if ($id === '' || $id === 'core' || isset($private[$id])) {
                continue;
            }
            if (ModuleManager::get($id) !== null) {
                continue;
            }
            if (is_dir(base_path('modules/' . $id))) {
                continue;
            }
            $row['dismissed'] = isset($dismissed[$id]);
            if ($row['dismissed'] && !$includeDismissed) {
                continue;
            }
            $row['ref'] = $ref;
            $out[] = $row;
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string)$a['id'], (string)$b['id']);
        });
        return $out;
    }

    /** @return list<array<string,mixed>> installable modules the admin chose to hide */
    public static function dismissedToInstall(): array
    {
        $out = [];
        foreach (UpdateCatalog::availableToInstall(false, true) as $row) {
            if (!empty($row['dismissed'])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function dismissedIds(): array
    {
        $state = UpdateFs::readState();
        $ids = is_array($state['dismissed_available'] ?? null) ? $state['dismissed_available'] : [];
        $out = [];
        foreach ($ids as $id) {
            if (is_string($id) && preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                $out[$id] = true;
            }
        }
        return array_keys($out);
    }

    /** @return array{ok:bool,id:string,dismissed:list<string>,error?:string,http:int} */
    public static function dismissAvailable(string $id): array
    {
        return UpdateCatalog::setDismissed($id, true);
    }

    /** @return array{ok:bool,id:string,dismissed:list<string>,error?:string,http:int} */
    public static function restoreAvailable(string $id): array
    {
        return UpdateCatalog::setDismissed($id, false);
    }

    /** @return array{ok:bool,id:string,dismissed:list<string>,error?:string,http:int} */
    private static function setDismissed(string $id, bool $dismissed): array
    {
        $id = strtolower(trim($id));
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $id) || $id === 'core') {
            return ['ok' => false, 'id' => $id, 'dismissed' => [], 'error' => 'Bad module id', 'http' => 400];
        }
        $ids = array_fill_keys(UpdateCatalog::dismissedIds(), true);
        if ($dismissed) {
            $ids[$id] = true;
        } else {
            unset($ids[$id]);
        }
        $list = array_keys($ids);
        sort($list);
        $state = UpdateFs::readState();
        $state['dismissed_available'] = $list;
        UpdateFs::writeState($state);
        return ['ok' => true, 'id' => $id, 'dismissed' => $list, 'http' => 200];
    }

    /**
     * Download monorepo archive and overlay modules/<id>/ for a package not yet on disk.
     * Writes [modules] <id>=0 first so the new module stays disabled (missing key means enabled).
     *
     * @return array{ok:bool,id:string,version?:string,files_copied?:int,error?:string,http:int}
     */
    public static function installModule(string $id): array
    {
        try {
            $id = UpdateVersions::normalizeTarget($id);
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => $id, 'error' => $e->getMessage(), 'http' => 400];
        }
        if ($id === 'core') {
            return ['ok' => false, 'id' => $id, 'error' => 'Cannot install core via module install', 'http' => 400];
        }
        if (ModuleManager::get($id) !== null || is_dir(base_path('modules/' . $id))) {
            return ['ok' => false, 'id' => $id, 'error' => 'Module already on disk: ' . $id, 'http' => 409];
        }

        $avail = UpdateCatalog::availableToInstall(true, true);
        $found = null;
        foreach ($avail as $row) {
            if ((string)($row['id'] ?? '') === $id) {
                $found = $row;
                break;
            }
        }
        if ($found === null) {
            return [
                'ok' => false,
                'id' => $id,
                'error' => 'Module not in remote catalog: ' . $id,
                'http' => 404,
            ];
        }

        $core = UpdateConfig::coreSource();
        if ($core === null) {
            return ['ok' => false, 'id' => $id, 'error' => 'No update sources', 'http' => 400];
        }

        $pkg = [
            'id' => $id,
            'kind' => 'module',
            'module' => $id,
            'provider' => $core['provider'],
            'repo' => $core['repo'],
            'url' => '',
            'manifest_url' => '',
            'channel' => $core['channel'],
            'ref' => $core['ref'],
            'asset' => $core['asset'],
            'path_prefix' => '',
            'token_set' => $core['token_set'],
            'token' => $core['token'],
            'bundle' => 'monorepo',
        ];

        $lock = UpdateFs::acquireLock();
        if ($lock === null) {
            return ['ok' => false, 'id' => $id, 'error' => 'Update already in progress (lock)', 'http' => 409];
        }
        try {
            Config::setModuleEnabled($id, false);
            $cache = [];
            $one = UpdateApply::applyOne($pkg, false, $cache);
            if (empty($one['ok'])) {
                return [
                    'ok' => false,
                    'id' => $id,
                    'error' => (string)($one['error'] ?? 'Install failed'),
                    'http' => 500,
                ];
            }
            ModuleManager::rediscover();
            ModuleCatalog::resetLocaleCache();
            try {
                ModuleManager::syncRegistry();
            } catch (Throwable $e) {
                // ignore
            }
            // Drop from cached remote catalog so UI refreshes immediately.
            $state = UpdateFs::readState();
            if (is_array($state['remote_catalog']['modules'] ?? null)) {
                $kept = [];
                foreach ($state['remote_catalog']['modules'] as $row) {
                    if (is_array($row) && (string)($row['id'] ?? '') !== $id) {
                        $kept[] = $row;
                    }
                }
                $state['remote_catalog']['modules'] = $kept;
                UpdateFs::writeState($state);
            }
            UpdateCatalog::restoreAvailable($id);
            return [
                'ok' => true,
                'id' => $id,
                'enabled' => false,
                'version' => (string)($one['version'] ?? $found['version'] ?? ''),
                'files_copied' => (int)($one['files_copied'] ?? 0),
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => $id, 'error' => $e->getMessage(), 'http' => 400];
        } finally {
            UpdateFs::releaseLock($lock);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    public static function normalizeCatalogRow(array $row): ?array
    {
        $id = strtolower(trim((string)($row['id'] ?? '')));
        if ($id === '' || $id === 'core' || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
            return null;
        }
        $cats = [];
        $catalog = is_array($row['catalog'] ?? null) ? $row['catalog'] : [];
        if (isset($catalog['categories']) && is_array($catalog['categories'])) {
            foreach ($catalog['categories'] as $c) {
                if (is_string($c) && $c !== '') {
                    $cats[] = $c;
                }
            }
        }
        $tags = [];
        if (isset($catalog['tags']) && is_array($catalog['tags'])) {
            foreach ($catalog['tags'] as $tg) {
                if (is_string($tg) && $tg !== '') {
                    $tags[] = $tg;
                }
            }
        }
        $maturity = strtolower(trim((string)($catalog['maturity'] ?? $row['maturity'] ?? 'beta')));
        if (!in_array($maturity, ['alpha', 'beta', 'release'], true)) {
            $maturity = 'beta';
        }
        $bilocale = static function ($v): array {
            if (!is_array($v)) {
                return ['en' => '', 'ru' => ''];
            }
            return [
                'en' => trim((string)($v['en'] ?? '')),
                'ru' => trim((string)($v['ru'] ?? '')),
            ];
        };
        return [
            'id' => $id,
            'version' => (string)($row['version'] ?? '0.0.0'),
            'type' => (string)($row['type'] ?? 'product'),
            'name' => $bilocale($row['name'] ?? null),
            'description' => $bilocale($row['description'] ?? null),
            'summary' => $bilocale($row['summary'] ?? null),
            'requires' => is_array($row['requires'] ?? null) ? $row['requires'] : [],
            'optionalRequires' => is_array($row['optionalRequires'] ?? null) ? $row['optionalRequires'] : [],
            'categories' => array_values(array_unique($cats)),
            'tags' => array_values(array_unique($tags)),
            'maturity' => $maturity,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function catalogFromGithubListing(string $repo, string $ref, string $token): array
    {
        $entries = UpdateCatalog::githubDirListing($repo, 'modules', $ref, $token);
        $out = [];
        $private = array_fill_keys(UpdateConfig::privateModuleIds(), true);
        foreach ($entries as $ent) {
            if (($ent['type'] ?? '') !== 'dir') {
                continue;
            }
            $id = (string)($ent['name'] ?? '');
            if ($id === '' || $id === 'core' || isset($private[$id]) || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                continue;
            }
            try {
                $raw = UpdateGithub::githubFileContents($repo, 'modules/' . $id . '/module.json', $ref, $token);
                $meta = json_decode($raw, true);
                if (!is_array($meta)) {
                    continue;
                }
                $meta['id'] = $id;
                $norm = UpdateCatalog::normalizeCatalogRow([
                    'id' => $id,
                    'version' => $meta['version'] ?? '0.0.0',
                    'type' => $meta['type'] ?? 'product',
                    'requires' => $meta['requires'] ?? [],
                    'optionalRequires' => $meta['optionalRequires'] ?? [],
                    'catalog' => $meta['catalog'] ?? [],
                    'name' => ['en' => $id, 'ru' => $id],
                    'description' => ['en' => '', 'ru' => ''],
                    'summary' => ['en' => '', 'ru' => ''],
                ]);
                if ($norm !== null) {
                    $out[] = $norm;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function githubDirListing(string $repo, string $path, string $ref, string $token): array
    {
        $api = 'https://api.github.com/repos/' . $repo . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)))
            . '?ref=' . rawurlencode($ref);
        $data = json_decode(UpdateGithub::httpGet($api, $token), true);
        if (!is_array($data)) {
            throw new RuntimeException('Cannot list ' . $path . ' @ ' . $ref);
        }
        // Single file response is an object; directory is a list.
        if (isset($data['type'])) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Candidates: module.json update.repository without [module.id] ini (external later).
     *
     * @return list<array<string,mixed>>
     */
    public static function moduleHints(): array
    {
        $configured = array_fill_keys(UpdateConfig::privateModuleIds(), true);
        $hints = [];
        foreach (ModuleManager::all() as $id => $meta) {
            $id = (string)$id;
            if ($id === 'core' || isset($configured[$id]) || !is_array($meta)) {
                continue;
            }
            $upd = $meta['update'] ?? null;
            if (!is_array($upd)) {
                continue;
            }
            $repository = trim((string)($upd['repository'] ?? $upd['url'] ?? ''));
            if ($repository === '') {
                continue;
            }
            $hints[] = [
                'id' => $id,
                'kind' => 'module',
                'repository' => $repository,
                'repo' => UpdateVersions::repoFromUrl($repository),
                'channel' => trim((string)($upd['channel'] ?? 'main')) ?: 'main',
                'local_version' => UpdateVersions::localVersion($id),
                'configured' => false,
            ];
        }
        usort($hints, static function (array $a, array $b): int {
            return strcmp($a['id'], $b['id']);
        });
        return $hints;
    }

}
