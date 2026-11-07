<?php
declare(strict_types=1);

/**
 * LongJob: module uninstall — dump crumbs (DbJobs) → drop one table/tick → finalize.
 * Domain checks mirror ModuleManager::uninstall; dump budgets stay in DbDump.
 */
final class CoreUninstallLongJob
{
    public const TYPE = 'core.module_uninstall';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function onStart(array $job, array $opts): array
    {
        unset($opts);
        $id = trim((string)($job['module_id'] ?? ''));
        if ($id === '' || $id === 'core') {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = $id === 'core'
                ? t('app.module.err.core_locked')
                : t('core.modules.err.no_module');
            $job['message'] = $job['error'];
            return $job;
        }
        if (ModuleManager::get($id) === null) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('app.module.err.not_found', ['id' => $id]);
            $job['message'] = $job['error'];
            return $job;
        }
        $strategy = ModuleManager::uninstallStrategy($id);
        if ($strategy === 'leave_tables') {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('app.module.err.uninstall_leave_tables', ['id' => $id]);
            $job['message'] = $job['error'];
            return $job;
        }
        $dependents = ModuleManager::enabledDependents($id);
        if ($dependents !== []) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('app.module.err.uninstall_dependents', [
                'id' => $id,
                'modules' => implode(', ', $dependents),
            ]);
            $job['message'] = $job['error'];
            return $job;
        }

        $ownership = ModuleManager::exclusiveOwnedTables($id);
        $exclusive = $ownership['exclusive'];
        $toDrop = [];
        foreach ($exclusive as $t) {
            if (Database::tableExists($t)) {
                $toDrop[] = $t;
            }
        }
        $job['module_id'] = $id;
        $job['to_drop'] = $toDrop;
        $job['dropped'] = [];
        $job['skipped_shared'] = $ownership['shared'];
        $job['dump_job_id'] = '';
        $job['dump_result'] = null;
        $job['phase'] = $toDrop !== [] ? 'dump' : 'finalize';
        $job['progress'] = 5;
        $job['progress_overall'] = 5;
        $job['message'] = t('core.longjob.uninstall_start', ['module' => $id]);
        LongJob::appendLog($job, $job['message']);

        if ($toDrop !== []) {
            $dumpJob = DbJobs::jobStart('dump', [
                'tables' => $toDrop,
                'label' => 'uninstall-' . $id . '-' . count($toDrop) . 't',
            ]);
            $job['dump_job_id'] = (string)($dumpJob['id'] ?? '');
            LongJob::appendLog($job, t('core.longjob.uninstall_dump_start', [
                'n' => (string)count($toDrop),
            ]));
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function tick(array $job): array
    {
        $phase = (string)($job['phase'] ?? 'finalize');
        if ($phase === 'dump') {
            $dumpId = (string)($job['dump_job_id'] ?? '');
            if ($dumpId === '') {
                $job['phase'] = 'drop';
                return $job;
            }
            $dj = DbJobs::jobTick($dumpId);
            $pct = (int)($dj['progress_overall'] ?? $dj['progress'] ?? 0);
            $job['progress'] = min(70, max(5, (int)floor($pct * 0.7)));
            $job['progress_overall'] = $job['progress'];
            $job['message'] = (string)($dj['message'] ?? t('core.longjob.uninstall_dumping'));
            if (($dj['status'] ?? '') === 'running') {
                return $job;
            }
            if (($dj['status'] ?? '') !== 'done') {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = t('app.module.err.uninstall_dump', [
                    'id' => (string)$job['module_id'],
                    'message' => (string)($dj['error'] ?? 'Dump failed'),
                ]);
                $job['message'] = $job['error'];
                return $job;
            }
            $job['dump_result'] = is_array($dj['result'] ?? null) ? $dj['result'] : null;
            $job['phase'] = 'drop';
            $job['drop_index'] = 0;
            LongJob::appendLog($job, t('core.longjob.uninstall_dump_done'));
            return $job;
        }

        if ($phase === 'drop') {
            $toDrop = is_array($job['to_drop'] ?? null) ? $job['to_drop'] : [];
            $idx = (int)($job['drop_index'] ?? 0);
            $total = count($toDrop);
            if ($idx >= $total) {
                $job['phase'] = 'finalize';
                return self::tick($job);
            }
            // Disable module before first DROP so schema/runtime stop using tables.
            if ($idx === 0) {
                self::disableModule((string)$job['module_id']);
                $job['disabled'] = true;
            }
            $table = (string)$toDrop[$idx];
            $report = DbTools::dropTables([$table]);
            $dropped = is_array($job['dropped'] ?? null) ? $job['dropped'] : [];
            foreach ((array)($report['dropped'] ?? [$table]) as $t) {
                $dropped[] = (string)$t;
            }
            $job['dropped'] = $dropped;
            $job['drop_index'] = $idx + 1;
            LongJob::appendLog($job, t('core.longjob.uninstall_drop', ['table' => $table]));
            $frac = $total > 0 ? (float)($idx + 1) / (float)$total : 1.0;
            $job['progress'] = 70 + (int)floor(25 * $frac);
            $job['progress_overall'] = $job['progress'];
            $job['message'] = t('core.longjob.uninstall_dropping', [
                'done' => (string)($idx + 1),
                'total' => (string)$total,
            ]);
            if ($job['drop_index'] >= $total) {
                $job['phase'] = 'finalize';
            }
            return $job;
        }

        return self::finalize($job);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function onCancel(array $job): array
    {
        $dumpId = (string)($job['dump_job_id'] ?? '');
        if ($dumpId !== '' && ($job['phase'] ?? '') === 'dump') {
            try {
                DbJobs::jobCancel($dumpId);
            } catch (Throwable $e) {
                // ignore
            }
        }
        // If drops already started, leave disabled + partial DROP (safer than re-enabling).
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function finalize(array $job): array
    {
        $id = (string)$job['module_id'];
        if (empty($job['disabled'])) {
            self::disableModule($id);
            $job['disabled'] = true;
        }
        $ledgerCleared = SchemaMigrator::clearModuleLedger($id);
        if (Database::tableExists('base_modules')) {
            $del = Database::pdo()->prepare('DELETE FROM base_modules WHERE id = ?');
            $del->execute([$id]);
        }
        $iniPath = base_path('cfg/modules/' . $id . '.ini');
        if (is_file($iniPath)) {
            @unlink($iniPath);
        }
        $dump = is_array($job['dump_result'] ?? null) ? $job['dump_result'] : null;
        $dropped = is_array($job['dropped'] ?? null) ? $job['dropped'] : [];
        $job['status'] = LongJob::STATUS_DONE;
        $job['progress'] = 100;
        $job['progress_overall'] = 100;
        $dumpFile = is_array($dump) ? (string)($dump['file'] ?? '') : '';
        $job['message'] = $dumpFile !== ''
            ? t('core.modules.msg.uninstalled_dump', [
                'module' => $id,
                'file' => $dumpFile,
                'n' => count($dropped),
            ])
            : t('core.modules.msg.uninstalled', [
                'module' => $id,
                'n' => count($dropped),
            ]);
        $job['result'] = [
            'module' => $id,
            'dump' => $dump,
            'dropped' => $dropped,
            'ledger_cleared' => $ledgerCleared,
            'skipped_shared' => $job['skipped_shared'] ?? [],
        ];
        LongJob::appendLog($job, $job['message']);
        unset($job['to_drop'], $job['dump_job_id']);
        return $job;
    }

    private static function disableModule(string $id): void
    {
        if (Config::moduleEnabled($id)) {
            ModuleManager::setEnabled($id, false);
        } else {
            Config::setModuleEnabled($id, false);
        }
    }
}
