<?php
declare(strict_types=1);

final class BankTxMapRepository
{
    public static function ready(): bool
    {
        return Database::tableExists('base_bank_tx_map');
    }

    public static function moveIdFor(string $provider, string $externalTxId): int
    {
        if ($externalTxId === '' || !self::ready()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_MOVE FROM base_bank_tx_map
             WHERE PROVIDER = ? AND EXTERNAL_TX_ID = ? LIMIT 1'
        );
        $st->execute([$provider, $externalTxId]);
        return (int)$st->fetchColumn();
    }

    public static function remember(
        string $provider,
        string $externalTxId,
        int $moveId,
        int $linkId,
        int $connectionId
    ): void {
        if ($externalTxId === '' || $moveId < 1 || !self::ready()) {
            return;
        }
        Database::pdo()->prepare(
            'INSERT INTO base_bank_tx_map
             (PROVIDER, EXTERNAL_TX_ID, ID_MOVE, ID_LINK, ID_CONNECTION, DT_CREATE)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ID_MOVE = VALUES(ID_MOVE)'
        )->execute([$provider, $externalTxId, $moveId, $linkId, $connectionId, time()]);
    }

    /**
     * @param list<int> $moveIds
     * @return array<int,array{provider:string,link_id:int}>
     */
    public static function byMoveIds(array $moveIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $moveIds))));
        if ($ids === [] || !self::ready()) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID_MOVE AS move_id, PROVIDER AS provider, ID_LINK AS link_id
             FROM base_bank_tx_map WHERE ID_MOVE IN ($in)"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['move_id']] = [
                'provider' => (string)$r['provider'],
                'link_id' => (int)$r['link_id'],
            ];
        }
        return $out;
    }
}
