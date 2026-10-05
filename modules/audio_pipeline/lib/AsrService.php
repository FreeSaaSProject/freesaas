<?php
declare(strict_types=1);

/**
 * ASR claim/result for Whisper workers (port of oper getFileName / saveFileDescription).
 */
final class AsrService
{
    public static function claimTtl(): int
    {
        $n = (int)Config::get('audio', 'asr_claim_ttl_sec', 90);
        return max(10, min(600, $n > 0 ? $n : 90));
    }

    public static function lockPath(string $name): string
    {
        $dir = base_path('var/audio_jobs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/' . $name;
    }

    /** Claim next untranscribed FILENAME or "None". */
    public static function claim(): string
    {
        if (!Database::tableExists('base_records')) {
            return 'None';
        }
        $lockFile = self::lockPath('asr_claim.lock');
        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            return 'None';
        }
        flock($fp, LOCK_EX);
        $now = time();
        $ttl = self::claimTtl();
        $lines = [];
        while (($line = fgets($fp)) !== false) {
            $parts = explode('|', trim($line));
            if (count($parts) === 2 && (int)$parts[1] > $now) {
                $lines[] = $line;
            }
        }

        $disp = 'None';
        $st = Database::pdo()->query(
            'SELECT ID, FILENAME FROM base_records
             WHERE DT_TRANSCRIBED < 1 AND DISABLED < 1 AND FILENAME <> \'\'
             ORDER BY DT_RECORDED ASC LIMIT 40'
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $filename = (string)$row['FILENAME'];
            $locked = false;
            foreach ($lines as $line) {
                if (strpos($line, $filename) !== false) {
                    $locked = true;
                    break;
                }
            }
            if (!$locked) {
                $lines[] = $filename . '|' . ($now + $ttl) . "\n";
                $disp = $filename;
                break;
            }
        }

        rewind($fp);
        ftruncate($fp, 0);
        foreach ($lines as $line) {
            fwrite($fp, $line);
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $disp;
    }

    /**
     * @return array{ok:bool|string,diag:string}
     */
    public static function saveResult(string $fileName, string $result, ?string $playtime, ?string $volume): array
    {
        $fileName = trim($fileName);
        $result = trim($result);
        if ($fileName === '' || !Database::tableExists('base_records')) {
            return ['ok' => false, 'diag' => 'missing_filename'];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, DT_TRANSCRIBED FROM base_records WHERE FILENAME = ? LIMIT 1'
        );
        $st->execute([$fileName]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'diag' => 'not_found'];
        }
        $dtTrans = (int)$row['DT_TRANSCRIBED'];
        $sets = [];
        $params = [];
        $diagChunk = '';

        if ($dtTrans === 1) {
            // Chunk mode: do not overwrite TRANSCRIPTION
            if ($playtime !== null && $playtime !== '') {
                $sets[] = 'PLAYTIME = ?';
                $params[] = (int)$playtime;
            }
            if ($volume !== null && $volume !== '' && is_numeric($volume)) {
                $sets[] = 'VOLUME = ?';
                $params[] = (float)$volume;
            }
            $diagChunk = ' SKIP_TRANSCRIPTION=chunk_mode';
            if ($sets === []) {
                return [
                    'ok' => true,
                    'diag' => 'TRANSCRIPTION=' . mb_strlen($result, 'UTF-8') . $diagChunk,
                ];
            }
        } else {
            $sets[] = 'TRANSCRIPTION = ?';
            $params[] = $result;
            $sets[] = 'DT_TRANSCRIBED = ?';
            $params[] = time();
            if ($playtime !== null && $playtime !== '') {
                $sets[] = 'PLAYTIME = ?';
                $params[] = (int)$playtime;
            }
            if ($volume !== null && $volume !== '' && is_numeric($volume)) {
                $sets[] = 'VOLUME = ?';
                $params[] = (float)$volume;
            }
        }
        $params[] = $fileName;
        $sql = 'UPDATE base_records SET ' . implode(', ', $sets) . ' WHERE FILENAME = ?';
        $ok = Database::pdo()->prepare($sql)->execute($params);
        $diag = 'TRANSCRIPTION=' . mb_strlen($result, 'UTF-8');
        $diag .= ' PLAYTIME=' . (($playtime !== null && $playtime !== '') ? (int)$playtime : 'NO');
        $diag .= ' VOLUME=' . (($volume !== null && $volume !== '') ? $volume : 'NO');
        $diag .= $diagChunk;
        return ['ok' => $ok, 'diag' => $diag];
    }

    /** Claim next chunk path under audiochunks/ or "None". */
    public static function claimChunk(): string
    {
        require_once base_path('modules/audio/lib/AudioSettingsRepository.php');
        require_once base_path('modules/audio/lib/FileStorage.php');

        $cfg = AudioSettingsRepository::storageConfig();
        $storage = FileStorage::fromSettings();
        $dir = '/' . trim($cfg['audio_chunks'], '/') . '/';
        $re = '/^EM_[A-Za-z0-9]+_\d{8}-\d{6}_NML_\d+_O\d+-\d+\.(mp3|wav|m4a)$/i';

        $lockFile = self::lockPath('asr_chunk_claim.lock');
        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            return 'None';
        }
        flock($fp, LOCK_EX);
        $now = time();
        $ttl = max(self::claimTtl(), 120);
        $lines = [];
        while (($line = fgets($fp)) !== false) {
            $parts = explode('|', trim($line));
            if (count($parts) === 2 && (int)$parts[1] > $now) {
                $lines[] = $line;
            }
        }

        $candidates = array_merge(
            $storage->glob($dir, '*.mp3'),
            $storage->glob($dir, '*.wav'),
            $storage->glob($dir, '*.m4a')
        );
        sort($candidates);
        $disp = 'None';
        foreach ($candidates as $path) {
            $base = basename((string)$path);
            if (!preg_match($re, $base)) {
                continue;
            }
            $rel = $dir . $base;
            $locked = false;
            foreach ($lines as $line) {
                if (strpos($line, $rel) !== false) {
                    $locked = true;
                    break;
                }
            }
            if (!$locked) {
                $lines[] = $rel . '|' . ($now + $ttl) . "\n";
                $disp = $rel;
                break;
            }
        }
        rewind($fp);
        ftruncate($fp, 0);
        foreach ($lines as $line) {
            fwrite($fp, $line);
        }
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $storage->close();
        return $disp;
    }

    /**
     * Append chunk transcript line to parent record; delete chunk file.
     *
     * @return array{ok:bool,diag:string}
     */
    public static function saveChunkResult(string $fileName, string $result): array
    {
        require_once base_path('modules/audio/lib/AudioSettingsRepository.php');
        require_once base_path('modules/audio/lib/FileStorage.php');

        $fileName = trim(urldecode($fileName));
        $result = trim(urldecode($result));
        $base = basename($fileName);
        if (!preg_match(
            '/^(EM_[A-Za-z0-9]+_\d{8}-\d{6}_NML_\d+)_O(\d+)-(\d+)\.(mp3|wav|m4a)$/i',
            $base,
            $m
        )) {
            return ['ok' => false, 'diag' => 'bad_chunk_name'];
        }
        $parentBase = $m[1];
        $startSec = (int)$m[2];
        $endSec = (int)$m[3];
        if (!preg_match('/^EM_[A-Za-z0-9]+_(\d{8})-(\d{6})_NML_(\d+)$/', $parentBase, $pm)) {
            return ['ok' => false, 'diag' => 'bad_parent'];
        }
        $date = $pm[1];
        $year = substr($date, 0, 4);
        $month = substr($date, 4, 2);
        $day = substr($date, 6, 2);
        $cfg = AudioSettingsRepository::storageConfig();
        $parsedRoot = '/' . trim($cfg['audio_parsed'], '/') . '/';
        $parentRel = $parsedRoot . $year . '/' . $month . '/' . $day . '/' . $parentBase . '.mp3';

        $line = sprintf('[%02d:%02d->%02d:%02d] %s',
            intdiv($startSec, 60), $startSec % 60,
            intdiv($endSec, 60), $endSec % 60,
            $result
        );

        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT ID, TRANSCRIPTION, DT_TRANSCRIBED FROM base_records WHERE FILENAME = ? LIMIT 1');
        $st->execute([$parentRel]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Try any extension / path containing parent base
            $st = $pdo->prepare('SELECT ID, TRANSCRIPTION, DT_TRANSCRIBED, FILENAME FROM base_records WHERE FILENAME LIKE ? LIMIT 1');
            $st->execute(['%' . $parentBase . '%']);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row) {
            return ['ok' => false, 'diag' => 'parent_missing'];
        }

        $prev = trim((string)($row['TRANSCRIPTION'] ?? ''));
        $next = $prev === '' ? $line : ($prev . "\n" . $line);
        $upd = $pdo->prepare(
            'UPDATE base_records SET TRANSCRIPTION = ?, DT_TRANSCRIBED = 1 WHERE ID = ?'
        );
        $ok = $upd->execute([$next, (int)$row['ID']]);

        $storage = FileStorage::fromSettings();
        $doc = rtrim($storage->documentRoot(), '/');
        $chunkPath = $fileName;
        if ($chunkPath !== '' && $chunkPath[0] !== '/') {
            $chunkPath = '/' . $chunkPath;
        }
        $full = $doc . $chunkPath;
        if ($storage->exists($full)) {
            $storage->unlink($full);
        }
        $storage->close();

        return ['ok' => $ok, 'diag' => 'chunk_len=' . mb_strlen($result, 'UTF-8')];
    }
}
