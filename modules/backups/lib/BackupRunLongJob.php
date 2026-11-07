<?php
declare(strict_types=1);

/** Shape BackupRunner rows for LongJobPanel (domain SoT stays in BackupRepository). */
final class BackupRunLongJob
{
    public const TYPE = 'backups.run';

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    public static function publicFromRun(array $run): array
    {
        $progress = BackupRunner::progress($run);
        $status = (string)($run['status'] ?? 'running');
        $phase = (string)($run['phase'] ?? ($progress['phase'] ?? ''));
        $pct = $progress['pct'] ?? null;
        $phases = is_array($progress['phases'] ?? null) ? $progress['phases'] : [];
        $index = (int)($progress['index'] ?? 0);
        $n = count($phases);

        // Blend phase position + in-phase pct so the bar advances across the whole run.
        if ($status === 'done') {
            $overall = 100;
        } elseif ($n <= 0) {
            $overall = $pct !== null ? (int)$pct : 0;
        } else {
            $idx = max(0, min($n, $index));
            $within = $pct !== null ? max(0, min(100, (int)$pct)) / 100.0 : 0.0;
            if ($status === 'cancelled' || $status === 'error') {
                $overall = (int)floor(100 * $idx / $n);
            } else {
                $overall = (int)floor(100 * ($idx + $within) / $n);
            }
            $overall = max(0, min(99, $overall));
        }

        $phaseKey = 'backups.phase.' . $phase;
        $phaseLabel = t($phaseKey);
        if ($phaseLabel === $phaseKey) {
            $phaseLabel = $phase;
        }
        $remote = (string)($run['remote_dir'] ?? '');
        $id = (int)($run['id'] ?? 0);
        $message = '#' . $id . ($phaseLabel !== '' ? ' · ' . $phaseLabel : '');
        if ($pct !== null && $status === 'running') {
            $message .= ' ' . (int)$pct . '%';
        }
        if ($remote !== '') {
            $message .= ' · ' . $remote;
        }
        if ($status === 'done' && $remote !== '') {
            $message = $remote . ' · ' . BackupNotify::formatBytes((int)($run['bytes'] ?? 0));
        }
        if ($status === 'error') {
            $message = (string)($run['error'] ?? $message);
        }

        $log = [];
        foreach ($phases as $i => $ph) {
            $pk = 'backups.phase.' . $ph;
            $pl = t($pk);
            if ($pl === $pk) {
                $pl = (string)$ph;
            }
            if ($status === 'done' || $i < $index) {
                $log[] = '✓ ' . $pl;
            } elseif ($i === $index && $status === 'running') {
                $log[] = '… ' . $pl . ($pct !== null ? (' ' . (int)$pct . '%') : '');
            } else {
                $log[] = '· ' . $pl;
            }
        }

        return [
            'id' => (string)$id,
            'type' => self::TYPE,
            'op' => self::TYPE,
            'status' => $status,
            'progress' => $overall,
            'progress_overall' => $overall,
            'progress_detail' => $pct !== null ? (int)$pct : $overall,
            'message' => $message,
            'error' => $status === 'error' ? (string)($run['error'] ?? '') : null,
            'phase' => $phase,
            'bytes' => (int)($run['bytes'] ?? 0),
            'file' => $remote,
            'log' => $log,
            'result' => [
                'run_id' => $id,
                'kind' => (string)($run['kind'] ?? ''),
                'remote_dir' => $remote,
                'progress' => $progress,
            ],
        ];
    }
}
