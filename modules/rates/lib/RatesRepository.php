<?php
declare(strict_types=1);

final class RatesRepository
{
    private const CONTENT_KEYS = ['content', 'content_sales', 'content_income', 'content_terminal'];

    /** Type-level skill bans: type_id => 1 (payroll ignores). */
    private const BAN_TYPE_KEYS = ['ban_booking', 'ban_online'];

    /** Product-level skill bans: product_id => 1. */
    private const BAN_PROC_KEY = 'ban_procs';

    /** @var array<string,array<string,mixed>|false> request memo for latestForOperator */
    private static $latestCache = [];

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'h.ID',
            'operator' => 'h.ID_OPERATOR',
            'dt' => 'h.DT',
            'descr' => 'h.DESCR',
        ];
    }

    /**
     * @param array{q?:string,operator_id?:int,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!Database::tableExists('base_procent_history')) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(h.DESCR LIKE ? OR CAST(h.ID AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
        }
        $opId = (int)($opts['operator_id'] ?? 0);
        if ($opId > 0) {
            $where[] = 'h.ID_OPERATOR = ?';
            $params[] = $opId;
        }
        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_procent_history h WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'h.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $audioCols = self::audioRateSelectSql('h');
        $banCols = self::banSelectSql('h');
        $sql = "SELECT h.ID AS id, h.ID_OPERATOR AS operator_id, h.DT AS dt, h.DESCR AS descr,
                       h.content, h.content_sales, h.content_income, h.content_terminal,
                       h.LOSS AS loss, h.LOSS_FIX AS loss_fix, h.OFFERS AS offers
                       $banCols
                       $audioCols
                FROM base_procent_history h
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::normalizeRow($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_procent_history')) {
            return null;
        }
        $audioCols = self::audioRateSelectSql('');
        $banCols = self::banSelectSql('');
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, ID_OPERATOR AS operator_id, DT AS dt, DESCR AS descr,
                    content, content_sales, content_income, content_terminal,
                    LOSS AS loss, LOSS_FIX AS loss_fix, OFFERS AS offers
                    $banCols
                    $audioCols
             FROM base_procent_history WHERE ID = ? LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalizeRow($row) : null;
    }

    /**
     * Latest rates for operator effective at or before $ts.
     * @return array<string,mixed>|null
     */
    /**
     * As-of timestamp for skill/booking on a calendar day: end of that day
     * so same-day grant/revoke/UI rows (DT=10:00…) beat older midnight rows.
     */
    public static function asOfForDay(int $dayStart): int
    {
        if ($dayStart < 1) {
            return time();
        }
        return (int)mktime(
            23,
            59,
            59,
            (int)date('n', $dayStart),
            (int)date('j', $dayStart),
            (int)date('Y', $dayStart)
        );
    }

    public static function latestForOperator(int $operatorId, int $ts): ?array
    {
        if ($operatorId < 1 || !Database::tableExists('base_procent_history')) {
            return null;
        }
        $key = $operatorId . '|' . $ts;
        if (array_key_exists($key, self::$latestCache)) {
            $hit = self::$latestCache[$key];
            return $hit === false ? null : $hit;
        }
        $audioCols = self::audioRateSelectSql('');
        $banCols = self::banSelectSql('');
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, ID_OPERATOR AS operator_id, DT AS dt, DESCR AS descr,
                    content, content_sales, content_income, content_terminal,
                    LOSS AS loss, LOSS_FIX AS loss_fix, OFFERS AS offers
                    $banCols
                    $audioCols
             FROM base_procent_history
             WHERE ID_OPERATOR = ? AND DT <= ?
             ORDER BY DT DESC, ID DESC LIMIT 1"
        );
        $st->execute([$operatorId, $ts]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            self::$latestCache[$key] = false;
            return null;
        }
        $norm = self::normalizeRow($row);
        self::$latestCache[$key] = $norm;
        return $norm;
    }

    /**
     * Prefetch latest rates for many operators at one effective timestamp (week/day pay).
     *
     * @param list<int> $operatorIds
     * @return array<int,array<string,mixed>> operator_id => rates
     */
    public static function latestForOperators(array $operatorIds, int $ts): array
    {
        $out = [];
        if ($ts < 1 || !Database::tableExists('base_procent_history')) {
            return $out;
        }
        $ids = [];
        foreach ($operatorIds as $oid) {
            $oid = (int)$oid;
            if ($oid < 1) {
                continue;
            }
            $key = $oid . '|' . $ts;
            if (array_key_exists($key, self::$latestCache)) {
                $hit = self::$latestCache[$key];
                if ($hit !== false) {
                    $out[$oid] = $hit;
                }
                continue;
            }
            $ids[$oid] = $oid;
        }
        if ($ids === []) {
            return $out;
        }
        $ids = array_values($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $audioCols = self::audioRateSelectSql('h');
        $banCols = self::banSelectSql('h');
        $params = array_merge($ids, [$ts], $ids);
        $st = Database::pdo()->prepare(
            "SELECT h.ID AS id, h.ID_OPERATOR AS operator_id, h.DT AS dt, h.DESCR AS descr,
                    h.content, h.content_sales, h.content_income, h.content_terminal,
                    h.LOSS AS loss, h.LOSS_FIX AS loss_fix, h.OFFERS AS offers
                    $banCols
                    $audioCols
             FROM base_procent_history h
             INNER JOIN (
                 SELECT ID_OPERATOR, MAX(DT) AS max_dt
                 FROM base_procent_history
                 WHERE ID_OPERATOR IN ($placeholders) AND DT <= ?
                 GROUP BY ID_OPERATOR
             ) latest ON latest.ID_OPERATOR = h.ID_OPERATOR AND latest.max_dt = h.DT
             WHERE h.ID_OPERATOR IN ($placeholders)"
        );
        $st->execute($params);
        /** @var array<int,array<string,mixed>> $best */
        $best = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $oid = (int)($row['operator_id'] ?? 0);
            if ($oid < 1) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if (!isset($best[$oid]) || $id > (int)($best[$oid]['id'] ?? 0)) {
                $best[$oid] = $row;
            }
        }
        foreach ($ids as $oid) {
            $key = $oid . '|' . $ts;
            if (!isset($best[$oid])) {
                self::$latestCache[$key] = false;
                continue;
            }
            $norm = self::normalizeRow($best[$oid]);
            self::$latestCache[$key] = $norm;
            $out[$oid] = $norm;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, bool $forceNowDt = false): array
    {
        $operatorId = (int)($post['operator_id'] ?? 0);
        if ($operatorId < 1) {
            throw new InvalidArgumentException(t('rates.err.operator_required'));
        }
        $descr = trim((string)($post['descr'] ?? ''));
        if ($descr === '') {
            throw new InvalidArgumentException(t('rates.err.descr_required'));
        }
        $dt = $forceNowDt ? time() : self::parseDt((string)($post['dt'] ?? ''));
        if ($dt < 1) {
            $dt = time();
        }

        $typeIds = array_keys(ProcedureTypesRepository::names());
        $maps = [];
        foreach (self::CONTENT_KEYS as $key) {
            $raw = $post[$key] ?? [];
            if (!is_array($raw)) {
                $raw = [];
            }
            $map = [];
            foreach ($typeIds as $tid) {
                $val = isset($raw[$tid]) ? str_replace(',', '.', trim((string)$raw[$tid])) : '0';
                $map[(int)$tid] = is_numeric($val) ? (float)$val : 0.0;
            }
            $maps[$key] = $map;
        }

        $bans = [];
        foreach (self::BAN_TYPE_KEYS as $key) {
            $raw = $post[$key] ?? [];
            if (!is_array($raw)) {
                $raw = [];
            }
            $map = [];
            foreach ($typeIds as $tid) {
                if (!empty($raw[$tid]) || !empty($raw[(string)$tid])) {
                    $map[(int)$tid] = 1;
                }
            }
            $bans[$key] = $map;
        }

        $banProcs = [];
        $rawProcs = $post[self::BAN_PROC_KEY] ?? [];
        if (is_array($rawProcs)) {
            foreach ($rawProcs as $pid => $on) {
                $pid = (int)$pid;
                if ($pid > 0 && !empty($on)) {
                    $banProcs[$pid] = 1;
                }
            }
        }

        $applied = self::applyZeroPctTypeBans($maps['content'], $bans['ban_booking'], $bans['ban_online']);

        return [
            'operator_id' => $operatorId,
            'dt' => $dt,
            'descr' => $descr,
            'content' => $maps['content'],
            'content_sales' => $maps['content_sales'],
            'content_income' => $maps['content_income'],
            'content_terminal' => $maps['content_terminal'],
            'ban_booking' => $applied['ban_booking'],
            'ban_online' => $applied['ban_online'],
            'ban_procs' => $banProcs,
            'loss' => (float)str_replace(',', '.', (string)($post['loss'] ?? '0')),
            'loss_fix' => (float)str_replace(',', '.', (string)($post['loss_fix'] ?? '0')),
            'offers' => (int)($post['offers'] ?? 0),
            'audio_hour_rate' => (float)str_replace(',', '.', (string)($post['audio_hour_rate'] ?? '0')),
            'script_score_rate' => (float)str_replace(',', '.', (string)($post['script_score_rate'] ?? '0')),
        ];
    }

    /**
     * Zero % (except SPEC/break) ⇒ force ban_booking + ban_online for that type.
     *
     * @param array<int|string,mixed> $content
     * @param array<int|string,mixed> $banBooking
     * @param array<int|string,mixed> $banOnline
     * @return array{ban_booking:array<int,int>,ban_online:array<int,int>}
     */
    public static function applyZeroPctTypeBans(array $content, array $banBooking = [], array $banOnline = []): array
    {
        $outBooking = [];
        foreach ($banBooking as $tid => $v) {
            if (!empty($v)) {
                $outBooking[(int)$tid] = 1;
            }
        }
        $outOnline = [];
        foreach ($banOnline as $tid => $v) {
            if (!empty($v)) {
                $outOnline[(int)$tid] = 1;
            }
        }
        $typeIds = array_keys(ProcedureTypesRepository::names());
        foreach ($typeIds as $tid) {
            $tid = (int)$tid;
            if (self::isSpecOrBreakType($tid)) {
                continue;
            }
            $pct = (float)($content[$tid] ?? $content[(string)$tid] ?? 0);
            if (!($pct > 0)) {
                $outBooking[$tid] = 1;
                $outOnline[$tid] = 1;
            }
        }
        return ['ban_booking' => $outBooking, 'ban_online' => $outOnline];
    }

    /** SPEC / break types (default 38) — zero % must not force booking bans. */
    public static function isSpecOrBreakType(int $typeId): bool
    {
        if ($typeId < 1) {
            return false;
        }
        if (class_exists('ProceduresChain', false) && ProceduresChain::isBreakTypeId($typeId)) {
            return true;
        }
        return $typeId === 38;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $data = self::normalizeWriteData($data);
        $cols = [
            'ID_OPERATOR', 'DT', 'DESCR',
            'content', 'content_sales', 'content_income', 'content_terminal',
            'LOSS', 'LOSS_FIX', 'OFFERS',
        ];
        $vals = [
            $data['operator_id'],
            $data['dt'],
            $data['descr'],
            serialize($data['content'] ?? []),
            serialize($data['content_sales'] ?? []),
            serialize($data['content_income'] ?? []),
            serialize($data['content_terminal'] ?? []),
            $data['loss'],
            $data['loss_fix'],
            $data['offers'],
        ];
        if (self::hasBanColumns()) {
            $cols[] = 'ban_booking';
            $cols[] = 'ban_online';
            $vals[] = serialize(is_array($data['ban_booking'] ?? null) ? $data['ban_booking'] : []);
            $vals[] = serialize(is_array($data['ban_online'] ?? null) ? $data['ban_online'] : []);
        }
        if (self::hasBanProcsColumn()) {
            $cols[] = 'ban_procs';
            $vals[] = serialize(is_array($data['ban_procs'] ?? null) ? $data['ban_procs'] : []);
        }
        if (self::hasAudioRateColumns()) {
            $cols[] = 'AUDIO_HOUR_RATE';
            $cols[] = 'SCRIPT_SCORE_RATE';
            $vals[] = (float)($data['audio_hour_rate'] ?? 0);
            $vals[] = (float)($data['script_score_rate'] ?? 0);
        }
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO base_procent_history (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')';
        Database::pdo()->prepare($sql)->execute($vals);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid rate id');
        }
        $data = self::normalizeWriteData($data);
        $sets = [
            'ID_OPERATOR = ?',
            'DT = ?',
            'DESCR = ?',
            'content = ?',
            'content_sales = ?',
            'content_income = ?',
            'content_terminal = ?',
            'LOSS = ?',
            'LOSS_FIX = ?',
            'OFFERS = ?',
        ];
        $vals = [
            $data['operator_id'],
            $data['dt'],
            $data['descr'],
            serialize($data['content'] ?? []),
            serialize($data['content_sales'] ?? []),
            serialize($data['content_income'] ?? []),
            serialize($data['content_terminal'] ?? []),
            $data['loss'],
            $data['loss_fix'],
            $data['offers'],
        ];
        if (self::hasBanColumns()) {
            $sets[] = 'ban_booking = ?';
            $sets[] = 'ban_online = ?';
            $vals[] = serialize(is_array($data['ban_booking'] ?? null) ? $data['ban_booking'] : []);
            $vals[] = serialize(is_array($data['ban_online'] ?? null) ? $data['ban_online'] : []);
        }
        if (self::hasBanProcsColumn()) {
            $sets[] = 'ban_procs = ?';
            $vals[] = serialize(is_array($data['ban_procs'] ?? null) ? $data['ban_procs'] : []);
        }
        if (self::hasAudioRateColumns()) {
            $sets[] = 'AUDIO_HOUR_RATE = ?';
            $sets[] = 'SCRIPT_SCORE_RATE = ?';
            $vals[] = (float)($data['audio_hour_rate'] ?? 0);
            $vals[] = (float)($data['script_score_rate'] ?? 0);
        }
        $vals[] = $id;
        $sql = 'UPDATE base_procent_history SET ' . implode(', ', $sets) . ' WHERE ID = ?';
        Database::pdo()->prepare($sql)->execute($vals);
    }

    /**
     * @param list<int> $ids
     * @return int deleted count
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = self::sanitizeIds($ids);
        if ($ids === [] || !Database::tableExists('base_procent_history')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_procent_history WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /**
     * @param list<int> $ids
     * @return int copied count
     */
    public static function copyByIds(array $ids): int
    {
        $done = 0;
        foreach (self::sanitizeIds($ids) as $id) {
            $row = self::find($id);
            if (!$row) {
                continue;
            }
            self::create([
                'operator_id' => (int)$row['operator_id'],
                'dt' => time(),
                'descr' => (string)$row['descr'],
                'content' => $row['content'],
                'content_sales' => $row['content_sales'],
                'content_income' => $row['content_income'],
                'content_terminal' => $row['content_terminal'],
                'ban_booking' => is_array($row['ban_booking'] ?? null) ? $row['ban_booking'] : [],
                'ban_online' => is_array($row['ban_online'] ?? null) ? $row['ban_online'] : [],
                'ban_procs' => is_array($row['ban_procs'] ?? null) ? $row['ban_procs'] : [],
                'loss' => (float)$row['loss'],
                'loss_fix' => (float)$row['loss_fix'],
                'offers' => (int)$row['offers'],
                'audio_hour_rate' => (float)($row['audio_hour_rate'] ?? 0),
                'script_score_rate' => (float)($row['script_score_rate'] ?? 0),
            ]);
            $done++;
        }
        return $done;
    }

    /**
     * Ensure every procedure type key exists (0) in content maps.
     * @param list<int> $ids
     * @return int updated count
     */
    public static function zerofillByIds(array $ids): int
    {
        $typeIds = array_keys(ProcedureTypesRepository::names());
        $done = 0;
        foreach (self::sanitizeIds($ids) as $id) {
            $row = self::find($id);
            if (!$row) {
                continue;
            }
            foreach (self::CONTENT_KEYS as $key) {
                $map = is_array($row[$key] ?? null) ? $row[$key] : [];
                foreach ($typeIds as $tid) {
                    if (!array_key_exists($tid, $map)) {
                        $map[$tid] = 0.0;
                    }
                }
                $row[$key] = $map;
            }
            self::update($id, [
                'operator_id' => (int)$row['operator_id'],
                'dt' => (int)$row['dt'],
                'descr' => (string)$row['descr'],
                'content' => $row['content'],
                'content_sales' => $row['content_sales'],
                'content_income' => $row['content_income'],
                'content_terminal' => $row['content_terminal'],
                'ban_booking' => is_array($row['ban_booking'] ?? null) ? $row['ban_booking'] : [],
                'ban_online' => is_array($row['ban_online'] ?? null) ? $row['ban_online'] : [],
                'ban_procs' => is_array($row['ban_procs'] ?? null) ? $row['ban_procs'] : [],
                'loss' => (float)$row['loss'],
                'loss_fix' => (float)$row['loss_fix'],
                'offers' => (int)$row['offers'],
                'audio_hour_rate' => (float)($row['audio_hour_rate'] ?? 0),
                'script_score_rate' => (float)($row['script_score_rate'] ?? 0),
            ]);
            $done++;
        }
        return $done;
    }

    /**
     * @param array<int,float|int|string> $map
     * @param array<int,string> $typeNames
     */
    public static function formatContentPreview(array $map, array $typeNames): string
    {
        $parts = [];
        foreach ($map as $tid => $val) {
            $v = (float)str_replace(',', '.', (string)$val);
            if ($v == 0.0) {
                continue;
            }
            $label = $typeNames[(int)$tid] ?? ('#' . (int)$tid);
            $short = explode(' — ', $label)[0];
            $parts[] = $short . ':' . rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
        }
        return implode(', ', $parts);
    }

    /**
     * Apply zero-% type bans before INSERT/UPDATE (all write paths).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalizeWriteData(array $data): array
    {
        if (!self::hasBanColumns()) {
            return $data;
        }
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        $applied = self::applyZeroPctTypeBans(
            $content,
            is_array($data['ban_booking'] ?? null) ? $data['ban_booking'] : [],
            is_array($data['ban_online'] ?? null) ? $data['ban_online'] : []
        );
        $data['ban_booking'] = $applied['ban_booking'];
        $data['ban_online'] = $applied['ban_online'];
        if (!isset($data['ban_procs']) || !is_array($data['ban_procs'])) {
            $data['ban_procs'] = [];
        }
        return $data;
    }

    /** @param array<string,mixed> $row */
    private static function normalizeRow(array $row): array
    {
        foreach (self::CONTENT_KEYS as $key) {
            $raw = $row[$key] ?? '';
            if (is_array($raw)) {
                $row[$key] = $raw;
                continue;
            }
            $un = @unserialize((string)$raw, ['allowed_classes' => false]);
            $row[$key] = is_array($un) ? $un : [];
        }
        foreach (array_merge(self::BAN_TYPE_KEYS, [self::BAN_PROC_KEY]) as $key) {
            if (!array_key_exists($key, $row)) {
                $row[$key] = [];
                continue;
            }
            $raw = $row[$key];
            if (is_array($raw)) {
                $row[$key] = $raw;
                continue;
            }
            $un = @unserialize((string)$raw, ['allowed_classes' => false]);
            $row[$key] = is_array($un) ? $un : [];
        }
        $row['id'] = (int)($row['id'] ?? 0);
        $row['operator_id'] = (int)($row['operator_id'] ?? 0);
        $row['dt'] = (int)($row['dt'] ?? 0);
        $row['loss'] = (float)($row['loss'] ?? 0);
        $row['loss_fix'] = (float)($row['loss_fix'] ?? 0);
        $row['offers'] = (int)($row['offers'] ?? 0);
        $row['audio_hour_rate'] = (float)($row['audio_hour_rate'] ?? 0);
        $row['script_score_rate'] = (float)($row['script_score_rate'] ?? 0);
        return $row;
    }

    public static function hasAudioRateColumns(): bool
    {
        return Database::tableExists('base_procent_history')
            && Database::columnExists('base_procent_history', 'AUDIO_HOUR_RATE')
            && Database::columnExists('base_procent_history', 'SCRIPT_SCORE_RATE');
    }

    public static function hasBanColumns(): bool
    {
        return Database::tableExists('base_procent_history')
            && Database::columnExists('base_procent_history', 'ban_booking')
            && Database::columnExists('base_procent_history', 'ban_online');
    }

    public static function hasBanProcsColumn(): bool
    {
        return Database::tableExists('base_procent_history')
            && Database::columnExists('base_procent_history', 'ban_procs');
    }

    /**
     * Leading comma fragment for SELECT lists, or empty when columns absent.
     */
    private static function audioRateSelectSql(string $alias): string
    {
        if (!self::hasAudioRateColumns()) {
            return '';
        }
        $p = $alias !== '' ? $alias . '.' : '';
        return ", {$p}AUDIO_HOUR_RATE AS audio_hour_rate, {$p}SCRIPT_SCORE_RATE AS script_score_rate";
    }

    private static function banSelectSql(string $alias): string
    {
        if (!self::hasBanColumns()) {
            return '';
        }
        $p = $alias !== '' ? $alias . '.' : '';
        $sql = ", {$p}ban_booking, {$p}ban_online";
        if (self::hasBanProcsColumn()) {
            $sql .= ", {$p}ban_procs";
        }
        return $sql;
    }

    private static function parseDt(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            return (int)$raw;
        }
        $ts = strtotime($raw);
        return $ts !== false ? $ts : 0;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function sanitizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
    }
}
