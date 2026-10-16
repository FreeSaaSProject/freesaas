<?php
declare(strict_types=1);

final class RatesRepository
{
    private const CONTENT_KEYS = ['content', 'content_sales', 'content_income', 'content_terminal'];

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
        $sql = "SELECT h.ID AS id, h.ID_OPERATOR AS operator_id, h.DT AS dt, h.DESCR AS descr,
                       h.content, h.content_sales, h.content_income, h.content_terminal,
                       h.LOSS AS loss, h.LOSS_FIX AS loss_fix, h.OFFERS AS offers
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
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, ID_OPERATOR AS operator_id, DT AS dt, DESCR AS descr,
                    content, content_sales, content_income, content_terminal,
                    LOSS AS loss, LOSS_FIX AS loss_fix, OFFERS AS offers
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
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, ID_OPERATOR AS operator_id, DT AS dt, DESCR AS descr,
                    content, content_sales, content_income, content_terminal,
                    LOSS AS loss, LOSS_FIX AS loss_fix, OFFERS AS offers
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
        $params = array_merge($ids, [$ts], $ids);
        $st = Database::pdo()->prepare(
            "SELECT h.ID AS id, h.ID_OPERATOR AS operator_id, h.DT AS dt, h.DESCR AS descr,
                    h.content, h.content_sales, h.content_income, h.content_terminal,
                    h.LOSS AS loss, h.LOSS_FIX AS loss_fix, h.OFFERS AS offers
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

        return [
            'operator_id' => $operatorId,
            'dt' => $dt,
            'descr' => $descr,
            'content' => $maps['content'],
            'content_sales' => $maps['content_sales'],
            'content_income' => $maps['content_income'],
            'content_terminal' => $maps['content_terminal'],
            'loss' => (float)str_replace(',', '.', (string)($post['loss'] ?? '0')),
            'loss_fix' => (float)str_replace(',', '.', (string)($post['loss_fix'] ?? '0')),
            'offers' => (int)($post['offers'] ?? 0),
            'audio_hour_rate' => (float)str_replace(',', '.', (string)($post['audio_hour_rate'] ?? '0')),
            'script_score_rate' => (float)str_replace(',', '.', (string)($post['script_score_rate'] ?? '0')),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $hasAudio = self::hasAudioRateColumns();
        if ($hasAudio) {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_procent_history
                 (ID_OPERATOR, DT, DESCR, content, content_sales, content_income, content_terminal,
                  LOSS, LOSS_FIX, OFFERS, AUDIO_HOUR_RATE, SCRIPT_SCORE_RATE)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $data['operator_id'],
                $data['dt'],
                $data['descr'],
                serialize($data['content']),
                serialize($data['content_sales']),
                serialize($data['content_income']),
                serialize($data['content_terminal']),
                $data['loss'],
                $data['loss_fix'],
                $data['offers'],
                (float)($data['audio_hour_rate'] ?? 0),
                (float)($data['script_score_rate'] ?? 0),
            ]);
        } else {
            $st = Database::pdo()->prepare(
                'INSERT INTO base_procent_history
                 (ID_OPERATOR, DT, DESCR, content, content_sales, content_income, content_terminal, LOSS, LOSS_FIX, OFFERS)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $data['operator_id'],
                $data['dt'],
                $data['descr'],
                serialize($data['content']),
                serialize($data['content_sales']),
                serialize($data['content_income']),
                serialize($data['content_terminal']),
                $data['loss'],
                $data['loss_fix'],
                $data['offers'],
            ]);
        }
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid rate id');
        }
        $hasAudio = self::hasAudioRateColumns();
        if ($hasAudio) {
            $st = Database::pdo()->prepare(
                'UPDATE base_procent_history SET
                 ID_OPERATOR = ?, DT = ?, DESCR = ?,
                 content = ?, content_sales = ?, content_income = ?, content_terminal = ?,
                 LOSS = ?, LOSS_FIX = ?, OFFERS = ?,
                 AUDIO_HOUR_RATE = ?, SCRIPT_SCORE_RATE = ?
                 WHERE ID = ?'
            );
            $st->execute([
                $data['operator_id'],
                $data['dt'],
                $data['descr'],
                serialize($data['content']),
                serialize($data['content_sales']),
                serialize($data['content_income']),
                serialize($data['content_terminal']),
                $data['loss'],
                $data['loss_fix'],
                $data['offers'],
                (float)($data['audio_hour_rate'] ?? 0),
                (float)($data['script_score_rate'] ?? 0),
                $id,
            ]);
        } else {
            $st = Database::pdo()->prepare(
                'UPDATE base_procent_history SET
                 ID_OPERATOR = ?, DT = ?, DESCR = ?,
                 content = ?, content_sales = ?, content_income = ?, content_terminal = ?,
                 LOSS = ?, LOSS_FIX = ?, OFFERS = ?
                 WHERE ID = ?'
            );
            $st->execute([
                $data['operator_id'],
                $data['dt'],
                $data['descr'],
                serialize($data['content']),
                serialize($data['content_sales']),
                serialize($data['content_income']),
                serialize($data['content_terminal']),
                $data['loss'],
                $data['loss_fix'],
                $data['offers'],
                $id,
            ]);
        }
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
