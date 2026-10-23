<?php
declare(strict_types=1);

/** Update engine: UpdateCheck. */
final class UpdateCheck
{
    /**
     * Packages to check/apply: monorepo core + all local modules (except those with own [module.x]),
     * plus external [module.x] sources.
     *
     * @return list<array<string,mixed>>
     */
    public static function discoverPackages(?string $target = null): array
    {
        $ini = UpdateConfig::sources();
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
            $want = UpdateVersions::normalizeTarget($target);
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
        $all = UpdateCheck::discoverPackages($target);
        $frozen = array_fill_keys(UpdateConfig::frozenModules(), true);
        if ($frozen !== []) {
            $explicit = $target !== null && trim($target) !== '' && strtolower(trim($target)) !== 'all';
            $kept = [];
            foreach ($all as $p) {
                if (isset($frozen[(string)$p['id']])) {
                    if ($explicit) {
                        throw new InvalidArgumentException(t('updates.err_frozen', ['id' => (string)$p['id']]));
                    }
                    continue;
                }
                $kept[] = $p;
            }
            $all = $kept;
        }
        if (!$onlyNewer) {
            return $all;
        }
        $cached = [];
        $state = UpdateFs::readState();
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
            $remote = UpdateGithub::resolveRemoteMeta($p);
            if (UpdateVersions::isNewer((string)$remote['version'], UpdateVersions::localVersion($id))) {
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
    public static function enrichStatusRow(array $row): array
    {
        $id = (string)($row['id'] ?? '');
        $row['label'] = UpdateVersions::packageLabel($id);
        $row['enabled'] = ($id === 'core') || ModuleManager::isEnabled($id);
        $row['frozen'] = $id !== 'core' && UpdateConfig::isFrozen($id);
        if ($row['frozen']) {
            $row['update_available'] = false;
        }
        return $row;
    }

    /** @param list<array<string,mixed>> $rows */
    public static function sortStatusRows(array &$rows): void
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
        $ini = UpdateConfig::sources();
        $state = UpdateFs::readState();
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
                $rows[] = UpdateCheck::enrichStatusRow([
                    'id' => $id,
                    'kind' => (string)($t['kind'] ?? ($id === 'core' ? 'core' : 'module')),
                    'bundle' => (string)($t['bundle'] ?? 'monorepo'),
                    'repo' => (string)($t['repo'] ?? ''),
                    'channel' => (string)($t['channel'] ?? ''),
                    'ref' => (string)($t['ref'] ?? ''),
                    'local_version' => (string)($t['local_version'] ?? UpdateVersions::localVersion($id)),
                    'remote_version' => (string)($t['remote_version'] ?? ''),
                    'update_available' => !empty($t['update_available']),
                    'configured' => true,
                ]);
            }
        } else {
            try {
                foreach (UpdateCheck::discoverPackages(null) as $p) {
                    $rows[] = UpdateCheck::enrichStatusRow([
                        'id' => $p['id'],
                        'kind' => $p['kind'],
                        'bundle' => (string)($p['bundle'] ?? ''),
                        'repo' => (string)($p['repo'] ?? ''),
                        'channel' => (string)($p['channel'] ?? ''),
                        'ref' => '',
                        'local_version' => UpdateVersions::localVersion((string)$p['id']),
                        'remote_version' => '',
                        'update_available' => false,
                        'configured' => true,
                    ]);
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        UpdateCheck::sortStatusRows($rows);

        $availableList = [];
        foreach ($rows as $r) {
            if (!empty($r['update_available'])) {
                $availableList[] = [
                    'id' => (string)($r['id'] ?? ''),
                    'label' => (string)($r['label'] ?? UpdateVersions::packageLabel((string)($r['id'] ?? ''))),
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

        $installable = [];
        try {
            foreach (UpdateCatalog::availableToInstall(false) as $row) {
                $iid = (string)($row['id'] ?? '');
                $label = ModuleCatalog::display(
                    is_array($row['name'] ?? null) ? $row['name'] : [],
                    $iid
                );
                $installable[] = [
                    'id' => $iid,
                    'label' => $label !== '' ? $label : $iid,
                    'version' => (string)($row['version'] ?? ''),
                    'maturity' => (string)($row['maturity'] ?? 'beta'),
                ];
            }
        } catch (Throwable $e) {
            $installable = [];
        }

        return [
            'ok' => true,
            'configured' => $ini !== [],
            'ini' => UpdateConfig::updatesIniPath(),
            'example' => UpdateConfig::exampleIniPath(),
            'sources' => $rows,
            'hints' => UpdateCatalog::moduleHints(),
            'last_check' => $lastCheck,
            'available_count' => count($availableList),
            'available' => $availableList,
            'installable_count' => count($installable),
            'installable' => $installable,
            'source' => $coreSrc !== null ? [
                'provider' => (string)$coreSrc['provider'],
                'repo' => (string)$coreSrc['repo'],
                'channel' => (string)$coreSrc['channel'],
                'ref' => (string)($coreSrc['ref'] ?? ''),
                'asset' => (string)($coreSrc['asset'] ?? ''),
            ] : null,
            'settings' => UpdateConfig::coreSettings(),
            'frozen_modules' => UpdateConfig::frozenModules(),
            'conflicts' => UpdateConflicts::listAll(),
            'conflicts_count' => UpdateConflicts::count(),
            'state' => $state,
            'http' => 200,
        ];
    }

    /**
     * @return array{ok:bool,targets:list,last_check:string,http:int,error?:string}
     */
    public static function check(?string $target = null): array
    {
        try {
            $packages = UpdateCheck::discoverPackages($target);
            if ($packages === []) {
                return [
                    'ok' => false,
                    'error' => 'No update sources (copy cfg/updates.example.ini → cfg/updates.ini)',
                    'targets' => [],
                    'last_check' => '',
                    'http' => 400,
                ];
            }
            // Refresh remote module catalog (new packages not yet on disk).
            if ($target === null || trim((string)$target) === '' || strtolower(trim((string)$target)) === 'all') {
                UpdateCatalog::fetchRemoteCatalog(true);
            }
            $targets = [];
            foreach ($packages as $p) {
                $targets[] = UpdateCheck::checkOnePackage($p);
            }
            $now = UpdateCheck::persistCheckResults($targets, $target !== null && trim((string)$target) !== '' && strtolower(trim((string)$target)) !== 'all');
            $installable = UpdateCatalog::availableToInstall(false);
            return [
                'ok' => true,
                'targets' => $targets,
                'last_check' => $now,
                'installable_count' => count($installable),
                'installable' => array_map(static function (array $row): array {
                    $iid = (string)($row['id'] ?? '');
                    return [
                        'id' => $iid,
                        'version' => (string)($row['version'] ?? ''),
                        'maturity' => (string)($row['maturity'] ?? 'beta'),
                    ];
                }, $installable),
                'http' => 200,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'targets' => [], 'last_check' => '', 'http' => 400];
        }
    }

    /**
     * Save check results. Partial=true merges into existing last_check_targets (single-module check).
     *
     * @param list<array<string,mixed>> $results
     */
    public static function persistCheckResults(array $results, bool $partial): string
    {
        $now = gmdate('c');
        $state = UpdateFs::readState();
        if (!$partial) {
            UpdateCheck::sortStatusRows($results);
            $state['last_check'] = $now;
            $state['last_check_targets'] = $results;
            UpdateFs::writeState($state);
            return $now;
        }
        $byId = [];
        $existing = is_array($state['last_check_targets'] ?? null) ? $state['last_check_targets'] : [];
        if ($existing === []) {
            try {
                foreach (UpdateCheck::discoverPackages(null) as $p) {
                    $id = (string)$p['id'];
                    $byId[$id] = UpdateCheck::enrichStatusRow([
                        'id' => $id,
                        'kind' => $p['kind'],
                        'bundle' => (string)($p['bundle'] ?? ''),
                        'repo' => (string)($p['repo'] ?? ''),
                        'channel' => (string)($p['channel'] ?? ''),
                        'ref' => '',
                        'local_version' => UpdateVersions::localVersion($id),
                        'remote_version' => '',
                        'update_available' => false,
                        'configured' => true,
                    ]);
                }
            } catch (Throwable $e) {
                // ignore
            }
        } else {
            foreach ($existing as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $byId[(string)$row['id']] = $row;
                }
            }
        }
        foreach ($results as $row) {
            if (is_array($row) && isset($row['id'])) {
                $byId[(string)$row['id']] = $row;
            }
        }
        $merged = array_values($byId);
        UpdateCheck::sortStatusRows($merged);
        $state['last_check'] = $now;
        $state['last_check_targets'] = $merged;
        UpdateFs::writeState($state);
        return $now;
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public static function checkOnePackage(array $p): array
    {
        $id = (string)$p['id'];
        $local = UpdateVersions::localVersion($id);
        try {
            $remote = UpdateGithub::resolveRemoteMeta($p);
            $remoteVer = (string)$remote['version'];
            return UpdateCheck::enrichStatusRow([
                'id' => $id,
                'kind' => $p['kind'],
                'bundle' => (string)($p['bundle'] ?? ''),
                'repo' => (string)($p['repo'] ?? ''),
                'channel' => (string)($p['channel'] ?? ''),
                'ref' => (string)($remote['ref'] ?? ''),
                'local_version' => $local,
                'remote_version' => $remoteVer,
                'update_available' => UpdateVersions::isNewer($remoteVer, $local),
                'url' => (string)($remote['url'] ?? ''),
                'sha256' => $remote['sha256'] ?? null,
                'artifact' => $remote['artifact'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Local-only / not yet pushed to the monorepo ref — do not abort the whole check.
            $msg = $e->getMessage();
            $missing = (bool)preg_match('/\b404\b/', $msg)
                || stripos($msg, 'not found') !== false;
            return UpdateCheck::enrichStatusRow([
                'id' => $id,
                'kind' => $p['kind'],
                'bundle' => (string)($p['bundle'] ?? ''),
                'repo' => (string)($p['repo'] ?? ''),
                'channel' => (string)($p['channel'] ?? ''),
                'ref' => (string)($p['ref'] ?? $p['channel'] ?? ''),
                'local_version' => $local,
                'remote_version' => '',
                'update_available' => false,
                'remote_missing' => $missing,
                'check_error' => $msg,
            ]);
        }
    }

    /** Progressive check for UI (one package per tick). */
    public static function checkJobStart(?string $target = null): array
    {
        $packages = UpdateCheck::discoverPackages($target);
        if ($packages === []) {
            throw new RuntimeException(t('updates.missing_ini'));
        }
        $partial = $target !== null && trim($target) !== '' && strtolower(trim($target)) !== 'all';
        if (!$partial) {
            try {
                UpdateCatalog::fetchRemoteCatalog(true);
            } catch (Throwable $e) {
                // catalog is best-effort during check
            }
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
            'partial' => $partial,
            'results' => [],
            'error' => '',
            'created_at' => time(),
            'updated_at' => time(),
        ];
        UpdateApply::jobSave($job);
        return $job;
    }

    /** @return array<string,mixed> */
    public static function checkJobTick(string $id): array
    {
        $job = UpdateApply::jobGet($id);
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
            $now = UpdateCheck::persistCheckResults($results, !empty($job['partial']));
            $job['status'] = 'done';
            $job['progress'] = 100;
            $job['message'] = t('updates.check_ok');
            $job['last_check'] = $now;
            $job['updated_at'] = time();
            UpdateApply::jobSave($job);
            return $job;
        }
        $p = $targets[$ti];
        $pid = (string)($p['id'] ?? '');
        $label = UpdateVersions::packageLabel($pid);
        $job['message'] = t('updates.check.step', [
            'name' => $label,
            'n' => (string)($ti + 1),
            'total' => (string)$n,
        ]);
        // checkOnePackage never throws — remote 404 / local-only modules become soft rows.
        $row = UpdateCheck::checkOnePackage($p);
        if (!isset($job['results']) || !is_array($job['results'])) {
            $job['results'] = [];
        }
        $job['results'][] = $row;
        $job['target_index'] = $ti + 1;
        $job['progress'] = (int)floor((($ti + 1) / max(1, $n)) * 100);
        $job['updated_at'] = time();
        UpdateApply::jobSave($job);
        return $job;
    }

    /**
     * After a successful overlay, refresh last_check_targets so the UI table
     * matches disk versions without requiring another Check.
     */
    public static function refreshCheckAfterApply(string $id, string $kind, string $bundle = ''): void
    {
        $state = UpdateFs::readState();
        $targets = is_array($state['last_check_targets'] ?? null) ? $state['last_check_targets'] : [];
        if ($targets === []) {
            return;
        }
        $refreshAllMonorepo = ($id === 'core' || $kind === 'core');
        $changed = false;
        foreach ($targets as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $tid = (string)($row['id'] ?? '');
            if ($tid === '') {
                continue;
            }
            $rowBundle = (string)($row['bundle'] ?? 'monorepo');
            if ($refreshAllMonorepo) {
                if ($rowBundle === 'external' && $tid !== 'core') {
                    continue;
                }
            } elseif ($tid !== $id) {
                continue;
            }
            $local = UpdateVersions::localVersion($tid);
            $remote = (string)($row['remote_version'] ?? '');
            $targets[$i]['local_version'] = $local;
            $targets[$i]['update_available'] = ($remote !== '' && UpdateVersions::isNewer($remote, $local));
            $targets[$i] = UpdateCheck::enrichStatusRow($targets[$i]);
            $changed = true;
        }
        if (!$changed) {
            return;
        }
        UpdateCheck::sortStatusRows($targets);
        $state['last_check_targets'] = $targets;
        UpdateFs::writeState($state);
    }

}
