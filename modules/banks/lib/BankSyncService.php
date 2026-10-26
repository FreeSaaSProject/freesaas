<?php
declare(strict_types=1);

/** Pull bank transactions and land them as ledger moves. */
final class BankSyncService
{
    /**
     * @return array{created:int,linked:int,skipped:int,errors:int,messages:list<string>}
     */
    public static function syncConnection(
        int $connectionId,
        string $dateFrom,
        string $dateTo,
        ?int $onlyLinkId = null
    ): array {
        $conn = BankConnectionsRepository::find($connectionId, true);
        if (!$conn) {
            throw new RuntimeException(t('banks.err.not_found'));
        }
        $provider = (string)$conn['provider'];
        $links = BankAccountLinksRepository::forConnection($connectionId);
        $stats = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
        $syncable = 0;
        $unlinked = 0;
        $disabled = 0;
        foreach ($links as $link) {
            if ($onlyLinkId !== null && (int)$link['id'] !== $onlyLinkId) {
                continue;
            }
            if ((int)$link['account_id'] < 1) {
                $unlinked++;
                continue;
            }
            if (!(int)$link['sync_enabled']) {
                $disabled++;
                continue;
            }
            $syncable++;
            try {
                $part = self::syncLink($provider, $connectionId, $link, $dateFrom, $dateTo);
                $stats['created'] += $part['created'];
                $stats['linked'] += $part['linked'];
                $stats['skipped'] += $part['skipped'];
                $stats['errors'] += $part['errors'];
                foreach ($part['messages'] as $m) {
                    $stats['messages'][] = $m;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $stats['messages'][] = '#' . (int)$link['id'] . ': ' . $e->getMessage();
            }
        }
        if ($syncable === 0) {
            if ($links === []) {
                $stats['messages'][] = t('banks.sync.no_remote_accounts');
            } elseif ($unlinked > 0) {
                $stats['messages'][] = t('banks.sync.no_ledger_link', ['n' => $unlinked]);
            } elseif ($disabled > 0) {
                $stats['messages'][] = t('banks.sync.sync_disabled', ['n' => $disabled]);
            } else {
                $stats['messages'][] = t('banks.sync.nothing_to_sync');
            }
        }
        self::rememberLastSync($connectionId, $dateFrom, $dateTo, $stats);
        return $stats;
    }

    /**
     * @param array{created:int,linked:int,skipped:int,errors:int,messages:list<string>} $stats
     */
    private static function rememberLastSync(
        int $connectionId,
        string $dateFrom,
        string $dateTo,
        array $stats
    ): void {
        $conn = BankConnectionsRepository::find($connectionId, true);
        if (!$conn) {
            return;
        }
        $meta = [];
        $raw = $conn['meta'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        } elseif (is_array($raw)) {
            $meta = $raw;
        }
        $meta['last_sync'] = [
            'at' => time(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'created' => (int)$stats['created'],
            'linked' => (int)$stats['linked'],
            'skipped' => (int)$stats['skipped'],
            'errors' => (int)$stats['errors'],
            'messages' => array_values(array_slice($stats['messages'], 0, 30)),
        ];
        BankConnectionsRepository::update($connectionId, ['meta' => $meta]);
    }

    /**
     * @return array{created:int,linked:int,skipped:int,errors:int,messages:list<string>}
     */
    public static function syncAllEnabled(string $dateFrom, string $dateTo): array
    {
        $stats = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
        $byConn = [];
        foreach (BankAccountLinksRepository::syncEnabledLinks() as $link) {
            $cid = (int)$link['connection_id'];
            $byConn[$cid] = true;
        }
        foreach (array_keys($byConn) as $cid) {
            $part = self::syncConnection((int)$cid, $dateFrom, $dateTo, null);
            $stats['created'] += $part['created'];
            $stats['linked'] += $part['linked'];
            $stats['skipped'] += $part['skipped'];
            $stats['errors'] += $part['errors'];
            foreach ($part['messages'] as $m) {
                $stats['messages'][] = $m;
            }
        }
        return $stats;
    }

    /**
     * Sync all sync-enabled bank links mapped to one ledger account.
     *
     * @return array{created:int,linked:int,skipped:int,errors:int,messages:list<string>}
     */
    public static function syncLedgerAccount(int $accountId, string $dateFrom, string $dateTo): array
    {
        $stats = ['created' => 0, 'linked' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
        $links = BankAccountLinksRepository::forLedgerAccount($accountId);
        if ($links === []) {
            $stats['messages'][] = t('banks.sync.nothing_to_sync');
            return $stats;
        }
        foreach ($links as $link) {
            $part = self::syncConnection(
                (int)$link['connection_id'],
                $dateFrom,
                $dateTo,
                (int)$link['id']
            );
            $stats['created'] += $part['created'];
            $stats['linked'] += $part['linked'];
            $stats['skipped'] += $part['skipped'];
            $stats['errors'] += $part['errors'];
            foreach ($part['messages'] as $m) {
                $stats['messages'][] = $m;
            }
        }
        return $stats;
    }

    /**
     * @param array<string,mixed> $link
     * @return array{created:int,linked:int,skipped:int,errors:int,messages:list<string>}
     */
    private static function syncLink(
        string $provider,
        int $connectionId,
        array $link,
        string $dateFrom,
        string $dateTo
    ): array {
        $created = 0;
        $linked = 0;
        $skipped = 0;
        $errors = 0;
        $messages = [];
        $txs = BankOAuth::fetchTransactions(
            $connectionId,
            (string)$link['external_account_id'],
            $dateFrom,
            $dateTo
        );
        $accountId = (int)$link['account_id'];
        $linkId = (int)$link['id'];
        $accLabel = (string)($link['account_number'] !== '' ? $link['account_number'] : $link['external_account_id']);
        $messages[] = t('banks.sync.fetched', [
            'account' => $accLabel,
            'n' => count($txs),
            'from' => $dateFrom,
            'to' => $dateTo,
        ]);
        $userId = Auth::userId() ?: 0;
        $now = time();
        $providerLabel = BanksConfig::providerLabel($provider);
        foreach ($txs as $tx) {
            $extId = (string)$tx['external_id'];
            if (BankTxMapRepository::moveIdFor($provider, $extId) > 0) {
                $skipped++;
                continue;
            }
            try {
                $direction = (int)$tx['direction'] >= 0 ? 1 : -1;
                $amount = (float)$tx['amount'];
                $bankDt = (int)$tx['dt'] > 0 ? (int)$tx['dt'] : $now;
                $candidates = BankTxMapRepository::findMatchCandidates(
                    $accountId,
                    $amount,
                    $direction,
                    $bankDt,
                    5
                );
                if (count($candidates) === 1) {
                    $moveId = (int)$candidates[0]['id'];
                    BankTxMapRepository::remember($provider, $extId, $moveId, $linkId, $connectionId);
                    $note = t('banks.sync.linked_note', [
                        'provider' => $providerLabel,
                        'ext_id' => $extId,
                        'date' => date('d.m.Y', $bankDt),
                    ]);
                    LedgerMovesRepository::appendDescr($moveId, $note);
                    $linked++;
                    $messages[] = t('banks.sync.linked_log', [
                        'ext_id' => $extId,
                        'move_id' => $moveId,
                    ]);
                    continue;
                }
                if (count($candidates) > 1) {
                    $messages[] = t('banks.sync.ambiguous_log', [
                        'ext_id' => $extId,
                        'n' => count($candidates),
                    ]);
                }

                $typeId = BankClassifyService::resolve($tx);
                if ($typeId < 1) {
                    throw new RuntimeException(t('banks.err.no_default_type'));
                }
                $descr = self::buildDescr($tx);
                $moveId = LedgerMovesRepository::create([
                    'account_id' => $accountId,
                    'type_id' => $typeId,
                    'direction' => $direction,
                    'summ' => $amount,
                    'descr' => $descr,
                    'dt' => $bankDt,
                    'dt_create' => $now,
                    'user_create' => $userId,
                    'unconfirmed' => 0,
                    'payment_id' => 0,
                    'supplier_id' => 0,
                    'user_payment' => 0,
                    'fund_movement_id' => 0,
                    'linked_id' => 0,
                    'account_to' => 0,
                ]);
                BankTxMapRepository::remember($provider, $extId, $moveId, $linkId, $connectionId);
                $created++;
            } catch (Throwable $e) {
                $errors++;
                $messages[] = $extId . ': ' . $e->getMessage();
            }
        }
        BankAccountLinksRepository::markSynced($linkId, $dateTo);
        return compact('created', 'linked', 'skipped', 'errors', 'messages');
    }

    /** @param array{purpose?:string,counterparty_name?:string,counterparty_inn?:string} $tx */
    private static function buildDescr(array $tx): string
    {
        $parts = [];
        $cp = trim((string)($tx['counterparty_name'] ?? ''));
        $inn = trim((string)($tx['counterparty_inn'] ?? ''));
        if ($cp !== '') {
            $parts[] = $cp . ($inn !== '' ? ' (ИНН ' . $inn . ')' : '');
        }
        $purpose = trim((string)($tx['purpose'] ?? ''));
        if ($purpose !== '') {
            $parts[] = $purpose;
        }
        return $parts !== [] ? implode(' — ', $parts) : 'bank';
    }
}
