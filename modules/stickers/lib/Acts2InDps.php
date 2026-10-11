<?php
declare(strict_types=1);

/**
 * Ledger ДПС linked to receipt acts (oper show_acts2_in payment column + bulk create).
 */
final class Acts2InDps
{
    public static function ledgerReady(): bool
    {
        return ModuleManager::isEnabled('ledger')
            && class_exists('LedgerMovesRepository', false)
            && class_exists('AccountMoveTypesRepository', false)
            && Database::tableExists('base_account_moves')
            && Database::tableExists('base_account_move_types');
    }

    /**
     * Move type options for bulk create (types with act_in flag).
     *
     * @return array<int,string>
     */
    public static function typeOptions(): array
    {
        if (!self::ledgerReady()) {
            return [];
        }
        $out = [];
        foreach (AccountMoveTypesRepository::leafMeta() as $id => $meta) {
            if ((int)($meta['act_in_enabled'] ?? 0) < 1) {
                continue;
            }
            if ((int)($meta['account_id'] ?? 0) < 1) {
                continue;
            }
            $out[(int)$id] = (string)$meta['name'];
        }
        return $out;
    }

    /**
     * @param list<int> $actIds
     * @return array<int, list<array{id:int,summ:float,type_id:int,account_id:int}>>
     */
    public static function movesByActIds(array $actIds): array
    {
        $clean = [];
        foreach ($actIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        $clean = array_values($clean);
        $out = [];
        foreach ($clean as $id) {
            $out[$id] = [];
        }
        if ($clean === [] || !self::ledgerReady()) {
            return $out;
        }
        $ph = implode(',', array_fill(0, count($clean), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, ID_ACT2_IN AS act2_in, SUMM AS summ,
                    ID_TYPE AS type_id, ID_ACCOUNT AS account_id
             FROM base_account_moves
             WHERE DELETED = 0 AND ID_ACT2_IN IN ($ph)
             ORDER BY ID ASC"
        );
        $st->execute($clean);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $aid = (int)$row['act2_in'];
            if (!isset($out[$aid])) {
                $out[$aid] = [];
            }
            $out[$aid][] = [
                'id' => (int)$row['id'],
                'summ' => (float)$row['summ'],
                'type_id' => (int)$row['type_id'],
                'account_id' => (int)$row['account_id'],
            ];
        }
        return $out;
    }

    /**
     * Create one ДПС per act (skip if move already linked or sum is 0).
     *
     * @param list<int> $actIds
     * @return array{done:int,skipped:list<array{id:int,reason:string}>,created:list<array{act_id:int,move_id:int}>}
     */
    public static function createForActs(array $actIds, int $typeId, int $userId): array
    {
        $result = ['done' => 0, 'skipped' => [], 'created' => []];
        if (!self::ledgerReady()) {
            foreach ($actIds as $id) {
                $result['skipped'][] = ['id' => (int)$id, 'reason' => t('stickers.dps.err.ledger_off')];
            }
            return $result;
        }
        if (!Auth::can('ledger.edit')) {
            foreach ($actIds as $id) {
                $result['skipped'][] = ['id' => (int)$id, 'reason' => t('stickers.dps.err.no_perm')];
            }
            return $result;
        }
        $type = AccountMoveTypesRepository::find($typeId);
        if ($type === null || (int)($type['act_in_enabled'] ?? 0) < 1) {
            foreach ($actIds as $id) {
                $result['skipped'][] = ['id' => (int)$id, 'reason' => t('stickers.dps.err.bad_type')];
            }
            return $result;
        }
        $accountId = (int)($type['account_id'] ?? 0);
        if ($accountId < 1) {
            foreach ($actIds as $id) {
                $result['skipped'][] = ['id' => (int)$id, 'reason' => t('stickers.dps.err.no_account')];
            }
            return $result;
        }
        $direction = (int)($type['direction'] ?? -1);
        if ($direction === 0) {
            $direction = -1;
        }
        $typeName = (string)($type['name'] ?? ('#' . $typeId));
        $existing = self::movesByActIds($actIds);

        foreach ($actIds as $rawId) {
            $actId = (int)$rawId;
            if ($actId < 1) {
                continue;
            }
            if (!empty($existing[$actId])) {
                $result['skipped'][] = ['id' => $actId, 'reason' => t('stickers.dps.err.already')];
                continue;
            }
            $act = Acts2InRepository::find($actId);
            if ($act === null) {
                $result['skipped'][] = ['id' => $actId, 'reason' => t('stickers.err.act_not_found', ['id' => $actId])];
                continue;
            }
            Acts2InRepository::recalcSumm($actId);
            $act = Acts2InRepository::find($actId) ?: $act;
            $summ = round((float)$act['summ'], 2);
            if ($summ <= 0) {
                $result['skipped'][] = ['id' => $actId, 'reason' => t('stickers.dps.err.zero_sum')];
                continue;
            }
            $dt = (int)$act['dt_create'];
            if ($dt < 1) {
                $dt = time();
            }
            $descr = t('stickers.dps.auto_descr', [
                'summ' => $summ,
                'type' => $typeName,
                'act' => $actId,
            ]);
            try {
                $moveId = LedgerMovesRepository::create([
                    'account_id' => $accountId,
                    'type_id' => $typeId,
                    'direction' => $direction,
                    'summ' => $summ,
                    'descr' => $descr,
                    'dt' => $dt,
                    'dt_create' => time(),
                    'user_create' => $userId > 0 ? $userId : Auth::userId(),
                    'unconfirmed' => 0,
                    'payment_id' => 0,
                    'supplier_id' => (int)$act['id_supplier'],
                    'user_payment' => 0,
                    'fund_movement_id' => 0,
                    'act2_in' => $actId,
                    'act2_not_req' => 0,
                    'dt_promised' => 0,
                    'promise_executed' => 0,
                    'linked_id' => 0,
                    'account_to' => 0,
                    'deleted' => 0,
                ]);
                $result['done']++;
                $result['created'][] = ['act_id' => $actId, 'move_id' => $moveId];
            } catch (Throwable $e) {
                $result['skipped'][] = ['id' => $actId, 'reason' => $e->getMessage()];
            }
        }
        return $result;
    }
}
