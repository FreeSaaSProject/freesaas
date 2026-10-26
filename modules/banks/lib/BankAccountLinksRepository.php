<?php
declare(strict_types=1);

final class BankAccountLinksRepository
{
    public static function ready(): bool
    {
        return Database::tableExists('base_bank_account_links');
    }

    /** @return list<array<string,mixed>> */
    public static function forConnection(int $connectionId): array
    {
        if ($connectionId < 1 || !self::ready()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_CONNECTION AS connection_id,
                    EXTERNAL_ACCOUNT_ID AS external_account_id,
                    ACCOUNT_NUMBER AS account_number, CURRENCY AS currency,
                    REMOTE_NAME AS remote_name, ID_ACCOUNT AS account_id,
                    SYNC_ENABLED AS sync_enabled, LAST_SYNC_AT AS last_sync_at,
                    CURSOR_VAL AS cursor_val, META AS meta,
                    DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_bank_account_links
             WHERE ID_CONNECTION = ?
             ORDER BY ACCOUNT_NUMBER ASC, ID ASC'
        );
        $st->execute([$connectionId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::ready()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_CONNECTION AS connection_id,
                    EXTERNAL_ACCOUNT_ID AS external_account_id,
                    ACCOUNT_NUMBER AS account_number, CURRENCY AS currency,
                    REMOTE_NAME AS remote_name, ID_ACCOUNT AS account_id,
                    SYNC_ENABLED AS sync_enabled, LAST_SYNC_AT AS last_sync_at,
                    CURSOR_VAL AS cursor_val, META AS meta
             FROM base_bank_account_links WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<int,array{provider:string,label:string}> account_id => badge info */
    public static function linkedAccountProviders(array $accountIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if ($ids === [] || !self::ready() || !Database::tableExists('base_bank_connections')) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT l.ID_ACCOUNT AS account_id, c.PROVIDER AS provider
             FROM base_bank_account_links l
             INNER JOIN base_bank_connections c ON c.ID = l.ID_CONNECTION
             WHERE l.ID_ACCOUNT IN ($in) AND l.ID_ACCOUNT > 0
               AND c.STATUS = 'active'"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $aid = (int)$r['account_id'];
            if (!isset($out[$aid])) {
                $out[$aid] = [
                    'provider' => (string)$r['provider'],
                    'label' => BanksConfig::providerLabel((string)$r['provider']),
                ];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function syncEnabledLinks(?int $connectionId = null): array
    {
        if (!self::ready()) {
            return [];
        }
        $sql = 'SELECT ID AS id, ID_CONNECTION AS connection_id,
                       EXTERNAL_ACCOUNT_ID AS external_account_id,
                       ACCOUNT_NUMBER AS account_number, CURRENCY AS currency,
                       REMOTE_NAME AS remote_name, ID_ACCOUNT AS account_id,
                       SYNC_ENABLED AS sync_enabled, LAST_SYNC_AT AS last_sync_at,
                       CURSOR_VAL AS cursor_val
                FROM base_bank_account_links
                WHERE SYNC_ENABLED = 1 AND ID_ACCOUNT > 0';
        $params = [];
        if ($connectionId !== null && $connectionId > 0) {
            $sql .= ' AND ID_CONNECTION = ?';
            $params[] = $connectionId;
        }
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Sync-enabled links for a ledger account on active connections.
     *
     * @return list<array<string,mixed>>
     */
    public static function forLedgerAccount(int $accountId): array
    {
        if ($accountId < 1 || !self::ready() || !Database::tableExists('base_bank_connections')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT l.ID AS id, l.ID_CONNECTION AS connection_id,
                    l.EXTERNAL_ACCOUNT_ID AS external_account_id,
                    l.ACCOUNT_NUMBER AS account_number, l.CURRENCY AS currency,
                    l.REMOTE_NAME AS remote_name, l.ID_ACCOUNT AS account_id,
                    l.SYNC_ENABLED AS sync_enabled, l.LAST_SYNC_AT AS last_sync_at,
                    l.CURSOR_VAL AS cursor_val, c.PROVIDER AS provider
             FROM base_bank_account_links l
             INNER JOIN base_bank_connections c ON c.ID = l.ID_CONNECTION
             WHERE l.ID_ACCOUNT = ? AND l.SYNC_ENABLED = 1 AND c.STATUS = ?
             ORDER BY l.ID ASC'
        );
        $st->execute([$accountId, 'active']);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Upsert remote account row; keep existing ledger link.
     * @param array{external_account_id:string,account_number?:string,currency?:string,remote_name?:string,meta?:mixed} $remote
     */
    public static function upsertRemote(int $connectionId, array $remote): int
    {
        $ext = trim((string)$remote['external_account_id']);
        if ($connectionId < 1 || $ext === '') {
            throw new InvalidArgumentException(t('banks.err.link'));
        }
        $existing = self::findByExternal($connectionId, $ext);
        $now = time();
        $meta = isset($remote['meta'])
            ? (is_string($remote['meta']) ? $remote['meta'] : json_encode($remote['meta'], JSON_UNESCAPED_UNICODE))
            : null;
        if ($existing) {
            Database::pdo()->prepare(
                'UPDATE base_bank_account_links SET
                 ACCOUNT_NUMBER = ?, CURRENCY = ?, REMOTE_NAME = ?, META = ?, DT_UPDATE = ?
                 WHERE ID = ?'
            )->execute([
                (string)($remote['account_number'] ?? $existing['account_number']),
                (string)($remote['currency'] ?? $existing['currency'] ?? 'RUB'),
                (string)($remote['remote_name'] ?? $existing['remote_name']),
                $meta ?? $existing['meta'],
                $now,
                (int)$existing['id'],
            ]);
            return (int)$existing['id'];
        }
        // Sandbox integrations: sync off by default (unlock scopes only; no DPS pollution).
        $syncDefault = 1;
        $conn = BankConnectionsRepository::find($connectionId, true);
        if ($conn) {
            $iid = (int)($conn['integration_id'] ?? 0);
            if ($iid > 0) {
                $int = BanksIntegrationRepository::find($iid, true);
                if ($int && BanksIntegrationRepository::isSandbox($int)) {
                    $syncDefault = 0;
                }
            } elseif (stripos((string)($conn['consent_id'] ?? ''), 'sandbox') !== false) {
                $syncDefault = 0;
            }
        }
        Database::pdo()->prepare(
            'INSERT INTO base_bank_account_links
             (ID_CONNECTION, EXTERNAL_ACCOUNT_ID, ACCOUNT_NUMBER, CURRENCY, REMOTE_NAME,
              ID_ACCOUNT, SYNC_ENABLED, META, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)'
        )->execute([
            $connectionId,
            $ext,
            (string)($remote['account_number'] ?? ''),
            (string)($remote['currency'] ?? 'RUB'),
            (string)($remote['remote_name'] ?? ''),
            $syncDefault,
            $meta,
            $now,
            $now,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function linkToLedger(int $linkId, int $accountId): void
    {
        if ($linkId < 1 || $accountId < 1) {
            throw new InvalidArgumentException(t('banks.err.link'));
        }
        Database::pdo()->prepare(
            'UPDATE base_bank_account_links SET ID_ACCOUNT = ?, DT_UPDATE = ? WHERE ID = ?'
        )->execute([$accountId, time(), $linkId]);
    }

    public static function unlink(int $linkId): void
    {
        if ($linkId < 1) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE base_bank_account_links SET ID_ACCOUNT = 0, DT_UPDATE = ? WHERE ID = ?'
        )->execute([time(), $linkId]);
    }

    /** @return array{remote:int,linked:int,syncing:int} */
    public static function countsForConnection(int $connectionId): array
    {
        $out = ['remote' => 0, 'linked' => 0, 'syncing' => 0];
        if ($connectionId < 1 || !self::ready()) {
            return $out;
        }
        $st = Database::pdo()->prepare(
            'SELECT
                COUNT(*) AS remote,
                SUM(CASE WHEN ID_ACCOUNT > 0 THEN 1 ELSE 0 END) AS linked,
                SUM(CASE WHEN ID_ACCOUNT > 0 AND SYNC_ENABLED = 1 THEN 1 ELSE 0 END) AS syncing
             FROM base_bank_account_links WHERE ID_CONNECTION = ?'
        );
        $st->execute([$connectionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'remote' => (int)($row['remote'] ?? 0),
            'linked' => (int)($row['linked'] ?? 0),
            'syncing' => (int)($row['syncing'] ?? 0),
        ];
    }

    public static function markSynced(int $linkId, string $cursor = ''): void
    {
        Database::pdo()->prepare(
            'UPDATE base_bank_account_links SET LAST_SYNC_AT = ?, CURSOR_VAL = ?, DT_UPDATE = ? WHERE ID = ?'
        )->execute([time(), $cursor, time(), $linkId]);
    }

    /** @return array<string,mixed>|null */
    public static function findByExternal(int $connectionId, string $externalId): ?array
    {
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_CONNECTION AS connection_id,
                    EXTERNAL_ACCOUNT_ID AS external_account_id,
                    ACCOUNT_NUMBER AS account_number, CURRENCY AS currency,
                    REMOTE_NAME AS remote_name, ID_ACCOUNT AS account_id,
                    SYNC_ENABLED AS sync_enabled, LAST_SYNC_AT AS last_sync_at,
                    CURSOR_VAL AS cursor_val, META AS meta
             FROM base_bank_account_links
             WHERE ID_CONNECTION = ? AND EXTERNAL_ACCOUNT_ID = ?
             LIMIT 1'
        );
        $st->execute([$connectionId, $externalId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
