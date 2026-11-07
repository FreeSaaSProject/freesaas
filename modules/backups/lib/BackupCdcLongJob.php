<?php
declare(strict_types=1);

/** LongJob crumbs for CDC trigger install / uninstall. */
final class BackupCdcLongJob
{
    public const TYPE_INSTALL = 'backups.cdc_install';
    public const TYPE_UNINSTALL = 'backups.cdc_uninstall';
    public const TABLES_PER_TICK = 8;
    public const TRIGGERS_PER_TICK = 40;

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function installOnStart(array $job, array $opts): array
    {
        unset($opts);
        if (!BackupCdc::journalReady()) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('backups.doctor.check.cdc_journal') . ' — migrate first';
            $job['message'] = $job['error'];
            return $job;
        }
        BackupConfig::set('cdc_enabled', '1');
        $tables = BackupCdc::targetTables();
        $job['tables'] = $tables;
        $job['index'] = 0;
        $job['triggers'] = 0;
        $job['errors'] = [];
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('backups.ui.busy.cdc_install');
        LongJob::appendLog($job, t('backups.longjob.cdc_install_start', [
            'n' => (string)count($tables),
        ]));
        if ($tables === []) {
            BackupCdc::finalizeInstall([]);
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('backups.flash.cdc_install', ['triggers' => '0', 'tables' => '0']);
            $job['result'] = ['tables' => 0, 'triggers' => 0, 'errors' => []];
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function installTick(array $job): array
    {
        $tables = is_array($job['tables'] ?? null) ? $job['tables'] : [];
        $index = (int)($job['index'] ?? 0);
        $total = count($tables);
        $end = min($total, $index + self::TABLES_PER_TICK);
        $errors = is_array($job['errors'] ?? null) ? $job['errors'] : [];
        $triggers = (int)($job['triggers'] ?? 0);
        for ($i = $index; $i < $end; $i++) {
            $table = (string)$tables[$i];
            $r = BackupCdc::installTable($table);
            $triggers += (int)$r['triggers'];
            foreach ($r['errors'] as $err) {
                $errors[] = $err;
            }
            LongJob::appendLog($job, t('backups.longjob.cdc_table', [
                'table' => $table,
                'n' => (string)(int)$r['triggers'],
            ]));
        }
        $job['index'] = $end;
        $job['triggers'] = $triggers;
        $job['errors'] = $errors;
        $frac = $total > 0 ? min(1.0, (float)$end / (float)$total) : 1.0;
        $job['progress'] = (int)floor($frac * 100);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('backups.ui.busy.cdc_install') . ' ' . $end . '/' . $total;
        if ($end >= $total) {
            BackupCdc::finalizeInstall($tables);
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('backups.flash.cdc_install', [
                'triggers' => (string)$triggers,
                'tables' => (string)$total,
            ]);
            $job['result'] = [
                'tables' => $total,
                'triggers' => $triggers,
                'errors' => $errors,
            ];
            LongJob::appendLog($job, $job['message']);
            if ($errors !== []) {
                LongJob::appendLog($job, implode('; ', array_slice($errors, 0, 5)));
            }
            unset($job['tables']);
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function uninstallOnStart(array $job, array $opts): array
    {
        unset($opts);
        $names = BackupCdc::listTriggerNames();
        $job['names'] = $names;
        $job['index'] = 0;
        $job['dropped'] = 0;
        $job['errors'] = [];
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('backups.ui.busy.cdc_uninstall');
        LongJob::appendLog($job, t('backups.longjob.cdc_uninstall_start', [
            'n' => (string)count($names),
        ]));
        if ($names === []) {
            BackupConfig::set('cdc_installed_at', '0');
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('backups.flash.cdc_uninstall', ['n' => '0']);
            $job['result'] = ['dropped' => 0, 'errors' => []];
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function uninstallTick(array $job): array
    {
        $names = is_array($job['names'] ?? null) ? $job['names'] : [];
        $index = (int)($job['index'] ?? 0);
        $total = count($names);
        $end = min($total, $index + self::TRIGGERS_PER_TICK);
        $chunk = array_slice($names, $index, $end - $index);
        $r = BackupCdc::dropTriggersChunk($chunk);
        $job['dropped'] = (int)($job['dropped'] ?? 0) + (int)$r['dropped'];
        $errors = is_array($job['errors'] ?? null) ? $job['errors'] : [];
        foreach ($r['errors'] as $err) {
            $errors[] = $err;
        }
        $job['errors'] = $errors;
        $job['index'] = $end;
        $frac = $total > 0 ? min(1.0, (float)$end / (float)$total) : 1.0;
        $job['progress'] = (int)floor($frac * 100);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('backups.ui.busy.cdc_uninstall') . ' ' . $end . '/' . $total;
        LongJob::appendLog($job, t('backups.longjob.cdc_drop_chunk', [
            'n' => (string)(int)$r['dropped'],
            'done' => (string)$end,
            'total' => (string)$total,
        ]));
        if ($end >= $total) {
            BackupConfig::set('cdc_installed_at', '0');
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('backups.flash.cdc_uninstall', [
                'n' => (string)(int)$job['dropped'],
            ]);
            $job['result'] = ['dropped' => (int)$job['dropped'], 'errors' => $errors];
            LongJob::appendLog($job, $job['message']);
            unset($job['names']);
        }
        return $job;
    }
}
