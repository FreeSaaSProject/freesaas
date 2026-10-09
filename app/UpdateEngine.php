<?php
declare(strict_types=1);

/**
 * Self-update: download release zips (HTTPS + optional token) and overlay onto the install.
 * Sources: cfg/updates.ini (see cfg/updates.example.ini). Never overwrites cfg/ or uploads.
 */
final class UpdateEngine
{
    private const MANIFEST_ASSET = 'freesaas-manifest.json';
    private const USER_AGENT = 'FreeSaaS-UpdateEngine/1.0';
    private const LOCK_TTL_SEC = 1800;

    /** Paths never written by a core overlay (relative to base_path). */
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
     * @return array{ok:bool,configured:bool,sources:list,state:array,http:int,error?:string}
     */
    public static function status(): array
    {
        $sources = self::sources();
        $state = self::readState();
        $rows = [];
        foreach ($sources as $s) {
            $id = $s['id'];
            $applied = null;
            if ($s['kind'] === 'core') {
                $applied = $state['core'] ?? null;
            } else {
                $applied = ($state['modules'][$id] ?? null);
            }
            $rows[] = [
                'id' => $id,
                'kind' => $s['kind'],
                'provider' => $s['provider'],
                'repo' => $s['repo'],
                'channel' => $s['channel'],
                'token_set' => $s['token_set'],
                'local_version' => self::localVersion($id),
                'applied' => $applied,
            ];
        }
        return [
            'ok' => true,
            'configured' => $sources !== [],
            'ini' => self::updatesIniPath(),
            'example' => self::exampleIniPath(),
            'sources' => $rows,
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
            $sources = self::resolveSources($target);
            if ($sources === []) {
                return [
                    'ok' => false,
                    'error' => 'No update sources configured (copy cfg/updates.example.ini → cfg/updates.ini)',
                    'targets' => [],
                    'http' => 400,
                ];
            }
            $targets = [];
            foreach ($sources as $s) {
                $remote = self::resolveRemote($s);
                $local = self::localVersion($s['id']);
                $updateAvailable = self::isNewer($remote['version'], $local);
                $targets[] = [
                    'id' => $s['id'],
                    'kind' => $s['kind'],
                    'local_version' => $local,
                    'remote_version' => $remote['version'],
                    'update_available' => $updateAvailable,
                    'channel' => $s['channel'],
                    'tag' => $remote['tag'] ?? null,
                    'artifact' => $remote['artifact'] ?? null,
                    'url' => $remote['url'],
                    'sha256' => $remote['sha256'] ?? null,
                ];
            }
            $state = self::readState();
            $state['last_check'] = gmdate('c');
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
            $sources = self::resolveSources($target);
            if ($sources === []) {
                return [
                    'ok' => false,
                    'error' => 'No update sources configured (copy cfg/updates.example.ini → cfg/updates.ini)',
                    'results' => [],
                    'http' => 400,
                ];
            }
            $results = [];
            $allOk = true;
            foreach ($sources as $s) {
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
            $remote = self::resolveRemote($source);
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
     * @return list<array<string,mixed>>
     */
    private static function resolveSources(?string $target): array
    {
        $all = self::sources();
        if ($target === null || trim($target) === '' || strtolower(trim($target)) === 'all') {
            return $all;
        }
        $want = self::normalizeTarget($target);
        foreach ($all as $s) {
            if ($s['id'] === $want) {
                return [$s];
            }
        }
        throw new InvalidArgumentException('Unknown update target: ' . $want . ' (not in cfg/updates.ini)');
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
            $dl = (string)($a['browser_download_url'] ?? '');
            if ($name === self::MANIFEST_ASSET && $dl !== '') {
                $manifestAsset = $a;
            }
            if ($wantAsset !== '' && $name === $wantAsset && $dl !== '') {
                $zipAsset = $a;
            }
        }
        if ($manifestAsset !== null) {
            $mBody = self::httpGet((string)$manifestAsset['browser_download_url'], $token);
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
                if ($name !== '' && preg_match('/\.zip$/i', $name)) {
                    $zipAsset = $a;
                    break;
                }
            }
        }
        if ($zipAsset !== null) {
            return [
                'version' => $version,
                'url' => (string)$zipAsset['browser_download_url'],
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
                $dl = (string)($a['browser_download_url'] ?? '');
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
        $private = array_fill_keys(self::privateModuleIds(), true);
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
                $destRel = $rel;
                if (self::isDenied($destRel)) {
                    continue;
                }
                if (preg_match('#^modules/([a-z][a-z0-9_]*)(/|$)#', $destRel, $m)) {
                    $modId = $m[1];
                    if (isset($private[$modId])) {
                        continue;
                    }
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

    private static function httpGet(string $url, string $token): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl extension required for updates');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        $headers = [
            'Accept: application/vnd.github+json, application/json',
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
}
