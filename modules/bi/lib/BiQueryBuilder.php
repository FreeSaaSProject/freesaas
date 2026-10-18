<?php
declare(strict_types=1);

/**
 * Build prepared SQL from a recipe query node + dataset catalog (whitelist only).
 */
final class BiQueryBuilder
{
    /** Allow month-grain guest/procedure frames (busy spa months). */
    public const MAX_LIMIT = 50000;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $params normalized ReportParams + optional bound overrides
     * @return array{sql:string,args:list<mixed>,aliases:list<string>}
     */
    public static function build(array $query, array $params): array
    {
        $fromId = (string)($query['from'] ?? '');
        $ds = BiDatasetCatalog::get($fromId);
        if ($ds === null) {
            throw new InvalidArgumentException('unknown_dataset:' . $fromId);
        }
        if (!Database::tableExists((string)$ds['requires_table'])) {
            throw new RuntimeException('dataset_unavailable:' . $fromId);
        }

        $fromAlias = 't0';
        $joinsSql = [];
        $joinMeta = []; // dataset id => alias
        $joinMeta[$fromId] = $fromAlias;
        $joinIdx = 1;

        $joinList = is_array($query['join'] ?? null) ? $query['join'] : [];
        foreach ($joinList as $j) {
            $toId = (string)($j['dataset'] ?? '');
            $left = (string)($j['left'] ?? '');
            $right = (string)($j['right'] ?? '');
            if (BiDatasetCatalog::findJoin($fromId, $toId, $left, $right) === null) {
                throw new InvalidArgumentException('join_not_allowed:' . $fromId . '->' . $toId);
            }
            $rightDs = BiDatasetCatalog::get($toId);
            if ($rightDs === null || !Database::tableExists((string)$rightDs['requires_table'])) {
                throw new RuntimeException('dataset_unavailable:' . $toId);
            }
            $alias = 't' . $joinIdx++;
            $joinMeta[$toId] = $alias;
            $leftCol = BiDatasetCatalog::resolveColumn($ds, $left);
            $rightCol = BiDatasetCatalog::resolveColumn($rightDs, $right);
            if ($leftCol === null || $rightCol === null) {
                throw new InvalidArgumentException('join_column_missing');
            }
            $joinsSql[] = 'LEFT JOIN `' . self::ident($rightDs['requires_table']) . '` ' . $alias
                . ' ON ' . $fromAlias . '.`' . self::ident($leftCol) . '` = ' . $alias . '.`' . self::ident($rightCol) . '`';
        }

        $args = [];
        $whereParts = [];

        // Period injection (skip when query sets period: false)
        $periodField = $ds['period_field'] ?? null;
        $periodKind = $ds['period_kind'] ?? null;
        $applyPeriod = ($query['period'] ?? true) !== false;
        if ($applyPeriod && is_string($periodField) && $periodField !== '' && is_string($periodKind)) {
            $bounds = self::boundsFor($params, $periodKind);
            if ($bounds !== null) {
                $col = $fromAlias . '.`' . self::ident($periodField) . '`';
                if ($periodKind === 'overlap_dt') {
                    $endField = (string)($ds['period_end_field'] ?? '');
                    if ($endField === '') {
                        throw new InvalidArgumentException('overlap_needs_period_end_field');
                    }
                    $endCol = $fromAlias . '.`' . self::ident($endField) . '`';
                    // Range overlap: end >= period_start AND start <= period_end
                    $whereParts[] = $endCol . ' >= ? AND ' . $col . ' <= ?';
                } elseif ($periodKind === 'half_open') {
                    $whereParts[] = $col . ' > ? AND ' . $col . ' < ?';
                } else {
                    // inclusive_dt / date_ymd
                    $whereParts[] = $col . ' >= ? AND ' . $col . ' <= ?';
                }
                $args[] = $bounds['start'];
                $args[] = $bounds['end'];
            }
        }

        // Run-param filters
        $filters = is_array($ds['filters'] ?? null) ? $ds['filters'] : [];
        $map = [
            'operator' => 'id_operator',
            'admin' => 'id_admin',
            'good' => 'id_good',
        ];
        foreach ($map as $filterKey => $paramKey) {
            if (!isset($filters[$filterKey])) {
                continue;
            }
            $val = (int)($params[$paramKey] ?? 0);
            if ($val > 0) {
                $whereParts[] = $fromAlias . '.`' . self::ident((string)$filters[$filterKey]) . '` = ?';
                $args[] = $val;
            }
        }

        $whereList = is_array($query['where'] ?? null) ? $query['where'] : [];
        foreach ($whereList as $w) {
            [$frag, $wArgs] = self::whereClause($w, $fromAlias, $ds, $joinMeta);
            $whereParts[] = $frag;
            foreach ($wArgs as $a) {
                $args[] = $a;
            }
        }

        $selectList = is_array($query['select'] ?? null) ? $query['select'] : [];
        if ($selectList === []) {
            throw new InvalidArgumentException('select_empty');
        }
        $selectSql = [];
        $aliases = [];
        $hasAgg = false;
        foreach ($selectList as $sel) {
            if (!is_array($sel)) {
                throw new InvalidArgumentException('select_item');
            }
            if (isset($sel['agg'])) {
                $hasAgg = true;
                $agg = strtolower((string)$sel['agg']);
                if (!in_array($agg, ['count', 'sum', 'avg', 'min', 'max', 'count_distinct'], true)) {
                    throw new InvalidArgumentException('bad_agg:' . $agg);
                }
                $as = self::aliasName((string)($sel['as'] ?? $agg));
                if ($agg === 'count' && empty($sel['field'])) {
                    $selectSql[] = 'COUNT(*) AS `' . self::ident($as) . '`';
                } elseif ($agg === 'count_distinct') {
                    $ref = self::fieldSql((string)($sel['field'] ?? ''), $fromId, $fromAlias, $ds, $joinMeta);
                    $selectSql[] = 'COUNT(DISTINCT ' . $ref . ') AS `' . self::ident($as) . '`';
                } else {
                    $ref = self::fieldSql((string)($sel['field'] ?? ''), $fromId, $fromAlias, $ds, $joinMeta);
                    $fn = strtoupper($agg === 'count' ? 'count' : $agg);
                    $selectSql[] = $fn . '(' . $ref . ') AS `' . self::ident($as) . '`';
                }
                $aliases[] = $as;
            } else {
                $field = (string)($sel['field'] ?? '');
                $as = self::aliasName((string)($sel['as'] ?? self::defaultAlias($field)));
                $ref = self::fieldSql($field, $fromId, $fromAlias, $ds, $joinMeta);
                $selectSql[] = $ref . ' AS `' . self::ident($as) . '`';
                $aliases[] = $as;
            }
        }

        $sql = 'SELECT ' . implode(', ', $selectSql)
            . ' FROM `' . self::ident($ds['requires_table']) . '` ' . $fromAlias;
        if ($joinsSql !== []) {
            $sql .= ' ' . implode(' ', $joinsSql);
        }
        if ($whereParts !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }

        $groupBy = is_array($query['group_by'] ?? null) ? $query['group_by'] : [];
        if ($groupBy !== []) {
            $gParts = [];
            foreach ($groupBy as $g) {
                $g = (string)$g;
                // group_by may reference select aliases from joins (e.g. admin_name)
                if (in_array($g, $aliases, true)) {
                    $gParts[] = '`' . self::ident($g) . '`';
                } else {
                    $gParts[] = self::fieldSql($g, $fromId, $fromAlias, $ds, $joinMeta);
                }
            }
            $sql .= ' GROUP BY ' . implode(', ', $gParts);
        } elseif ($hasAgg && count($selectList) > 1) {
            // pure aggregate with only agg selects — ok without GROUP BY
        }

        $having = is_array($query['having'] ?? null) ? $query['having'] : [];
        if ($having !== []) {
            $hParts = [];
            foreach ($having as $h) {
                $field = (string)($h['field'] ?? '');
                $op = (string)($h['op'] ?? '=');
                self::assertCompareOp($op);
                if ($op === 'in' || $op === 'not_in') {
                    $vals = is_array($h['value'] ?? null) ? $h['value'] : [];
                    if ($vals === []) {
                        throw new InvalidArgumentException('having_in_empty');
                    }
                    $ph = implode(',', array_fill(0, count($vals), '?'));
                    $hParts[] = '`' . self::ident($field) . '` ' . ($op === 'in' ? 'IN' : 'NOT IN') . ' (' . $ph . ')';
                    foreach ($vals as $v) {
                        $args[] = $v;
                    }
                } else {
                    $hParts[] = '`' . self::ident($field) . '` ' . $op . ' ?';
                    $args[] = $h['value'] ?? null;
                }
            }
            $sql .= ' HAVING ' . implode(' AND ', $hParts);
        }

        $orderBy = is_array($query['order_by'] ?? null) ? $query['order_by'] : [];
        if ($orderBy !== []) {
            $oParts = [];
            foreach ($orderBy as $o) {
                $field = (string)($o['field'] ?? '');
                $dir = strtoupper((string)($o['dir'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';
                if (in_array($field, $aliases, true)) {
                    $oParts[] = '`' . self::ident($field) . '` ' . $dir;
                } else {
                    $oParts[] = self::fieldSql($field, $fromId, $fromAlias, $ds, $joinMeta) . ' ' . $dir;
                }
            }
            $sql .= ' ORDER BY ' . implode(', ', $oParts);
        }

        $limit = (int)($query['limit'] ?? self::MAX_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $sql .= ' LIMIT ' . $limit;

        return ['sql' => $sql, 'args' => $args, 'aliases' => $aliases];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{start:mixed,end:mixed}|null
     */
    public static function boundsFor(array $params, string $periodKind): ?array
    {
        $year = (int)($params['year'] ?? date('Y'));
        $month = (int)($params['month'] ?? date('n'));
        $day = (int)($params['_day'] ?? 0);

        if ($day > 0) {
            $startTs = mktime(0, 0, 0, $month, $day, $year);
            $endTs = mktime(23, 59, 59, $month, $day, $year);
        } else {
            $b = ReportParams::monthBounds($year, $month);
            $startTs = (int)$b['start'];
            $endTs = (int)$b['end'];
        }

        if ($periodKind === 'date_ymd') {
            return [
                'start' => date('Y-m-d', $startTs),
                'end' => date('Y-m-d', $endTs),
            ];
        }
        // inclusive_dt / half_open use unix timestamps (legacy likespa)
        return ['start' => $startTs, 'end' => $endTs];
    }

    /**
     * @param array<string,mixed> $w
     * @param array<string,string> $joinMeta
     * @return array{0:string,1:list<mixed>}
     */
    private static function whereClause(array $w, string $fromAlias, array $ds, array $joinMeta): array
    {
        $field = (string)($w['field'] ?? '');
        $op = (string)($w['op'] ?? '=');
        self::assertCompareOp($op);
        $fromId = (string)$ds['id'];
        $ref = self::fieldSql($field, $fromId, $fromAlias, $ds, $joinMeta);
        if ($op === 'in' || $op === 'not_in') {
            $vals = is_array($w['value'] ?? null) ? $w['value'] : [];
            if ($vals === []) {
                throw new InvalidArgumentException('where_in_empty');
            }
            $ph = implode(',', array_fill(0, count($vals), '?'));
            return [$ref . ' ' . ($op === 'in' ? 'IN' : 'NOT IN') . ' (' . $ph . ')', array_values($vals)];
        }
        return [$ref . ' ' . $op . ' ?', [$w['value'] ?? null]];
    }

    /**
     * @param array<string,string> $joinMeta
     */
    private static function fieldSql(
        string $field,
        string $fromId,
        string $fromAlias,
        array $ds,
        array $joinMeta
    ): string {
        $field = trim($field);
        if ($field === '') {
            throw new InvalidArgumentException('empty_field');
        }
        if (strpos($field, '.') !== false) {
            [$dsId, $fid] = explode('.', $field, 2);
            if (!isset($joinMeta[$dsId])) {
                throw new InvalidArgumentException('field_dataset_not_joined:' . $dsId);
            }
            $jds = BiDatasetCatalog::get($dsId);
            if ($jds === null) {
                throw new InvalidArgumentException('unknown_dataset:' . $dsId);
            }
            $col = BiDatasetCatalog::resolveColumn($jds, $fid);
            if ($col === null) {
                throw new InvalidArgumentException('unknown_field:' . $field);
            }
            return $joinMeta[$dsId] . '.`' . self::ident($col) . '`';
        }
        $col = BiDatasetCatalog::resolveColumn($ds, $field);
        if ($col === null) {
            throw new InvalidArgumentException('unknown_field:' . $field);
        }
        return $fromAlias . '.`' . self::ident($col) . '`';
    }

    private static function assertCompareOp(string $op): void
    {
        if (!in_array($op, ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in'], true)) {
            throw new InvalidArgumentException('bad_compare_op:' . $op);
        }
    }

    private static function aliasName(string $as): string
    {
        $as = preg_replace('/[^a-zA-Z0-9_]/', '', $as) ?? '';
        if ($as === '' || !preg_match('/^[a-zA-Z_]/', $as)) {
            throw new InvalidArgumentException('bad_alias');
        }
        return $as;
    }

    private static function defaultAlias(string $field): string
    {
        if (strpos($field, '.') !== false) {
            $parts = explode('.', $field);
            $field = end($parts) ?: $field;
        }
        return self::aliasName($field);
    }

    private static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('bad_ident:' . $name);
        }
        return $name;
    }
}
