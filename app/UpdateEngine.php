<?php
declare(strict_types=1);

/**
 * Self-update engine.
 *
 * Check: reads VERSION + each modules/<id>/module.json from the git ref (Contents API).
 * Apply (monorepo): one zipball/release zip for the whole tree; overlay selected paths.
 * External modules ([module.x] other repo): own archive.
 *
 * Never overwrites cfg/, uploads, var/, log/.
 */
final class UpdateEngine
{
    private const USER_AGENT = 'FreeSaaS-UpdateEngine/1.0';
    private const LOCK_TTL_SEC = 1800;

    private const DENY_PREFIXES = [
        'cfg/',
        'var/',
        'log/',
        '.git/',
        'assets/uploads/',
    ];

    private const DENY_FILES = [
        'cfg/config.ini',
        'cfg/updates.ini',
        'cfg/updates.state.json',
        '.gitignore',
    ];

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
     * @return list<array<string,mixed>>
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
        foreach (self::sources() as $s) {
            if ($s['kind'] === 'module') {
                $ids[(string)$s['id']] = true;
            }
        }
        return array_keys($ids);
    }

    /** Human label for UI (translated module name). */
    public static function packageLabel(string $target): string
    {
        $target = self::normalizeTarget($target);
        if ($target === 'core') {
            return t('updates.pkg.core');
        }
        $meta = ModuleManager::get($target);
        if (is_array($meta)) {
            $key = trim((string)($meta['name'] ?? ''));
            if ($key !== '') {
                return t($key);
            }
        }
        return $target;
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

    public static function repoFromUrl(string $url): string
    {
        $url = trim($url);
        if (preg_match('#github\.com[:/]+([^/]+)/([^/.\s]+)#i', $url, $m)) {
            return $m[1] . '/' . preg_replace('/\.git$/i', '', $m[2]);
        }
        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $url)) {
            return $url;
        }
        return '';
    }

    /**
     * Candidates: module.json update.repository without [module.id] ini (external later).
     *
     * @return list<array<string,mixed>>
     */
    public static function moduleHints(): array
    {
        $configured = array_fill_keys(self::privateModuleIds(), true);
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
                'repo' => self::repoFromUrl($repository),
                'channel' => trim((string)($upd['channel'] ?? 'main')) ?: 'main',
                'local_version' => self::localVersion($id),
                'configured' => false,
            ];
        }
        usort($hints, static function (array $a, array $b): int {
            return strcmp($a['id'], $b['id']);
        });
        return $hints;
    }

    /**
     * Packages to check/apply: monorepo core + all local modules (except those with own [module.x]),
     * plus external [module.x] sources.
     *
     * @return list<array<string,mixed>>
     */
    public static function discoverPackages(?string $target = null): array
    {
        $ini = self::sources();
        $core = null;
        $external = [];
        foreach ($ini as $s) {
            if ($s['id'] === 'core') {
                $core = $s;
            } else {
                $external[$s['id']] = $s;
            }
        }
        $packages = [];
        if ($core !== null) {
            $core['bundle'] = 'monorepo';
            $packages[] = $core;
            foreach (ModuleManager::all() as $id => $_) {
                $id = (string)$id;
                if ($id === 'core' || isset($external[$id])) {
                    continue;
                }
                // Disabled modules: skip check/UI (still get files when core full-zip applies).
                if (!ModuleManager::isEnabled($id)) {
                    continue;
                }
                $packages[] = [
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
            }
        }
        foreach ($external as $s) {
            $s['bundle'] = 'external';
            $packages[] = $s;
        }

        if ($target !== null && trim($target) !== '' && strtolower(trim($target)) !== 'all') {
            $want = self::normalizeTarget($target);
            foreach ($packages as $p) {
                if ($p['id'] === $want) {
                    return [$p];
                }
            }
            throw new InvalidArgumentException('Unknown update target: ' . $want);
        }

        usort($packages, static function (array $a, array $b): int {
            if ($a['id'] === 'core') {
                return -1;
            }
            if ($b['id'] === 'core') {
                return 1;
            }
            return strcmp((string)$a['id'], (string)$b['id']);
        });
        return $packages;
    }

    /** Alias used by older call sites. */
    public static function resolvePackages(?string $target = null, bool $onlyNewer = false): array
    {
        $all = self::discoverPackages($target);
        if (!$onlyNewer) {
            return $all;
        }
        $cached = [];
        $state = self::readState();
        if (!empty($state['last_check_targets']) && is_array($state['last_check_targets'])) {
            foreach ($state['last_check_targets'] as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $cached[(string)$row['id']] = $row;
                }
            }
        }
        $out = [];
        foreach ($all as $p) {
            $id = (string)$p['id'];
            if (isset($cached[$id])) {
                if (!empty($cached[$id]['update_available'])) {
                    if (!empty($cached[$id]['url'])) {
                        $p['_remote'] = [
                            'version' => (string)($cached[$id]['remote_version'] ?? ''),
                            'url' => (string)$cached[$id]['url'],
                            'sha256' => $cached[$id]['sha256'] ?? null,
                            'artifact' => $cached[$id]['artifact'] ?? null,
                            'ref' => $cached[$id]['ref'] ?? null,
                        ];
                    }
                    $out[] = $p;
                }
                continue;
            }
            $remote = self::resolveRemoteMeta($p);
            if (self::isNewer((string)$remote['version'], self::localVersion($id))) {
                $p['_remote'] = $remote;
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function enrichStatusRow(array $row): array
    {
        $id = (string)($row['id'] ?? '');
        $row['label'] = self::packageLabel($id);
        $row['enabled'] = ($id === 'core') || ModuleManager::isEnabled($id);
        return $row;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function sortStatusRows(array &$rows): void
    {
        usort($rows, static function (array $a, array $b): int {
            $aa = !empty($a['update_available']) ? 0 : 1;
            $bb = !empty($b['update_available']) ? 0 : 1;
            if ($aa !== $bb) {
                return $aa <=> $bb;
            }
            $ca = ((string)($a['id'] ?? '') === 'core') ? 0 : 1;
            $cb = ((string)($b['id'] ?? '') === 'core') ? 0 : 1;
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }
            return strcasecmp((string)($a['label'] ?? $a['id'] ?? ''), (string)($b['label'] ?? $b['id'] ?? ''));
        });
    }

    /**
     * @return array{ok:bool,configured:bool,sources:list,hints:list,last_check:?string,available_count:int,source:?array,settings:array,state:array,http:int}
     */
    public static function status(): array
    {
        $ini = self::sources();
        $state = self::readState();
        $lastCheck = isset($state['last_check']) ? (string)$state['last_check'] : null;
        $rows = [];
        $external = [];
        foreach ($ini as $s) {
            if ($s['id'] !== 'core') {
                $external[(string)$s['id']] = true;
            }
        }

        if (!empty($state['last_check_targets']) && is_array($state['last_check_targets'])) {
            foreach ($state['last_check_targets'] as $t) {
                if (!is_array($t) || empty($t['id'])) {
                    continue;
                }
                $id = (string)$t['id'];
                if ($id !== 'core' && !isset($external[$id]) && !ModuleManager::isEnabled($id)) {
                    continue;
                }
                $rows[] = self::enrichStatusRow([
                    'id' => $id,
                    'kind' => (string)($t['kind'] ?? ($id === 'core' ? 'core' : 'module')),
                    'bundle' => (string)($t['bundle'] ?? 'monorepo'),
                    'repo' => (string)($t['repo'] ?? ''),
                    'channel' => (string)($t['channel'] ?? ''),
                    'ref' => (string)($t['ref'] ?? ''),
                    'local_version' => (string)($t['local_version'] ?? self::localVersion($id)),
                    'remote_version' => (string)($t['remote_version'] ?? ''),
                    'update_available' => !empty($t['update_available']),
                    'configured' => true,
                ]);
            }
        } else {
            try {
                foreach (self::discoverPackages(null) as $p) {
                    $rows[] = self::enrichStatusRow([
                        'id' => $p['id'],
                        'kind' => $p['kind'],
                        'bundle' => (string)($p['bundle'] ?? ''),
                        'repo' => (string)($p['repo'] ?? ''),
                        'channel' => (string)($p['channel'] ?? ''),
                        'ref' => '',
                        'local_version' => self::localVersion((string)$p['id']),
                        'remote_version' => '',
                        'update_available' => false,
                        'configured' => true,
                    ]);
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        self::sortStatusRows($rows);

        $availableList = [];
        foreach ($rows as $r) {
            if (!empty($r['update_available'])) {
                $availableList[] = [
                    'id' => (string)($r['id'] ?? ''),
                    'label' => (string)($r['label'] ?? self::packageLabel((string)($r['id'] ?? ''))),
                    'local_version' => (string)($r['local_version'] ?? ''),
                    'remote_version' => (string)($r['remote_version'] ?? ''),
                ];
            }
        }

        $coreSrc = null;
        foreach ($ini as $s) {
            if ($s['id'] === 'core') {
                $coreSrc = $s;
                break;
            }
        }

        return [
            'ok' => true,
            'configured' => $ini !== [],
            'ini' => self::updatesIniPath(),
            'example' => self::exampleIniPath(),
            'sources' => $rows,
            'hints' => self::moduleHints(),
            'last_check' => $lastCheck,
            'available_count' => count($availableList),
            'available' => $availableList,
            'source' => $coreSrc !== null ? [
                'provider' => (string)$coreSrc['provider'],
                'repo' => (string)$coreSrc['repo'],
                'channel' => (string)$coreSrc['channel'],
                'ref' => (string)($coreSrc['ref'] ?? ''),
                'asset' => (string)($coreSrc['asset'] ?? ''),
            ] : null,
            'settings' => self::coreSettings(),
            'state' => $state,
            'http' => 200,
        ];
    }

    /**
     * Form values for /updates/settings (secrets masked).
     *
     * @return array<string,mixed>
     */
    public static function coreSettings(): array
    {
        $core = null;
        foreach (self::sources() as $s) {
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
            'configured' => is_file(self::updatesIniPath()),
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

        $path = self::updatesIniPath();
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

        $lines = [
            '; FreeSaaS self-update (written by Updates settings UI). Do not commit.',
            '; Check: Contents API. Apply: zipball (channel=branch) or full release zip (stable).',
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

    /**
     * @return array{ok:bool,targets:list,last_check:string,http:int,error?:string}
     */
    public static function check(?string $target = null): array
    {
        try {
            $packages = self::discoverPackages($target);
            if ($packages === []) {
                return [
                    'ok' => false,
                    'error' => 'No update sources (copy cfg/updates.example.ini → cfg/updates.ini)',
                    'targets' => [],
                    'last_check' => '',
                    'http' => 400,
                ];
            }
            $targets = [];
            foreach ($packages as $p) {
                $targets[] = self::checkOnePackage($p);
            }
            self::sortStatusRows($targets);
            $now = gmdate('c');
            $state = self::readState();
            $state['last_check'] = $now;
            $state['last_check_targets'] = $targets;
            self::writeState($state);
            return ['ok' => true, 'targets' => $targets, 'last_check' => $now, 'http' => 200];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'targets' => [], 'last_check' => '', 'http' => 400];
        }
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function checkOnePackage(array $p): array
    {
        $remote = self::resolveRemoteMeta($p);
        $local = self::localVersion((string)$p['id']);
        $remoteVer = (string)$remote['version'];
        return self::enrichStatusRow([
            'id' => $p['id'],
            'kind' => $p['kind'],
            'bundle' => (string)($p['bundle'] ?? ''),
            'repo' => (string)($p['repo'] ?? ''),
            'channel' => (string)($p['channel'] ?? ''),
            'ref' => (string)($remote['ref'] ?? ''),
            'local_version' => $local,
            'remote_version' => $remoteVer,
            'update_available' => self::isNewer($remoteVer, $local),
            'url' => (string)($remote['url'] ?? ''),
            'sha256' => $remote['sha256'] ?? null,
            'artifact' => $remote['artifact'] ?? null,
        ]);
    }

    /** Progressive check for UI (one package per tick). */
    public static function checkJobStart(?string $target = null): array
    {
        $packages = self::discoverPackages($target);
        if ($packages === []) {
            throw new RuntimeException(t('updates.missing_ini'));
        }
        $id = 'chk-' . bin2hex(random_bytes(8));
        $job = [
            'id' => $id,
            'kind' => 'check',
            'status' => 'running',
            'progress' => 0,
            'message' => t('updates.check.starting'),
            'step' => 'check',
            'target_index' => 0,
            'targets' => $packages,
            'results' => [],
            'error' => '',
            'created_at' => time(),
            'updated_at' => time(),
        ];
        self::jobSave($job);
        return $job;
    }

    /** @return array<string,mixed> */
    public static function checkJobTick(string $id): array
    {
        $job = self::jobGet($id);
        if (($job['kind'] ?? '') !== 'check') {
            throw new RuntimeException('Not a check job');
        }
        if (($job['status'] ?? '') !== 'running') {
            return $job;
        }
        $targets = is_array($job['targets'] ?? null) ? $job['targets'] : [];
        $ti = (int)($job['target_index'] ?? 0);
        $n = count($targets);
        if ($ti >= $n) {
            $results = is_array($job['results'] ?? null) ? $job['results'] : [];
            self::sortStatusRows($results);
            $now = gmdate('c');
            $state = self::readState();
            $state['last_check'] = $now;
            $state['last_check_targets'] = $results;
            self::writeState($state);
            $job['status'] = 'done';
            $job['progress'] = 100;
            $job['message'] = t('updates.check_ok');
            $job['last_check'] = $now;
            $job['updated_at'] = time();
            self::jobSave($job);
            return $job;
        }
        $p = $targets[$ti];
        $pid = (string)($p['id'] ?? '');
        $label = self::packageLabel($pid);
        $job['message'] = t('updates.check.step', [
            'name' => $label,
            'n' => (string)($ti + 1),
            'total' => (string)$n,
        ]);
        try {
            $row = self::checkOnePackage($p);
            if (!isset($job['results']) || !is_array($job['results'])) {
                $job['results'] = [];
            }
            $job['results'][] = $row;
            $job['target_index'] = $ti + 1;
            $job['progress'] = (int)floor((($ti + 1) / max(1, $n)) * 100);
            $job['updated_at'] = time();
            self::jobSave($job);
            return $job;
        } catch (Throwable $e) {
            $job['status'] = 'error';
            $job['error'] = $e->getMessage();
            $job['message'] = $e->getMessage();
            $job['updated_at'] = time();
            self::jobSave($job);
            return $job;
        }
    }

    /**
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
                        ? 'Nothing to apply (no newer packages). Run Check first.'
                        : 'Unknown or empty update target',
                    'results' => [],
                    'http' => 400,
                ];
            }
            $results = [];
            $allOk = true;
            $archiveCache = [];
            foreach ($packages as $p) {
                $one = self::applyOne($p, $migrate, $archiveCache);
                $results[] = $one;
                if (empty($one['ok'])) {
                    $allOk = false;
                }
            }
            return ['ok' => $allOk, 'results' => $results, 'http' => $allOk ? 200 : 500];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'results' => [], 'http' => 400];
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,array{zip:string,stage:string}> $archiveCache
     * @return array<string,mixed>
     */
    private static function applyOne(array $source, bool $migrate, array &$archiveCache): array
    {
        $id = (string)$source['id'];
        try {
            $remote = isset($source['_remote']) && is_array($source['_remote'])
                ? $source['_remote']
                : self::resolveRemoteMeta($source);

            $cacheKey = ((string)($source['bundle'] ?? '')) . '|' . ((string)($source['repo'] ?? '')) . '|' . ((string)($remote['ref'] ?? '')) . '|' . ((string)($remote['url'] ?? ''));
            if (!isset($archiveCache[$cacheKey])) {
                self::ensureDir(self::workDir());
                $stamp = gmdate('YmdHis') . '-' . substr(sha1($cacheKey), 0, 8);
                $zipPath = self::workDir() . '/' . $stamp . '.zip';
                $stageDir = self::workDir() . '/' . $stamp . '-stage';
                self::downloadFile((string)$remote['url'], $zipPath, (string)$source['token']);
                $sha = (string)($remote['sha256'] ?? '');
                if ($sha !== '') {
                    $got = hash_file('sha256', $zipPath);
                    if (!is_string($got) || !hash_equals(strtolower($sha), strtolower($got))) {
                        throw new RuntimeException('SHA-256 mismatch for ' . $id);
                    }
                }
                self::extractZip($zipPath, $stageDir);
                $archiveCache[$cacheKey] = ['zip' => $zipPath, 'stage' => $stageDir];
            }
            $stageDir = $archiveCache[$cacheKey]['stage'];
            $payloadRoot = self::findPayloadRoot(
                $stageDir,
                (string)($source['path_prefix'] ?? ''),
                (string)$source['kind'],
                $id
            );
            $copied = self::overlay($payloadRoot, (string)$source['kind'], $id, (string)($source['bundle'] ?? ''));

            if ($migrate) {
                $mig = $source['kind'] === 'module'
                    ? AgentOps::migrateRun($id)
                    : AgentOps::migrateRun(null);
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
                'ref' => $remote['ref'] ?? null,
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
            return ['ok' => false, 'id' => $id, 'kind' => $source['kind'], 'error' => $e->getMessage()];
        }
    }

    /**
     * Resolve remote version + archive URL for a package.
     *
     * @param array<string,mixed> $pkg
     * @return array{version:string,url:string,sha256?:?string,artifact?:?string,ref?:string}
     */
    private static function resolveRemoteMeta(array $pkg): array
    {
        if (isset($pkg['_remote']) && is_array($pkg['_remote']) && !empty($pkg['_remote']['url'])) {
            /** @var array{version:string,url:string,sha256?:?string,artifact?:?string,ref?:string} */
            return $pkg['_remote'];
        }
        $provider = (string)($pkg['provider'] ?? 'github');
        if ($provider === 'http') {
            return self::resolveHttpMeta($pkg);
        }
        return self::resolveGithubMeta($pkg);
    }

    /**
     * @param array<string,mixed> $pkg
     * @return array{version:string,url:string,sha256?:?string,artifact?:?string,ref?:string}
     */
    private static function resolveHttpMeta(array $pkg): array
    {
        $manifestUrl = (string)($pkg['manifest_url'] ?: $pkg['url']);
        if ($manifestUrl === '') {
            throw new RuntimeException('http provider needs manifest_url');
        }
        $data = json_decode(self::httpGet($manifestUrl, (string)$pkg['token']), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid HTTP manifest');
        }
        $version = trim((string)($data['version'] ?? ''));
        $url = trim((string)($data['url'] ?? $data['download_url'] ?? ''));
        if ($version === '' || $url === '') {
            throw new RuntimeException('HTTP manifest missing version/url');
        }
        $out = ['version' => $version, 'url' => $url, 'artifact' => (string)($data['artifact'] ?? basename($url))];
        if (!empty($data['sha256'])) {
            $out['sha256'] = (string)$data['sha256'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $pkg
     * @return array{version:string,url:string,sha256?:?string,artifact?:?string,ref:string}
     */
    private static function resolveGithubMeta(array $pkg): array
    {
        $repo = (string)$pkg['repo'];
        if ($repo === '' || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new RuntimeException('github provider requires repo=owner/name');
        }
        $token = (string)$pkg['token'];
        $ref = self::resolveGitRef($pkg);
        $bundle = (string)($pkg['bundle'] ?? 'monorepo');
        $id = (string)$pkg['id'];
        $kind = (string)$pkg['kind'];

        // Version from live files on the ref (no release catalog required).
        if ($kind === 'core') {
            $ver = trim(self::githubFileContents($repo, 'VERSION', $ref, $token));
            if ($ver === '') {
                $raw = self::githubFileContents($repo, 'modules/core/module.json', $ref, $token);
                $j = json_decode($raw, true);
                $ver = is_array($j) ? trim((string)($j['version'] ?? '')) : '';
            }
        } else {
            $path = ($bundle === 'external' && (string)($pkg['path_prefix'] ?? '') === '')
                ? 'module.json'
                : ('modules/' . $id . '/module.json');
            if ($bundle === 'external' && (string)($pkg['path_prefix'] ?? '') !== '') {
                $path = rtrim((string)$pkg['path_prefix'], '/') . '/module.json';
            }
            $raw = self::githubFileContents($repo, $path, $ref, $token);
            $j = json_decode($raw, true);
            $ver = is_array($j) ? trim((string)($j['version'] ?? '')) : '';
        }
        if ($ver === '') {
            throw new RuntimeException('Cannot read remote version for ' . $id . ' @ ' . $ref);
        }

        $channel = strtolower((string)($pkg['channel'] ?? 'main'));
        $url = '';
        $sha = null;
        $artifact = null;

        // Stable: prefer one full release zip asset; else zipball of the release tag/ref.
        if ($channel === 'stable' || $channel === 'latest' || (isset($channel[0]) && $channel[0] === 'v')) {
            $release = self::githubRelease($repo, (string)$pkg['channel'], $token);
            $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
            $want = (string)($pkg['asset'] ?? '');
            $picked = null;
            foreach ($assets as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $name = (string)($a['name'] ?? '');
                if ($want !== '' && $name === $want) {
                    $picked = $a;
                    break;
                }
            }
            if ($picked === null) {
                // Full monorepo zip only: freesaas-1.2.3.zip (not core-/module-).
                foreach ($assets as $a) {
                    if (!is_array($a)) {
                        continue;
                    }
                    $name = (string)($a['name'] ?? '');
                    if (preg_match('/^freesaas-\d+\.\d+.*\.zip$/i', $name)) {
                        $picked = $a;
                        break;
                    }
                }
            }
            if ($picked !== null) {
                $url = self::githubAssetDownloadUrl($picked);
                $artifact = (string)($picked['name'] ?? '');
            }
            if ($url === '') {
                $url = self::githubArchiveUrl($repo, $ref);
                $artifact = 'zipball:' . $ref;
            }
        } else {
            // Branch / floating channel: archive of ref
            $url = self::githubArchiveUrl($repo, $ref);
            $artifact = 'zipball:' . $ref;
        }

        return [
            'version' => $ver,
            'url' => $url,
            'sha256' => $sha,
            'artifact' => $artifact,
            'ref' => $ref,
        ];
    }

    /**
     * @param array<string,mixed> $pkg
     */
    private static function resolveGitRef(array $pkg): string
    {
        $explicit = trim((string)($pkg['ref'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $channel = trim((string)($pkg['channel'] ?? 'main'));
        if ($channel === '' || $channel === 'stable' || $channel === 'latest') {
            if ($channel === 'stable' || $channel === 'latest') {
                $rel = self::githubRelease((string)$pkg['repo'], $channel, (string)$pkg['token']);
                return (string)($rel['tag_name'] ?? 'main');
            }
            return 'main';
        }
        return $channel;
    }

    /**
     * @return array<string,mixed>
     */
    private static function githubRelease(string $repo, string $channel, string $token): array
    {
        if ($channel === '' || $channel === 'stable' || $channel === 'latest') {
            $api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        } else {
            $api = 'https://api.github.com/repos/' . $repo . '/releases/tags/' . rawurlencode($channel);
        }
        $data = json_decode(self::httpGet($api, $token), true);
        if (!is_array($data) || empty($data['tag_name'])) {
            throw new RuntimeException('GitHub release not found for ' . $repo . ' channel=' . $channel);
        }
        return $data;
    }

    private static function githubFileContents(string $repo, string $path, string $ref, string $token): string
    {
        $api = 'https://api.github.com/repos/' . $repo . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)))
            . '?ref=' . rawurlencode($ref);
        $data = json_decode(self::httpGet($api, $token), true);
        if (!is_array($data)) {
            throw new RuntimeException('Cannot read ' . $path . ' @ ' . $ref);
        }
        if (($data['encoding'] ?? '') === 'base64' && isset($data['content'])) {
            $bin = base64_decode(preg_replace('/\s+/', '', (string)$data['content']) ?: '', true);
            return is_string($bin) ? $bin : '';
        }
        if (!empty($data['download_url'])) {
            return self::httpGet((string)$data['download_url'], $token);
        }
        throw new RuntimeException('Unexpected contents payload for ' . $path);
    }

    /** @param array<string,mixed> $asset */
    private static function githubAssetDownloadUrl(array $asset): string
    {
        $api = trim((string)($asset['url'] ?? ''));
        if ($api !== '' && strpos($api, 'api.github.com/') !== false) {
            return $api;
        }
        return trim((string)($asset['browser_download_url'] ?? ''));
    }

    private static function normalizeTarget(string $target): string
    {
        $t = strtolower(trim($target));
        if (strpos($t, 'module:') === 0) {
            $t = substr($t, strlen('module:'));
        }
        if ($t === '' || $t === 'core') {
            return 'core';
        }
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $t)) {
            throw new InvalidArgumentException('Invalid target id');
        }
        return $t;
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
            if (is_file($root . '/module.json')) {
                return $root;
            }
            $nested = $root . '/modules/' . $id;
            if (is_dir($nested)) {
                return $nested;
            }
            throw new RuntimeException('Module tree not found in archive: ' . $id);
        }
        // core / full tree root
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
    private static function overlay(string $fromDir, string $kind, string $id, string $bundle = ''): int
    {
        $base = rtrim(base_path(''), '/');
        $private = array_fill_keys(self::privateModuleIds(), true);
        $copied = 0;

        // Module apply: fromDir is already the module root → write into modules/<id>/
        // Core/full apply: fromDir is tree root → copy relative paths (skip private modules).
        if ($kind === 'module') {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fromDir, FilesystemIterator::SKIP_DOTS)
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $full = $file->getPathname();
                $rel = str_replace('\\', '/', substr($full, strlen($fromDir) + 1));
                if ($rel === '' || $rel === false) {
                    continue;
                }
                $destRel = 'modules/' . $id . '/' . $rel;
                if (self::isDenied($destRel)) {
                    continue;
                }
                $copied += self::copyOne($full, $base . '/' . $destRel) ? 1 : 0;
            }
            return $copied;
        }

        // Full / core tree overlay from monorepo zip
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fromDir, FilesystemIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $rel = str_replace('\\', '/', substr($full, strlen($fromDir) + 1));
            if ($rel === '' || $rel === false) {
                continue;
            }
            if (self::isDenied($rel)) {
                continue;
            }
            if (preg_match('#^modules/([a-z][a-z0-9_]*)(/|$)#', $rel, $m)) {
                if (isset($private[$m[1]])) {
                    continue; // owned by external [module.x]
                }
            }
            $copied += self::copyOne($full, $base . '/' . $rel) ? 1 : 0;
        }
        return $copied;
    }

    private static function copyOne(string $from, string $to): bool
    {
        $dir = dirname($to);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create directory: ' . $dir);
            }
        }
        if (!@copy($from, $to)) {
            throw new RuntimeException('Cannot write: ' . $to);
        }
        return true;
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
                if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                    throw new RuntimeException('Cannot create staging dir');
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
            // Release asset API needs octet-stream; zipball/tarball reject it with HTTP 415.
            $accept = self::downloadAcceptForUrl($url);
            $headers = [
                'Accept: ' . $accept,
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

    /** Accept header for archive download (GitHub API media types differ by endpoint). */
    private static function downloadAcceptForUrl(string $url): string
    {
        if (strpos($url, 'api.github.com/') !== false && strpos($url, '/releases/assets/') !== false) {
            return 'application/octet-stream';
        }
        if (strpos($url, 'api.github.com/') !== false
            && (strpos($url, '/zipball/') !== false || strpos($url, '/tarball/') !== false)
        ) {
            // octet-stream → HTTP 415 on /zipball/; vnd.github+json redirects to codeload.
            return 'application/vnd.github+json';
        }
        return '*/*';
    }

    /**
     * Archive URL for a git ref. Prefer codeload (works with Accept star/star);
     * API zipball needs application/vnd.github+json (octet-stream returns 415).
     */
    private static function githubArchiveUrl(string $repo, string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            $ref = 'main';
        }
        // API zipball resolves branch vs tag; keep as fallback path via downloadAcceptForUrl.
        // Codeload is more reliable when we know it's a branch name used as channel.
        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $ref)) {
            if ($ref[0] === 'v' || preg_match('/^\d+\.\d+/', $ref)) {
                return 'https://codeload.github.com/' . $repo . '/zip/refs/tags/' . rawurlencode($ref);
            }
            return 'https://codeload.github.com/' . $repo . '/zip/refs/heads/' . rawurlencode($ref);
        }
        return 'https://api.github.com/repos/' . $repo . '/zipball/' . rawurlencode($ref);
    }

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
            return ['core' => null, 'modules' => [], 'last_check' => null, 'last_check_targets' => []];
        }
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data)) {
            return ['core' => null, 'modules' => [], 'last_check' => null, 'last_check_targets' => []];
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
        if ($json === false || @file_put_contents($path, $json . "\n") === false) {
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

    // --- Jobs (progress UI) -------------------------------------------------

    /** @return array<string,mixed> */
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
            'target_index' => 0,
            'targets' => $packages,
            'migrate' => $migrate,
            'results' => [],
            'work' => null,
            'archives' => [],
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
        $found = [];
        $results = is_array($job['results'] ?? null) ? $job['results'] : [];
        foreach ($results as $row) {
            if (!is_array($row) || empty($row['update_available'])) {
                continue;
            }
            $fid = (string)($row['id'] ?? '');
            $found[] = [
                'id' => $fid,
                'label' => (string)($row['label'] ?? self::packageLabel($fid)),
                'local_version' => (string)($row['local_version'] ?? ''),
                'remote_version' => (string)($row['remote_version'] ?? ''),
            ];
        }
        return [
            'id' => (string)($job['id'] ?? ''),
            'kind' => (string)($job['kind'] ?? 'apply'),
            'status' => (string)($job['status'] ?? ''),
            'progress' => (int)($job['progress'] ?? 0),
            'message' => (string)($job['message'] ?? ''),
            'step' => (string)($job['step'] ?? ''),
            'target' => $curId,
            'target_label' => $curId !== '' ? self::packageLabel($curId) : '',
            'target_index' => $ti,
            'targets_total' => count($targets),
            'error' => (string)($job['error'] ?? ''),
            'results' => $results,
            'found' => $found,
            'found_count' => count($found),
            'last_check' => $job['last_check'] ?? null,
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
            self::jobCleanupArchives($job);
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
                self::jobCleanupArchives($job);
                $job['updated_at'] = time();
                self::jobSave($job);
                return $job;
            }
            $source = $targets[$ti];
            $sid = (string)($source['id'] ?? '');
            $step = (string)($job['step'] ?? 'resolve');
            $work = is_array($job['work'] ?? null) ? $job['work'] : [];
            $archives = is_array($job['archives'] ?? null) ? $job['archives'] : [];

            if ($step === 'resolve') {
                $job['message'] = t('updates.job.step_resolve', ['id' => $sid]);
                $remote = self::resolveRemoteMeta($source);
                $cacheKey = ((string)($source['bundle'] ?? '')) . '|' . ((string)($source['repo'] ?? '')) . '|' . ((string)($remote['ref'] ?? '')) . '|' . ((string)$remote['url']);
                $work = ['remote' => $remote, 'cache_key' => $cacheKey, 'copied' => 0];
                $job['work'] = $work;
                $job['step'] = isset($archives[$cacheKey]) ? 'overlay' : 'download';
            } elseif ($step === 'download') {
                $job['message'] = t('updates.job.step_download', ['id' => $sid]);
                self::ensureDir(self::workDir());
                $stamp = gmdate('YmdHis') . '-' . substr(sha1((string)$work['cache_key']), 0, 8);
                $zipPath = self::workDir() . '/' . $stamp . '.zip';
                $stageDir = self::workDir() . '/' . $stamp . '-stage';
                self::downloadFile((string)$work['remote']['url'], $zipPath, (string)$source['token']);
                $work['zip'] = $zipPath;
                $work['stage'] = $stageDir;
                $job['work'] = $work;
                $job['step'] = 'verify';
            } elseif ($step === 'verify') {
                $job['message'] = t('updates.job.step_verify', ['id' => $sid]);
                $sha = (string)($work['remote']['sha256'] ?? '');
                if ($sha !== '' && !empty($work['zip'])) {
                    $got = hash_file('sha256', (string)$work['zip']);
                    if (!is_string($got) || !hash_equals(strtolower($sha), strtolower($got))) {
                        throw new RuntimeException('SHA-256 mismatch for ' . $sid);
                    }
                }
                $job['step'] = 'extract';
            } elseif ($step === 'extract') {
                $job['message'] = t('updates.job.step_extract', ['id' => $sid]);
                self::extractZip((string)$work['zip'], (string)$work['stage']);
                $archives[(string)$work['cache_key']] = [
                    'zip' => (string)$work['zip'],
                    'stage' => (string)$work['stage'],
                ];
                $job['archives'] = $archives;
                $job['step'] = 'overlay';
            } elseif ($step === 'overlay') {
                $job['message'] = t('updates.job.step_overlay', ['id' => $sid]);
                $key = (string)($work['cache_key'] ?? '');
                $stage = (string)(($archives[$key]['stage'] ?? $work['stage'] ?? ''));
                $payloadRoot = self::findPayloadRoot(
                    $stage,
                    (string)($source['path_prefix'] ?? ''),
                    (string)$source['kind'],
                    $sid
                );
                $work['copied'] = self::overlay($payloadRoot, (string)$source['kind'], $sid, (string)($source['bundle'] ?? ''));
                $job['work'] = $work;
                $job['step'] = !empty($job['migrate']) ? 'migrate' : 'finalize';
            } elseif ($step === 'migrate') {
                $job['message'] = t('updates.job.step_migrate', ['id' => $sid]);
                $mig = $source['kind'] === 'module'
                    ? AgentOps::migrateRun($sid)
                    : AgentOps::migrateRun(null);
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
                $state = self::readState();
                $entry = [
                    'version' => (string)($remote['version'] ?? ''),
                    'applied_at' => gmdate('c'),
                    'ok' => true,
                    'ref' => $remote['ref'] ?? null,
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
                ];
                $job['work'] = null;
                $job['target_index'] = $ti + 1;
                $job['step'] = 'resolve';
            } else {
                $job['step'] = 'resolve';
            }

            $nTargets = max(1, count($targets));
            $si = array_search((string)$job['step'], self::JOB_STEPS, true);
            if ($si === false) {
                $si = 0;
            }
            $doneTargets = (int)$job['target_index'];
            $frac = ($doneTargets + (($si + 1) / max(1, count(self::JOB_STEPS)))) / $nTargets;
            if ($doneTargets >= $nTargets) {
                $job['status'] = 'done';
                $job['progress'] = 100;
                $job['message'] = t('updates.job.done');
                self::jobCleanupArchives($job);
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
            self::jobCleanupArchives($job);
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
    private static function jobCleanupArchives(array &$job): void
    {
        $archives = is_array($job['archives'] ?? null) ? $job['archives'] : [];
        foreach ($archives as $a) {
            if (!is_array($a)) {
                continue;
            }
            if (!empty($a['zip'])) {
                @unlink((string)$a['zip']);
            }
            if (!empty($a['stage'])) {
                self::rmTree((string)$a['stage']);
            }
        }
        $job['archives'] = [];
        $work = is_array($job['work'] ?? null) ? $job['work'] : null;
        if ($work !== null) {
            if (!empty($work['zip'])) {
                @unlink((string)$work['zip']);
            }
            if (!empty($work['stage'])) {
                self::rmTree((string)$work['stage']);
            }
            $job['work'] = null;
        }
    }
}
