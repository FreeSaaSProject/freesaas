<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Retention: delete monitoring frames; keep alert media + label datasets + model weights.
 */
final class VideoPurge
{
    /** @return array{ok:bool,files:int,bytes:int,message:string} */
    public static function run(): array
    {
        $days = (int)Config::get('video', 'retention_days', 7);
        $maxMb = (int)Config::get('video', 'max_storage_mb', 2048);
        $keepAlerts = (int)Config::get('video', 'keep_alert_media', 1) === 1;
        $mode = (string)Config::get('video', 'purge_mode', 'default');
        $alertsOnly = $mode === 'alerts_only';

        $protected = $keepAlerts ? VideoAlertsRepository::protectedPaths() : [];
        // Camera "latest" paths stay until replaced — still purgeable if not protected alerts
        $olderThan = time() - max(0, $days) * 86400;
        if ($alertsOnly) {
            $olderThan = time() + 86400;
        }

        $files = 0;
        $bytes = 0;

        $deleteTracked = static function (array $row) use (&$files, &$bytes, $protected): void {
            foreach (VideoDetectionsRepository::pathsOfRow($row) as $path) {
                if ($path === '' || isset($protected[$path])) {
                    continue;
                }
                $sz = VideoStorage::sizeBytes($path);
                if (VideoStorage::delete($path)) {
                    $files++;
                    $bytes += $sz;
                }
            }
            VideoDetectionsRepository::clearFrame((int)$row['ID']);
        };

        $batch = VideoDetectionsRepository::purgeCandidates($olderThan, $alertsOnly);
        foreach ($batch as $row) {
            $deleteTracked($row);
        }

        // Sweep orphan JPEGs under frames/ and live/ not referenced by detections/alerts
        $swept = self::sweepOrphans($olderThan, $alertsOnly, $protected);
        $files += $swept['files'];
        $bytes += $swept['bytes'];

        if ($maxMb > 0) {
            $limit = $maxMb * 1024 * 1024;
            $guard = 0;
            while (VideoStorage::monitoringBytes() > $limit && $guard < 20) {
                $guard++;
                $more = VideoDetectionsRepository::purgeCandidates(time() + 1, true);
                if ($more === []) {
                    $swept2 = self::sweepOrphans(time() + 1, true, $protected);
                    $files += $swept2['files'];
                    $bytes += $swept2['bytes'];
                    break;
                }
                foreach ($more as $row) {
                    $deleteTracked($row);
                    if (VideoStorage::monitoringBytes() <= $limit) {
                        break 2;
                    }
                }
            }
        }

        return [
            'ok' => true,
            'files' => $files,
            'bytes' => $bytes,
            'message' => "purged {$files} files / {$bytes} bytes",
        ];
    }

    /**
     * @param array<string, true> $protected
     * @return array{files:int,bytes:int}
     */
    private static function sweepOrphans(int $olderThanTs, bool $allNonAlert, array $protected): array
    {
        $referenced = $protected;
        $pdo = Database::pdo();
        $st = $pdo->query('SELECT FRAME_PATH, EXTRA_PATHS_JSON FROM base_video_detections WHERE FRAME_PATH <> \'\' OR EXTRA_PATHS_JSON IS NOT NULL');
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                foreach (VideoDetectionsRepository::pathsOfRow($row ?: []) as $p) {
                    $referenced[$p] = true;
                }
            }
        }
        $st2 = $pdo->query('SELECT LAST_FRAME_PATH, LAST_ANNOTATED_PATH FROM base_video_cameras');
        if ($st2) {
            while ($row = $st2->fetch(PDO::FETCH_ASSOC)) {
                foreach ([(string)($row['LAST_FRAME_PATH'] ?? ''), (string)($row['LAST_ANNOTATED_PATH'] ?? '')] as $p) {
                    $p = trim($p);
                    if ($p !== '') {
                        $referenced[$p] = true;
                    }
                }
            }
        }

        $files = 0;
        $bytes = 0;
        foreach (['frames', 'live'] as $sub) {
            $dir = VideoStorage::root() . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $mtime = (int)$file->getMTime();
                if (!$allNonAlert && $mtime >= $olderThanTs) {
                    continue;
                }
                $abs = $file->getPathname();
                $rel = VideoStorage::relFromAbs($abs);
                if (isset($referenced[$rel])) {
                    continue;
                }
                $sz = (int)$file->getSize();
                if (@unlink($abs)) {
                    $files++;
                    $bytes += $sz;
                }
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }
}
