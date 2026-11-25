<?php
declare(strict_types=1);

/**
 * Accrual rules for accumulating (debit) funds: percent of period income or a fixed sum, per week or month.
 */
final class FundRulesRepository
{
    public const KIND_PERCENT = 1;
    public const KIND_FIXED = 2;
    public const PERIOD_WEEK = 1;
    public const PERIOD_MONTH = 2;
    public const BASE_ALL_INCOME = 1;
    public const BASE_TYPES = 2;

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return ['id' => 'r.ID', 'fund' => 'r.ID_FUND', 'name' => 'r.NAME', 'enabled' => 'r.ENABLED'];
    }

    public static function tableReady(): bool
    {
        return Database::tableExists('base_fund_rules');
    }

    public static function kindName(int $kind): string
    {
        return $kind === self::KIND_FIXED ? 'fixed' : 'percent';
    }

    public static function periodName(int $period): string
    {
        return $period === self::PERIOD_MONTH ? 'month' : 'week';
    }

    public static function baseModeName(int $mode): string
    {
        return $mode === self::BASE_TYPES ? 'types' : 'all_income';
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function cast(array $r): array
    {
        $types = [];
        foreach (explode(',', (string)($r['base_types'] ?? '')) as $part) {
            $n = (int)trim($part);
            if ($n > 0) {
                $types[] = $n;
            }
        }
        $fundId = (int)$r['fund_id'];
        $fund = FundsRepository::allRows()[$fundId] ?? null;
        return [
            'id' => (int)$r['id'],
            'fund_id' => $fundId,
            'fund_name' => $fund !== null ? (string)$fund['name'] : '',
            'name' => (string)$r['name'],
            'kind' => self::kindName((int)$r['kind']),
            'percent' => (float)$r['percent'],
            'fixed_sum' => (float)$r['fixed_sum'],
            'period' => self::periodName((int)$r['period']),
            'base_mode' => self::baseModeName((int)$r['base_mode']),
            'base_types' => $types,
            'dt_from' => (int)$r['dt_from'],
            'dt_to' => (int)$r['dt_to'],
            'enabled' => (int)$r['enabled'] > 0,
            'note' => (string)($r['note'] ?? ''),
            'dt_update' => (int)$r['dt_update'],
        ];
    }

    private const SELECT = 'SELECT r.ID AS id, r.ID_FUND AS fund_id, r.NAME AS name, r.KIND AS kind,
            r.PERCENT AS percent, r.FIXED_SUM AS fixed_sum, r.PERIOD AS period, r.BASE_MODE AS base_mode,
            r.BASE_TYPES AS base_types, r.DT_FROM AS dt_from, r.DT_TO AS dt_to, r.ENABLED AS enabled,
            r.NOTE AS note, r.DT_UPDATE AS dt_update FROM base_fund_rules r';

    /**
     * @param array{fund_id?:int,enabled?:int,order?:string} $opts enabled: 1 only enabled, 0 only disabled, -1 any
     * @return list<array<string,mixed>>
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $where = ['1=1'];
        $params = [];
        $fundId = (int)($opts['fund_id'] ?? 0);
        if ($fundId > 0) {
            $ids = [$fundId];
            foreach (FundsRepository::allRows() as $f) {
                if ($f['pid'] === $fundId) {
                    $ids[] = $f['id'];
                }
            }
            $where[] = 'r.ID_FUND IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $i) {
                $params[] = $i;
            }
        }
        $enabled = (int)($opts['enabled'] ?? -1);
        if ($enabled >= 0) {
            $where[] = 'r.ENABLED = ?';
            $params[] = $enabled > 0 ? 1 : 0;
        }
        $order = trim((string)($opts['order'] ?? '')) ?: 'r.ID_FUND ASC, r.ID ASC';
        $st = Database::pdo()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = self::cast($r);
        }
        return $out;
    }

    /** @return array<int,list<array<string,mixed>>> fund id => rules */
    public static function byFund(): array
    {
        $out = [];
        foreach (self::list() as $r) {
            $out[(int)$r['fund_id']][] = $r;
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(self::SELECT . ' WHERE r.ID = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? self::cast($r) : null;
    }

    private static function parseDate(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw) && strlen($raw) > 8) {
            return (int)$raw;
        }
        $ts = strtotime($raw);
        return $ts === false ? 0 : (int)$ts;
    }

    /**
     * Normalize input (form POST or agent JSON). Accepts string enums or legacy ints.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function dataFromInput(array $in, ?array $existing = null): array
    {
        $fundId = array_key_exists('fund_id', $in) ? (int)$in['fund_id'] : (int)($existing['fund_id'] ?? 0);
        $fund = FundsRepository::allRows()[$fundId] ?? null;
        if ($fund === null) {
            throw new InvalidArgumentException(t('funds.rules.err.fund'));
        }
        if (FundsRepository::isGroup($fundId)) {
            throw new InvalidArgumentException(t('funds.moves.err.fund_group'));
        }
        if ((int)$fund['type'] !== 2) {
            throw new InvalidArgumentException(t('funds.rules.err.fund_type'));
        }

        $kindRaw = strtolower(trim((string)($in['kind'] ?? ($existing['kind'] ?? 'percent'))));
        $kind = ($kindRaw === 'fixed' || $kindRaw === '2') ? self::KIND_FIXED : self::KIND_PERCENT;
        $percent = array_key_exists('percent', $in)
            ? (float)str_replace(',', '.', (string)$in['percent'])
            : (float)($existing['percent'] ?? 0);
        $fixed = array_key_exists('fixed_sum', $in)
            ? (float)str_replace(',', '.', (string)$in['fixed_sum'])
            : (float)($existing['fixed_sum'] ?? 0);
        if ($kind === self::KIND_PERCENT && ($percent <= 0 || $percent > 100)) {
            throw new InvalidArgumentException(t('funds.rules.err.percent'));
        }
        if ($kind === self::KIND_FIXED && $fixed <= 0) {
            throw new InvalidArgumentException(t('funds.rules.err.fixed'));
        }
        if ($kind === self::KIND_PERCENT) {
            $fixed = 0.0;
        } else {
            $percent = 0.0;
        }

        $periodRaw = strtolower(trim((string)($in['period'] ?? ($existing['period'] ?? 'week'))));
        $period = ($periodRaw === 'month' || $periodRaw === '2') ? self::PERIOD_MONTH : self::PERIOD_WEEK;

        $modeRaw = strtolower(trim((string)($in['base_mode'] ?? ($existing['base_mode'] ?? 'all_income'))));
        $mode = ($modeRaw === 'types' || $modeRaw === '2') ? self::BASE_TYPES : self::BASE_ALL_INCOME;
        $typesIn = $in['base_types'] ?? ($existing['base_types'] ?? []);
        if (is_string($typesIn)) {
            $typesIn = preg_split('/[\s,;]+/', $typesIn) ?: [];
        }
        $types = [];
        foreach ((array)$typesIn as $v) {
            $n = (int)$v;
            if ($n > 0) {
                $types[$n] = $n;
            }
        }
        if ($mode === self::BASE_TYPES && $kind === self::KIND_PERCENT && $types === []) {
            throw new InvalidArgumentException(t('funds.rules.err.types'));
        }
        if ($mode === self::BASE_ALL_INCOME) {
            $types = [];
        }

        $dtFrom = array_key_exists('dt_from', $in) ? self::parseDate((string)$in['dt_from']) : (int)($existing['dt_from'] ?? 0);
        $dtTo = array_key_exists('dt_to', $in) ? self::parseDate((string)$in['dt_to']) : (int)($existing['dt_to'] ?? 0);
        if ($dtFrom > 0 && $dtTo > 0 && $dtTo < $dtFrom) {
            throw new InvalidArgumentException(t('funds.rules.err.dates'));
        }
        $enabled = array_key_exists('enabled', $in)
            ? (!empty($in['enabled']) && (string)$in['enabled'] !== '0' && (string)$in['enabled'] !== 'false' ? 1 : 0)
            : ($existing !== null ? (int)!empty($existing['enabled']) : 1);

        return [
            'fund_id' => $fundId,
            'name' => mb_substr(trim((string)($in['name'] ?? ($existing['name'] ?? ''))), 0, 250),
            'kind' => $kind,
            'percent' => $percent,
            'fixed_sum' => $fixed,
            'period' => $period,
            'base_mode' => $mode,
            'base_types' => implode(',', array_values($types)),
            'dt_from' => $dtFrom,
            'dt_to' => $dtTo,
            'enabled' => $enabled,
            'note' => trim((string)($in['note'] ?? ($existing['note'] ?? ''))),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_fund_rules
             (ID_FUND, NAME, KIND, PERCENT, FIXED_SUM, PERIOD, BASE_MODE, BASE_TYPES, DT_FROM, DT_TO, ENABLED, NOTE,
              DT_CREATE, DT_UPDATE, ID_USER_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['fund_id'], $data['name'], $data['kind'], $data['percent'], $data['fixed_sum'],
            $data['period'], $data['base_mode'], $data['base_types'], $data['dt_from'], $data['dt_to'],
            $data['enabled'], $data['note'], $now, $now, Auth::userId(),
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $st = Database::pdo()->prepare(
            'UPDATE base_fund_rules SET ID_FUND = ?, NAME = ?, KIND = ?, PERCENT = ?, FIXED_SUM = ?, PERIOD = ?,
             BASE_MODE = ?, BASE_TYPES = ?, DT_FROM = ?, DT_TO = ?, ENABLED = ?, NOTE = ?, DT_UPDATE = ?, ID_USER_UPDATE = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['fund_id'], $data['name'], $data['kind'], $data['percent'], $data['fixed_sum'],
            $data['period'], $data['base_mode'], $data['base_types'], $data['dt_from'], $data['dt_to'],
            $data['enabled'], $data['note'], time(), Auth::userId(), $id,
        ]);
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        $st = Database::pdo()->prepare('UPDATE base_fund_rules SET ENABLED = ?, DT_UPDATE = ?, ID_USER_UPDATE = ? WHERE ID = ?');
        $st->execute([$enabled ? 1 : 0, time(), Auth::userId(), $id]);
    }

    /**
     * Delete rules. Accrual movements already posted stay in the fund (RULE_ID is kept as history).
     *
     * @param list<int> $ids
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !self::tableReady()) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_fund_rules WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
