<?php
declare(strict_types=1);

/** DB hub: dump/job paths, dump catalog, upload limits, progress labels. */
final class DbFs
{
    public static function dumpsDir(): string
    {
        $dir = base_path('var/db_dumps');
        if (!is_dir($dir)) {
            if (!is_dir(base_path('var'))) {
                @mkdir(base_path('var'), 0775, true);
            }
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function jobsDir(): string
    {
        $dir = base_path('var/db_jobs');
        if (!is_dir($dir)) {
            if (!is_dir(base_path('var'))) {
                @mkdir(base_path('var'), 0775, true);
            }
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * @return list<array{file:string,bytes:int,mtime:int,stamp:string,label:string}>
     */
    public static function listDumps(): array
    {
        $dir = self::dumpsDir();
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (preg_match('/\.sql(\.gz)?$/i', $name)) {
                $names[$name] = true;
            }
        }
        $out = [];
        foreach (array_keys($names) as $name) {
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $stamp = '';
            $extra = '';
            if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})(?:-(.+))?\.sql(\.gz)?$/i', $name, $m)) {
                $stamp = $m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . $m[4];
                $extra = isset($m[5]) ? (string)$m[5] : '';
            } elseif (preg_match('/^(\d{14})(?:-(.+))?\.sql(\.gz)?$/i', $name, $m)) {
                $raw = $m[1];
                $stamp = substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2)
                    . ' ' . substr($raw, 8, 2) . ':' . substr($raw, 10, 2) . ':' . substr($raw, 12, 2);
                $extra = isset($m[2]) ? (string)$m[2] : '';
            }
            $out[] = [
                'file' => $name,
                'bytes' => (int)filesize($path),
                'mtime' => (int)filemtime($path),
                'stamp' => $stamp,
                'label' => $extra,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['file'], $b['file']);
        });
        return $out;
    }

    public static function resolveDumpPath(string $file): string
    {
        $file = basename(str_replace(["\0", '\\'], '', $file));
        if ($file === '' || $file === '.' || $file === '..') {
            throw new InvalidArgumentException('Invalid dump file name');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+\.sql(\.gz)?$/i', $file)) {
            throw new InvalidArgumentException('Invalid dump file name');
        }
        $path = self::dumpsDir() . '/' . $file;
        $realDir = realpath(self::dumpsDir());
        $realFile = realpath($path);
        if ($realDir === false || $realFile === false || strpos($realFile, $realDir . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException('Dump file not found');
        }
        if (!is_file($realFile)) {
            throw new RuntimeException('Dump file not found');
        }
        return $realFile;
    }

    public static function deleteDump(string $file): void
    {
        $path = self::resolveDumpPath($file);
        $siblings = [$path];
        if (preg_match('/\.sql\.gz$/i', $path)) {
            $siblings[] = preg_replace('/\.gz$/i', '', $path);
        } elseif (preg_match('/\.sql$/i', $path)) {
            $siblings[] = $path . '.gz';
        }
        $deleted = false;
        foreach ($siblings as $p) {
            if (!is_string($p) || $p === '' || !is_file($p)) {
                continue;
            }
            if (@unlink($p)) {
                $deleted = true;
            }
            $meta = $p . '.meta';
            if (is_file($meta)) {
                @unlink($meta);
            }
        }
        if (!$deleted) {
            throw new RuntimeException('Failed to delete dump');
        }
    }

    /**
     * Save a browser-uploaded .sql / .sql.gz into var/db_dumps/ (does not restore).
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file $_FILES item
     * @return array{ok:bool,file:string,path:string,bytes:int}
     */
    public static function saveUploadedDump(array $file): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException(t('core.db.err.upload_missing'));
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException(t('core.db.err.upload_too_large', [
                'limit' => self::phpUploadLimitLabel(),
            ]));
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(t('core.db.err.upload_failed', ['code' => (string)$err]));
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException(t('core.db.err.upload_failed', ['code' => 'tmp']));
        }
        if ($size <= 0) {
            throw new InvalidArgumentException(t('core.db.err.upload_empty'));
        }
        if ($size > DbFetch::FETCH_MAX_BYTES) {
            throw new InvalidArgumentException(t('core.db.err.upload_too_large', [
                'limit' => '2 GiB',
            ]));
        }

        $orig = basename(str_replace(["\0", '\\'], '', (string)($file['name'] ?? '')));
        $ext = '';
        if (preg_match('/\.sql\.gz$/i', $orig)) {
            $ext = '.sql.gz';
        } elseif (preg_match('/\.sql$/i', $orig)) {
            $ext = '.sql';
        } else {
            throw new InvalidArgumentException(t('core.db.err.upload_type'));
        }

        $head = (string)@file_get_contents($tmp, false, null, 0, 16);
        if ($ext === '.sql.gz') {
            if (strlen($head) < 2 || $head[0] !== "\x1f" || $head[1] !== "\x8b") {
                throw new InvalidArgumentException(t('core.db.err.upload_type'));
            }
        } else {
            $probe = ltrim($head);
            if ($probe === '' || ($probe[0] !== '-' && $probe[0] !== '/' && strncasecmp($probe, 'DROP', 4) !== 0
                && strncasecmp($probe, 'CREATE', 6) !== 0 && strncasecmp($probe, 'INSERT', 6) !== 0
                && strncasecmp($probe, 'SET', 3) !== 0 && strncasecmp($probe, '/*!', 3) !== 0)) {
                throw new InvalidArgumentException(t('core.db.err.upload_type'));
            }
        }

        $label = (string)preg_replace('/\.sql(\.gz)?$/i', '', $orig);
        $label = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $label);
        $label = trim($label, '.-_');
        if ($label === '') {
            $label = 'local';
        }
        if (strlen($label) > 80) {
            $label = substr($label, 0, 80);
        }

        $basename = date('Y-m-d_H-i-s') . '-upload-' . $label . $ext;
        if (!preg_match('/^[A-Za-z0-9._-]+\.sql(\.gz)?$/i', $basename)) {
            throw new InvalidArgumentException(t('core.db.err.upload_type'));
        }

        $dir = self::dumpsDir();
        $dest = $dir . '/' . $basename;
        if (is_file($dest)) {
            $basename = date('Y-m-d_H-i-s') . '-upload-' . $label . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . $ext;
            $dest = $dir . '/' . $basename;
        }

        if (!@move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException(t('core.db.err.upload_save'));
        }
        @chmod($dest, 0664);

        return [
            'ok' => true,
            'file' => $basename,
            'path' => $dest,
            'bytes' => (int)filesize($dest),
        ];
    }

    /** Effective browser-upload cap from PHP ini (and 2 GiB hard max), human-readable. */
    public static function phpUploadLimitLabel(): string
    {
        $bytes = self::phpUploadLimitBytes();
        if ($bytes >= 1073741824) {
            return rtrim(rtrim(number_format($bytes / 1073741824, 1, '.', ''), '0'), '.') . ' GiB';
        }
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MiB';
        }
        return (string)max(1, (int)round($bytes / 1024)) . ' KiB';
    }

    public static function phpUploadLimitBytes(): int
    {
        $upload = self::iniBytes('upload_max_filesize');
        $post = self::iniBytes('post_max_size');
        $lim = DbFetch::FETCH_MAX_BYTES;
        if ($upload > 0) {
            $lim = min($lim, $upload);
        }
        if ($post > 0) {
            $lim = min($lim, $post);
        }
        return max(1, $lim);
    }

    private static function iniBytes(string $key): int
    {
        $raw = trim((string)ini_get($key));
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        $n = (float)$raw;
        $u = strtolower(substr($raw, -1));
        if ($u === 'g') {
            $n *= 1024 * 1024 * 1024;
        } elseif ($u === 'm') {
            $n *= 1024 * 1024;
        } elseif ($u === 'k') {
            $n *= 1024;
        }
        return (int)$n;
    }

    public static function readDumpMetaBytes(string $dumpPath): int
    {
        $meta = $dumpPath . '.meta';
        if (!is_file($meta)) {
            return 0;
        }
        $raw = file_get_contents($meta);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return 0;
        }
        return max(0, (int)($data['uncompressed_bytes'] ?? 0));
    }

    public static function writeDumpMeta(string $gzPath, int $uncompressedBytes): void
    {
        $meta = $gzPath . '.meta';
        @file_put_contents($meta, json_encode([
            'uncompressed_bytes' => $uncompressedBytes,
            'created_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function formatBytesLabel(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / (1024 * 1024), 2) . ' MB';
    }

    public static function formatDurationLabel(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        if ($m <= 0) {
            return $s . 's';
        }
        return $m . 'm ' . sprintf('%02d', $s) . 's';
    }

}
