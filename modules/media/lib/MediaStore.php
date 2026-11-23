<?php
declare(strict_types=1);

final class MediaStore
{
    private string $mode;
    private ?FtpClient $ftp = null;

    public function __construct()
    {
        $this->mode = MediaConfig::mode();
        if ($this->mode === 'ftp') {
            $cfg = MediaConfig::ftpConfig();
            $this->ftp = new FtpClient([
                'host' => $cfg['host'],
                'port' => (int)$cfg['port'],
                'user' => $cfg['user'],
                'pass' => $cfg['pass'],
                'ssl' => $cfg['ssl'] === '1',
                'passive' => $cfg['passive'] !== '0',
                'base_path' => $cfg['base_path'],
            ]);
        }
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * Persist processed master+thumb under owner path.
     *
     * @param array{path:string,thumb:string,mime:string,bytes:int,width:int,height:int,original_name?:string} $processed
     * @return array{storage_path:string,thumb_path:string}
     */
    public function putProcessed(string $ownerTable, int $ownerId, array $processed): array
    {
        $ownerTable = preg_replace('/[^a-z0-9_]/', '', strtolower($ownerTable)) ?? '';
        $relDir = $ownerTable . '/' . date('Y') . '/' . date('m');
        $base = bin2hex(random_bytes(8)) . '_' . $ownerId;
        $relMaster = $relDir . '/' . $base . '.jpg';
        $relThumb = $relDir . '/' . $base . '_t.jpg';

        if ($this->mode === 'ftp' && $this->ftp !== null) {
            $this->ftp->connect();
            $this->ftp->mkdirs($relDir);
            $this->ftp->put((string)$processed['path'], $relMaster, -1, 120);
            $this->ftp->put((string)$processed['thumb'], $relThumb, -1, 60);
        } else {
            $root = MediaConfig::storageRoot();
            $absDir = $root . '/' . $relDir;
            if (!is_dir($absDir) && !@mkdir($absDir, 0750, true) && !is_dir($absDir)) {
                throw new RuntimeException(t('media.err.storage_mkdir'));
            }
            if (!@rename((string)$processed['path'], $absDir . '/' . $base . '.jpg')
                && !@copy((string)$processed['path'], $absDir . '/' . $base . '.jpg')
            ) {
                throw new RuntimeException(t('media.err.storage_write'));
            }
            @unlink((string)$processed['path']);
            if (!@rename((string)$processed['thumb'], $absDir . '/' . $base . '_t.jpg')
                && !@copy((string)$processed['thumb'], $absDir . '/' . $base . '_t.jpg')
            ) {
                throw new RuntimeException(t('media.err.storage_write'));
            }
            @unlink((string)$processed['thumb']);
        }
        return ['storage_path' => $relMaster, 'thumb_path' => $relThumb];
    }

    public function deletePaths(string $storagePath, string $thumbPath = ''): void
    {
        $storagePath = ltrim(str_replace('\\', '/', $storagePath), '/');
        $thumbPath = ltrim(str_replace('\\', '/', $thumbPath), '/');
        if ($this->mode === 'ftp' && $this->ftp !== null) {
            $this->ftp->connect();
            if ($storagePath !== '') {
                try {
                    $this->ftp->delete($storagePath);
                } catch (Throwable $e) {
                    // best-effort
                }
            }
            if ($thumbPath !== '') {
                try {
                    $this->ftp->delete($thumbPath);
                } catch (Throwable $e) {
                    // best-effort
                }
            }
            return;
        }
        $root = MediaConfig::storageRoot();
        foreach ([$storagePath, $thumbPath] as $rel) {
            if ($rel === '') {
                continue;
            }
            $abs = $root . '/' . $rel;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }

    /**
     * Copy remote/local object to a temp file for streaming; caller must unlink.
     */
    public function materialize(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || strpos($relative, '..') !== false) {
            throw new InvalidArgumentException(t('media.err.bad_path'));
        }
        if ($this->mode === 'ftp' && $this->ftp !== null) {
            $this->ftp->connect();
            $tmp = tempnam(sys_get_temp_dir(), 'fsm');
            if ($tmp === false) {
                throw new RuntimeException(t('media.err.temp_dir'));
            }
            $this->ftp->get($relative, $tmp);
            return $tmp;
        }
        $abs = MediaConfig::storageRoot() . '/' . $relative;
        if (!is_file($abs)) {
            throw new RuntimeException(t('media.err.file_missing'));
        }
        return $abs;
    }

    public function isTempMaterialized(string $path): bool
    {
        if ($this->mode !== 'ftp') {
            return false;
        }
        $tmp = rtrim(sys_get_temp_dir(), '/');
        return strpos($path, $tmp) === 0;
    }
}
