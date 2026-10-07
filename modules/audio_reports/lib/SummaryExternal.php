<?php
declare(strict_types=1);

/**
 * External table aggregations for summary reports (oper rs_sql_* / rs_normalize_external_* / rs_fetch_external_source).
 */
final class SummaryExternal
{
    public static function sqlIdent(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            return '';
        }
        return $name;
    }

    /**
     * Extra WHERE fragment for external sources.
     * @return string|false empty string OK; false if forbidden content
     */
    public static function sqlWhereExtra(string $where)
    {
        $where = trim($where);
        if ($where === '') {
            return '';
        }
        if (preg_match('/[;`\\\\]|--|\/\*|\*\//', $where)) {
            return false;
        }
        if (preg_match('/\b(union|select|insert|update|delete|drop|alter|create|truncate|replace|outfile|dumpfile|load_file|information_schema|benchmark|sleep|handler|grant|revoke|into|call|execute|prepare|set|show|describe|explain|use|lock|unlock|kill)\b/i', $where)) {
            return false;
        }
        return $where;
    }

    /**
     * @param mixed $src
     * @return array<string,mixed>
     */
    public static function normalizeSource($src, int $idx = 0): array
    {
        if (!is_array($src)) {
            $src = [];
        }
        $title = isset($src['title']) ? trim((string)$src['title']) : '';
        $table = self::sqlIdent(isset($src['table']) ? (string)$src['table'] : '');
        $empField = self::sqlIdent(isset($src['emp_field']) ? (string)$src['emp_field'] : '');
        $dateField = self::sqlIdent(isset($src['date_field']) ? (string)$src['date_field'] : '');
        $valueField = self::sqlIdent(isset($src['value_field']) ? (string)$src['value_field'] : '');
        $agg = isset($src['agg']) ? strtolower(trim((string)$src['agg'])) : '';
        if ($agg === '' || !in_array($agg, ['sum', 'count', 'avg'], true)) {
            $agg = ($valueField !== '') ? 'sum' : 'count';
        }
        if ($agg === 'count') {
            $valueField = '';
        }
        $whereRaw = isset($src['where']) ? trim((string)$src['where']) : '';
        $whereOk = self::sqlWhereExtra($whereRaw);
        $where = ($whereOk === false) ? '' : $whereOk;
        $key = isset($src['key']) ? preg_replace('/[^A-Za-z0-9_]/', '', (string)$src['key']) : '';
        if ($key === '') {
            $key = 'ext_' . $idx;
        }
        $unit = isset($src['unit']) ? trim((string)$src['unit']) : '';
        $unit = mb_substr($unit, 0, 24, 'UTF-8');
        $decimals = isset($src['decimals']) ? (int)$src['decimals'] : 0;
        if ($decimals < 0) {
            $decimals = 0;
        }
        if ($decimals > 4) {
            $decimals = 4;
        }
        $tip = isset($src['tip']) ? trim((string)$src['tip']) : '';
        $tip = mb_substr($tip, 0, 400, 'UTF-8');

        $out = [
            'key' => $key,
            'title' => ($title !== '' ? $title : ('External data #' . ($idx + 1))),
            'table' => $table,
            'emp_field' => $empField,
            'date_field' => $dateField,
            'value_field' => $valueField,
            'agg' => $agg,
            'where' => $where,
            'where_invalid' => ($whereOk === false) ? 1 : 0,
            'show_card' => array_key_exists('show_card', $src) ? (!empty($src['show_card']) ? 1 : 0) : 1,
            'show_detail' => array_key_exists('show_detail', $src) ? (!empty($src['show_detail']) ? 1 : 0) : 1,
            'unit' => $unit,
            'decimals' => $decimals,
            'tip' => $tip,
            '_valid' => ($table !== '' && $empField !== '' && $dateField !== '' && ($agg === 'count' || $valueField !== '')),
        ];
        return $out;
    }

    /**
     * @param mixed $list
     * @return list<array<string,mixed>>
     */
    public static function normalizeSources($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        $i = 0;
        foreach ($list as $src) {
            $n = self::normalizeSource($src, $i);
            if (empty($n['_valid'])) {
                continue;
            }
            unset($n['_valid']);
            $base = (string)$n['key'];
            $k = $base;
            $nTry = 1;
            while (isset($out[$k])) {
                $k = $base . '_' . $nTry;
                $nTry++;
            }
            $n['key'] = $k;
            $out[$k] = $n;
            $i++;
        }
        return array_values($out);
    }

    /**
     * @param array<string,mixed> $req
     * @return list<array<string,mixed>>
     */
    public static function parseFromRequest(array $req): array
    {
        if (!isset($req['external_sources']) || !is_array($req['external_sources'])) {
            return [];
        }
        $list = [];
        foreach ($req['external_sources'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($row['show_card'])) {
                $row['show_card'] = 0;
            }
            if (!isset($row['show_detail'])) {
                $row['show_detail'] = 0;
            }
            $list[] = $row;
        }
        return $list;
    }

    /**
     * Aggregate one external table for employees in period.
     *
     * @param array<string,mixed> $src
     * @param list<int>|string $empIds employee ids or comma-separated list
     * @return array{
     *   key:string,title:string,by_emp:array<int,float>,by_emp_day:array<int,array<string,float>>,
     *   total:float|int,rows_count:int,error:string,meta:array<string,mixed>
     * }
     */
    public static function fetchOne(array $src, $empIds, int $periodStart, int $periodEnd): array
    {
        $src = self::normalizeSource($src);
        $meta = $src;
        unset($meta['_valid']);
        $result = [
            'key' => (string)$src['key'],
            'title' => (string)$src['title'],
            'by_emp' => [],
            'by_emp_day' => [],
            'total' => 0,
            'rows_count' => 0,
            'error' => '',
            'meta' => $meta,
        ];
        if (empty($src['_valid'])) {
            $result['error'] = 'Missing table / employee field / date field (or value for sum/avg)';
            return $result;
        }
        if (!empty($src['where_invalid'])) {
            $result['error'] = 'Invalid WHERE clause';
            return $result;
        }
        if ($src['agg'] !== 'count' && $src['value_field'] === '') {
            $result['error'] = 'Value field required for sum/avg';
            return $result;
        }

        $table = (string)$src['table'];
        $empF = (string)$src['emp_field'];
        $dateF = (string)$src['date_field'];
        $valF = (string)$src['value_field'];
        $agg = (string)$src['agg'];

        if (!self::probeTable($table)) {
            $result['error'] = 'Table unavailable: ' . $table;
            return $result;
        }

        $empList = self::empListSql($empIds);
        $whereBase = ' WHERE `' . $empF . '` IN (' . $empList . ')'
            . ' AND `' . $dateF . '` >= ' . (int)$periodStart
            . ' AND `' . $dateF . '` <= ' . (int)$periodEnd;
        if ($src['where'] !== '') {
            $whereBase .= ' AND (' . $src['where'] . ')';
        }

        if ($agg === 'count') {
            $sqlRows = 'SELECT `' . $empF . '` AS emp_id, `' . $dateF . '` AS dt FROM `' . $table . '`' . $whereBase;
        } else {
            $sqlRows = 'SELECT `' . $empF . '` AS emp_id, `' . $dateF . '` AS dt, `' . $valF . '` AS val FROM `' . $table . '`' . $whereBase;
        }

        $pdo = Database::pdo();
        try {
            $st = $pdo->query($sqlRows);
        } catch (Throwable $e) {
            $result['error'] = 'SQL error: ' . $e->getMessage();
            return $result;
        }
        if (!$st) {
            $result['error'] = 'SQL error';
            return $result;
        }

        $byEmp = [];
        $byEmpDay = [];
        $byEmpDayCnt = [];
        $rowsCnt = 0;
        $total = 0.0;
        $totalSum = 0.0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $empId = (int)$row['emp_id'];
            $dt = (int)$row['dt'];
            if ($dt <= 0) {
                continue;
            }
            $dayKey = date('Y-m-d', $dt);
            $val = ($agg === 'count') ? 1.0 : (float)$row['val'];
            $rowsCnt++;
            if (!isset($byEmpDay[$empId])) {
                $byEmpDay[$empId] = [];
            }
            if (!isset($byEmpDay[$empId][$dayKey])) {
                $byEmpDay[$empId][$dayKey] = 0.0;
            }
            if ($agg === 'avg') {
                if (!isset($byEmpDayCnt[$empId])) {
                    $byEmpDayCnt[$empId] = [];
                }
                if (!isset($byEmpDayCnt[$empId][$dayKey])) {
                    $byEmpDayCnt[$empId][$dayKey] = 0;
                }
                $byEmpDay[$empId][$dayKey] += $val;
                $byEmpDayCnt[$empId][$dayKey]++;
                $totalSum += $val;
            } else {
                $byEmpDay[$empId][$dayKey] += $val;
                if (!isset($byEmp[$empId])) {
                    $byEmp[$empId] = 0.0;
                }
                $byEmp[$empId] += $val;
                $total += $val;
            }
        }

        if ($agg === 'avg') {
            foreach ($byEmpDay as $empId => $days) {
                foreach ($days as $dayKey => $sum) {
                    $cnt = isset($byEmpDayCnt[$empId][$dayKey]) ? (int)$byEmpDayCnt[$empId][$dayKey] : 0;
                    $byEmpDay[$empId][$dayKey] = ($cnt > 0) ? ($sum / $cnt) : 0.0;
                }
            }
            $sqlEmp = 'SELECT `' . $empF . '` AS emp_id, AVG(`' . $valF . '`) AS val, COUNT(*) AS cnt FROM `' . $table . '`'
                . $whereBase . ' GROUP BY `' . $empF . '`';
            try {
                $resEmp = $pdo->query($sqlEmp);
                if ($resEmp) {
                    while ($row = $resEmp->fetch(PDO::FETCH_ASSOC)) {
                        $byEmp[(int)$row['emp_id']] = (float)$row['val'];
                    }
                }
            } catch (Throwable $e) {
                // keep day averages; emp totals stay empty
            }
            $total = ($rowsCnt > 0) ? ($totalSum / $rowsCnt) : 0.0;
        }

        $result['by_emp'] = $byEmp;
        $result['by_emp_day'] = $byEmpDay;
        $result['total'] = $total;
        $result['rows_count'] = $rowsCnt;
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $sources
     * @param list<int>|string $empIds
     * @return list<array<string,mixed>>
     */
    public static function fetchAll(array $sources, $empIds, int $periodStart, int $periodEnd): array
    {
        $out = [];
        foreach ($sources as $src) {
            if (!is_array($src)) {
                continue;
            }
            $out[] = self::fetchOne($src, $empIds, $periodStart, $periodEnd);
        }
        return $out;
    }

    private static function probeTable(string $table): bool
    {
        if ($table === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            return false;
        }
        if (Database::tableExists($table)) {
            return true;
        }
        try {
            $st = Database::pdo()->query('SELECT 1 FROM `' . $table . '` LIMIT 0');
            return (bool)$st;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @param list<int>|string $empIds */
    private static function empListSql($empIds): string
    {
        if (is_string($empIds)) {
            $empIds = trim($empIds);
            return $empIds !== '' ? $empIds : '0';
        }
        if (!is_array($empIds) || $empIds === []) {
            return '0';
        }
        return implode(',', array_map('intval', $empIds));
    }
}
