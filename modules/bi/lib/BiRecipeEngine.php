<?php
declare(strict_types=1);

/**
 * Execute a validated BI JSON recipe → report sections.
 */
final class BiRecipeEngine
{
    public const MAX_QUERIES = 40;
    public const MAX_DERIVE_STEPS = 60;
    public const MAX_FRAME_ROWS = 5000;
    public const MAX_DERIVE_ROWS = 25000;
    public const MAX_CROSS_CELLS = 100000;
    public const MAX_RUNTIME_SEC = 20;

    /** @var int */
    private static $budgetStartedAt = 0;

    /**
     * @param array<string,mixed> $recipe
     * @param array<string,mixed> $params normalized ReportParams
     * @return array{title:string,sections:list<array<string,mixed>>,meta?:array<string,mixed>}
     */
    public static function run(array $recipe, array $params): array
    {
        self::$budgetStartedAt = time();
        $frames = [];
        $queries = is_array($recipe['queries'] ?? null) ? $recipe['queries'] : [];
        if (count($queries) > self::MAX_QUERIES) {
            throw new InvalidArgumentException('recipe_too_many_queries');
        }
        foreach ($queries as $q) {
            self::tickBudget();
            $as = (string)($q['as'] ?? '');
            if ($as === '') {
                throw new InvalidArgumentException('query_as_missing');
            }
            ReportProgress::note('Query: ' . $as);
            $frames[$as] = self::capRows(self::runQuery($q, $params), 'query:' . $as);
            ReportProgress::mark($as . ': ' . count($frames[$as]) . ' rows');
        }

        $derive = is_array($recipe['derive'] ?? null) ? $recipe['derive'] : [];
        if (count($derive) > self::MAX_DERIVE_STEPS) {
            throw new InvalidArgumentException('recipe_too_many_derive');
        }
        foreach ($derive as $d) {
            self::tickBudget();
            $as = (string)($d['as'] ?? '');
            $from = (string)($d['from'] ?? '');
            if ($as === '' || $from === '' || !isset($frames[$from])) {
                throw new InvalidArgumentException('derive_from_missing:' . $from);
            }
            ReportProgress::note('Derive: ' . $as);
            $frames[$as] = self::capRows(self::runDerive($d, $frames, $params), 'derive:' . $as);
            ReportProgress::mark($as . ': ' . count($frames[$as]) . ' rows');
        }

        $sections = [];
        $secList = is_array($recipe['sections'] ?? null) ? $recipe['sections'] : [];
        foreach ($secList as $sec) {
            $type = (string)($sec['type'] ?? '');
            if ($type === 'metrics') {
                $sections[] = self::sectionMetrics($sec, $frames);
            } elseif ($type === 'table') {
                $sections[] = self::sectionTable($sec, $frames);
            } elseif ($type === 'pivot') {
                $sections[] = self::sectionPivot($sec, $params);
            } elseif ($type === 'text') {
                $errs = [];
                $body = BiText::sanitizeBody($sec['body'] ?? '', 'text.body', $errs);
                if ($body === null) {
                    throw new InvalidArgumentException($errs[0] ?? 'text_body_invalid');
                }
                $sec['body'] = $body;
                $sections[] = self::sectionText($sec);
            } else {
                throw new InvalidArgumentException('bad_section_type:' . $type);
            }
        }

        $title = (string)($recipe['title'] ?? $recipe['id'] ?? 'Report');
        $bounds = ReportParams::monthBounds((int)$params['year'], (int)$params['month']);
        return [
            'title' => $title,
            'sections' => $sections,
            'meta' => [
                'recipe_id' => (string)($recipe['id'] ?? ''),
                'recipe_v' => (int)($recipe['v'] ?? 1),
                'period' => $bounds['label'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function runQuery(array $query, array $params): array
    {
        $fromId = (string)($query['from'] ?? '');
        $ds = BiDatasetCatalog::get($fromId);
        if ($ds === null || !Database::tableExists((string)$ds['requires_table'])) {
            ReportProgress::note('Skip unavailable dataset: ' . $fromId);
            return [];
        }
        try {
            $built = BiQueryBuilder::build($query, $params);
        } catch (Throwable $e) {
            ReportProgress::note('Query build failed: ' . $e->getMessage());
            return [];
        }
        $st = Database::pdo()->prepare($built['sql']);
        $st->execute($built['args']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $norm = [];
            foreach ($row as $k => $v) {
                $norm[(string)$k] = is_numeric($v) && !is_string($v) ? $v + 0 : $v;
                if (is_string($v) && is_numeric($v)) {
                    $norm[(string)$k] = strpos($v, '.') !== false ? (float)$v : (int)$v;
                }
            }
            $out[] = $norm;
        }
        return $out;
    }

    /**
     * Derive pipeline: join_frame → filter → select/compute → group/aggs →
     * compute_after → window → sort → limit.
     *
     * @param array<string,mixed> $derive
     * @param array<string,list<array<string,mixed>>> $frames
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private static function runDerive(array $derive, array $frames, array $params): array
    {
        self::tickBudget();
        $from = (string)($derive['from'] ?? '');
        $rows = $frames[$from] ?? [];

        $join = is_array($derive['join_frame'] ?? null) ? $derive['join_frame'] : null;
        if ($join !== null) {
            $rows = self::joinFrame($rows, $join, $frames);
            self::tickBudget();
        }

        $bounds = ReportParams::monthBounds((int)$params['year'], (int)$params['month']);
        $periodCtx = [
            '_period_start' => (int)$bounds['start'],
            '_period_end' => (int)$bounds['end'],
            '_period_label' => (string)$bounds['label'],
        ];

        $filter = $derive['filter'] ?? null;
        if ($filter !== null) {
            $filtered = [];
            $n = 0;
            foreach ($rows as $row) {
                if ((++$n % 500) === 0) {
                    self::tickBudget();
                }
                $ctx = array_merge($periodCtx, $row);
                if (BiExpr::truthy(BiExpr::eval($filter, $ctx))) {
                    $filtered[] = $row;
                }
            }
            $rows = $filtered;
        }

        $select = $derive['select'] ?? ['*'];
        $compute = is_array($derive['compute'] ?? null) ? $derive['compute'] : [];
        $mapped = [];
        $n = 0;
        foreach ($rows as $row) {
            if ((++$n % 500) === 0) {
                self::tickBudget();
            }
            $ctx = array_merge($periodCtx, $row);
            if ($select === ['*'] || $select === '*') {
                $new = $row;
            } else {
                $new = [];
                foreach ((array)$select as $col) {
                    $col = (string)$col;
                    if (array_key_exists($col, $row)) {
                        $new[$col] = $row[$col];
                    }
                }
            }
            foreach ($compute as $c) {
                $as = (string)($c['as'] ?? '');
                if ($as === '') {
                    continue;
                }
                $new[$as] = BiExpr::eval($c['expr'] ?? null, array_merge($ctx, $new));
            }
            $mapped[] = $new;
        }
        $rows = $mapped;

        if (array_key_exists('group_by', $derive)) {
            $groupBy = is_array($derive['group_by']) ? $derive['group_by'] : [];
            $aggs = is_array($derive['aggs'] ?? null) ? $derive['aggs'] : [];
            $rows = self::groupReduce($rows, $groupBy, $aggs, $periodCtx);
        }

        $computeAfter = is_array($derive['compute_after'] ?? null) ? $derive['compute_after'] : [];
        if ($computeAfter !== []) {
            foreach ($rows as $i => $row) {
                $ctx = array_merge($periodCtx, $row);
                foreach ($computeAfter as $c) {
                    $as = (string)($c['as'] ?? '');
                    if ($as === '') {
                        continue;
                    }
                    $rows[$i][$as] = BiExpr::eval($c['expr'] ?? null, array_merge($ctx, $rows[$i]));
                }
            }
        }

        $having = $derive['having'] ?? null;
        if ($having !== null) {
            $filtered = [];
            foreach ($rows as $row) {
                $ctx = array_merge($periodCtx, $row);
                if (BiExpr::truthy(BiExpr::eval($having, $ctx))) {
                    $filtered[] = $row;
                }
            }
            $rows = $filtered;
        }

        $windows = is_array($derive['window'] ?? null) ? $derive['window'] : [];
        foreach ($windows as $w) {
            $rows = self::applyWindow($rows, $w);
        }

        $sort = is_array($derive['sort'] ?? null) ? $derive['sort'] : [];
        if ($sort === [] && is_array($derive['order_by'] ?? null)) {
            $sort = $derive['order_by'];
        }
        if ($sort !== []) {
            usort($rows, static function (array $a, array $b) use ($sort): int {
                foreach ($sort as $o) {
                    $f = (string)($o['field'] ?? '');
                    $dir = strtolower((string)($o['dir'] ?? 'asc')) === 'desc' ? -1 : 1;
                    $va = $a[$f] ?? null;
                    $vb = $b[$f] ?? null;
                    if ($va == $vb) {
                        continue;
                    }
                    return ($va < $vb ? -1 : 1) * $dir;
                }
                return 0;
            });
        }

        $limit = (int)($derive['limit'] ?? 0);
        if ($limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $left
     * @param array<string,mixed> $join
     * @param array<string,list<array<string,mixed>>> $frames
     * @return list<array<string,mixed>>
     */
    private static function joinFrame(array $left, array $join, array $frames): array
    {
        $frameName = (string)($join['frame'] ?? '');
        $right = $frames[$frameName] ?? null;
        if ($right === null) {
            throw new InvalidArgumentException('join_frame_missing:' . $frameName);
        }
        $type = strtolower((string)($join['type'] ?? 'left'));
        if ($type === 'cross') {
            if ($right === []) {
                return $left;
            }
            $cells = count($left) * count($right);
            if ($cells > self::MAX_CROSS_CELLS) {
                throw new InvalidArgumentException(
                    'cross_join_too_large:' . count($left) . 'x' . count($right)
                );
            }
            $out = [];
            foreach ($left as $lrow) {
                foreach ($right as $rrow) {
                    $out[] = array_merge($rrow, $lrow);
                }
            }
            return self::capRows($out, 'cross_join');
        }

        $lk = (string)($join['left'] ?? '');
        $rk = (string)($join['right'] ?? '');
        if ($lk === '' || $rk === '') {
            throw new InvalidArgumentException('join_frame_keys');
        }
        $index = [];
        foreach ($right as $rrow) {
            $key = (string)($rrow[$rk] ?? '');
            $index[$key][] = $rrow;
        }
        $out = [];
        foreach ($left as $lrow) {
            $key = (string)($lrow[$lk] ?? '');
            $matches = $index[$key] ?? [null];
            foreach ($matches as $rrow) {
                if (count($out) >= self::MAX_DERIVE_ROWS) {
                    throw new InvalidArgumentException('join_frame_too_large');
                }
                if ($rrow === null) {
                    $out[] = $lrow;
                } else {
                    $out[] = array_merge($rrow, $lrow);
                }
            }
        }
        return $out;
    }

    private static function tickBudget(): void
    {
        if (self::$budgetStartedAt > 0 && (time() - self::$budgetStartedAt) > self::MAX_RUNTIME_SEC) {
            throw new RuntimeException('recipe_timeout');
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function capRows(array $rows, string $where): array
    {
        $n = count($rows);
        $max = strpos($where, 'query:') === 0 ? self::MAX_FRAME_ROWS : self::MAX_DERIVE_ROWS;
        if ($n > $max) {
            throw new InvalidArgumentException('frame_too_large:' . $where . ':' . $n);
        }
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $groupBy
     * @param list<array<string,mixed>> $aggs
     * @param array<string,mixed> $periodCtx
     * @return list<array<string,mixed>>
     */
    private static function groupReduce(array $rows, array $groupBy, array $aggs, array $periodCtx): array
    {
        $groups = [];
        $order = [];
        foreach ($rows as $row) {
            $keyParts = [];
            foreach ($groupBy as $g) {
                $keyParts[] = (string)($row[(string)$g] ?? '');
            }
            $key = implode("\0", $keyParts);
            if (!isset($groups[$key])) {
                $groups[$key] = [];
                $order[] = $key;
            }
            $groups[$key][] = $row;
        }

        $out = [];
        foreach ($order as $key) {
            $members = $groups[$key];
            $base = [];
            foreach ($groupBy as $g) {
                $g = (string)$g;
                $base[$g] = $members[0][$g] ?? null;
            }
            foreach ($aggs as $agg) {
                $as = (string)($agg['as'] ?? '');
                if ($as === '') {
                    continue;
                }
                $base[$as] = self::aggOp($agg, $members, $periodCtx);
            }
            $out[] = $base;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $agg
     * @param list<array<string,mixed>> $members
     * @param array<string,mixed> $periodCtx
     * @return mixed
     */
    private static function aggOp(array $agg, array $members, array $periodCtx)
    {
        $op = strtolower((string)($agg['op'] ?? 'count'));
        $field = (string)($agg['field'] ?? '');
        $where = $agg['where'] ?? null;

        $selected = [];
        foreach ($members as $row) {
            if ($where !== null) {
                $ctx = array_merge($periodCtx, $row);
                if (!BiExpr::truthy(BiExpr::eval($where, $ctx))) {
                    continue;
                }
            }
            $selected[] = $row;
        }

        switch ($op) {
            case 'count':
            case 'count_where':
                return count($selected);
            case 'first':
                if ($selected === []) {
                    return null;
                }
                return $field !== '' ? ($selected[0][$field] ?? null) : $selected[0];
            case 'sum':
                $s = 0.0;
                foreach ($selected as $row) {
                    $s += (float)($row[$field] ?? 0);
                }
                return $s;
            case 'avg':
                if ($selected === []) {
                    return null;
                }
                $s = 0.0;
                foreach ($selected as $row) {
                    $s += (float)($row[$field] ?? 0);
                }
                return $s / count($selected);
            case 'min':
                if ($selected === []) {
                    return null;
                }
                $m = null;
                foreach ($selected as $row) {
                    $v = $row[$field] ?? null;
                    if ($m === null || $v < $m) {
                        $m = $v;
                    }
                }
                return $m;
            case 'max':
                if ($selected === []) {
                    return null;
                }
                $m = null;
                foreach ($selected as $row) {
                    $v = $row[$field] ?? null;
                    if ($m === null || $v > $m) {
                        $m = $v;
                    }
                }
                return $m;
            case 'join':
            case 'collect':
            case 'join_where':
                $sep = (string)($agg['sep'] ?? ', ');
                $parts = [];
                foreach ($selected as $row) {
                    $v = $row[$field] ?? '';
                    if ($v === null || $v === '') {
                        continue;
                    }
                    $parts[] = (string)$v;
                }
                return implode($sep, $parts);
            default:
                throw new InvalidArgumentException('bad_group_agg:' . $op);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $w
     * @return list<array<string,mixed>>
     */
    private static function applyWindow(array $rows, array $w): array
    {
        $fn = strtolower((string)($w['fn'] ?? 'rank'));
        $as = (string)($w['as'] ?? $fn);
        $orderBy = is_array($w['order_by'] ?? null) ? $w['order_by'] : [];
        $partition = is_array($w['partition_by'] ?? null) ? $w['partition_by'] : [];

        $groups = [];
        foreach ($rows as $i => $row) {
            $key = '';
            foreach ($partition as $p) {
                $key .= '|' . (string)($row[(string)$p] ?? '');
            }
            $groups[$key][] = $i;
        }

        foreach ($groups as $idxs) {
            usort($idxs, static function (int $a, int $b) use ($rows, $orderBy): int {
                foreach ($orderBy as $o) {
                    $f = (string)($o['field'] ?? '');
                    $dir = strtolower((string)($o['dir'] ?? 'asc')) === 'desc' ? -1 : 1;
                    $va = $rows[$a][$f] ?? null;
                    $vb = $rows[$b][$f] ?? null;
                    if ($va == $vb) {
                        continue;
                    }
                    return ($va < $vb ? -1 : 1) * $dir;
                }
                return 0;
            });

            $rank = 0;
            $dense = 0;
            $prev = null;
            $rowNum = 0;
            foreach ($idxs as $idx) {
                $rowNum++;
                $sig = '';
                foreach ($orderBy as $o) {
                    $sig .= '|' . (string)($rows[$idx][(string)($o['field'] ?? '')] ?? '');
                }
                if ($sig !== $prev) {
                    $dense++;
                    $rank = $rowNum;
                    $prev = $sig;
                }
                if ($fn === 'row_number') {
                    $rows[$idx][$as] = $rowNum;
                } elseif ($fn === 'dense_rank') {
                    $rows[$idx][$as] = $dense;
                } else {
                    $rows[$idx][$as] = $rank;
                }
            }
        }
        return $rows;
    }

    /**
     * Plain-text / i18n note block for the constructor (no HTML).
     *
     * @param array<string,mixed> $sec
     * @return array<string,mixed>
     */
    private static function sectionText(array $sec): array
    {
        $body = $sec['body'] ?? '';
        if (is_array($body)) {
            $parts = [];
            foreach ($body as $line) {
                $parts[] = (string)$line;
            }
            $body = implode("\n", $parts);
        } else {
            $body = (string)$body;
        }
        return [
            'title' => (string)($sec['title'] ?? ''),
            'type' => 'text',
            'body' => $body,
        ];
    }

    /**
     * @param array<string,mixed> $sec
     * @param array<string,list<array<string,mixed>>> $frames
     * @return array<string,mixed>
     */
    private static function sectionMetrics(array $sec, array $frames): array
    {
        $metrics = [];
        $items = is_array($sec['items'] ?? null) ? $sec['items'] : [];
        foreach ($items as $item) {
            $label = (string)($item['label'] ?? $item['field'] ?? '');
            $from = (string)($item['from'] ?? '');
            $field = (string)($item['field'] ?? '');
            $agg = strtolower((string)($item['agg'] ?? ''));
            $rows = $frames[$from] ?? [];
            if ($agg !== '' && in_array($agg, ['sum', 'avg', 'min', 'max', 'count'], true)) {
                $metrics[$label] = self::aggRows($rows, $field, $agg);
            } else {
                $metrics[$label] = $rows[0][$field] ?? 0;
            }
        }
        return [
            'title' => (string)($sec['title'] ?? 'Metrics'),
            'type' => 'metrics',
            'metrics' => $metrics,
        ];
    }

    /**
     * @param array<string,mixed> $sec
     * @param array<string,list<array<string,mixed>>> $frames
     * @return array<string,mixed>
     */
    private static function sectionTable(array $sec, array $frames): array
    {
        $from = (string)($sec['from'] ?? '');
        $rows = $frames[$from] ?? [];
        $cols = is_array($sec['columns'] ?? null) ? $sec['columns'] : [];
        if ($cols === [] && $rows !== []) {
            $cols = array_keys($rows[0]);
        }
        $colDefs = [];
        $colIds = [];
        foreach ($cols as $c) {
            if (is_array($c)) {
                $id = (string)($c['id'] ?? '');
                $label = (string)($c['label'] ?? $id);
                if ($id === '') {
                    continue;
                }
                $colDefs[] = ['id' => $id, 'label' => $label];
                $colIds[] = $id;
            } else {
                $c = (string)$c;
                $colDefs[] = ['id' => $c, 'label' => $c];
                $colIds[] = $c;
            }
        }
        $outRows = [];
        foreach ($rows as $row) {
            $r = [];
            foreach ($colIds as $c) {
                $r[$c] = $row[$c] ?? '';
            }
            $outRows[] = $r;
        }
        return [
            'title' => (string)($sec['title'] ?? 'Table'),
            'type' => 'table',
            'columns' => $colDefs,
            'rows' => $outRows,
        ];
    }

    /**
     * @param array<string,mixed> $sec
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function sectionPivot(array $sec, array $params): array
    {
        $grain = (string)($sec['grain'] ?? 'month_to_date');
        $slots = self::pivotSlots($params, $grain);
        $metrics = is_array($sec['metrics'] ?? null) ? $sec['metrics'] : [];
        $columns = [];
        $grid = [];
        $metricKeys = [];

        foreach ($slots as $slot) {
            $label = (string)$slot['label'];
            $columns[] = $label;
            $slotParams = $params;
            $slotParams['year'] = (int)$slot['year'];
            $slotParams['month'] = (int)$slot['month'];
            $slotParams['_day'] = (int)($slot['day'] ?? 0);
            ReportProgress::note('Pivot ' . $label . '…');

            foreach ($metrics as $m) {
                $mid = (string)($m['id'] ?? $m['label'] ?? 'm');
                $mlabel = (string)($m['label'] ?? $mid);
                $metricKeys[$mlabel] = true;
                $from = (string)($m['from'] ?? '');
                $agg = strtolower((string)($m['agg'] ?? 'count'));
                $field = (string)($m['field'] ?? '');
                $q = [
                    'as' => '_pivot',
                    'from' => $from,
                    'where' => is_array($m['where'] ?? null) ? $m['where'] : [],
                    'select' => $agg === 'count' && $field === ''
                        ? [['agg' => 'count', 'as' => 'v']]
                        : [['agg' => $agg, 'field' => $field !== '' ? $field : 'id', 'as' => 'v']],
                    'limit' => 1,
                ];
                try {
                    $rows = self::runQuery($q, $slotParams);
                    $grid[$mlabel][$label] = $rows[0]['v'] ?? 0;
                } catch (Throwable $e) {
                    $grid[$mlabel][$label] = '';
                }
            }
        }

        $colDefs = [['id' => '_metric', 'label' => '']];
        foreach ($columns as $col) {
            $colDefs[] = ['id' => $col, 'label' => $col];
        }
        $outRows = [];
        foreach (array_keys($metricKeys) as $mlabel) {
            $r = ['_metric' => $mlabel];
            foreach ($columns as $col) {
                $r[$col] = $grid[$mlabel][$col] ?? '';
            }
            $outRows[] = $r;
        }
        return [
            'title' => (string)($sec['title'] ?? 'Pivot'),
            'type' => 'table',
            'columns' => $colDefs,
            'rows' => $outRows,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array{label:string,year:int,month:int,day?:int}>
     */
    private static function pivotSlots(array $params, string $grain): array
    {
        $year = (int)$params['year'];
        $month = (int)$params['month'];
        if ($grain === 'days_in_month') {
            $days = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
            $slots = [];
            for ($d = 1; $d <= $days; $d++) {
                $slots[] = [
                    'label' => sprintf('%02d', $d),
                    'year' => $year,
                    'month' => $month,
                    'day' => $d,
                ];
            }
            return $slots;
        }
        // month_to_date: current month only (single column)
        return [[
            'label' => sprintf('%04d-%02d', $year, $month),
            'year' => $year,
            'month' => $month,
        ]];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return mixed
     */
    private static function aggRows(array $rows, string $field, string $agg)
    {
        if ($agg === 'count') {
            return count($rows);
        }
        if ($rows === []) {
            return 0;
        }
        $vals = [];
        foreach ($rows as $row) {
            $vals[] = (float)($row[$field] ?? 0);
        }
        switch ($agg) {
            case 'sum':
                return array_sum($vals);
            case 'avg':
                return array_sum($vals) / count($vals);
            case 'min':
                return min($vals);
            case 'max':
                return max($vals);
        }
        return 0;
    }
}
