<?php
declare(strict_types=1);

/** LongJob: bank connection or ledger-account sync (one link per tick). */
final class BanksLongJob
{
    public const TYPE_SYNC = 'banks.sync';

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function syncOnStart(array $job, array $opts): array
    {
        unset($opts);
        $dateFrom = (string)($job['date_from'] ?? '');
        $dateTo = (string)($job['date_to'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $job['status'] = LongJob::STATUS_ERROR;
            $job['error'] = t('banks.err.sync');
            $job['message'] = $job['error'];
            return $job;
        }

        $ledgerAccountId = (int)($job['ledger_account_id'] ?? $job['account_id'] ?? 0);
        $connectionId = (int)($job['connection_id'] ?? 0);
        $onlyLinkId = (int)($job['link_id'] ?? 0);

        /** @var list<array{connection_id:int,link_id:int}> $queue */
        $queue = [];

        if ($ledgerAccountId > 0) {
            $job['ledger_account_id'] = $ledgerAccountId;
            foreach (BankAccountLinksRepository::forLedgerAccount($ledgerAccountId) as $link) {
                if ((int)($link['sync_enabled'] ?? 0) < 1) {
                    continue;
                }
                if (BanksConfig::isFileProvider((string)($link['provider'] ?? ''))) {
                    continue;
                }
                $cid = (int)($link['connection_id'] ?? 0);
                $lid = (int)($link['id'] ?? 0);
                if ($cid < 1 || $lid < 1) {
                    continue;
                }
                if ($onlyLinkId > 0 && $lid !== $onlyLinkId) {
                    continue;
                }
                $queue[] = ['connection_id' => $cid, 'link_id' => $lid];
            }
        } else {
            if ($connectionId < 1) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = t('banks.err.sync');
                $job['message'] = $job['error'];
                return $job;
            }
            $conn = BankConnectionsRepository::find($connectionId, true);
            if (!$conn) {
                $job['status'] = LongJob::STATUS_ERROR;
                $job['error'] = t('banks.err.not_found');
                $job['message'] = $job['error'];
                return $job;
            }
            if (BanksConfig::isFileProvider((string)$conn['provider'])) {
                $job['status'] = LongJob::STATUS_DONE;
                $job['progress'] = 100;
                $job['progress_overall'] = 100;
                $job['message'] = t('banks.sync.file_only');
                $job['result'] = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => [$job['message']]];
                return $job;
            }
            $job['connection_id'] = $connectionId;
            foreach (BankAccountLinksRepository::forConnection($connectionId) as $link) {
                if ($onlyLinkId > 0 && (int)$link['id'] !== $onlyLinkId) {
                    continue;
                }
                if ((int)$link['account_id'] < 1 || !(int)$link['sync_enabled']) {
                    continue;
                }
                $queue[] = ['connection_id' => $connectionId, 'link_id' => (int)$link['id']];
            }
        }

        $job['queue'] = $queue;
        $job['index'] = 0;
        $job['stats'] = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
        $job['progress'] = 0;
        $job['progress_overall'] = 0;
        $job['message'] = t('banks.conn.sync');
        LongJob::appendLog($job, t('banks.longjob.sync_start', [
            'n' => (string)count($queue),
            'from' => $dateFrom,
            'to' => $dateTo,
        ]));
        if ($queue === []) {
            $job['status'] = LongJob::STATUS_DONE;
            $job['progress'] = 100;
            $job['progress_overall'] = 100;
            $job['message'] = t('banks.sync.nothing_to_sync');
            $job['result'] = $job['stats'];
        }
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function syncTick(array $job): array
    {
        $queue = is_array($job['queue'] ?? null) ? $job['queue'] : [];
        $index = (int)($job['index'] ?? 0);
        $total = count($queue);
        if ($index >= $total) {
            return self::finish($job);
        }
        $item = is_array($queue[$index] ?? null) ? $queue[$index] : [];
        $connectionId = (int)($item['connection_id'] ?? 0);
        $linkId = (int)($item['link_id'] ?? 0);
        $dateFrom = (string)$job['date_from'];
        $dateTo = (string)$job['date_to'];
        $part = BankSyncService::syncConnection($connectionId, $dateFrom, $dateTo, $linkId, false);
        $stats = is_array($job['stats'] ?? null) ? $job['stats'] : [
            'created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => [],
        ];
        $stats['created'] += (int)$part['created'];
        $stats['linked'] += (int)$part['linked'];
        $stats['skipped'] += (int)$part['skipped'];
        $stats['errors'] += (int)$part['errors'];
        foreach ($part['messages'] as $m) {
            $stats['messages'][] = $m;
            LongJob::appendLog($job, (string)$m);
        }
        $job['stats'] = $stats;
        $job['index'] = $index + 1;
        $frac = $total > 0 ? min(1.0, (float)$job['index'] / (float)$total) : 1.0;
        $job['progress'] = (int)floor($frac * 100);
        $job['progress_overall'] = $job['progress'];
        $job['message'] = t('banks.conn.sync') . ' ' . $job['index'] . '/' . $total;
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
        $stats = is_array($job['stats'] ?? null) ? $job['stats'] : [
            'created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => [],
        ];
        $connectionId = (int)($job['connection_id'] ?? 0);
        if ($connectionId > 0) {
            BankSyncService::rememberLastSync(
                $connectionId,
                (string)$job['date_from'],
                (string)$job['date_to'],
                $stats
            );
        } else {
            // Ledger multi-connection: stamp each distinct connection once.
            $seen = [];
            foreach ((array)($job['queue'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $cid = (int)($item['connection_id'] ?? 0);
                if ($cid < 1 || isset($seen[$cid])) {
                    continue;
                }
                $seen[$cid] = true;
                BankSyncService::rememberLastSync(
                    $cid,
                    (string)$job['date_from'],
                    (string)$job['date_to'],
                    $stats
                );
            }
        }
        $job['status'] = LongJob::STATUS_DONE;
        $job['progress'] = 100;
        $job['progress_overall'] = 100;
        $job['message'] = t('banks.conn.synced', [
            'created' => $stats['created'],
            'linked' => $stats['linked'],
            'skipped' => $stats['skipped'],
            'errors' => $stats['errors'],
        ]);
        $job['result'] = $stats;
        LongJob::appendLog($job, $job['message']);
        unset($job['queue'], $job['stats']);
        return $job;
    }
}
