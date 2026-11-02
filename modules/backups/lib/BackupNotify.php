<?php
declare(strict_types=1);

final class BackupNotify
{
    public const STALE_DEDUP_SEC = 43200; // 12h

    /**
     * @param array<string,mixed> $notices
     * @return array<string,mixed>|list
     */
    public static function collectNotices($notices)
    {
        if (!is_array($notices)) {
            $notices = [];
        }
        if (!BackupConfig::notifyUi() || !Auth::check() || !Auth::can('backups.view')) {
            return $notices;
        }
        try {
            $last = BackupRepository::findLast();
            if ($last !== null && ($last['status'] ?? '') === 'error' && BackupConfig::notifyOnFail()) {
                $notices[] = [
                    'id' => 'backups.fail',
                    'tone' => 'danger',
                    'href' => '/backups',
                    'title' => t('backups.notice.fail_title'),
                    'short' => t('backups.notice.fail_short', [
                        'error' => mb_substr((string)($last['error'] ?? ''), 0, 80),
                    ]),
                ];
            }
            if (BackupConfig::notifyOnStale() && self::isStale()) {
                $notices[] = [
                    'id' => 'backups.stale',
                    'tone' => 'warn',
                    'href' => '/backups',
                    'title' => t('backups.notice.stale_title'),
                    'short' => t('backups.notice.stale_short', [
                        'hours' => (string)BackupConfig::notifyStaleHours(),
                    ]),
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
        return $notices;
    }

    public static function isStale(): bool
    {
        if (!BackupConfig::enabled()) {
            return false;
        }
        $ok = BackupRepository::findLastSuccess();
        $hours = BackupConfig::notifyStaleHours();
        if ($ok === null) {
            // never succeeded — stale if module enabled longer than threshold is unknown; use last run start or treat as stale
            $last = BackupRepository::findLast();
            if ($last === null) {
                return true;
            }
            return ((int)$last['started_at'] + $hours * 3600) < time();
        }
        $ts = (int)($ok['finished_at'] ?: $ok['started_at']);
        return ($ts + $hours * 3600) < time();
    }

    public static function onFail(array $run): void
    {
        if (!BackupConfig::notifyOnFail()) {
            return;
        }
        self::sendBots(
            t('backups.notify.fail', [
                'id' => (string)($run['id'] ?? ''),
                'error' => mb_substr((string)($run['error'] ?? ''), 0, 200),
                'phase' => (string)($run['phase'] ?? ''),
            ]),
            'backups.fail.' . (int)($run['id'] ?? 0)
        );
    }

    public static function onSuccess(array $run): void
    {
        if (!BackupConfig::notifyOnSuccess()) {
            return;
        }
        self::sendBots(
            t('backups.notify.success', [
                'id' => (string)($run['id'] ?? ''),
                'bytes' => self::formatBytes((int)($run['bytes'] ?? 0)),
                'remote' => (string)($run['remote_dir'] ?? ''),
            ]),
            'backups.ok.' . (int)($run['id'] ?? 0)
        );
    }

    public static function checkStale(): void
    {
        if (!BackupConfig::notifyOnStale() || !self::isStale()) {
            return;
        }
        $last = BackupConfig::lastStaleNotifiedAt();
        if ($last > 0 && (time() - $last) < self::STALE_DEDUP_SEC) {
            return;
        }
        self::sendBots(
            t('backups.notify.stale', ['hours' => (string)BackupConfig::notifyStaleHours()]),
            'backups.stale.' . date('Ymd')
        );
        BackupConfig::setLastStaleNotifiedAt(time());
    }

    /** Test message using current settings. */
    public static function sendTest(): array
    {
        $text = t('backups.notify.test', ['at' => date('c')]);
        $queued = self::sendBots($text, 'backups.test.' . time());
        return [
            'ok' => true,
            'notify_bots' => BackupConfig::notifyBots(),
            'queued' => $queued,
            'text' => $text,
        ];
    }

    /** @return list<int> queued send ids */
    private static function sendBots(string $text, string $dedupeKey): array
    {
        if (!BackupConfig::notifyBots()) {
            return [];
        }
        if (!ModuleManager::isEnabled('bots')) {
            return [];
        }
        $queued = [];
        foreach (BackupConfig::notifyStaffIds() as $uid) {
            $res = EventBus::emit('bots.send', [
                'audience' => 'staff',
                'user_id' => $uid,
                'text' => $text,
                'name' => 'backups',
                'dedupe_key' => $dedupeKey . '.u' . $uid,
            ]);
            $qid = is_array($res) ? (int)($res['queued_id'] ?? 0) : 0;
            if ($qid > 0) {
                $queued[] = $qid;
            }
        }
        $chat = BackupConfig::notifyChatId();
        if ($chat !== '') {
            $res = EventBus::emit('bots.send', [
                'audience' => 'staff',
                'chat_id' => $chat,
                'platform' => BackupConfig::notifyPlatform(),
                'text' => $text,
                'name' => 'backups',
                'dedupe_key' => $dedupeKey . '.chat',
            ]);
            $qid = is_array($res) ? (int)($res['queued_id'] ?? 0) : 0;
            if ($qid > 0) {
                $queued[] = $qid;
            }
        }
        return $queued;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KiB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MiB';
        }
        return round($bytes / 1073741824, 2) . ' GiB';
    }
}
