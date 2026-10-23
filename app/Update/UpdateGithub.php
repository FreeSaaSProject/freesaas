<?php
declare(strict_types=1);

/** Update engine: UpdateGithub. */
final class UpdateGithub
{
    public const USER_AGENT = 'FreeSaaS-UpdateEngine/1.0';

    /**
     * Resolve remote version + archive URL for a package.
     *
     * @param array<string,mixed> $pkg
     * @return array{version:string,url:string,sha256?:?string,artifact?:?string,ref?:string}
     */
    public static function resolveRemoteMeta(array $pkg): array
    {
        if (isset($pkg['_remote']) && is_array($pkg['_remote']) && !empty($pkg['_remote']['url'])) {
            /** @var array{version:string,url:string,sha256?:?string,artifact?:?string,ref?:string} */
            return $pkg['_remote'];
        }
        $provider = (string)($pkg['provider'] ?? 'github');
        if ($provider === 'http') {
            return UpdateGithub::resolveHttpMeta($pkg);
        }
        return UpdateGithub::resolveGithubMeta($pkg);
    }

    /**
     * @param array<string,mixed> $pkg
     * @return array{version:string,url:string,sha256?:?string,artifact?:?string,ref?:string}
     */
    public static function resolveHttpMeta(array $pkg): array
    {
        $manifestUrl = (string)($pkg['manifest_url'] ?: $pkg['url']);
        if ($manifestUrl === '') {
            throw new RuntimeException('http provider needs manifest_url');
        }
        $data = json_decode(UpdateGithub::httpGet($manifestUrl, (string)$pkg['token']), true);
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
    public static function resolveGithubMeta(array $pkg): array
    {
        $repo = (string)$pkg['repo'];
        if ($repo === '' || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new RuntimeException('github provider requires repo=owner/name');
        }
        $token = (string)$pkg['token'];
        $ref = UpdateGithub::resolveGitRef($pkg);
        $bundle = (string)($pkg['bundle'] ?? 'monorepo');
        $id = (string)$pkg['id'];
        $kind = (string)$pkg['kind'];

        // Version from modules/<id>/module.json on the ref (core included). Root VERSION is not used.
        if ($bundle === 'external' && (string)($pkg['path_prefix'] ?? '') === '') {
            $path = 'module.json';
        } elseif ($bundle === 'external' && (string)($pkg['path_prefix'] ?? '') !== '') {
            $path = rtrim((string)$pkg['path_prefix'], '/') . '/module.json';
        } else {
            $path = 'modules/' . ($kind === 'core' ? 'core' : $id) . '/module.json';
        }
        $raw = UpdateGithub::githubFileContents($repo, $path, $ref, $token);
        $j = json_decode($raw, true);
        $ver = is_array($j) ? trim((string)($j['version'] ?? '')) : '';
        if ($ver === '') {
            throw new RuntimeException('Cannot read remote version for ' . $id . ' @ ' . $ref);
        }

        $channel = strtolower((string)($pkg['channel'] ?? 'main'));
        $url = '';
        $sha = null;
        $artifact = null;

        // Stable: prefer one full release zip asset; else zipball of the release tag/ref.
        if ($channel === 'stable' || $channel === 'latest' || (isset($channel[0]) && $channel[0] === 'v')) {
            $release = UpdateGithub::githubRelease($repo, (string)$pkg['channel'], $token);
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
                $url = UpdateGithub::githubAssetDownloadUrl($picked);
                $artifact = (string)($picked['name'] ?? '');
            }
            if ($url === '') {
                $url = UpdateGithub::githubArchiveUrl($repo, $ref);
                $artifact = 'zipball:' . $ref;
            }
        } else {
            // Branch / floating channel: archive of ref
            $url = UpdateGithub::githubArchiveUrl($repo, $ref);
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
    public static function resolveGitRef(array $pkg): string
    {
        $explicit = trim((string)($pkg['ref'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $channel = trim((string)($pkg['channel'] ?? 'main'));
        if ($channel === '' || $channel === 'stable' || $channel === 'latest') {
            if ($channel === 'stable' || $channel === 'latest') {
                $rel = UpdateGithub::githubRelease((string)$pkg['repo'], $channel, (string)$pkg['token']);
                return (string)($rel['tag_name'] ?? 'main');
            }
            return 'main';
        }
        return $channel;
    }

    /**
     * @return array<string,mixed>
     */
    public static function githubRelease(string $repo, string $channel, string $token): array
    {
        if ($channel === '' || $channel === 'stable' || $channel === 'latest') {
            $api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        } else {
            $api = 'https://api.github.com/repos/' . $repo . '/releases/tags/' . rawurlencode($channel);
        }
        $data = json_decode(UpdateGithub::httpGet($api, $token), true);
        if (!is_array($data) || empty($data['tag_name'])) {
            throw new RuntimeException('GitHub release not found for ' . $repo . ' channel=' . $channel);
        }
        return $data;
    }

    public static function githubFileContents(string $repo, string $path, string $ref, string $token): string
    {
        $api = 'https://api.github.com/repos/' . $repo . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)))
            . '?ref=' . rawurlencode($ref);
        $data = json_decode(UpdateGithub::httpGet($api, $token), true);
        if (!is_array($data)) {
            throw new RuntimeException('Cannot read ' . $path . ' @ ' . $ref);
        }
        if (($data['encoding'] ?? '') === 'base64' && isset($data['content'])) {
            $bin = base64_decode(preg_replace('/\s+/', '', (string)$data['content']) ?: '', true);
            return is_string($bin) ? $bin : '';
        }
        if (!empty($data['download_url'])) {
            return UpdateGithub::httpGet((string)$data['download_url'], $token);
        }
        throw new RuntimeException('Unexpected contents payload for ' . $path);
    }

    public static function commitSha(string $repo, string $ref, string $token): string
    {
        $api = 'https://api.github.com/repos/' . $repo . '/commits/' . rawurlencode($ref);
        $data = json_decode(UpdateGithub::httpGet($api, $token), true);
        $sha = is_array($data) ? (string)($data['sha'] ?? '') : '';
        return preg_match('/^[0-9a-f]{40}$/i', $sha) ? strtolower($sha) : '';
    }

    /**
     * Resolve the commit behind a moving ref and download that exact commit, so the
     * installed tree can later serve as a baseline for local-edit detection.
     *
     * @param array<string,mixed> $pkg
     * @param array<string,mixed> $remote
     * @return array<string,mixed>
     */
    public static function pinCommit(array $pkg, array $remote): array
    {
        if ((string)($pkg['provider'] ?? 'github') !== 'github' || !empty($remote['commit'])) {
            return $remote;
        }
        $repo = (string)($pkg['repo'] ?? '');
        $ref = (string)($remote['ref'] ?? '');
        if ($repo === '' || $ref === '') {
            return $remote;
        }
        try {
            $sha = UpdateGithub::commitSha($repo, $ref, (string)($pkg['token'] ?? ''));
        } catch (Throwable $e) {
            return $remote;
        }
        if ($sha === '') {
            return $remote;
        }
        $remote['commit'] = $sha;
        if (strpos((string)($remote['artifact'] ?? ''), 'zipball:') === 0) {
            $remote['url'] = UpdateGithub::githubArchiveUrl($repo, $sha);
        }
        return $remote;
    }

    /** @param array<string,mixed> $asset */
    public static function githubAssetDownloadUrl(array $asset): string
    {
        $api = trim((string)($asset['url'] ?? ''));
        if ($api !== '' && strpos($api, 'api.github.com/') !== false) {
            return $api;
        }
        return trim((string)($asset['browser_download_url'] ?? ''));
    }

    public static function downloadFile(string $url, string $dest, string $token): void
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
            $accept = UpdateGithub::downloadAcceptForUrl($url);
            $headers = [
                'Accept: ' . $accept,
                'User-Agent: ' . UpdateGithub::USER_AGENT,
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
    public static function downloadAcceptForUrl(string $url): string
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
    public static function githubArchiveUrl(string $repo, string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            $ref = 'main';
        }
        if (preg_match('/^[0-9a-f]{40}$/i', $ref)) {
            return 'https://codeload.github.com/' . $repo . '/zip/' . $ref;
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

    public static function httpGet(string $url, string $token, bool $octetStream = false): string
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
            'User-Agent: ' . UpdateGithub::USER_AGENT,
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

}
