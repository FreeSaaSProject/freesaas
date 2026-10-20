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
        $olderThan = time() - max(0, $days) * 86400;
        if ($alertsOnly) {
            $olderThan = time() + 86400; // all non-alert candidates
        }

        $files = 0;
        $bytes = 0;

        $batch = VideoDetectionsRepository::purgeCandidates($olderThan, $alertsOnly);
        foreach ($batch as $row) {
            $path = trim((string)($row['FRAME_PATH'] ?? ''));
            if ($path === '' || isset($protected[$path])) {
                continue;
            }
            $sz = VideoStorage::sizeBytes($path);
            if (VideoStorage::delete($path)) {
                $files++;
                $bytes += $sz;
            }
            VideoDetectionsRepository::clearFrame((int)$row['ID']);
        }

        // Hard cap: purge oldest non-alert frames until under max_storage_mb
        if ($maxMb > 0) {
            $limit = $maxMb * 1024 * 1024;
            $guard = 0;
            while (VideoStorage::monitoringBytes() > $limit && $guard < 20) {
                $guard++;
                $more = VideoDetectionsRepository::purgeCandidates(time() + 1, true);
                if ($more === []) {
                    break;
                }
                foreach ($more as $row) {
                    $path = trim((string)($row['FRAME_PATH'] ?? ''));
                    if ($path === '' || isset($protected[$path])) {
                        continue;
                    }
                    $sz = VideoStorage::sizeBytes($path);
                    if (VideoStorage::delete($path)) {
                        $files++;
                        $bytes += $sz;
                    }
                    VideoDetectionsRepository::clearFrame((int)$row['ID']);
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
}
