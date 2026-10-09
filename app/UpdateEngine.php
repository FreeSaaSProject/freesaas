<?php
declare(strict_types=1);

/**
 * Self-update: download release zips (HTTPS + optional token) and overlay onto the install.
 * Sources: cfg/updates.ini (see cfg/updates.example.ini). Never overwrites cfg/ or uploads.
 */
final class UpdateEngine
{
    private const MANIFEST_ASSET = 'freesaas-manifest.json';
    private const CATALOG_ASSET = 'freesaas-catalog.json';
    private const USER_AGENT = 'FreeSaaS-UpdateEngine/1.0';
    private const LOCK_TTL_SEC = 1800;

    /** Paths never written by any overlay (relative to base_path). */
    private const DENY_PREFIXES = [
        'cfg/',
        'var/',
        'log/',
        '.git/',
        'assets/uploads/',
    ];

    /** Exact relative paths never written. */
    private const DENY_FILES = [
        'cfg/config.ini',
        'cfg/updates.ini',
        'cfg/updates.state.json',
        '.gitignore',
    ];

    /**
     * Core package may only write platform paths — product modules update via their own packages.
     * @var list<string>
     */
    private const CORE_ALLOW_PREFIXES = [
        'app/',
        'bin/',
        'modules/core/',
        'docs/',
        'assets/css/',
        'assets/js/',
    ];

    /** @var list<string> */
    private const CORE_ALLOW_FILES = [
        'index.php',
        'VERSION',
        '.htaccess',
        'AGENTS.md',
        'README.md',
    ];

    /** Apply job steps (one tick advances one step). */
    private const JOB_STEPS = [
        'resolve',
        'download',
        'verify',
        'extract',
        'overlay',
        'migrate',
        'finalize',
    ];

    public static function updatesIniPath(): string
    {
        return base_path('cfg/updates.ini');
    }

    public static function exampleIniPath(): string
    {
        return base_path('cfg/updates.example.ini');
    }

    public static function statePath(): string
    {
        return base_path('cfg/updates.state.json');
    }

    public static function workDir(): string
    {
        return base_path('var/updates');
    }

    /**
     * @return list<array{
     *   id:string,
     *   kind:string,
     *   module:?string,
     *   provider:string,
     *   repo:string,
     *   url:string,
     *   manifest_url:string,
     *   channel:string,
     *   asset:string,
     *   path_prefix:string,
     *   token_set:bool,
     *   token:string
     * }>
     */
    public static function sources(): array
    {
        $path = self::updatesIniPath();
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
            $out[] = [
                'id' => $id,
                'kind' => $kind,
                'module' => $module,
                'provider' => $provider,
                'repo' => trim((string)($rows['repo'] ?? '')),
                'url' => trim((string)($rows['url'] ?? '')),
                'manifest_url' => trim((string)($rows['manifest_url'] ?? '')),
                'channel' => trim((string)($rows['channel'] ?? 'stable')),
                'asset' => trim((string)($rows['asset'] ?? '')),
                'path_prefix' => trim((string)($rows['path_prefix'] ?? ''), '/'),
                'token_set' => $token !== '',
                'token' => $token,
            ];
        }
        return $out;
    }

    /** Module ids that have their own update source (skip when overlaying core). */
    public static function privateModuleIds(): array
    {
        $ids = [];
        foreach (self::sources() as $s) {
            if ($s['kind'] === 'module' && is_string($s['module']) && $s['module'] !== '') {
                $ids[$s['module']] = true;
            }
        }
        return array_keys($ids);
    }

    public static function localVersion(string $target): string
    {
        $target = self::normalizeTarget($target);
        if ($target === 'core') {
            $verFile = base_path('VERSION');
            if (is_file($verFile)) {
                $v = trim((string)@file_get_contents($verFile));
                if ($v !== '') {
                    return $v;
                }
            }
            $meta = ModuleManager::get('core');
            return is_array($meta) ? (string)($meta['version'] ?? '0.0.0') : '0.0.0';
        }
        $meta = ModuleManager::get($target);
        if (is_array($meta) && isset($meta['version'])) {
            return (string)$meta['version'];
        }
        $jsonPath = base_path('modules/' . $target . '/module.json');
        if (is_file($jsonPath)) {
            $data = json_decode((string)@file_get_contents($jsonPath), true);
            if (is_array($data) && isset($data['version'])) {
                return (string)$data['version'];
            }
        }
        return '0.0.0';
    }

    /**
     * Modules that advertise update.repository in module.json but lack cfg/updates.ini section.
     *
     * @return list<array{id:string,kind:string,repository:string,repo:string,channel:string,local_version:string,configured:bool}>
     */
    public static function moduleHints(): array
    {
        $configured = [];
        foreach (self::sources() as $s) {
            if ($s['kind'] === 'module') {
                $configured[(string)$s['id']] = true;
            }
        }
        $hints = [];
        foreach (ModuleManager::all() as $id => $meta) {
            $id = (string)$id;
            if ($id === 'core' || $id === 'updates') {
                continue;
            }
            if (isset($configured[$id])) {
                continue;
            }
            if (!is_array($meta)) {
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
            $channel = trim((string)($upd['channel'] ?? 'stable'));
            if ($channel === '') {
                $channel = 'stable';
            }
            $repo = self::repoFromUrl($repository);
            $hints[] = [
                'id' => $id,
                'kind' => 'module',
                'repository' => $repository,
                'repo' => $repo,
                'channel' => $channel,
                'local_version' => self::localVersion($id),
                'configured' => false,
            ];
        }
        usort($hints, static function (array $a, array $b): int {
            return strcmp($a['id'], $b['id']);
        });
        return $hints;
    }

    /** owner/name from https://github.com/owner/name(.git) */
    public static function repoFromUrl(string $url): string
    {
        $url = trim($url);
        if (preg_match('#github\.com[:/]+([^/]+)/([^/.\s]+)#i', $url, $m)) {
            return $m[1] . '/' . preg_replace('/\.git$/i', '', $m[2]);
        }
        if (preg_match('#gitlab\.com[:/]+([^/]+)/([^/.\s]+)#i', $url, $m)) {
            return $m[1] . '/' . preg_replace('/\.git$/i', '', $m[2]);
        }
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $url)) {
            return $url;
        }
        return '';
    }

    /**
     * @return array{ok:bool,configured:bool,sources:list,hints:list,state:array,http:int,error?:string}
     */
    public static function status(): array
    {
        $ini = self::sources();
        $state = self::readState();
        $rows = [];
        // Prefer last successful check (includes catalog packages); else show ini sources only.
        if (!empty($state['last_check_targets']) && is_array($state['last_check_targets'])) {
            foreach ($state['last_check_targets'] as $t) {
                if (!is_array($t) || empty($t['id'])) {
                    continue;
                }
                $id = (string)$t['id'];
                $applied = $id === 'core'
                    ? ($state['core'] ?? null)
                    : ($state['modules'][$id] ?? null);
                $rows[] = [
                    'id' => $id,
                    'kind' => (string)($t['kind'] ?? ($id === 'core' ? 'core' : 'module')),
                    'provider' => !empty($t['from_catalog']) ? 'catalog' : 'ini',
                    'repo' => (string)($t['artifact'] ?? ''),
                    'channel' => (string)($t['channel'] ?? ''),
                    'token_set' => null,
                    'local_version' => (string)($t['local_version'] ?? self::localVersion($id)),
                    'remote_version' => (string)($t['remote_version'] ?? ''),
                    'update_available' => !empty($t['update_available']),
                    'applied' => $applied,
                    'configured' => true,
                    'from_catalog' => !empty($t['from_catalog']),
                ];
            }
        } else {
            foreach ($ini as $s) {
                $id = $s['id'];
                $applied = $s['kind'] === 'core'
                    ? ($state['core'] ?? null)
                    : ($state['modules'][$id] ?? null);
                $rows[] = [
                    'id' => $id,
                    'kind' => $s['kind'],
                    'provider' => $s['provider'],
                    'repo' => $s['repo'],
                    'channel' => $s['channel'],
                    'token_set' => $s['token_set'],
                    'local_version' => self::localVersion($id),
                    'applied' => $applied,
                    'configured' => true,
                ];
            }
        }
        return [
            'ok' => true,
            'configured' => $ini !== [],
            'ini' => self::updatesIniPath(),
            'example' => self::exampleIniPath(),
            'sources' => $rows,
            'hints' => self::moduleHints(),
            'state' => $state,
            'http' => 200,
        ];
    }

    /**
     * @return array{ok:bool,targets:list,http:int,error?:string}
     */
    public static function check(?string $target = null): array
    {
        try {
            $packages = self::resolvePackages($target);
            if ($packages === []) {
                return [
                    'ok' => false,
                    'error' => 'No update packages found (configure cfg/updates.ini and/or publish freesaas-catalog.json)',
                    'targets' => [],
                    'http' => 400,
                ];
            }
            $targets = [];
            foreach ($packages as $s) {
                $remote = self::packageRemote($s);
                $local = self::localVersion($s['id']);
                $remoteVer = (string)$remote['version'];
                $updateAvailable = self::isNewer($remoteVer, $local);
                $targets[] = [
                    'id' => $s['id'],
                    'kind' => $s['kind'],
                    'local_version' => $local,
                    'remote_version' => $remoteVer,
                    'update_available' => $updateAvailable,
                    'channel' => $s['channel'],
                    'tag' => $remote['tag'] ?? null,
                    'artifact' => $remote['artifact'] ?? null,
                    'url' => $remote['url'] ?? null,
                    'sha256' => $remote['sha256'] ?? null,
                    'from_catalog' => !empty($s['from_catalog']),
                ];
            }
            $state = self::readState();
            $state['last_check'] = gmdate('c');
            $state['last_check_targets'] = $targets;
            self::writeState($state);
            return ['ok' => true, 'targets' => $targets, 'http' => 200];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'targets' => [], 'http' => 400];
        }
    }

    /**
     * Download + overlay + migrate for one or all configured sources.
     *
     * @return array{ok:bool,results:list,http:int,error?:string}
     */
    public static function apply(?string $target = null, bool $migrate = true): array
    {
        $lock = self::acquireLock();
        if ($lock === null) {
            return ['ok' => false, 'error' => 'Update already in progress (lock)', 'results' => [], 'http' => 409];
        }
        try {
            $onlyNewer = $target === null || trim((string)$target) === '' || strtolower(trim((string)$target)) === 'all';
            $packages = self::resolvePackages($target, $onlyNewer);
            if ($packages === []) {
                return [
                    'ok' => false,
                    'error' => $onlyNewer
                        ? 'Nothing to apply (no packages with a newer remote version)'
                        : 'Unknown or empty update target',
                    'results' => [],
                    'http' => 400,
                ];
            }
            $results = [];
            $allOk = true;
            foreach ($packages as $s) {
                $one = self::applyOne($s, $migrate);
                $results[] = $one;
                if (empty($one['ok'])) {
                    $allOk = false;
                }
            }
            return [
                'ok' => $allOk,
                'results' => $results,
                'http' => $allOk ? 200 : 500,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'results' => [], 'http' => 400];
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private static function applyOne(array $source, bool $migrate): array
    {
        $id = (string)$source['id'];
        $work = self::workDir();
        self::ensureDir($work);
        $stamp = gmdate('YmdHis') . '-' . preg_replace('/[^a-z0-9_]+/i', '', $id);
        $zipPath = $work . '/' . $stamp . '.zip';
        $stageDir = $work . '/' . $stamp . '-stage';

        try {
            $remote = self::packageRemote($source);
            self::downloadFile((string)$remote['url'], $zipPath, (string)$source['token']);
            $sha = (string)($remote['sha256'] ?? '');
            if ($sha !== '') {
                $got = hash_file('sha256', $zipPath);
                if (!is_string($got) || !hash_equals(strtolower($sha), strtolower($got))) {
                    throw new RuntimeException('SHA-256 mismatch for ' . $id);
                }
            }
            self::extractZip($zipPath, $stageDir);
            $payloadRoot = self::findPayloadRoot($stageDir, (string)$source['path_prefix'], (string)$source['kind'], $id);
            $copied = self::overlay($payloadRoot, (string)$source['kind'], $id);
            if ($migrate) {
                if ($source['kind'] === 'module') {
                    $mig = AgentOps::migrateRun($id);
                } else {
                    $mig = AgentOps::migrateRun(null);
                }
            } else {
                $mig = ['ok' => true, 'skipped' => true];
            }
            $health = null;
            try {
                $health = AgentOps::health($source['kind'] === 'module' ? $id : null);
            } catch (Throwable $e) {
                $health = ['ok' => false, 'error' => $e->getMessage()];
            }
            $state = self::readState();
            $entry = [
                'version' => (string)$remote['version'],
                'applied_at' => gmdate('c'),
                'ok' => !empty($mig['ok']),
                'artifact' => $remote['artifact'] ?? null,
            ];
            if ($source['kind'] === 'core') {
                $state['core'] = $entry;
            } else {
                if (!isset($state['modules']) || !is_array($state['modules'])) {
                    $state['modules'] = [];
                }
                $state['modules'][$id] = $entry;
            }
            self::writeState($state);

            return [
                'ok' => !empty($mig['ok']),
                'id' => $id,
                'kind' => $source['kind'],
                'version' => (string)$remote['version'],
                'files_copied' => $copied,
                'migrate' => $mig,
                'health' => $health,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'id' => $id,
                'kind' => $source['kind'],
                'error' => $e->getMessage(),
            ];
        } finally {
            @unlink($zipPath);
            self::rmTree($stageDir);
        }
    }

    /**
     * Expand registry catalog + ini overrides into per-package sources.
     * When $onlyNewer, keep packages whose remote version is newer than local
     * (uses last_check_targets cache when present for the same ids).
     *
     * @return list<array<string,mixed>>
     */
    public static function resolvePackages(?string $target = null, bool $onlyNewer = false): array
    {
        $ini = self::sources();
        $coreSrc = null;
        foreach ($ini as $s) {
            if ($s['id'] === 'core') {
                $coreSrc = $s;
                break;
            }
        }

        // Catalog first (all public packages), then ini overrides (private modules / pinned core).
        $byId = [];
        if ($coreSrc !== null) {
            try {
                foreach (self::fetchRemoteCatalog($coreSrc) as $pkg) {
                    $id = (string)($pkg['id'] ?? '');
                    if ($id !== '') {
                        $byId[$id] = $pkg;
                    }
                }
            } catch (Throwable $e) {
                // no catalog — ini-only still works
            }
        }
        foreach ($ini as $s) {
            $byId[$s['id']] = $s;
        }
        if ($byId === []) {
            return [];
        }

        $all = array_values($byId);
        usort($all, static function (array $a, array $b): int {
            if ($a['id'] === 'core') {
                return -1;
            }
            if ($b['id'] === 'core') {
                return 1;
            }
            return strcmp((string)$a['id'], (string)$b['id']);
        });

        if ($target !== null && trim($target) !== '' && strtolower(trim($target)) !== 'all') {
            $want = self::normalizeTarget($target);
            $found = null;
            foreach ($all as $s) {
                if ($s['id'] === $want) {
                    $found = $s;
                    break;
                }
            }
            if ($found === null) {
                throw new InvalidArgumentException('Unknown update target: ' . $want);
            }
            $all = [$found];
        }

        if (!$onlyNewer) {
            return $all;
        }

        $out = [];
        $cached = [];
        $state = self::readState();
        if (!empty($state['last_check_targets']) && is_array($state['last_check_targets'])) {
            foreach ($state['last_check_targets'] as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $cached[(string)$row['id']] = $row;
                }
            }
        }
        foreach ($all as $s) {
            $id = (string)$s['id'];
            if (isset($cached[$id]) && array_key_exists('update_available', $cached[$id])) {
                if (!empty($cached[$id]['update_available'])) {
                    // Prefer cached remote meta on the source for apply.
                    if (!empty($cached[$id]['url'])) {
                        $s['_remote'] = [
                            'version' => (string)($cached[$id]['remote_version'] ?? ''),
                            'url' => (string)$cached[$id]['url'],
                            'sha256' => $cached[$id]['sha256'] ?? null,
                            'artifact' => $cached[$id]['artifact'] ?? null,
                            'tag' => $cached[$id]['tag'] ?? null,
                        ];
                    }
                    $out[] = $s;
                }
                continue;
            }
            $remote = self::packageRemote($s);
            if (self::isNewer((string)$remote['version'], self::localVersion($id))) {
                $s['_remote'] = $remote;
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $source
     * @return array{version:string,url:string,sha256?:string,artifact?:string,tag?:string}
     */
    private static function packageRemote(array $source): array
    {
        if (isset($source['_remote']) && is_array($source['_remote']) && !empty($source['_remote']['url'])) {
            /** @var array{version:string,url:string,sha256?:string,artifact?:string,tag?:string} */
            return $source['_remote'];
        }
        if (!empty($source['from_catalog']) && !empty($source['url']) && !empty($source['version'])) {
            $out = [
                'version' => (string)$source['version'],
                'url' => (string)$source['url'],
                'artifact' => (string)($source['artifact'] ?? ''),
            ];
            if (!empty($source['sha256'])) {
                $out['sha256'] = (string)$source['sha256'];
            }
            if (!empty($source['tag'])) {
                $out['tag'] = (string)$source['tag'];
            }
            return $out;
        }
        return self::resolveRemote($source);
    }

    /**
     * Load freesaas-catalog.json (or synthesize core-only) from the registry release.
     *
     * @param array<string,mixed> $coreSrc
     * @return list<array<string,mixed>>
     */
    private static function fetchRemoteCatalog(array $coreSrc): array
    {
        $provider = (string)$coreSrc['provider'];
        $token = (string)$coreSrc['token'];
        $channel = (string)$coreSrc['channel'];
        $packages = [];

        if ($provider === 'http') {
            $catalogUrl = trim((string)($coreSrc['catalog_url'] ?? ''));
            if ($catalogUrl === '') {
                // Same host as manifest: try sibling freesaas-catalog.json
                $manifestUrl = (string)($coreSrc['manifest_url'] ?: $coreSrc['url']);
                if ($manifestUrl !== '') {
                    $catalogUrl = preg_replace('#freesaas-manifest[^/]*\.json$#', self::CATALOG_ASSET, $manifestUrl) ?: '';
                }
            }
            if ($catalogUrl === '') {
                throw new RuntimeException('No catalog_url for http registry');
            }
            $raw = self::httpGet($catalogUrl, $token);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new RuntimeException('Invalid catalog JSON');
            }
            $packages = self::normalizeCatalogPackages($data, $coreSrc, []);
            return $packages;
        }

        // github
        $repo = (string)$coreSrc['repo'];
        if ($channel === '' || $channel === 'stable' || $channel === 'latest') {
            $api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        } else {
            $api = 'https://api.github.com/repos/' . $repo . '/releases/tags/' . rawurlencode($channel);
        }
        $release = json_decode(self::httpGet($api, $token), true);
        if (!is_array($release) || empty($release['tag_name'])) {
            throw new RuntimeException('GitHub release not found for catalog');
        }
        $tag = (string)$release['tag_name'];
        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
        $catalogAsset = null;
        foreach ($assets as $a) {
            if (is_array($a) && (string)($a['name'] ?? '') === self::CATALOG_ASSET) {
                $catalogAsset = $a;
                break;
            }
        }
        if ($catalogAsset !== null) {
            $raw = self::httpGet(self::githubAssetDownloadUrl($catalogAsset), $token, true);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new RuntimeException('Invalid freesaas-catalog.json');
            }
            return self::normalizeCatalogPackages($data, $coreSrc, $assets, $tag);
        }

        // No catalog yet: single core package from existing resolve path.
        $remote = self::resolveGithub($coreSrc);
        return [[
            'id' => 'core',
            'kind' => 'core',
            'module' => null,
            'provider' => 'github',
            'repo' => $repo,
            'url' => (string)$remote['url'],
            'manifest_url' => '',
            'channel' => $channel,
            'asset' => (string)($remote['artifact'] ?? ''),
            'path_prefix' => '',
            'token_set' => $token !== '',
            'token' => $token,
            'version' => (string)$remote['version'],
            'sha256' => $remote['sha256'] ?? null,
            'artifact' => $remote['artifact'] ?? null,
            'tag' => $tag,
            'from_catalog' => true,
        ]];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $coreSrc
     * @param list<mixed> $assets
     * @return list<array<string,mixed>>
     */
    private static function normalizeCatalogPackages(array $data, array $coreSrc, array $assets, string $tag = ''): array
    {
        $rows = $data['packages'] ?? null;
        if (!is_array($rows)) {
            throw new RuntimeException('catalog.packages missing');
        }
        $token = (string)$coreSrc['token'];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            $kind = trim((string)($row['kind'] ?? ($id === 'core' ? 'core' : 'module')));
            $version = trim((string)($row['version'] ?? ''));
            $artifact = trim((string)($row['artifact'] ?? $row['url'] ?? ''));
            if ($id === '' || $version === '' || $artifact === '') {
                continue;
            }
            if ($kind !== 'core' && $kind !== 'module') {
                $kind = 'module';
            }
            $url = trim((string)($row['url'] ?? ''));
            if ($url === '' || strpos($url, '://') === false) {
                $name = basename($artifact !== '' ? $artifact : $url);
                $resolved = self::findAssetUrl($assets, $name);
                if ($resolved === null) {
                    continue; // asset not in this release
                }
                $url = $resolved;
            }
            $sha = trim((string)($row['sha256'] ?? ''));
            $out[] = [
                'id' => $id,
                'kind' => $kind,
                'module' => $kind === 'module' ? $id : null,
                'provider' => (string)$coreSrc['provider'],
                'repo' => (string)$coreSrc['repo'],
                'url' => $url,
                'manifest_url' => '',
                'channel' => (string)($coreSrc['channel'] ?? 'stable'),
                'asset' => basename($artifact),
                'path_prefix' => trim((string)($row['path_prefix'] ?? ''), '/'),
                'token_set' => $token !== '',
                'token' => $token,
                'version' => $version,
                'sha256' => $sha !== '' ? $sha : null,
                'artifact' => basename($artifact),
                'tag' => $tag !== '' ? $tag : null,
                'from_catalog' => true,
            ];
        }
        return $out;
    }

    private static function normalizeTarget(string $target): string
    {
        $t = strtolower(trim($target));
        if (strpos($t, 'module:') === 0) {
            $t = substr($t, strlen('module:'));
        }
        if ($t === '' || $t === 'core') {
            return $t === '' ? 'core' : 'core';
        }
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $t)) {
            throw new InvalidArgumentException('Invalid target id');
        }
        return $t;
    }

    /**
     * @param array<string,mixed> $source
     * @return array{version:string,url:string,sha256?:string,artifact?:string,tag?:string}
     */
    private static function resolveRemote(array $source): array
    {
        $provider = (string)$source['provider'];
        if ($provider === 'http') {
            return self::resolveHttp($source);
        }
        return self::resolveGithub($source);
    }

    /**
     * @param array<string,mixed> $source
     * @return array{version:string,url:string,sha256?:string,artifact?:string,tag?:string}
     */
    private static function resolveHttp(array $source): array
    {
        $manifestUrl = (string)$source['manifest_url'];
        if ($manifestUrl === '') {
            $manifestUrl = (string)$source['url'];
        }
        if ($manifestUrl === '') {
            throw new RuntimeException('http provider requires manifest_url (or url to manifest JSON)');
        }
        $body = self::httpGet($manifestUrl, (string)$source['token']);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid manifest JSON from ' . $manifestUrl);
        }
        return self::normalizeManifest($data, (string)$source['token']);
    }

    /**
     * @param array<string,mixed> $source
     * @return array{version:string,url:string,sha256?:string,artifact?:string,tag?:string}
     */
    private static function resolveGithub(array $source): array
    {
        $repo = (string)$source['repo'];
        if ($repo === '' || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new RuntimeException('github provider requires repo=owner/name');
        }
        $channel = (string)$source['channel'];
        $token = (string)$source['token'];
        if ($channel === '' || $channel === 'stable' || $channel === 'latest') {
            $api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        } else {
            $tag = $channel;
            if ($tag !== '' && $tag[0] !== 'v' && preg_match('/^\d/', $tag)) {
                // keep as-is; tags may be v-prefixed or not
            }
            $api = 'https://api.github.com/repos/' . $repo . '/releases/tags/' . rawurlencode($channel);
        }
        $raw = self::httpGet($api, $token);
        $release = json_decode($raw, true);
        if (!is_array($release) || empty($release['tag_name'])) {
            throw new RuntimeException('GitHub release not found for ' . $repo . ' channel=' . $channel);
        }
        $tag = (string)$release['tag_name'];
        $version = ltrim($tag, 'vV');
        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];

        $wantAsset = (string)$source['asset'];
        $manifestAsset = null;
        $zipAsset = null;
        foreach ($assets as $a) {
            if (!is_array($a)) {
                continue;
            }
            $name = (string)($a['name'] ?? '');
            $dl = self::githubAssetDownloadUrl($a);
            if ($name === self::MANIFEST_ASSET && $dl !== '') {
                $manifestAsset = $a;
            }
            if ($wantAsset !== '' && $name === $wantAsset && $dl !== '') {
                $zipAsset = $a;
            }
        }
        if ($manifestAsset !== null) {
            // Private releases: browser_download_url 404s; use API asset URL + octet-stream.
            $mBody = self::httpGet(self::githubAssetDownloadUrl($manifestAsset), $token, true);
            $mData = json_decode($mBody, true);
            if (is_array($mData)) {
                $norm = self::normalizeManifest($mData, $token);
                $norm['tag'] = $tag;
                if (($norm['version'] ?? '') === '') {
                    $norm['version'] = $version;
                }
                // Build scripts may store artifact basename as url — resolve against release assets.
                $normUrl = (string)($norm['url'] ?? '');
                if ($normUrl !== '' && strpos($normUrl, '://') === false) {
                    $resolved = self::findAssetUrl($assets, $normUrl);
                    if ($resolved === null && isset($norm['artifact'])) {
                        $resolved = self::findAssetUrl($assets, (string)$norm['artifact']);
                    }
                    if ($resolved === null) {
                        throw new RuntimeException('Manifest artifact not found in release assets: ' . $normUrl);
                    }
                    $norm['url'] = $resolved;
                }
                return $norm;
            }
        }
        if ($zipAsset === null && $wantAsset === '') {
            foreach ($assets as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $name = (string)($a['name'] ?? '');
                if ($name !== '' && preg_match('/\.zip$/i', $name) && self::githubAssetDownloadUrl($a) !== '') {
                    $zipAsset = $a;
                    break;
                }
            }
        }
        if ($zipAsset !== null) {
            return [
                'version' => $version,
                'url' => self::githubAssetDownloadUrl($zipAsset),
                'artifact' => (string)($zipAsset['name'] ?? ''),
                'tag' => $tag,
            ];
        }
        $zipball = (string)($release['zipball_url'] ?? '');
        if ($zipball === '') {
            $zipball = 'https://api.github.com/repos/' . $repo . '/zipball/' . rawurlencode($tag);
        }
        return [
            'version' => $version,
            'url' => $zipball,
            'artifact' => 'zipball:' . $tag,
            'tag' => $tag,
        ];
    }

    /**
     * Prefer GitHub API asset URL (works for private repos with Bearer + Accept: octet-stream).
     * browser_download_url returns 404 for private releases even with a token.
     *
     * @param array<string,mixed> $asset
     */
    private static function githubAssetDownloadUrl(array $asset): string
    {
        $api = trim((string)($asset['url'] ?? ''));
        if ($api !== '' && strpos($api, 'api.github.com/') !== false) {
            return $api;
        }
        return trim((string)($asset['browser_download_url'] ?? ''));
    }

    /**
     * @param list<mixed> $assets
     */
    private static function findAssetUrl(array $assets, string $name): ?string
    {
        $name = basename($name);
        if ($name === '') {
            return null;
        }
        foreach ($assets as $a) {
            if (!is_array($a)) {
                continue;
            }
            if ((string)($a['name'] ?? '') === $name) {
                $dl = self::githubAssetDownloadUrl($a);
                return $dl !== '' ? $dl : null;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{version:string,url:string,sha256?:string,artifact?:string,tag?:string}
     */
    private static function normalizeManifest(array $data, string $token): array
    {
        $version = trim((string)($data['version'] ?? ''));
        $url = trim((string)($data['url'] ?? $data['download_url'] ?? ''));
        $sha = trim((string)($data['sha256'] ?? ''));
        $artifact = trim((string)($data['artifact'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('manifest missing url');
        }
        if ($version === '') {
            throw new RuntimeException('manifest missing version');
        }
        $out = [
            'version' => $version,
            'url' => $url,
            'artifact' => $artifact !== '' ? $artifact : basename(parse_url($url, PHP_URL_PATH) ?: 'artifact.zip'),
        ];
        if ($sha !== '') {
            $out['sha256'] = $sha;
        }
        return $out;
    }

    private static function isNewer(string $remote, string $local): bool
    {
        $remote = ltrim(trim($remote), 'vV');
        $local = ltrim(trim($local), 'vV');
        if ($remote === '' || $remote === $local) {
            return false;
        }
        return version_compare($remote, $local, '>');
    }

    private static function findPayloadRoot(string $stageDir, string $pathPrefix, string $kind, string $id): string
    {
        $root = self::unwrapSingleTopDir($stageDir);
        if ($pathPrefix !== '') {
            $p = $root . '/' . $pathPrefix;
            if (!is_dir($p)) {
                throw new RuntimeException('path_prefix not found in archive: ' . $pathPrefix);
            }
            return $p;
        }
        if ($kind === 'module') {
            // Zip root is module tree, or contains modules/<id>/
            if (is_file($root . '/module.json')) {
                return $root;
            }
            $nested = $root . '/modules/' . $id;
            if (is_dir($nested) && is_file($nested . '/module.json')) {
                return $nested;
            }
        }
        return $root;
    }

    private static function unwrapSingleTopDir(string $dir): string
    {
        $entries = array_values(array_filter(scandir($dir) ?: [], static function ($n) {
            return $n !== '.' && $n !== '..' && $n !== '__MACOSX';
        }));
        if (count($entries) === 1 && is_dir($dir . '/' . $entries[0])) {
            return $dir . '/' . $entries[0];
        }
        return $dir;
    }

    /**
     * @return int files copied
     */
    private static function overlay(string $fromDir, string $kind, string $id): int
    {
        $base = rtrim(base_path(''), '/');
        $copied = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fromDir, FilesystemIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $rel = substr($full, strlen($fromDir) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($rel === false || $rel === '') {
                continue;
            }
            if ($kind === 'module') {
                $destRel = 'modules/' . $id . '/' . $rel;
            } else {
                // Platform package: never touch product modules (even if zip still contains them).
                $destRel = $rel;
                if (!self::isCoreAllowed($destRel)) {
                    continue;
                }
            }
            if (self::isDenied($destRel)) {
                continue;
            }
            $dest = $base . '/' . $destRel;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                if (!@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                    throw new RuntimeException('Cannot create directory: ' . $destDir);
                }
            }
            if (!@copy($full, $dest)) {
                throw new RuntimeException('Cannot write: ' . $destRel);
            }
            $copied++;
        }
        return $copied;
    }

    private static function isDenied(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (self::DENY_FILES as $f) {
            if ($rel === $f) {
                return true;
            }
        }
        foreach (self::DENY_PREFIXES as $p) {
            if (strpos($rel, $p) === 0) {
                return true;
            }
        }
        if (strpos($rel, 'cfg/modules/') === 0 && substr($rel, -4) === '.ini') {
            return true;
        }
        return false;
    }

    private static function isCoreAllowed(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (self::CORE_ALLOW_FILES as $f) {
            if ($rel === $f) {
                return true;
            }
        }
        foreach (self::CORE_ALLOW_PREFIXES as $p) {
            if (strpos($rel, $p) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function extractZip(string $zipPath, string $destDir): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension required for updates');
        }
        self::ensureDir($destDir);
        $zip = new ZipArchive();
        $open = $zip->open($zipPath);
        if ($open !== true) {
            throw new RuntimeException('Cannot open zip (code ' . (string)$open . ')');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $name = str_replace('\\', '/', $name);
                if (strpos($name, '..') !== false) {
                    throw new RuntimeException('Unsafe path in zip: ' . $name);
                }
                if (substr($name, -1) === '/') {
                    continue;
                }
                $target = $destDir . '/' . $name;
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    if (!@mkdir($parent, 0755, true) && !is_dir($parent)) {
                        throw new RuntimeException('Cannot create staging dir');
                    }
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException('Cannot read zip entry: ' . $name);
                }
                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new RuntimeException('Cannot write staging file');
                }
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    private static function downloadFile(string $url, string $dest, string $token): void
    {
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot create download file');
        }
        try {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('curl extension required for updates');
            }
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed');
            }
            // GitHub private release assets require API URL + octet-stream (not browser_download_url).
            $headers = [
                'Accept: application/octet-stream',
                'User-Agent: ' . self::USER_AGENT,
            ];
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $ok = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = (string)curl_error($ch);
            curl_close($ch);
            if ($ok === false || $code < 200 || $code >= 300) {
                throw new RuntimeException('Download failed HTTP ' . $code . ($err !== '' ? (': ' . $err) : ''));
            }
        } finally {
            fclose($fh);
        }
        if (!is_file($dest) || filesize($dest) < 1) {
            throw new RuntimeException('Downloaded file is empty');
        }
    }

    /**
     * @param bool $octetStream true for GitHub release asset API URLs (private-repo downloads)
     */
    private static function httpGet(string $url, string $token, bool $octetStream = false): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl extension required for updates');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        if (!$octetStream && strpos($url, 'api.github.com/') !== false && strpos($url, '/releases/assets/') !== false) {
            $octetStream = true;
        }
        $headers = [
            'Accept: ' . ($octetStream ? 'application/octet-stream' : 'application/vnd.github+json, application/json'),
            'User-Agent: ' . self::USER_AGENT,
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('HTTP GET failed ' . $code . ' for ' . $url . ($err !== '' ? (': ' . $err) : ''));
        }
        return (string)$body;
    }

    /** @return array<string,mixed> */
    private static function readState(): array
    {
        $path = self::statePath();
        if (!is_file($path)) {
            return ['core' => null, 'modules' => [], 'last_check' => null];
        }
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) {
            return ['core' => null, 'modules' => [], 'last_check' => null];
        }
        if (!isset($data['modules']) || !is_array($data['modules'])) {
            $data['modules'] = [];
        }
        return $data;
    }

    /** @param array<string,mixed> $state */
    private static function writeState(array $state): void
    {
        $path = self::statePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Cannot encode updates state');
        }
        if (@file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Cannot write ' . $path);
        }
    }

    /** @return resource|null */
    private static function acquireLock()
    {
        $dir = self::workDir();
        self::ensureDir($dir);
        $path = $dir . '/.lock';
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return null;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            $mtime = @filemtime($path);
            if (is_int($mtime) && (time() - $mtime) > self::LOCK_TTL_SEC) {
                // stale lock — try once more after truncate hint
                flock($fh, LOCK_UN);
                fclose($fh);
                @unlink($path);
                $fh = @fopen($path, 'c+');
                if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
                    return null;
                }
            } else {
                fclose($fh);
                return null;
            }
        }
        ftruncate($fh, 0);
        fwrite($fh, (string)getmypid() . ' ' . gmdate('c'));
        fflush($fh);
        return $fh;
    }

    /** @param resource $fh */
    private static function releaseLock($fh): void
    {
        flock($fh, LOCK_UN);
        fclose($fh);
        @unlink(self::workDir() . '/.lock');
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create ' . $dir);
            }
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Not writable: ' . $dir);
        }
    }

    private static function rmTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $p = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    // --- Chunked apply jobs (UI progress) ------------------------------------

    /**
     * @return array<string,mixed>
     */
    public static function jobStart(?string $target = null, bool $migrate = true): array
    {
        $onlyNewer = $target === null || trim((string)$target) === '' || strtolower(trim((string)$target)) === 'all';
        $packages = self::resolvePackages($target, $onlyNewer);
        if ($packages === []) {
            throw new RuntimeException(t('updates.job.nothing'));
        }
        $id = bin2hex(random_bytes(8));
        $job = [
            'id' => $id,
            'status' => 'running',
            'progress' => 0,
            'message' => t('updates.job.starting'),
            'step' => 'resolve',
            'step_index' => 0,
            'target_index' => 0,
            'targets' => $packages,
            'migrate' => $migrate,
            'results' => [],
            'work' => null,
            'error' => '',
            'created_at' => time(),
            'updated_at' => time(),
        ];
        self::jobSave($job);
        return $job;
    }

    /** @return array<string,mixed> */
    public static function jobGet(string $id): array
    {
        $path = self::jobPath($id);
        if (!is_file($path)) {
            throw new RuntimeException('Unknown update job');
        }
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException('Corrupt update job');
        }
        return $data;
    }

    /** @return array<string,mixed> */
    public static function jobPublic(array $job): array
    {
        $targets = is_array($job['targets'] ?? null) ? $job['targets'] : [];
        $ti = (int)($job['target_index'] ?? 0);
        $cur = $targets[$ti] ?? null;
        $curId = is_array($cur) ? (string)($cur['id'] ?? '') : '';
        return [
            'id' => (string)($job['id'] ?? ''),
            'status' => (string)($job['status'] ?? ''),
            'progress' => (int)($job['progress'] ?? 0),
            'message' => (string)($job['message'] ?? ''),
            'step' => (string)($job['step'] ?? ''),
            'target' => $curId,
            'target_index' => $ti,
            'targets_total' => count($targets),
            'error' => (string)($job['error'] ?? ''),
            'results' => $job['results'] ?? [],
        ];
    }

    /** @return array<string,mixed> */
    public static function jobCancel(string $id): array
    {
        $job = self::jobGet($id);
        if (($job['status'] ?? '') === 'running') {
            $job['status'] = 'cancelled';
            $job['message'] = t('updates.job.cancelled');
            $job['updated_at'] = time();
            self::jobCleanupWork($job);
            self::jobSave($job);
        }
        return $job;
    }

    /** @return array<string,mixed> */
    public static function jobTick(string $id): array
    {
        $job = self::jobGet($id);
        if (($job['status'] ?? '') !== 'running') {
            return $job;
        }
        $lock = self::acquireLock();
        if ($lock === null) {
            $job['message'] = t('updates.job.waiting_lock');
            $job['updated_at'] = time();
            self::jobSave($job);
            return $job;
        }
        try {
            $targets = is_array($job['targets'] ?? null) ? $job['targets'] : [];
            $ti = (int)($job['target_index'] ?? 0);
            if ($ti >= count($targets)) {
                $job['status'] = 'done';
                $job['progress'] = 100;
                $job['message'] = t('updates.job.done');
                $job['updated_at'] = time();
                self::jobSave($job);
                return $job;
            }
            $source = $targets[$ti];
            $step = (string)($job['step'] ?? 'resolve');
            $sid = (string)($source['id'] ?? '');
            $work = is_array($job['work'] ?? null) ? $job['work'] : [];

            if ($step === 'resolve') {
                $job['message'] = t('updates.job.step_resolve', ['id' => $sid]);
                $remote = self::packageRemote($source);
                $stamp = gmdate('YmdHis') . '-' . preg_replace('/[^a-z0-9_]+/i', '', $sid);
                $work = [
                    'remote' => $remote,
                    'zip' => self::workDir() . '/' . $stamp . '.zip',
                    'stage' => self::workDir() . '/' . $stamp . '-stage',
                    'copied' => 0,
                ];
                $job['work'] = $work;
                $job['step'] = 'download';
            } elseif ($step === 'download') {
                $job['message'] = t('updates.job.step_download', ['id' => $sid]);
                self::ensureDir(self::workDir());
                self::downloadFile((string)$work['remote']['url'], (string)$work['zip'], (string)$source['token']);
                $job['step'] = 'verify';
            } elseif ($step === 'verify') {
                $job['message'] = t('updates.job.step_verify', ['id' => $sid]);
                $sha = (string)($work['remote']['sha256'] ?? '');
                if ($sha !== '') {
                    $got = hash_file('sha256', (string)$work['zip']);
                    if (!is_string($got) || !hash_equals(strtolower($sha), strtolower($got))) {
                        throw new RuntimeException('SHA-256 mismatch for ' . $sid);
                    }
                }
                $job['step'] = 'extract';
            } elseif ($step === 'extract') {
                $job['message'] = t('updates.job.step_extract', ['id' => $sid]);
                self::extractZip((string)$work['zip'], (string)$work['stage']);
                $job['step'] = 'overlay';
            } elseif ($step === 'overlay') {
                $job['message'] = t('updates.job.step_overlay', ['id' => $sid]);
                $payloadRoot = self::findPayloadRoot(
                    (string)$work['stage'],
                    (string)($source['path_prefix'] ?? ''),
                    (string)$source['kind'],
                    $sid
                );
                $work['copied'] = self::overlay($payloadRoot, (string)$source['kind'], $sid);
                $job['work'] = $work;
                $job['step'] = !empty($job['migrate']) ? 'migrate' : 'finalize';
            } elseif ($step === 'migrate') {
                $job['message'] = t('updates.job.step_migrate', ['id' => $sid]);
                if ($source['kind'] === 'module') {
                    $mig = AgentOps::migrateRun($sid);
                } else {
                    $mig = AgentOps::migrateRun(null);
                }
                $work['migrate'] = $mig;
                $job['work'] = $work;
                if (empty($mig['ok'])) {
                    throw new RuntimeException((string)($mig['error'] ?? 'Migration failed for ' . $sid));
                }
                $job['step'] = 'finalize';
            } elseif ($step === 'finalize') {
                $job['message'] = t('updates.job.step_finalize', ['id' => $sid]);
                $remote = is_array($work['remote'] ?? null) ? $work['remote'] : [];
                $mig = $work['migrate'] ?? ['ok' => true, 'skipped' => true];
                $health = null;
                try {
                    $health = AgentOps::health($source['kind'] === 'module' ? $sid : null);
                } catch (Throwable $e) {
                    $health = ['ok' => false, 'error' => $e->getMessage()];
                }
                $state = self::readState();
                $entry = [
                    'version' => (string)($remote['version'] ?? ''),
                    'applied_at' => gmdate('c'),
                    'ok' => true,
                    'artifact' => $remote['artifact'] ?? null,
                ];
                if ($source['kind'] === 'core') {
                    $state['core'] = $entry;
                } else {
                    if (!isset($state['modules']) || !is_array($state['modules'])) {
                        $state['modules'] = [];
                    }
                    $state['modules'][$sid] = $entry;
                }
                self::writeState($state);
                $job['results'][] = [
                    'ok' => true,
                    'id' => $sid,
                    'kind' => $source['kind'],
                    'version' => (string)($remote['version'] ?? ''),
                    'files_copied' => (int)($work['copied'] ?? 0),
                    'migrate' => $mig,
                    'health' => $health,
                ];
                @unlink((string)($work['zip'] ?? ''));
                self::rmTree((string)($work['stage'] ?? ''));
                $job['work'] = null;
                $job['target_index'] = $ti + 1;
                $job['step'] = 'resolve';
                $job['step_index'] = 0;
            } else {
                $job['step'] = 'resolve';
            }

            $nTargets = max(1, count($targets));
            $stepList = self::JOB_STEPS;
            $si = array_search((string)$job['step'], $stepList, true);
            if ($si === false) {
                $si = 0;
            }
            // Progress within current target + completed targets
            $doneTargets = (int)$job['target_index'];
            if ($job['step'] === 'resolve' && $doneTargets > $ti) {
                // advanced
            }
            $frac = ($doneTargets + (($si + 1) / max(1, count($stepList)))) / $nTargets;
            if (($job['target_index'] ?? 0) >= $nTargets) {
                $job['status'] = 'done';
                $job['progress'] = 100;
                $job['message'] = t('updates.job.done');
            } else {
                $job['progress'] = (int)min(99, floor($frac * 100));
            }
            $job['updated_at'] = time();
            self::jobSave($job);
            return $job;
        } catch (Throwable $e) {
            $job['status'] = 'error';
            $job['error'] = $e->getMessage();
            $job['message'] = $e->getMessage();
            $job['updated_at'] = time();
            self::jobCleanupWork($job);
            self::jobSave($job);
            return $job;
        } finally {
            self::releaseLock($lock);
        }
    }

    private static function jobPath(string $id): string
    {
        $id = preg_replace('/[^a-f0-9]/i', '', $id) ?? '';
        if ($id === '') {
            throw new InvalidArgumentException('Bad job id');
        }
        return self::workDir() . '/job-' . $id . '.json';
    }

    /** @param array<string,mixed> $job */
    private static function jobSave(array $job): void
    {
        self::ensureDir(self::workDir());
        $path = self::jobPath((string)$job['id']);
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Cannot write update job');
        }
    }

    /** @param array<string,mixed> $job */
    private static function jobCleanupWork(array &$job): void
    {
        $work = is_array($job['work'] ?? null) ? $job['work'] : null;
        if ($work === null) {
            return;
        }
        if (!empty($work['zip'])) {
            @unlink((string)$work['zip']);
        }
        if (!empty($work['stage'])) {
            self::rmTree((string)$work['stage']);
        }
        $job['work'] = null;
    }
}
