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
     * End of last cue in custom `[mm:ss->mm:ss]` or SRT `--> HH:MM:SS,mmm`.
     * Returns whole seconds ≥ 1, or null.
     */
    public static function playtimeFromTranscript(string $result): ?int
    {
        $best = 0;
        if (preg_match_all('/\[(\d+):(\d{2})->(\d+):(\d{2})\]/u', $result, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $end = ((int)$m[3]) * 60 + (int)$m[4];
                if ($end > $best) {
                    $best = $end;
                }
            }
        }
        if (preg_match_all('/-->\s*(\d{2}):(\d{2}):(\d{2}),(\d{3})/', $result, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $end = ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (int)$m[3];
                if ($end > $best) {
                    $best = $end;
                }
            }
        }

        return $best >= 1 ? $best : null;
    }

    /**
     * Backfill PLAYTIME=0 rows that already have a transcript with cue end times.
     *
     * @return array{ok:bool,scanned:int,updated:int,skipped:int,dry_run:bool,error?:string}
     */
    public static function backfillPlaytimeFromTranscripts(
        ?string $dayYmd = null,
        ?int $adminId = null,
        bool $dryRun = true,
        int $limit = 5000
    ): array {
        if (!Database::tableExists('base_records')) {
            return ['ok' => false, 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'dry_run' => $dryRun, 'error' => 'no_table'];
        }
        $limit = max(1, min(50000, $limit));
        $where = ['COALESCE(PLAYTIME, 0) < 1', 'TRANSCRIPTION IS NOT NULL', "TRIM(TRANSCRIPTION) <> ''"];
        $params = [];
        if ($adminId !== null && $adminId > 0) {
            $where[] = 'ID_ADMIN = ?';
            $params[] = $adminId;
        }
        if ($dayYmd !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayYmd) === 1) {
            $from = strtotime($dayYmd . ' 00:00:00');
            $to = $from + 86400;
            if ($from > 0) {
                $where[] = 'DT_RECORDED >= ? AND DT_RECORDED < ?';
                $params[] = $from;
                $params[] = $to;
            }
        }
        $sql = 'SELECT ID, TRANSCRIPTION FROM base_records WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ID ASC LIMIT ' . $limit;
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $updated = 0;
        $skipped = 0;
        $upd = Database::pdo()->prepare('UPDATE base_records SET PLAYTIME = ? WHERE ID = ? AND COALESCE(PLAYTIME, 0) < 1');
        foreach ($rows as $row) {
            $pt = self::playtimeFromTranscript((string)($row['TRANSCRIPTION'] ?? ''));
            if ($pt === null) {
                $skipped++;
                continue;
            }
            if (!$dryRun) {
                $upd->execute([$pt, (int)$row['ID']]);
            }
            $updated++;
        }

        return [
            'ok' => true,
            'scanned' => count($rows),
            'updated' => $updated,
            'skipped' => $skipped,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Recalc PLAYTIME from transcript cue ends for selected ids (UI bulk).
     * Only updates rows with PLAYTIME < 1 that have a non-empty transcript.
     *
     * @param list<int> $ids
     * @return array{done:int,skipped:list<int>}
     */
    public static function fixPlaytimeFromTranscriptByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ($ids === [] || !Database::tableExists('base_records')) {
            return ['done' => 0, 'skipped' => []];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID, TRANSCRIPTION, COALESCE(PLAYTIME, 0) AS playtime
             FROM base_records WHERE ID IN ($in)"
        );
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $upd = Database::pdo()->prepare(
            'UPDATE base_records SET PLAYTIME = ? WHERE ID = ? AND COALESCE(PLAYTIME, 0) < 1'
        );
        $done = 0;
        $skipped = [];
        foreach ($rows as $row) {
            $id = (int)$row['ID'];
            if ((int)$row['playtime'] >= 1) {
                $skipped[] = $id;
                continue;
            }
            $tr = trim((string)($row['TRANSCRIPTION'] ?? ''));
            if ($tr === '') {
                $skipped[] = $id;
                continue;
            }
            $pt = self::playtimeFromTranscript($tr);
            if ($pt === null) {
                $skipped[] = $id;
                continue;
            }
            $upd->execute([$pt, $id]);
            $done++;
        }

        return ['done' => $done, 'skipped' => $skipped];
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
            'SELECT ID, DT_TRANSCRIBED, DT_RECORDED FROM base_records WHERE FILENAME = ? LIMIT 1'
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

        // Worker sometimes posts error text as if it were a transcript.
        $asrNoise = class_exists('TranscriptGarbage', false)
            && is_callable(['TranscriptGarbage', 'isAsrErrorTranscription'])
            && TranscriptGarbage::isAsrErrorTranscription($result);
        if ($asrNoise) {
            $result = '';
            $diagChunk .= ' ASR_ERROR_CLEARED=1';
        }

        $ptInt = ($playtime !== null && $playtime !== '' && is_numeric($playtime)) ? (int)$playtime : 0;
        if ($ptInt < 1 && $result !== '') {
            $fromTr = self::playtimeFromTranscript($result);
            if ($fromTr !== null) {
                $playtime = (string)$fromTr;
                $ptInt = $fromTr;
                $diagChunk .= ' PLAYTIME_FROM_TRANSCRIPT=1';
            }
        }
        // Never write PLAYTIME=0 from a bad probe — leave existing or omit.
        $writePlaytime = $ptInt >= 1;

        if ($dtTrans === 1) {
            // Chunk mode: do not overwrite TRANSCRIPTION
            if ($writePlaytime) {
                $sets[] = 'PLAYTIME = ?';
                $params[] = $ptInt;
            }
            if ($volume !== null && $volume !== '' && is_numeric($volume)) {
                $sets[] = 'VOLUME = ?';
                $params[] = (float)$volume;
            }
            $diagChunk .= ' SKIP_TRANSCRIPTION=chunk_mode';
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
            // Stamp even when clearing ASR error text — otherwise claim loops forever.
            $params[] = time();
            if ($writePlaytime) {
                $sets[] = 'PLAYTIME = ?';
                $params[] = $ptInt;
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
        $diag .= ' PLAYTIME=' . ($writePlaytime ? $ptInt : 'NO');
        $diag .= ' VOLUME=' . (($volume !== null && $volume !== '') ? $volume : 'NO');
        $diag .= $diagChunk;
        if ($ok && $dtTrans !== 1) {
            $queuedDay = self::enqueueStemForRecordDay((int)($row['DT_RECORDED'] ?? 0));
            if ($queuedDay !== '') {
                $diag .= ' STEM_QUEUED=' . $queuedDay;
            }
        }
        return ['ok' => $ok, 'diag' => $diag];
    }

    /** Delay before a late past-day result triggers that day's collect + publish. */
    public static function lateStemDelaySec(): int
    {
        $n = (int)Config::get('audio', 'late_stem_delay_sec', 3600);
        return max(300, min(86400, $n > 0 ? $n : 3600));
    }

    /**
     * Late records for a past day must re-run that day's stem/collect/publish.
     * Today is covered by the Whisper idle ping; older than lookback → manual Re-®.
     * Deferred by lateStemDelaySec(): a stream of uploads yields one collect+publish, not one per tick.
     */
    private static function enqueueStemForRecordDay(int $dtRecorded): string
    {
        if ($dtRecorded < 1 || !class_exists('AudioJobQueue', false) || !class_exists('StemRunner', false)) {
            return '';
        }
        $dayStart = (int)mktime(0, 0, 0, (int)date('n', $dtRecorded), (int)date('j', $dtRecorded), (int)date('Y', $dtRecorded));
        $today = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $oldest = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j') - StemRunner::LOOKBACK_DAYS, (int)date('Y'));
        if ($dayStart < $oldest || $dayStart >= $today) {
            return '';
        }
        $day = date('Y-m-d', $dayStart);
        try {
            AudioJobQueue::requestStem([
                'day' => $day,
                'not_before' => time() + self::lateStemDelaySec(),
            ]);
        } catch (Throwable $e) {
            error_log('[audio/asr/result] stem enqueue: ' . $e->getMessage());
            return '';
        }
        return $day;
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
