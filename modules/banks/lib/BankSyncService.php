<?php
declare(strict_types=1);

/** Pull bank transactions and land them as ledger moves. */
final class BankSyncService
{
    /**
     * @return array{created:int,skipped:int,errors:int,messages:list<string>}
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
        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
        foreach ($links as $link) {
            if ($onlyLinkId !== null && (int)$link['id'] !== $onlyLinkId) {
                continue;
            }
            if ((int)$link['account_id'] < 1 || !(int)$link['sync_enabled']) {
                continue;
            }
            try {
                $part = self::syncLink($provider, $connectionId, $link, $dateFrom, $dateTo);
                $stats['created'] += $part['created'];
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
        return $stats;
    }

    /**
     * @return array{created:int,skipped:int,errors:int,messages:list<string>}
     */
    public static function syncAllEnabled(string $dateFrom, string $dateTo): array
    {
        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => []];
        $byConn = [];
        foreach (BankAccountLinksRepository::syncEnabledLinks() as $link) {
            $cid = (int)$link['connection_id'];
            $byConn[$cid] = true;
        }
        foreach (array_keys($byConn) as $cid) {
            $part = self::syncConnection((int)$cid, $dateFrom, $dateTo, null);
            $stats['created'] += $part['created'];
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
     * @return array{created:int,skipped:int,errors:int,messages:list<string>}
     */
    private static function syncLink(
        string $provider,
        int $connectionId,
        array $link,
        string $dateFrom,
        string $dateTo
    ): array {
        $created = 0;
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
        $userId = Auth::userId() ?: 0;
        $now = time();
        foreach ($txs as $tx) {
            $extId = (string)$tx['external_id'];
            if (BankTxMapRepository::moveIdFor($provider, $extId) > 0) {
                $skipped++;
                continue;
            }
            try {
                $typeId = BankClassifyService::resolve($tx);
                if ($typeId < 1) {
                    throw new RuntimeException(t('banks.err.no_default_type'));
                }
                $descr = self::buildDescr($tx);
                $moveId = LedgerMovesRepository::create([
                    'account_id' => $accountId,
                    'type_id' => $typeId,
                    'direction' => (int)$tx['direction'] >= 0 ? 1 : -1,
                    'summ' => (float)$tx['amount'],
                    'descr' => $descr,
                    'dt' => (int)$tx['dt'],
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
        return compact('created', 'skipped', 'errors', 'messages');
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
