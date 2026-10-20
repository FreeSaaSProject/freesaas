<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class VideoConfig
{
    /** @return array<string, mixed> */
    public static function formValues(): array
    {
        $token = VideoWorkerAuth::configuredToken();

        return [
            'worker_token_masked' => VideoWorkerAuth::mask($token),
            'worker_token_set' => $token !== '',
            'retention_days' => (int)Config::get('video', 'retention_days', 7),
            'max_storage_mb' => (int)Config::get('video', 'max_storage_mb', 2048),
            'keep_alert_media' => (int)Config::get('video', 'keep_alert_media', 1) === 1,
            'latest_frame_ring' => (int)Config::get('video', 'latest_frame_ring', 3),
            'purge_mode' => (string)Config::get('video', 'purge_mode', 'default'),
            'storage_bytes' => VideoStorage::monitoringBytes(),
        ];
    }

    /** @param array<string, mixed> $post */
    public static function saveFromPost(array $post): void
    {
        if (!empty($post['regenerate_token'])) {
            VideoWorkerAuth::generateAndPersist();
        } elseif (isset($post['worker_token']) && trim((string)$post['worker_token']) !== '') {
            Config::set('video', 'worker_token', trim((string)$post['worker_token']));
        } else {
            VideoWorkerAuth::ensureGenerated();
        }

        $days = max(0, (int)($post['retention_days'] ?? 7));
        Config::set('video', 'retention_days', (string)$days);

        $mb = max(0, (int)($post['max_storage_mb'] ?? 2048));
        Config::set('video', 'max_storage_mb', (string)$mb);

        Config::set('video', 'keep_alert_media', !empty($post['keep_alert_media']) ? '1' : '0');

        $ring = max(1, min(30, (int)($post['latest_frame_ring'] ?? 3)));
        Config::set('video', 'latest_frame_ring', (string)$ring);

        $mode = (string)($post['purge_mode'] ?? 'default');
        if (!in_array($mode, ['default', 'alerts_only'], true)) {
            $mode = 'default';
        }
        Config::set('video', 'purge_mode', $mode);
    }
}
