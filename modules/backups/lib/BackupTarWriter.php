<?php
declare(strict_types=1);

/**
 * Streaming ustar writer with resumable state across cron ticks.
 *
 * State keys: files (list of {path,name,size,mtime}), file_idx, file_offset, header_done, finalized.
 */
final class BackupTarWriter
{
    /** Soft cap per tick (time budget usually binds first). Was 8 MiB — too small for ~250+ MiB light packs. */
    public const BUDGET_BYTES = 512 * 1024 * 1024;

    /**
     * Build file inventory under roots (relative names inside tar).
     *
     * @param list<string> $roots absolute paths
     * @param list<string> $excludes absolute path prefixes to skip
     * @return list<array{path:string,name:string,size:int,mtime:int}>
     */
    public static function inventory(array $roots, array $excludes = []): array
    {
        $files = [];
        $excludeNorm = [];
        foreach ($excludes as $ex) {
            $excludeNorm[] = rtrim(str_replace('\\', '/', $ex), '/');
        }
        foreach ($roots as $root) {
            $root = rtrim(str_replace('\\', '/', $root), '/');
            if ($root === '' || !is_dir($root)) {
                continue;
            }
            $baseName = basename($root);
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $info) {
                /** @var SplFileInfo $info */
                if (!$info->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $info->getPathname());
                $skip = false;
                foreach ($excludeNorm as $ex) {
                    if ($ex !== '' && ($path === $ex || strpos($path, $ex . '/') === 0)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
                $rel = substr($path, strlen($root));
                $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
                $name = $baseName . ($rel !== '' ? '/' . $rel : '');
                $files[] = [
                    'path' => $path,
                    'name' => $name,
                    'size' => (int)$info->getSize(),
                    'mtime' => (int)$info->getMTime(),
                ];
            }
        }
        usort($files, static function (array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });
        return $files;
    }

    /**
     * @param list<array{path:string,name:string,size:int,mtime:int}> $files
     * @param array<string,mixed> $state
     * @return array{done:bool,state:array<string,mixed>,bytes:int}
     */
    public static function tick(string $outPath, array $files, array $state, int $budgetSec = 40): array
    {
        $fileIdx = (int)($state['file_idx'] ?? 0);
        $fileOffset = (int)($state['file_offset'] ?? 0);
        $headerDone = !empty($state['header_done']);
        $finalized = !empty($state['finalized']);
        if ($finalized) {
            return ['done' => true, 'state' => $state, 'bytes' => 0];
        }

        $mode = ($fileIdx === 0 && $fileOffset === 0 && !$headerDone) ? 'wb' : 'ab';
        $out = fopen($outPath, $mode);
        if ($out === false) {
            throw new RuntimeException('Cannot open tar dest');
        }

        $t0 = microtime(true);
        $written = 0;
        $n = count($files);

        while ((microtime(true) - $t0) < $budgetSec && $written < self::BUDGET_BYTES) {
            if ($fileIdx >= $n) {
                // two zero blocks
                fwrite($out, str_repeat("\0", 1024));
                $written += 1024;
                $finalized = true;
                break;
            }
            $file = $files[$fileIdx];
            $name = (string)$file['name'];
            $size = (int)$file['size'];
            $mtime = (int)$file['mtime'];
            $path = (string)$file['path'];

            if (!$headerDone) {
                $hdr = self::headerBlocks($name, $size, $mtime);
                fwrite($out, $hdr);
                $written += strlen($hdr);
                $headerDone = true;
                $fileOffset = 0;
            }

            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                // skip missing file: write zeros for remaining size
                $remain = $size - $fileOffset;
                while ($remain > 0 && (microtime(true) - $t0) < $budgetSec) {
                    $chunk = min(65536, $remain);
                    fwrite($out, str_repeat("\0", $chunk));
                    $remain -= $chunk;
                    $fileOffset += $chunk;
                    $written += $chunk;
                }
            } else {
                if ($fileOffset > 0) {
                    fseek($fh, $fileOffset);
                }
                while ($fileOffset < $size && (microtime(true) - $t0) < $budgetSec && $written < self::BUDGET_BYTES) {
                    $need = min(65536, $size - $fileOffset);
                    $buf = fread($fh, $need);
                    if ($buf === false || $buf === '') {
                        break;
                    }
                    fwrite($out, $buf);
                    $nread = strlen($buf);
                    $fileOffset += $nread;
                    $written += $nread;
                }
                fclose($fh);
            }

            if ($fileOffset >= $size) {
                $pad = (512 - ($size % 512)) % 512;
                if ($pad > 0) {
                    fwrite($out, str_repeat("\0", $pad));
                    $written += $pad;
                }
                $fileIdx++;
                $fileOffset = 0;
                $headerDone = false;
            } else {
                break;
            }
        }

        fclose($out);

        $state['file_idx'] = $fileIdx;
        $state['file_offset'] = $fileOffset;
        $state['header_done'] = $headerDone ? 1 : 0;
        $state['finalized'] = $finalized ? 1 : 0;

        return [
            'done' => $finalized,
            'state' => $state,
            'bytes' => $written,
        ];
    }

    private static function headerBlocks(string $name, int $size, int $mtime): string
    {
        $blocks = '';
        if (strlen($name) > 100) {
            $blocks .= self::gnuLongLink($name);
            $nameField = '././@LongLink';
        } else {
            $nameField = $name;
        }
        $blocks .= self::ustarHeader($nameField, $size, $mtime, '0');
        return $blocks;
    }

    private static function gnuLongLink(string $name): string
    {
        $payload = $name . "\0";
        $size = strlen($payload);
        $hdr = self::ustarHeader('././@LongLink', $size, time(), 'L');
        $pad = (512 - ($size % 512)) % 512;
        return $hdr . $payload . ($pad > 0 ? str_repeat("\0", $pad) : '');
    }

    private static function ustarHeader(string $name, int $size, int $mtime, string $type): string
    {
        $name = substr($name, 0, 100);
        $header = str_pad($name, 100, "\0");
        $header .= self::octal(0644, 8);
        $header .= self::octal(0, 8); // uid
        $header .= self::octal(0, 8); // gid
        $header .= self::octal($size, 12);
        $header .= self::octal($mtime, 12);
        $header .= '        '; // checksum placeholder
        $header .= $type;
        $header .= str_repeat("\0", 100); // linkname
        $header .= 'ustar' . "\0";
        $header .= '00';
        $header .= str_repeat("\0", 32); // uname
        $header .= str_repeat("\0", 32); // gname
        $header .= str_repeat("\0", 8); // devmajor
        $header .= str_repeat("\0", 8); // devminor
        $header .= str_repeat("\0", 155); // prefix
        $header .= str_repeat("\0", 12);
        $sum = 0;
        $len = strlen($header);
        for ($i = 0; $i < $len; $i++) {
            $sum += ord($header[$i]);
        }
        $checksum = self::octal($sum, 8);
        $header = substr($header, 0, 148) . $checksum . substr($header, 156);
        return substr($header, 0, 512);
    }

    private static function octal(int $value, int $width): string
    {
        $s = decoct(max(0, $value));
        // classic tar: digits + NUL, space-padded / null-terminated
        return str_pad($s, $width - 1, '0', STR_PAD_LEFT) . "\0";
    }
}
