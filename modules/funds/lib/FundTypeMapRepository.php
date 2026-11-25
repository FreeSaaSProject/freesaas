<?php
declare(strict_types=1);

/**
 * Ledger move type → fund map: an expense DPS of a mapped type is posted to the fund as CAPUSE automatically
 * (see FundsLedgerHooks::onSaved).
 */
final class FundTypeMapRepository
{
    /** @var array<int,int>|null type id => fund id (enabled rows) */
    private static $cache = null;

    public static function tableReady(): bool
    {
        return Database::tableExists('base_fund_type_map');
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** Fund id for a ledger type, 0 when not mapped or disabled. */
    public static function fundForType(int $typeId): int
    {
        if ($typeId < 1 || !self::tableReady()) {
            return 0;
        }
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Database::pdo()->query('SELECT ID_TYPE, ID_FUND FROM base_fund_type_map WHERE ENABLED > 0')
                ->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                self::$cache[(int)$r['ID_TYPE']] = (int)$r['ID_FUND'];
            }
        }
        return self::$cache[$typeId] ?? 0;
    }

    /** @return list<array<string,mixed>> */
    public static function list(): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $typeNames = class_exists('AccountMoveTypesRepository') ? AccountMoveTypesRepository::names() : [];
        $funds = FundsRepository::allRows();
        $out = [];
        foreach (Database::pdo()->query('SELECT ID, ID_TYPE, ID_FUND, ENABLED, DT_UPDATE FROM base_fund_type_map ORDER BY ID_FUND ASC, ID_TYPE ASC')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $fundId = (int)$r['ID_FUND'];
            $typeId = (int)$r['ID_TYPE'];
            $out[] = [
                'id' => (int)$r['ID'],
                'type_id' => $typeId,
                'type_name' => (string)($typeNames[$typeId] ?? ('#' . $typeId)),
                'fund_id' => $fundId,
                'fund_name' => (string)($funds[$fundId]['name'] ?? ('#' . $fundId)),
                'enabled' => (int)$r['ENABLED'] > 0,
                'dt_update' => (int)$r['DT_UPDATE'],
            ];
        }
        return $out;
    }

    /**
     * Upsert by ledger type (one fund per type).
     *
     * @return array{id:int,created:bool}
     */
    public static function save(int $typeId, int $fundId, bool $enabled = true): array
    {
        if ($typeId < 1) {
            throw new InvalidArgumentException(t('funds.typemap.err.type'));
        }
        if (class_exists('AccountMoveTypesRepository') && AccountMoveTypesRepository::find($typeId) === null) {
            throw new InvalidArgumentException(t('funds.typemap.err.type'));
        }
        $fund = FundsRepository::allRows()[$fundId] ?? null;
        if ($fund === null) {
            throw new InvalidArgumentException(t('funds.rules.err.fund'));
        }
        if (FundsRepository::isGroup($fundId)) {
            throw new InvalidArgumentException(t('funds.moves.err.fund_group'));
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT ID FROM base_fund_type_map WHERE ID_TYPE = ? LIMIT 1');
        $st->execute([$typeId]);
        $existing = (int)$st->fetchColumn();
        $now = time();
        if ($existing > 0) {
            $pdo->prepare('UPDATE base_fund_type_map SET ID_FUND = ?, ENABLED = ?, DT_UPDATE = ? WHERE ID = ?')
                ->execute([$fundId, $enabled ? 1 : 0, $now, $existing]);
            self::flush();
            return ['id' => $existing, 'created' => false];
        }
        $pdo->prepare('INSERT INTO base_fund_type_map (ID_TYPE, ID_FUND, ENABLED, DT_UPDATE) VALUES (?, ?, ?, ?)')
            ->execute([$typeId, $fundId, $enabled ? 1 : 0, $now]);
        self::flush();
        return ['id' => (int)$pdo->lastInsertId(), 'created' => true];
    }

    /** Delete by row id or by ledger type id (type_id wins when both given). */
    public static function delete(int $id, int $typeId = 0): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        if ($typeId > 0) {
            $st = Database::pdo()->prepare('DELETE FROM base_fund_type_map WHERE ID_TYPE = ?');
            $st->execute([$typeId]);
        } elseif ($id > 0) {
            $st = Database::pdo()->prepare('DELETE FROM base_fund_type_map WHERE ID = ?');
            $st->execute([$id]);
        } else {
            return 0;
        }
        self::flush();
        return $st->rowCount();
    }
}
