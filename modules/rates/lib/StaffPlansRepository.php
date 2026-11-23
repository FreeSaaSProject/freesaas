<?php
declare(strict_types=1);

/**
 * Specialist month plan, ₽/hour norm, bonus tiers.
 * Independent of acquisition.
 */
final class StaffPlansRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_staff_plans');
    }

    /**
     * @param array{operator_id?:int,period_ym?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['ID > 0'];
        $params = [];
        $op = (int)($opts['operator_id'] ?? 0);
        if ($op > 0) {
            $where[] = 'ID_OPERATOR = ?';
            $params[] = $op;
        }
        $ym = trim((string)($opts['period_ym'] ?? ''));
        if ($ym !== '' && preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $where[] = 'PERIOD_YM = ?';
            $params[] = $ym;
        }
        $sqlWhere = implode(' AND ', $where);
        $limit = max(1, min(200, (int)($opts['limit'] ?? 50)));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $pdo = Database::pdo();
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_staff_plans WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $st = $pdo->prepare(
            "SELECT ID AS id, ID_OPERATOR AS operator_id, PERIOD_YM AS period_ym,
                    PLAN_SUM AS plan_sum, NORM_RUB_HOUR AS norm_rub_hour,
                    BONUS_TIERS AS bonus_tiers, NOTE AS note, DT_UPDATE AS dt_update
             FROM base_staff_plans WHERE $sqlWhere
             ORDER BY PERIOD_YM DESC, ID_OPERATOR ASC
             LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::cast($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_OPERATOR AS operator_id, PERIOD_YM AS period_ym,
                    PLAN_SUM AS plan_sum, NORM_RUB_HOUR AS norm_rub_hour,
                    BONUS_TIERS AS bonus_tiers, NOTE AS note, DT_UPDATE AS dt_update
             FROM base_staff_plans WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::cast($row) : null;
    }

    public static function findByOperatorPeriod(int $operatorId, string $periodYm): ?array
    {
        if ($operatorId < 1 || !preg_match('/^\d{4}-\d{2}$/', $periodYm) || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_OPERATOR AS operator_id, PERIOD_YM AS period_ym,
                    PLAN_SUM AS plan_sum, NORM_RUB_HOUR AS norm_rub_hour,
                    BONUS_TIERS AS bonus_tiers, NOTE AS note, DT_UPDATE AS dt_update
             FROM base_staff_plans WHERE ID_OPERATOR = ? AND PERIOD_YM = ? LIMIT 1'
        );
        $st->execute([$operatorId, $periodYm]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::cast($row) : null;
    }

    /** @param array<string,mixed> $post */
    public static function save(?int $id, array $post): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('rates.plans.err.no_table'));
        }
        $operatorId = max(0, (int)($post['operator_id'] ?? 0));
        $periodYm = trim((string)($post['period_ym'] ?? ''));
        if ($operatorId < 1) {
            throw new InvalidArgumentException(t('rates.plans.err.operator'));
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $periodYm)) {
            throw new InvalidArgumentException(t('rates.plans.err.period'));
        }
        $planSum = (float)($post['plan_sum'] ?? 0);
        $norm = (float)($post['norm_rub_hour'] ?? 0);
        $tiers = self::normalizeTiers($post['bonus_tiers'] ?? '');
        $note = trim((string)($post['note'] ?? ''));
        $pdo = Database::pdo();
        if ($id !== null && $id > 0) {
            $st = $pdo->prepare(
                'UPDATE base_staff_plans SET ID_OPERATOR=?, PERIOD_YM=?, PLAN_SUM=?, NORM_RUB_HOUR=?,
                 BONUS_TIERS=?, NOTE=?, DT_UPDATE=? WHERE ID=?'
            );
            $st->execute([$operatorId, $periodYm, $planSum, $norm, $tiers, $note, time(), $id]);
            return $id;
        }
        $existing = self::findByOperatorPeriod($operatorId, $periodYm);
        if ($existing !== null) {
            return self::save((int)$existing['id'], $post);
        }
        $st = $pdo->prepare(
            'INSERT INTO base_staff_plans
             (ID_OPERATOR, PERIOD_YM, PLAN_SUM, NORM_RUB_HOUR, BONUS_TIERS, NOTE, DT_UPDATE)
             VALUES (?,?,?,?,?,?,?)'
        );
        $st->execute([$operatorId, $periodYm, $planSum, $norm, $tiers, $note, time()]);
        return (int)$pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_staff_plans WHERE ID = ?')->execute([$id]);
    }

    /** @param mixed $raw */
    private static function normalizeTiers($raw): string
    {
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $from = (float)($t['from'] ?? $t['min'] ?? 0);
                $pct = (float)($t['pct'] ?? $t['bonus_pct'] ?? 0);
                $out[] = ['from' => $from, 'pct' => $pct];
            }
            return json_encode($out, JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return '[]';
        }
        $decoded = json_decode($s, true);
        if (!is_array($decoded)) {
            // Lines: "100000:5" = from plan% or absolute → bonus %
            $out = [];
            foreach (preg_split('/\r\n|\n|\r/', $s) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, ':') === false) {
                    continue;
                }
                [$a, $b] = array_map('trim', explode(':', $line, 2));
                $out[] = ['from' => (float)$a, 'pct' => (float)$b];
            }
            return json_encode($out, JSON_UNESCAPED_UNICODE) ?: '[]';
        }
        return self::normalizeTiers($decoded);
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['operator_id'] = (int)($row['operator_id'] ?? 0);
        $row['period_ym'] = (string)($row['period_ym'] ?? '');
        $row['plan_sum'] = (float)($row['plan_sum'] ?? 0);
        $row['norm_rub_hour'] = (float)($row['norm_rub_hour'] ?? 0);
        $row['note'] = (string)($row['note'] ?? '');
        $row['dt_update'] = (int)($row['dt_update'] ?? 0);
        $tiersRaw = (string)($row['bonus_tiers'] ?? '[]');
        $decoded = json_decode($tiersRaw, true);
        $row['bonus_tiers'] = is_array($decoded) ? $decoded : [];
        $row['bonus_tiers_raw'] = $tiersRaw;
        return $row;
    }
}
