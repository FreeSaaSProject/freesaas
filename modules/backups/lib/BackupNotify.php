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
        $stats = self::runStats($run);
        self::sendBots(
            t('backups.notify.fail', [
                'id' => (string)($run['id'] ?? ''),
                'kind' => $stats['kind'],
                'error' => mb_substr((string)($run['error'] ?? ''), 0, 200),
                'phase' => (string)($run['phase'] ?? ''),
                'duration' => $stats['duration'],
                'bytes' => $stats['bytes'],
            ]),
            'backups.fail.' . (int)($run['id'] ?? 0)
        );
    }

    public static function onSuccess(array $run): void
    {
        if (!BackupConfig::notifyOnSuccess()) {
            return;
        }
        $stats = self::runStats($run);
        self::sendBots(
            t('backups.notify.success', [
                'id' => (string)($run['id'] ?? ''),
                'kind' => $stats['kind'],
                'bytes' => $stats['bytes'],
                'duration' => $stats['duration'],
                'remote' => (string)($run['remote_dir'] ?? ''),
            ]),
            'backups.ok.' . (int)($run['id'] ?? 0)
        );
    }

    /**
     * @param array<string,mixed> $run
     * @return array{kind:string,bytes:string,duration:string,duration_sec:int}
     */
    private static function runStats(array $run): array
    {
        $kind = (string)($run['kind'] ?? '');
        if ($kind === '' && is_array($run['state'] ?? null)) {
            $kind = (string)(($run['state']['kind'] ?? '') ?: 'full');
        }
        if ($kind === '') {
            $kind = 'full';
        }
        $started = (int)($run['started_at'] ?? 0);
        $finished = (int)($run['finished_at'] ?? 0);
        if ($finished < 1) {
            $finished = time();
        }
        $sec = $started > 0 ? max(0, $finished - $started) : 0;
        return [
            'kind' => $kind,
            'bytes' => self::formatBytes((int)($run['bytes'] ?? 0)),
            'duration' => self::formatDuration($sec),
            'duration_sec' => $sec,
        ];
    }

    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        if ($m < 60) {
            return $s > 0 ? ($m . 'm ' . $s . 's') : ($m . 'm');
        }
        $h = intdiv($m, 60);
        $m = $m % 60;
        return $m > 0 ? ($h . 'h ' . $m . 'm') : ($h . 'h');
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
