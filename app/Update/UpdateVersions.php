<?php
declare(strict_types=1);

/** Update engine: UpdateVersions. */
final class UpdateVersions
{
    /** Human label for UI (translated module name). */
    public static function packageLabel(string $target): string
    {
        $target = UpdateVersions::normalizeTarget($target);
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

    /** Canonical version file for a package (core included). */
    public static function versionJsonPath(string $target): string
    {
        $target = UpdateVersions::normalizeTarget($target);
        return base_path('modules/' . $target . '/module.json');
    }

    public static function localVersion(string $target): string
    {
        $target = UpdateVersions::normalizeTarget($target);
        $meta = ModuleManager::get($target);
        if (is_array($meta) && isset($meta['version']) && trim((string)$meta['version']) !== '') {
            return trim((string)$meta['version']);
        }
        $jsonPath = UpdateVersions::versionJsonPath($target);
        if (is_file($jsonPath)) {
            $data = json_decode((string)@file_get_contents($jsonPath), true);
            if (is_array($data) && isset($data['version']) && trim((string)$data['version']) !== '') {
                return trim((string)$data['version']);
            }
        }
        return '0.0.0';
    }

    /**
     * Bump modules/<id>/module.json → version. For core, also syncs root VERSION.
     *
     * @param 'patch'|'minor'|'major'|'set' $mode
     * @return array{ok:bool,id:string,from:string,to:string,file:string,synced_root_version?:bool,error?:string,http:int}
     */
    public static function bumpVersion(string $id, string $mode = 'patch', ?string $set = null): array
    {
        $id = UpdateVersions::normalizeTarget($id);
        $path = UpdateVersions::versionJsonPath($id);
        if (!is_file($path)) {
            return ['ok' => false, 'id' => $id, 'from' => '', 'to' => '', 'file' => $path, 'error' => 'module.json not found', 'http' => 404];
        }
        $raw = (string)@file_get_contents($path);
        if ($raw === '' || !preg_match('/"version"\s*:\s*"([^"]*)"/', $raw, $m)) {
            return ['ok' => false, 'id' => $id, 'from' => '', 'to' => '', 'file' => $path, 'error' => 'version field missing', 'http' => 422];
        }
        $from = trim($m[1]);
        $mode = strtolower(trim($mode));
        if ($mode === 'set' || $set !== null) {
            $to = trim((string)$set);
            if ($to === '' || !preg_match('/^\d+\.\d+\.\d+/', $to)) {
                return ['ok' => false, 'id' => $id, 'from' => $from, 'to' => '', 'file' => $path, 'error' => 'set= requires x.y.z', 'http' => 422];
            }
        } else {
            if (!in_array($mode, ['patch', 'minor', 'major'], true)) {
                $mode = 'patch';
            }
            $to = UpdateVersions::bumpSemver($from !== '' ? $from : '0.0.0', $mode);
        }
        if ($to === $from) {
            return ['ok' => true, 'id' => $id, 'from' => $from, 'to' => $to, 'file' => $path, 'http' => 200];
        }
        $next = preg_replace('/"version"\s*:\s*"[^"]*"/', '"version": "' . $to . '"', $raw, 1);
        if (!is_string($next) || @file_put_contents($path, $next) === false) {
            return ['ok' => false, 'id' => $id, 'from' => $from, 'to' => $to, 'file' => $path, 'error' => 'cannot write module.json', 'http' => 500];
        }
        $out = ['ok' => true, 'id' => $id, 'from' => $from, 'to' => $to, 'file' => $path, 'http' => 200];
        if ($id === 'core') {
            $out['synced_root_version'] = @file_put_contents(base_path('VERSION'), $to . "\n") !== false;
        }
        return $out;
    }

    /** @param 'patch'|'minor'|'major' $mode */
    public static function bumpSemver(string $ver, string $mode): string
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $ver, $m)) {
            return '0.0.1';
        }
        $a = (int)$m[1];
        $b = (int)$m[2];
        $c = (int)$m[3];
        if ($mode === 'major') {
            return ($a + 1) . '.0.0';
        }
        if ($mode === 'minor') {
            return $a . '.' . ($b + 1) . '.0';
        }
        return $a . '.' . $b . '.' . ($c + 1);
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

    public static function normalizeTarget(string $target): string
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

    public static function isNewer(string $remote, string $local): bool
    {
        $remote = ltrim(trim($remote), 'vV');
        $local = ltrim(trim($local), 'vV');
        if ($remote === '' || $remote === $local) {
            return false;
        }
        return version_compare($remote, $local, '>');
    }

}
