<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Optional consequence of the video module: while the on-duty staff member is absent from the post,
 * their cabin records around the absence are disabled; an accepted appeal re-enables them.
 * Soft coupling only through EventBus (video.alert.created / video.appeal.decided); switch + windows in
 * cfg/modules/audio.ini [audio] video_effects_*; disabled by default.
 */
final class AudioVideoEffects
{
    public const KIND_ABSENT = 'rule:absent';

    /** @return array{enabled:bool,before_min:int,after_min:int} */
    public static function config(): array
    {
        return [
            'enabled' => (int)Config::get('audio', 'video_effects_enabled', 0) === 1,
            'before_min' => max(0, (int)Config::get('audio', 'video_effects_before_min', 5)),
            'after_min' => max(0, (int)Config::get('audio', 'video_effects_after_min', 5)),
        ];
    }

    /** @param array<string,mixed> $post */
    public static function save(array $post): void
    {
        Config::set('audio', 'video_effects_enabled', !empty($post['video_effects_enabled']) ? '1' : '0');
        Config::set('audio', 'video_effects_before_min', (string)max(0, min(120, (int)($post['video_effects_before_min'] ?? 5))));
        Config::set('audio', 'video_effects_after_min', (string)max(0, min(120, (int)($post['video_effects_after_min'] ?? 5))));
    }

    public static function tableReady(): bool
    {
        return Database::tableExists('base_audio_video_effects') && Database::tableExists('base_records');
    }

    /**
     * New absence alert → disable the staff member's records overlapping the absence (−before … +playtime+after).
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function onAlertCreated($payload)
    {
        if (!is_array($payload) || (string)($payload['kind'] ?? '') !== self::KIND_ABSENT) {
            return $payload;
        }
        $cfg = self::config();
        $staffId = (int)($payload['staff_id'] ?? 0);
        $alertId = (int)($payload['alert_id'] ?? 0);
        if (!$cfg['enabled'] || $staffId < 1 || $alertId < 1 || !self::tableReady()) {
            return $payload;
        }
        $dt = (int)($payload['dt'] ?? 0) ?: time();
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $from = (int)($meta['absent_since'] ?? 0);
        if ($from < 1 || $from > $dt) {
            $from = $dt;
        }
        try {
            self::disableWindow($alertId, $staffId, $from, $dt, $cfg['before_min'] * 60, $cfg['after_min'] * 60);
        } catch (Throwable $e) {
            error_log('[audio.video_effects] ' . $e->getMessage());
        }

        return $payload;
    }

    /**
     * Accepted appeal → re-enable exactly the records this alert disabled
     * (kept disabled while another alert still claims the record).
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function onAppealDecided($payload)
    {
        if (!is_array($payload) || empty($payload['accepted'])) {
            return $payload;
        }
        $alertId = (int)($payload['alert_id'] ?? 0);
        if ($alertId < 1 || !self::tableReady()) {
            return $payload;
        }
        try {
            self::restoreAlert($alertId);
        } catch (Throwable $e) {
            error_log('[audio.video_effects] restore: ' . $e->getMessage());
        }

        return $payload;
    }

    /** @return int number of records disabled */
    public static function disableWindow(int $alertId, int $staffId, int $from, int $to, int $beforeSec, int $afterSec): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT ID FROM base_records
             WHERE ID_ADMIN = ? AND DISABLED = 0
               AND DT_RECORDED <= ? AND (DT_RECORDED + PLAYTIME) >= ?'
        );
        // Record [R, R+playtime] meets [from-before, to+after].
        $st->execute([$staffId, $to + $afterSec, $from - $beforeSec]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids === []) {
            return 0;
        }
        $now = time();
        $ins = $pdo->prepare('INSERT IGNORE INTO base_audio_video_effects (ALERT_ID, RECORD_ID, DT_CREATE) VALUES (?, ?, ?)');
        foreach ($ids as $rid) {
            $ins->execute([$alertId, $rid, $now]);
        }
        RecordsRepository::setDisabledByIds($ids, true);

        return count($ids);
    }

    /** @return int number of records re-enabled */
    public static function restoreAlert(int $alertId): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT RECORD_ID FROM base_audio_video_effects WHERE ALERT_ID = ?');
        $st->execute([$alertId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids === []) {
            return 0;
        }
        // Keep disabled when another (not cancelled) alert still claims the record.
        $in = implode(',', array_fill(0, count($ids), '?'));
        $other = $pdo->prepare("SELECT DISTINCT RECORD_ID FROM base_audio_video_effects WHERE RECORD_ID IN ($in) AND ALERT_ID <> ?");
        $other->execute(array_merge($ids, [$alertId]));
        $held = array_flip(array_map('intval', $other->fetchAll(PDO::FETCH_COLUMN) ?: []));
        $restore = array_values(array_filter($ids, static fn (int $i): bool => !isset($held[$i])));
        if ($restore !== []) {
            RecordsRepository::setDisabledByIds($restore, false);
        }
        $pdo->prepare('DELETE FROM base_audio_video_effects WHERE ALERT_ID = ?')->execute([$alertId]);

        return count($restore);
    }

    /**
     * Registry for /video/settings: which modules react to absence alerts and whether they are on.
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function onCollect($payload)
    {
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['items'] = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $payload['items'][] = [
            'id' => 'audio.disable_records',
            'module' => 'audio',
            'label' => 'audio.video_effects.title',
            'enabled' => self::config()['enabled'],
            'href' => '/audio/settings',
        ];

        return $payload;
    }
}
