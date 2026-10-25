<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class RemittanceAgreementsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_remittance_agreements');
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'a.ID',
            'name' => 'a.NAME',
            'supplier' => 's.NAME',
        ];
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int,enabled?:int|null} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.NAME LIKE ? OR CAST(a.ID AS CHAR) = ? OR s.NAME LIKE ? OR s.INN LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if (array_key_exists('enabled', $opts) && $opts['enabled'] !== null && $opts['enabled'] !== '') {
            $where[] = 'a.ENABLED = ?';
            $params[] = (int)$opts['enabled'] > 0 ? 1 : 0;
        }
        $sqlWhere = implode(' AND ', $where);
        $join = 'LEFT JOIN base_suppliers s ON s.ID = a.ID_SUPPLIER';
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_remittance_agreements a $join WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'a.NAME ASC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT a.ID AS id, a.NAME AS name, a.ENABLED AS enabled,
                       a.ID_SUPPLIER AS supplier_id, a.ID_ACCOUNT AS account_id, a.ID_TYPE AS type_id,
                       a.FEE_PERCENT AS fee_percent, a.FEE_POSTING AS fee_posting,
                       a.FEE_MOVE_TYPE_ID AS fee_move_type_id, a.FEE_ACCOUNT_ID AS fee_account_id,
                       a.PARSER_PRESET AS parser_preset,
                       a.PATTERN_PERIOD_FROM AS pattern_period_from,
                       a.PATTERN_PERIOD_TO AS pattern_period_to,
                       a.PATTERN_GROSS AS pattern_gross, a.PATTERN_FEE AS pattern_fee,
                       a.EXPECTED_CHANNELS AS expected_channels,
                       a.AUTO_SET_SUPPLIER AS auto_set_supplier,
                       s.NAME AS supplier_name, s.INN AS supplier_inn, s.UR_NAME AS supplier_ur_name
                FROM base_remittance_agreements a
                $join
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return ['items' => array_map([self::class, 'cast'], $items), 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public static function listEnabled(): array
    {
        $res = self::list(['enabled' => 1, 'limit' => 0, 'order' => 'a.ID ASC']);
        return $res['items'];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT a.ID AS id, a.NAME AS name, a.ENABLED AS enabled,
                    a.ID_SUPPLIER AS supplier_id, a.ID_ACCOUNT AS account_id, a.ID_TYPE AS type_id,
                    a.FEE_PERCENT AS fee_percent, a.FEE_POSTING AS fee_posting,
                    a.FEE_MOVE_TYPE_ID AS fee_move_type_id, a.FEE_ACCOUNT_ID AS fee_account_id,
                    a.PARSER_PRESET AS parser_preset,
                    a.PATTERN_PERIOD_FROM AS pattern_period_from,
                    a.PATTERN_PERIOD_TO AS pattern_period_to,
                    a.PATTERN_GROSS AS pattern_gross, a.PATTERN_FEE AS pattern_fee,
                    a.EXPECTED_CHANNELS AS expected_channels,
                    a.AUTO_SET_SUPPLIER AS auto_set_supplier,
                    s.NAME AS supplier_name, s.INN AS supplier_inn, s.UR_NAME AS supplier_ur_name
             FROM base_remittance_agreements a
             LEFT JOIN base_suppliers s ON s.ID = a.ID_SUPPLIER
             WHERE a.ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::cast($row) : null;
    }

    /** @param array<string,mixed> $data */
    public static function save(?int $id, array $data): int
    {
        $pdo = Database::pdo();
        $now = time();
        $name = mb_substr(trim((string)($data['name'] ?? '')), 0, 255);
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $supplierId = max(0, (int)($data['supplier_id'] ?? 0));
        $accountId = max(0, (int)($data['account_id'] ?? 0));
        $typeId = max(0, (int)($data['type_id'] ?? 0));
        $feePercent = round((float)($data['fee_percent'] ?? 2), 4);
        $feePosting = (string)($data['fee_posting'] ?? 'none');
        if (!in_array($feePosting, ['none', 'expense_move'], true)) {
            $feePosting = 'none';
        }
        $feeMoveTypeId = max(0, (int)($data['fee_move_type_id'] ?? 0));
        $feeAccountId = max(0, (int)($data['fee_account_id'] ?? 0));
        $preset = mb_substr(trim((string)($data['parser_preset'] ?? '')), 0, 64);
        $from = mb_substr(trim((string)($data['pattern_period_from'] ?? '')), 0, 512);
        $to = mb_substr(trim((string)($data['pattern_period_to'] ?? '')), 0, 512);
        $gross = mb_substr(trim((string)($data['pattern_gross'] ?? '')), 0, 512);
        $fee = mb_substr(trim((string)($data['pattern_fee'] ?? '')), 0, 512);
        $channels = mb_substr(trim((string)($data['expected_channels'] ?? 'spa_ops')), 0, 255);
        if ($channels === '') {
            $channels = 'spa_ops';
        }
        $autoSet = !empty($data['auto_set_supplier']) ? 1 : 0;

        if ($preset !== '' && ($from === '' || $to === '' || $gross === '' || $fee === '')) {
            $slots = RemittanceParser::slotsForPreset($preset);
            if ($from === '') {
                $from = $slots['period_from'];
            }
            if ($to === '') {
                $to = $slots['period_to'];
            }
            if ($gross === '') {
                $gross = $slots['gross'];
            }
            if ($fee === '') {
                $fee = $slots['fee'];
            }
        }

        if ($id !== null && $id > 0) {
            $st = $pdo->prepare(
                'UPDATE base_remittance_agreements SET
                 NAME=?, ENABLED=?, ID_SUPPLIER=?, ID_ACCOUNT=?, ID_TYPE=?,
                 FEE_PERCENT=?, FEE_POSTING=?, FEE_MOVE_TYPE_ID=?, FEE_ACCOUNT_ID=?,
                 PARSER_PRESET=?, PATTERN_PERIOD_FROM=?, PATTERN_PERIOD_TO=?,
                 PATTERN_GROSS=?, PATTERN_FEE=?, EXPECTED_CHANNELS=?, AUTO_SET_SUPPLIER=?,
                 DT_UPDATE=?
                 WHERE ID=?'
            );
            $st->execute([
                $name, $enabled, $supplierId, $accountId, $typeId,
                $feePercent, $feePosting, $feeMoveTypeId, $feeAccountId,
                $preset, $from, $to, $gross, $fee, $channels, $autoSet,
                $now, $id,
            ]);
            return $id;
        }

        $st = $pdo->prepare(
            'INSERT INTO base_remittance_agreements
             (NAME, ENABLED, ID_SUPPLIER, ID_ACCOUNT, ID_TYPE, FEE_PERCENT, FEE_POSTING,
              FEE_MOVE_TYPE_ID, FEE_ACCOUNT_ID, PARSER_PRESET, PATTERN_PERIOD_FROM,
              PATTERN_PERIOD_TO, PATTERN_GROSS, PATTERN_FEE, EXPECTED_CHANNELS,
              AUTO_SET_SUPPLIER, DT_CREATE, DT_UPDATE)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $name, $enabled, $supplierId, $accountId, $typeId, $feePercent, $feePosting,
            $feeMoveTypeId, $feeAccountId, $preset, $from, $to, $gross, $fee, $channels,
            $autoSet, $now, $now,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === [] || !self::tableReady()) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if (Database::tableExists('base_remittance_batches')) {
            $stB = Database::pdo()->prepare("DELETE FROM base_remittance_batches WHERE ID_AGREEMENT IN ($ph)");
            $stB->execute($ids);
        }
        $st = Database::pdo()->prepare("DELETE FROM base_remittance_agreements WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['enabled'] = (int)($row['enabled'] ?? 0);
        $row['supplier_id'] = (int)($row['supplier_id'] ?? 0);
        $row['account_id'] = (int)($row['account_id'] ?? 0);
        $row['type_id'] = (int)($row['type_id'] ?? 0);
        $row['fee_percent'] = (float)($row['fee_percent'] ?? 0);
        $row['fee_move_type_id'] = (int)($row['fee_move_type_id'] ?? 0);
        $row['fee_account_id'] = (int)($row['fee_account_id'] ?? 0);
        $row['auto_set_supplier'] = (int)($row['auto_set_supplier'] ?? 0);
        $row['parser_preset'] = (string)($row['parser_preset'] ?? '');
        $row['pattern_period_from'] = (string)($row['pattern_period_from'] ?? '');
        $row['pattern_period_to'] = (string)($row['pattern_period_to'] ?? '');
        $row['pattern_gross'] = (string)($row['pattern_gross'] ?? '');
        $row['pattern_fee'] = (string)($row['pattern_fee'] ?? '');
        $row['expected_channels'] = (string)($row['expected_channels'] ?? 'spa_ops');
        $row['fee_posting'] = (string)($row['fee_posting'] ?? 'none');
        $row['supplier_name'] = (string)($row['supplier_name'] ?? '');
        $row['supplier_inn'] = (string)($row['supplier_inn'] ?? '');
        $row['supplier_ur_name'] = (string)($row['supplier_ur_name'] ?? '');
        return $row;
    }
}
