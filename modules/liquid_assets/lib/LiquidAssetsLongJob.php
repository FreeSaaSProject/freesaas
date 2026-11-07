<?php
declare(strict_types=1);

/** LongJob: T-Invest portfolio sync (one account per tick). */
final class LiquidAssetsLongJob
{
    public const TYPE_TINVEST_SYNC = 'liquid_assets.tinvest_sync';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function syncOnStart(array $job, array $opts): array
    {
        unset($opts);
        $ym = (string)($job['ym'] ?? date('Y-m'));
        $period = LiquidAssetsReport::parseYm($ym);
        if (empty($period['is_current'])) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('liquid_assets.sync.past_only');
            $job['message'] = $job['error'];
            return $job;
        }
        $ids = TinvestAccountsRepository::ids();
        $job['ym'] = $period['ym'];
        $job['month_start'] = (int)$period['month_start'];
        $job['month_end'] = (int)$period['month_end'];
        $job['ids'] = $ids;
        $job['index'] = 0;
        $job['lines'] = [];
        $job['pending'] = [];
        $job['all_ok'] = true;
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('liquid_assets.report.sync');
        TinvestApiClient::resetStats();
        LongJob::appendLog($job, t('liquid_assets.longjob.sync_start', [
            'n' => (string)count($ids),
        ]));
        if ($ids === []) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('liquid_assets.sync.no_rows');
            $job['message'] = $job['error'];
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function syncTick(array $job): array
    {
        $ids = is_array($job['ids'] ?? null) ? $job['ids'] : [];
        $index = (int)($job['index'] ?? 0);
        $total = count($ids);
        if ($index >= $total) {
            return self::finish($job);
        }
        $id = (int)$ids[$index];
        $userId = (int)($job['user_id'] ?? 0);
        $r = TinvestSyncService::syncAccount(
            $id,
            $userId,
            (int)$job['month_start'],
            (int)$job['month_end']
        );
        $lines = is_array($job['lines'] ?? null) ? $job['lines'] : [];
        $lines[] = (string)($r['msg'] ?? '');
        $job['lines'] = $lines;
        if (empty($r['ok'])) {
            $job['all_ok'] = false;
        } elseif (!empty($r['need_confirm'])) {
            $pending = is_array($job['pending'] ?? null) ? $job['pending'] : [];
            $pending[] = [
                'id_tinvest' => (int)($r['id_tinvest'] ?? $id),
                'id_fund' => (int)($r['id_fund'] ?? 0),
                'label' => (string)($r['label'] ?? ''),
                'asset_x' => (float)($r['asset_x'] ?? 0),
                'fund_y' => (float)($r['fund_y'] ?? 0),
                'existing_reval_id' => (int)($r['existing_reval_id'] ?? 0),
                'snapshot_id' => (int)($r['snapshot_id'] ?? 0),
                'total' => (float)($r['total'] ?? 0),
            ];
            $job['pending'] = $pending;
        }
        LongJob::appendLog($job, (string)($r['msg'] ?? ('#' . $id)));
        $job['index'] = $index + 1;
        $frac = $total > 0 ? min(1.0, (float)$job['index'] / (float)$total) : 1.0;
        $job['progress'] = (int)floor($frac * 100);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('liquid_assets.report.sync') . ' ' . $job['index'] . '/' . $total;
        if ($job['index'] >= $total) {
            return self::finish($job);
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function finish(array $job): array
    {
        $lines = is_array($job['lines'] ?? null) ? $job['lines'] : [];
        $pending = is_array($job['pending'] ?? null) ? $job['pending'] : [];
        $job['status'] = LongJob::STATUS_DONE;
        $job['progress'] = 100;
        $job['progress_overall'] = 100;
        $job['message'] = t('liquid_assets.sync.snapshots')
            . ' (≤ ' . date('d.m.Y', TinvestSyncService::snapshotDt((int)$job['month_end'])) . ')';
        $job['result'] = [
            'ok' => !empty($job['all_ok']),
            'ym' => (string)($job['ym'] ?? ''),
            'lines' => $lines,
            'pending' => $pending,
            'need_confirm' => $pending !== [],
        ];
        LongJob::appendLog($job, $job['message']);
        unset($job['ids'], $job['pending'], $job['lines']);
        return $job;
    }
}
