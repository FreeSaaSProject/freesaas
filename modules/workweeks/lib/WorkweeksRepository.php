<?php
declare(strict_types=1);

final class WorkweeksRepository
{
    /** When tables module is off / empty, massage-table id defaults to 1. */
    public static function tablesActive(): bool
    {
        return class_exists('TablesRepository', false) && TablesRepository::isActive();
    }

    /** @deprecated use tablesActive() */
    public static function cabinetsActive(): bool
    {
        return self::tablesActive();
    }

    public static function normalizeTableId(int $idTable): int
    {
        if (class_exists('TablesRepository', false)) {
            return TablesRepository::normalizeId($idTable);
        }
        return $idTable > 0 ? $idTable : 1;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listForDay(int $dayStart): array
    {
        if ($dayStart <= 0 || !Database::tableExists('base_workweeks')) {
            return [];
        }
        $pdo = Database::pdo();
        $joins = '';
        $extra = 'NULL AS operator_name, 0 AS name_enabled, 0 AS assist_team, 0 AS is_hidden, NULL AS color, NULL AS color_text, 0 AS can_be_assigned_asany';
        $order = 'w.PRICE_COL ASC, w.ID ASC';
        if (Database::tableExists('base_users')) {
            $joins = ' LEFT JOIN base_users u ON u.id = w.ID_OPERATOR';
            $extra = "COALESCE(NULLIF(u.name, ''), u.login) AS operator_name,
                      COALESCE(u.name_enabled, 0) AS name_enabled,
                      COALESCE(u.ASSIST_TEAM, 0) AS assist_team,
                      COALESCE(u.IS_HIDDEN, 0) AS is_hidden,
                      u.color AS color,
                      u.color_text AS color_text,
                      0 AS can_be_assigned_asany";
            if (Database::tableExists('base_admin_statuses')
                && Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED_ASANY')
            ) {
                $joins .= ' LEFT JOIN base_admin_statuses st ON st.ID = u.name_enabled';
                $extra = "COALESCE(NULLIF(u.name, ''), u.login) AS operator_name,
                          COALESCE(u.name_enabled, 0) AS name_enabled,
                          COALESCE(u.ASSIST_TEAM, 0) AS assist_team,
                          COALESCE(u.IS_HIDDEN, 0) AS is_hidden,
                          u.color AS color,
                          u.color_text AS color_text,
                          COALESCE(st.CAN_BE_ASSIGNED_ASANY, 0) AS can_be_assigned_asany";
            }
            $asanyExpr = (
                Database::tableExists('base_admin_statuses')
                && Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED_ASANY')
            ) ? 'st.CAN_BE_ASSIGNED_ASANY' : '0';
            $order = "CASE
                        WHEN COALESCE(u.IS_HIDDEN, 0) > 0 THEN 4
                        WHEN COALESCE(u.ASSIST_TEAM, 0) > 0 THEN 3
                        WHEN COALESCE($asanyExpr, 0) > 0 OR COALESCE(u.name_enabled, 0) = 2 THEN 2
                        WHEN COALESCE(u.name_enabled, 0) = 18 THEN 1
                        ELSE 0
                      END ASC,
                      w.PRICE_COL ASC,
                      COALESCE(NULLIF(u.name, ''), u.login) ASC,
                      w.ID ASC";
        }
        $st = $pdo->prepare(
            "SELECT w.ID AS id, w.ID_OPERATOR AS id_operator, w.ID_OPERATOR_CREATE AS id_operator_create,
                    w.DT_CREATE AS dt_create, w.DT AS dt, w.PRICE_COL AS price_col,
                    w.ID_TABLE AS id_table, w.MAS_MAX AS mas_max,
                    $extra
             FROM base_workweeks w
             $joins
             WHERE w.DT = ?
             ORDER BY $order"
        );
        $st->execute([$dayStart]);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeShiftRow($row);
        }
        return $items;
    }

    /**
     * Staff who can be put on a shift (status CAN_BE_ASSIGNED / ASANY), sorted like oper.
     *
     * @return array<int,array{id:int,label:string,acronym:string,bg:string,fg:string,group:int}>
     */
    public static function listAssignableStaff(): array
    {
        if (!Database::tableExists('base_users')) {
            return [];
        }
        $pdo = Database::pdo();
        $hasStatus = Database::tableExists('base_admin_statuses')
            && Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED');
        $hasAsany = $hasStatus && Database::columnExists('base_admin_statuses', 'CAN_BE_ASSIGNED_ASANY');

        if ($hasStatus) {
            $asanyCol = $hasAsany ? 'COALESCE(st.CAN_BE_ASSIGNED_ASANY, 0)' : '0';
            $sql = "SELECT u.id, u.login, u.name, u.name_enabled, u.ASSIST_TEAM, u.IS_HIDDEN,
                           u.color, u.color_text,
                           COALESCE(st.CAN_BE_ASSIGNED, 0) AS can_be_assigned,
                           $asanyCol AS can_be_assigned_asany
                    FROM base_users u
                    LEFT JOIN base_admin_statuses st ON st.ID = u.name_enabled
                    WHERE u.enabled = '1'
                      AND (COALESCE(st.CAN_BE_ASSIGNED, 0) > 0 OR $asanyCol > 0)
                    ORDER BY CASE
                               WHEN COALESCE(u.IS_HIDDEN, 0) > 0 THEN 4
                               WHEN COALESCE(u.ASSIST_TEAM, 0) > 0 THEN 3
                               WHEN $asanyCol > 0 OR u.name_enabled = 2 THEN 2
                               WHEN u.name_enabled = 18 THEN 1
                               ELSE 0
                             END ASC,
                             COALESCE(NULLIF(u.name, ''), u.login) ASC
                    LIMIT 500";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $rows = $pdo->query(
                "SELECT id, login, name, name_enabled, ASSIST_TEAM, IS_HIDDEN, color, color_text,
                        1 AS can_be_assigned, 0 AS can_be_assigned_asany
                 FROM base_users WHERE enabled = '1'
                 ORDER BY COALESCE(NULLIF(name, ''), login) ASC LIMIT 500"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (!self::canAssignOperator($id)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = (string)($row['login'] ?? ('#' . $id));
            }
            $bg = trim((string)($row['color'] ?? ''));
            $fg = trim((string)($row['color_text'] ?? ''));
            $out[$id] = [
                'id' => $id,
                'label' => $name,
                'acronym' => class_exists('CalendarTimeline', false)
                    ? CalendarTimeline::acronym($name)
                    : mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8'),
                'bg' => $bg !== '' ? $bg : 'var(--bg-input)',
                'fg' => $fg !== '' ? $fg : 'var(--text)',
                'group' => self::shiftGroupRank($row),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    public static function shiftGroupRank(array $row): int
    {
        if ((int)($row['is_hidden'] ?? $row['IS_HIDDEN'] ?? 0) > 0) {
            return 4;
        }
        if ((int)($row['assist_team'] ?? $row['ASSIST_TEAM'] ?? 0) > 0) {
            return 3;
        }
        if ((int)($row['can_be_assigned_asany'] ?? 0) > 0
            || (int)($row['name_enabled'] ?? 0) === 2
        ) {
            return 2;
        }
        if ((int)($row['name_enabled'] ?? 0) === 18) {
            return 1;
        }
        return 0;
    }

    /** @param array<string,mixed> $row */
    private static function normalizeShiftRow(array $row): array
    {
        $name = (string)($row['operator_name'] ?? '');
        $group = self::shiftGroupRank([
            'is_hidden' => $row['is_hidden'] ?? 0,
            'assist_team' => $row['assist_team'] ?? 0,
            'can_be_assigned_asany' => $row['can_be_assigned_asany'] ?? 0,
            'name_enabled' => $row['name_enabled'] ?? 0,
        ]);
        return [
            'id' => (int)$row['id'],
            'id_operator' => (int)$row['id_operator'],
            'id_operator_create' => (int)$row['id_operator_create'],
            'dt_create' => (int)$row['dt_create'],
            'dt' => (int)$row['dt'],
            'price_col' => (int)$row['price_col'],
            'id_table' => (int)$row['id_table'],
            'mas_max' => (int)$row['mas_max'],
            'operator_name' => $name,
            'name_enabled' => (int)($row['name_enabled'] ?? 0),
            'assist_team' => (int)($row['assist_team'] ?? 0),
            'is_hidden' => (int)($row['is_hidden'] ?? 0),
            'can_be_assigned_asany' => (int)($row['can_be_assigned_asany'] ?? 0),
            'color' => (string)($row['color'] ?? ''),
            'color_text' => (string)($row['color_text'] ?? ''),
            'group' => $group,
        ];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_workweeks')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_OPERATOR AS id_operator, ID_OPERATOR_CREATE AS id_operator_create,
                    DT_CREATE AS dt_create, DT AS dt, PRICE_COL AS price_col,
                    ID_TABLE AS id_table, MAS_MAX AS mas_max
             FROM base_workweeks WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function findByDayOperator(int $dayStart, int $operatorId): ?array
    {
        if ($dayStart <= 0 || $operatorId < 1 || !Database::tableExists('base_workweeks')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_OPERATOR AS id_operator, PRICE_COL AS price_col,
                    ID_TABLE AS id_table, MAS_MAX AS mas_max
             FROM base_workweeks WHERE DT = ? AND ID_OPERATOR = ? LIMIT 1'
        );
        $st->execute([$dayStart, $operatorId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function assign(int $dayStart, int $operatorId, int $priceCol = 1, int $masMax = 1, int $idTable = 0): int
    {
        if ($dayStart <= 0 || $operatorId < 1) {
            throw new InvalidArgumentException(t('workweeks.err.invalid'));
        }
        $existing = self::findByDayOperator($dayStart, $operatorId);
        if ($existing) {
            self::update((int)$existing['id'], $priceCol, $masMax, $idTable);
            return (int)$existing['id'];
        }
        $priceCol = max(1, min(5, $priceCol));
        $masMax = max(1, $masMax);
        $idTable = self::normalizeTableId($idTable);
        $st = Database::pdo()->prepare(
            'INSERT INTO base_workweeks
             (ID_OPERATOR, ID_OPERATOR_CREATE, DT_CREATE, DT, PRICE_COL, ID_TABLE, MAS_MAX)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $operatorId,
            Auth::userId(),
            time(),
            $dayStart,
            $priceCol,
            $idTable,
            $masMax,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function update(int $id, int $priceCol, int $masMax, int $idTable = 0): void
    {
        if ($id < 1) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_workweeks SET PRICE_COL = ?, MAS_MAX = ?, ID_TABLE = ? WHERE ID = ?'
        );
        $st->execute([
            max(1, min(5, $priceCol)),
            max(1, $masMax),
            self::normalizeTableId($idTable),
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !Database::tableExists('base_workweeks')) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_workweeks WHERE ID = ?')->execute([$id]);
    }

    public static function canAssign(): bool
    {
        if (!Auth::can('workweeks.edit')) {
            return false;
        }
        if (Auth::capability('EVT_ASSIGN')) {
            return true;
        }
        return !empty(Auth::statusFlags()['EVT_ASSIGN_MY']);
    }

    public static function canAssignOperator(int $operatorId): bool
    {
        if (!self::canAssign()) {
            return false;
        }
        if (Auth::capability('EVT_ASSIGN')) {
            return $operatorId > 0;
        }
        return $operatorId === Auth::userId();
    }

    /** Shared day URL helper for embeds and standalone UI. */
    public static function dayUrl(string $date, string $base = '/workweeks/day'): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        return rtrim($base, '/') . '/' . $date;
    }

    /**
     * Handle assign/update/delete POST for a day. Returns flash message or throws.
     *
     * @return string flash message key resolved text
     */
    public static function handleDayPost(int $dayStart, array $post): string
    {
        $action = (string)($post['shift_action'] ?? '');
        if ($action === 'add') {
            $op = (int)($post['id_operator'] ?? 0);
            if (!self::canAssignOperator($op)) {
                throw new RuntimeException(t('workweeks.err.no_assign'));
            }
            self::assign(
                $dayStart,
                $op,
                (int)($post['price_col'] ?? 1),
                (int)($post['mas_max'] ?? 1),
                (int)($post['id_table'] ?? 0)
            );
            return t('workweeks.msg.saved');
        }
        if ($action === 'update') {
            $sid = (int)($post['shift_id'] ?? 0);
            $row = self::find($sid);
            if (!$row || (int)$row['dt'] !== $dayStart) {
                throw new RuntimeException(t('workweeks.err.not_found'));
            }
            if (!self::canAssignOperator((int)$row['id_operator'])) {
                throw new RuntimeException(t('workweeks.err.no_assign'));
            }
            self::update(
                $sid,
                (int)($post['price_col'] ?? 1),
                (int)($post['mas_max'] ?? 1),
                (int)($post['id_table'] ?? 0)
            );
            return t('workweeks.msg.saved');
        }
        if ($action === 'delete') {
            $sid = (int)($post['shift_id'] ?? 0);
            $row = self::find($sid);
            if (!$row || (int)$row['dt'] !== $dayStart) {
                throw new RuntimeException(t('workweeks.err.not_found'));
            }
            if (!self::canAssignOperator((int)$row['id_operator'])) {
                throw new RuntimeException(t('workweeks.err.no_assign'));
            }
            self::delete($sid);
            return t('workweeks.msg.deleted');
        }
        throw new RuntimeException(t('workweeks.err.invalid'));
    }

    /**
     * Oper-style matrix: days × operators with current assignments.
     *
     * @param list<int> $operatorIds
     * @return array{
     *   days:list<array{ts:int,date:string,label:string,weekday:string,is_weekend:bool}>,
     *   operators:array<int,array{id:int,label:string,acronym:string,bg:string,fg:string}>,
     *   assigned:array<string,array<int,array{price_col:int,mas_max:int}>>,
     *   day_price:array<string,int>,
     *   day_mas_max:array<string,int>
     * }
     */
    public static function nazopMatrix(int $startTs, int $dayCount, array $operatorIds): array
    {
        $dayCount = max(1, min(120, $dayCount));
        $startTs = (int)strtotime(date('Y-m-d 00:00:00', $startTs));
        $ops = [];
        $assignable = self::listAssignableStaff();
        foreach ($operatorIds as $oid) {
            $oid = (int)$oid;
            if ($oid < 1 || !isset($assignable[$oid])) {
                continue;
            }
            if (!self::canAssignOperator($oid)) {
                continue;
            }
            $ops[$oid] = $assignable[$oid];
        }
        // Do not auto-expand to all staff — caller picks columns (oper userManage).

        $days = [];
        $endTs = $startTs + ($dayCount * 86400);
        $assigned = [];
        $dayPrice = [];
        $dayMasMax = [];

        // Load assignments for the day range (all ops), then filter display columns.
        if (Database::tableExists('base_workweeks')) {
            $st = Database::pdo()->prepare(
                'SELECT ID_OPERATOR AS id_operator, DT AS dt, PRICE_COL AS price_col, MAS_MAX AS mas_max
                 FROM base_workweeks
                 WHERE DT >= ? AND DT < ?'
            );
            $st->execute([$startTs, $endTs]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $d = date('Y-m-d', (int)$row['dt']);
                $oid = (int)$row['id_operator'];
                $pc = max(1, (int)$row['price_col']);
                $mm = max(1, (int)$row['mas_max']);
                $assigned[$d][$oid] = ['price_col' => $pc, 'mas_max' => $mm];
            }
        }

        // Day-level price/mas only across visible operator columns.
        foreach ($assigned as $d => $byOp) {
            $visible = [];
            foreach ($byOp as $oid => $meta) {
                if (isset($ops[$oid])) {
                    $visible[$oid] = $meta;
                }
            }
            if ($visible === []) {
                continue;
            }
            $pc0 = null;
            $mixed = false;
            $mm0 = 1;
            foreach ($visible as $meta) {
                $pc = (int)$meta['price_col'];
                $mm0 = max($mm0, (int)$meta['mas_max']);
                if ($pc0 === null) {
                    $pc0 = $pc;
                } elseif ($pc0 !== $pc) {
                    $mixed = true;
                }
            }
            $dayPrice[$d] = $mixed ? 0 : (int)$pc0;
            $dayMasMax[$d] = $mm0;
        }

        for ($i = 0; $i < $dayCount; $i++) {
            $ts = $startTs + ($i * 86400);
            $n = (int)date('N', $ts);
            $days[] = [
                'ts' => $ts,
                'date' => date('Y-m-d', $ts),
                'label' => date('d.m.Y', $ts),
                'weekday' => class_exists('CalendarWeek', false)
                    ? CalendarWeek::weekdayShort($n)
                    : date('D', $ts),
                'is_weekend' => $n >= 6,
            ];
        }

        return [
            'days' => $days,
            'operators' => $ops,
            'assigned' => $assigned,
            'day_price' => $dayPrice,
            'day_mas_max' => $dayMasMax,
        ];
    }

    /**
     * Save nazop matrix. $checked[date][opId]=1, $prices[date]=n, $masMax[date]=n.
     *
     * @param array<string,array<int,int|true>> $checked
     * @param array<string,int> $prices
     * @param array<string,int> $masMax
     * @param list<int> $managedOpIds operators columns in the form
     */
    public static function saveNazopMatrix(array $checked, array $prices, array $masMax, array $managedOpIds): string
    {
        if (!self::canAssign()) {
            throw new RuntimeException(t('workweeks.err.no_assign'));
        }
        $managed = [];
        foreach ($managedOpIds as $oid) {
            $oid = (int)$oid;
            if ($oid > 0 && self::canAssignOperator($oid)) {
                $managed[$oid] = true;
            }
        }
        if ($managed === []) {
            throw new RuntimeException(t('workweeks.err.no_assign'));
        }

        $dates = [];
        foreach (array_keys($checked) as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)) {
                $dates[(string)$d] = true;
            }
        }
        foreach (array_keys($prices) as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)) {
                $dates[(string)$d] = true;
            }
        }
        foreach (array_keys($masMax) as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d)) {
                $dates[(string)$d] = true;
            }
        }

        $changed = 0;
        foreach (array_keys($dates) as $date) {
            $ts = strtotime($date . ' 00:00:00');
            if ($ts === false) {
                continue;
            }
            $dayStart = (int)$ts;
            $pc = max(1, min(5, (int)($prices[$date] ?? 1)));
            $mm = max(1, min(9, (int)($masMax[$date] ?? 1)));
            $want = [];
            foreach (($checked[$date] ?? []) as $oid => $on) {
                $oid = (int)$oid;
                if ($oid > 0 && !empty($on) && isset($managed[$oid])) {
                    $want[$oid] = true;
                }
            }
            foreach (array_keys($managed) as $oid) {
                $existing = self::findByDayOperator($dayStart, $oid);
                if (isset($want[$oid])) {
                    if ($existing) {
                        self::update((int)$existing['id'], $pc, $mm, (int)($existing['id_table'] ?? 1));
                    } else {
                        self::assign($dayStart, $oid, $pc, $mm, 1);
                    }
                    $changed++;
                } elseif ($existing) {
                    self::delete((int)$existing['id']);
                    $changed++;
                }
            }
        }
        return t('workweeks.msg.saved');
    }
}
