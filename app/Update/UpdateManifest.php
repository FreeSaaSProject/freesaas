<?php
declare(strict_types=1);

/**
 * Baseline hashes of files as the updater last wrote them (detects instance edits).
 */
final class UpdateManifest
{
    /** @var array<string,list<string>|null> repo@ref → git tree map (rel → blob sha) */
    private static $treeCache = [];

    public static function path(): string
    {
        return UpdateFs::workDir() . '/installed-manifest.json';
    }

    /** @return array<string,string> rel → sha1 */
    public static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data) || !is_array($data['files'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($data['files'] as $rel => $hash) {
            if (is_string($rel) && is_string($hash) && $hash !== '') {
                $out[$rel] = $hash;
            }
        }
        return $out;
    }

    /** @param array<string,string> $files */
    public static function save(array $files): void
    {
        UpdateFs::ensureDir(UpdateFs::workDir());
        ksort($files);
        $json = json_encode(
            ['updated_at' => gmdate('c'), 'files' => $files],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false || @file_put_contents(self::path(), $json . "\n") === false) {
            throw new RuntimeException('Cannot write ' . self::path());
        }
    }

    public static function gitBlobSha(string $file): string
    {
        $content = @file_get_contents($file);
        if (!is_string($content)) {
            return '';
        }
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }

    /** Branch names move; only tags and commit shas describe what was installed. */
    public static function isImmutableRef(string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '') {
            return false;
        }
        if (preg_match('/^[0-9a-f]{40}$/i', $ref)) {
            return true;
        }
        return $ref[0] === 'v' && (bool)preg_match('/^v\d/', $ref);
    }

    /**
     * Ref (commit or tag) the package was last installed from, if recorded and immutable.
     *
     * @param array<string,mixed> $source
     */
    public static function installedRef(array $source): string
    {
        $state = UpdateFs::readState();
        $id = (string)($source['id'] ?? '');
        $kind = (string)($source['kind'] ?? 'module');
        $bundle = (string)($source['bundle'] ?? 'monorepo');
        $entries = [];
        if ($kind === 'core') {
            $entries[] = $state['core'] ?? null;
        } else {
            $entries[] = $state['modules'][$id] ?? null;
            if ($bundle !== 'external') {
                $entries[] = $state['core'] ?? null;
            }
        }
        foreach ($entries as $e) {
            if (!is_array($e)) {
                continue;
            }
            foreach (['commit', 'ref'] as $k) {
                $ref = trim((string)($e[$k] ?? ''));
                if ($ref !== '' && self::isImmutableRef($ref)) {
                    return $ref;
                }
            }
        }
        return '';
    }

    /**
     * Git blob shas of the installed ref, keyed by destination rel path. Null when unknown.
     *
     * @param array<string,mixed> $source
     * @return array<string,string>|null
     */
    public static function installedTree(array $source): ?array
    {
        if ((string)($source['provider'] ?? 'github') !== 'github') {
            return null;
        }
        $repo = (string)($source['repo'] ?? '');
        $ref = self::installedRef($source);
        if ($repo === '' || $ref === '') {
            return null;
        }
        $key = $repo . '@' . $ref;
        if (!array_key_exists($key, self::$treeCache)) {
            self::$treeCache[$key] = null;
            try {
                $api = 'https://api.github.com/repos/' . $repo . '/git/trees/' . rawurlencode($ref) . '?recursive=1';
                $data = json_decode(UpdateGithub::httpGet($api, (string)($source['token'] ?? '')), true);
                if (is_array($data) && is_array($data['tree'] ?? null) && empty($data['truncated'])) {
                    $map = [];
                    foreach ($data['tree'] as $node) {
                        if (is_array($node) && ($node['type'] ?? '') === 'blob' && isset($node['path'], $node['sha'])) {
                            $map[(string)$node['path']] = (string)$node['sha'];
                        }
                    }
                    self::$treeCache[$key] = $map;
                }
            } catch (Throwable $e) {
                self::$treeCache[$key] = null;
            }
        }
        $map = self::$treeCache[$key];
        if ($map === null) {
            return null;
        }
        if ((string)($source['bundle'] ?? 'monorepo') !== 'external') {
            return $map;
        }
        $id = (string)($source['id'] ?? '');
        $prefix = trim((string)($source['path_prefix'] ?? ''), '/');
        $out = [];
        foreach ($map as $repoPath => $sha) {
            if ($prefix !== '') {
                if (strpos($repoPath, $prefix . '/') !== 0) {
                    continue;
                }
                $repoPath = substr($repoPath, strlen($prefix) + 1);
            }
            $out['modules/' . $id . '/' . $repoPath] = $sha;
        }
        return $out;
    }
}
