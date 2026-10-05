<?php
declare(strict_types=1);

/**
 * Inbox → base_records ingest (port of oper gens/recordParser.php, FileStorage-aware).
 */
final class IngestRunner
{
    /**
     * @return array{ok:bool,processed:int,skipped:int,message:string,extra?:array}
     */
    public static function run(bool $importBrokenDate = false): array
    {
        require_once base_path('modules/audio/lib/AudioSettingsRepository.php');
        require_once base_path('modules/audio/lib/FileStorage.php');

        JobRunner::applyTimeLimit();
        $deadline = JobRunner::deadline();
        $batch = JobRunner::batchSize();
        $cfg = AudioSettingsRepository::storageConfig();
        $storage = FileStorage::fromSettings();
        $inbox = '/' . trim($cfg['audio_inbox'], '/') . '/';
        $parsedRoot = '/' . trim($cfg['audio_parsed'], '/') . '/';
        $docRoot = rtrim($storage->documentRoot(), '/');

        $files = array_merge(
            $storage->glob($inbox, '*.mp3'),
            $storage->glob($inbox, '*.m4a')
        );
        sort($files);

        $processed = 0;
        $skipped = 0;
        $broken = 0;
        $now = time();
        $messages = [];

        foreach ($files as $path) {
            if (JobRunner::timedOut($deadline) || $processed >= $batch) {
                break;
            }
            $fileName = basename((string)$path);
            if ($fileName === '' || $fileName === '.' || $fileName === '..') {
                continue;
            }
            $parsed = self::parseFilename($fileName);
            if ($parsed === null) {
                $skipped++;
                $messages[] = 'bad_name:' . $fileName;
                continue;
            }

            $dtRecord = $parsed['dt'];
            if ($dtRecord > $now && !$importBrokenDate) {
                $broken++;
                $skipped++;
                continue;
            }
            if ($dtRecord > $now && $importBrokenDate) {
                // Keep time-of-day from name, clamp date to today
                $dtRecord = mktime(
                    (int)date('H', $parsed['dt']),
                    (int)date('i', $parsed['dt']),
                    (int)date('s', $parsed['dt']),
                    (int)date('n'),
                    (int)date('j'),
                    (int)date('Y')
                ) ?: $now;
            }

            $y = date('Y', $dtRecord);
            $m = date('m', $dtRecord);
            $d = date('d', $dtRecord);
            $destDirRel = $parsedRoot . $y . '/' . $m . '/' . $d;
            $destRel = $destDirRel . '/' . $fileName;
            $srcFull = self::absPath($storage, $path, $docRoot, $inbox, $fileName);
            $destFull = $docRoot . $destRel;

            if ($storage->exists($destFull)) {
                // Already in parsed — drop inbox duplicate if present
                if ($storage->exists($srcFull)) {
                    $storage->unlink($srcFull);
                }
                $skipped++;
                continue;
            }

            $storage->mkdir($docRoot . $destDirRel);
            if (!$storage->exists($srcFull)) {
                $skipped++;
                continue;
            }
            if (!$storage->rename($srcFull, $destFull)) {
                $skipped++;
                $messages[] = 'move_fail:' . $fileName;
                continue;
            }

            $adminId = self::resolveAdmin($parsed['serial'], $dtRecord);
            $pdo = Database::pdo();
            $st = $pdo->prepare(
                'INSERT INTO base_records (DT_RECORDED, DT_CREATE, ID_DICTOPHONE, FILENAME, ID_ADMIN)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([$dtRecord, $now, $parsed['serial'], $destRel, max(0, $adminId)]);
            $recordId = (int)$pdo->lastInsertId();
            if ($recordId > 0 && Database::tableExists('base_records_balances')) {
                $pdo->prepare(
                    'INSERT INTO base_records_balances (DT, DIRECTION, AMOUNT, ID_RECORD) VALUES (?, -1, 6, ?)'
                )->execute([$dtRecord, $recordId]);
            }
            $processed++;
        }

        $storage->close();
        return [
            'ok' => true,
            'processed' => $processed,
            'skipped' => $skipped,
            'message' => 'ingest_done',
            'extra' => ['broken_dates' => $broken, 'notes' => array_slice($messages, 0, 20)],
        ];
    }

    /**
     * @return array{dt:int,serial:int}|null
     */
    public static function parseFilename(string $fileName): ?array
    {
        $parts = explode('_', $fileName);
        if (count($parts) < 4) {
            return null;
        }
        $serialRaw = explode('.', (string)end($parts));
        $serial = (int)($serialRaw[0] ?? 0);
        if ($serial < 1) {
            return null;
        }

        $date = '';
        $time = '';
        if (isset($parts[2]) && preg_match('/^\d{8}-\d{6}$/', $parts[2])) {
            [$date, $time] = explode('-', $parts[2], 2);
        } elseif (isset($parts[2], $parts[3]) && preg_match('/^\d{8}$/', $parts[2]) && preg_match('/^\d{6}/', $parts[3])) {
            $date = $parts[2];
            $time = substr($parts[3], 0, 6);
        } else {
            return null;
        }

        $year = (int)substr($date, 0, 4);
        $month = (int)substr($date, 4, 2);
        $day = (int)substr($date, 6, 2);
        $hour = (int)substr($time, 0, 2);
        $minute = (int)substr($time, 2, 2);
        $second = (int)substr($time, 4, 2);
        $dt = mktime($hour, $minute, $second, $month, $day, $year);
        if ($dt === false) {
            return null;
        }
        return ['dt' => $dt, 'serial' => $serial];
    }

    private static function absPath(FileStorage $storage, string $path, string $docRoot, string $inbox, string $fileName): string
    {
        // Local glob returns absolute; FTP returns relative like /audio/file.mp3
        if ($path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:\\\\#', $path))) {
            if (strpos($path, $docRoot) === 0) {
                return $path;
            }
            // Relative from storage root
            if (strpos($path, $inbox) === 0 || strpos($path, ltrim($inbox, '/')) !== false) {
                return $docRoot . (strpos($path, '/') === 0 ? $path : '/' . $path);
            }
        }
        return $docRoot . $inbox . $fileName;
    }

    private static function resolveAdmin(int $serial, int $dtRecord): int
    {
        if (Database::tableExists('base_badge_assignments')) {
            $st = Database::pdo()->prepare(
                'SELECT ID_OPERATOR FROM base_badge_assignments
                 WHERE ID_BADGE = ? AND DT_START <= ? AND DT_END > ?
                 ORDER BY DT_START DESC LIMIT 1'
            );
            $st->execute([$serial, $dtRecord, $dtRecord]);
            $op = $st->fetchColumn();
            if ($op !== false && (int)$op > 0) {
                return (int)$op;
            }
        }
        if (Database::tableExists('base_workweeks')) {
            $dayStart = mktime(0, 0, 0, (int)date('n', $dtRecord), (int)date('j', $dtRecord), (int)date('Y', $dtRecord));
            $st = Database::pdo()->prepare(
                'SELECT ID_OPERATOR FROM base_workweeks WHERE DT = ? AND ID_TABLE = ? LIMIT 1'
            );
            $st->execute([(int)$dayStart, $serial]);
            $op = $st->fetchColumn();
            if ($op !== false && (int)$op > 0) {
                return (int)$op;
            }
        }
        return 0;
    }
}
