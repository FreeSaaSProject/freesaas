<?php
declare(strict_types=1);

/**
 * Execute a validated BI JSON recipe → report sections.
 */
final class BiRecipeEngine
{
    public const MAX_QUERIES = 40;
    public const MAX_DERIVE_STEPS = 60;
    public const MAX_FRAME_ROWS = 50000;
    /** Shift attribution joins (guests × lanes) — busy months ~20k guests × several lanes. */
    public const MAX_DERIVE_ROWS = 500000;
    public const MAX_CROSS_CELLS = 500000;
    public const MAX_RUNTIME_SEC = 20;

    /** @var float */
    private static $budgetStartedAt = 0.0;

    /** @var string|null cached working dialect: mysql|mariadb */
    private static $sqlTimeoutDialect = null;

    /** @var string|null dialect applied for the in-flight query (for clear) */
    private static $sqlTimeoutApplied = null;

    /**
     * @param array<string,mixed> $recipe
     * @param array<string,mixed> $params normalized ReportParams
     * @return array{title:string,sections:list<array<string,mixed>>,meta?:array<string,mixed>}
     */
    public static function run(array $recipe, array $params): array
    {
        self::$budgetStartedAt = microtime(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::MAX_RUNTIME_SEC + 5);
        }
        $prevMem = ini_get('memory_limit');
        // Guest×lane attribution for busy months needs headroom above default 128M.
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }
        try {
            return self::runInner($recipe, $params);
        } finally {
            if (function_exists('ini_set') && is_string($prevMem) && $prevMem !== '') {
                @ini_set('memory_limit', $prevMem);
            }
            self::$budgetStartedAt = 0.0;
        }
    }

    /**
     * @param array<string,mixed> $recipe
     * @param array<string,mixed> $params
     * @return array{title:string,sections:list<array<string,mixed>>,meta?:array<string,mixed>}
     */
    private static function runInner(array $recipe, array $params): array
    {
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
        $secList = is_array($recipe['sections'] ?? null) ? $recipe['sections'] : [];
        $derive = self::optimizeDerivePipeline($derive, $secList);
        foreach ($derive as $di => $d) {
            self::tickBudget();
            $as = (string)($d['as'] ?? '');
            $from = (string)($d['from'] ?? '');
            if ($as === '' || $from === '' || !isset($frames[$from])) {
                throw new InvalidArgumentException('derive_from_missing:' . $from);
            }
            ReportProgress::note('Derive: ' . $as);
            $frames[$as] = self::capRows(self::runDerive($d, $frames, $params), 'derive:' . $as);
            ReportProgress::mark($as . ': ' . count($frames[$as]) . ' rows');
            self::gcUnusedFrames($frames, $derive, (int)$di, $secList);
        }

        $sections = [];
        $secList = is_array($recipe['sections'] ?? null) ? $recipe['sections'] : [];
        foreach ($secList as $sec) {
            self::tickBudget();
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
        self::tickBudget();
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
        $pdo = Database::pdo();
        $timeoutMs = self::remainingBudgetMs();
        if ($timeoutMs <= 0) {
            throw new RuntimeException('recipe_timeout');
        }
        self::applySqlTimeout($pdo, $timeoutMs);
        try {
            $st = $pdo->prepare($built['sql']);
            $st->execute($built['args']);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            if (self::isSqlTimeoutError($e)) {
                throw new RuntimeException('recipe_timeout', 0, $e);
            }
            throw $e;
        } finally {
            self::clearSqlTimeout($pdo);
        }
        self::tickBudget();
        $out = [];
        $n = 0;
        foreach ($rows as $row) {
            if ((++$n % 500) === 0) {
                self::tickBudget();
            }
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
     * Fuse join-only → group_by steps so join product is never materialised.
     *
     * @param list<array<string,mixed>> $derive
     * @param list<array<string,mixed>> $sections
     * @return list<array<string,mixed>>
     */
    private static function optimizeDerivePipeline(array $derive, array $sections): array
    {
        if (count($derive) < 2) {
            return $derive;
        }
        $refs = self::frameRefCounts($derive, $sections);
        $out = [];
        $n = count($derive);
        for ($i = 0; $i < $n; $i++) {
            $cur = $derive[$i];
            $next = ($i + 1 < $n) ? $derive[$i + 1] : null;
            if (
                is_array($cur)
                && is_array($next)
                && self::isJoinOnlyDerive($cur)
                && self::isGroupOnlyDerive($next)
                && (string)($next['from'] ?? '') === (string)($cur['as'] ?? '')
                && (int)($refs[(string)($cur['as'] ?? '')] ?? 0) === 1
            ) {
                $merged = $cur;
                $merged['as'] = (string)($next['as'] ?? $cur['as']);
                foreach (['group_by', 'aggs', 'compute_after', 'having', 'window', 'sort', 'order_by', 'limit'] as $k) {
                    if (array_key_exists($k, $next)) {
                        $merged[$k] = $next[$k];
                    }
                }
                $out[] = $merged;
                $i++;
                continue;
            }
            $out[] = $cur;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $derive
     * @param list<array<string,mixed>> $sections
     * @return array<string,int>
     */
    private static function frameRefCounts(array $derive, array $sections): array
    {
        $refs = [];
        $bump = static function (string $name) use (&$refs): void {
            if ($name === '') {
                return;
            }
            $refs[$name] = ($refs[$name] ?? 0) + 1;
        };
        foreach ($derive as $d) {
            if (!is_array($d)) {
                continue;
            }
            $bump((string)($d['from'] ?? ''));
            $jf = $d['join_frame']['frame'] ?? null;
            if (is_string($jf)) {
                $bump($jf);
            }
        }
        foreach ($sections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $bump((string)($sec['from'] ?? ''));
            $items = is_array($sec['items'] ?? null) ? $sec['items'] : [];
            foreach ($items as $it) {
                if (is_array($it)) {
                    $bump((string)($it['from'] ?? ''));
                }
            }
        }
        return $refs;
    }

    /** @param array<string,mixed> $d */
    private static function isJoinOnlyDerive(array $d): bool
    {
        if (!is_array($d['join_frame'] ?? null)) {
            return false;
        }
        if (array_key_exists('group_by', $d) || array_key_exists('aggs', $d)) {
            return false;
        }
        if (($d['filter'] ?? null) !== null) {
            return false;
        }
        if (!empty($d['compute']) || !empty($d['compute_after']) || !empty($d['window'])) {
            return false;
        }
        if (!empty($d['sort']) || !empty($d['order_by']) || (int)($d['limit'] ?? 0) > 0) {
            return false;
        }
        if (BiDeriveRegistry::expandKeyIn($d) !== null) {
            return false;
        }
        $select = $d['select'] ?? ['*'];
        return $select === ['*'] || $select === '*';
    }

    /** @param array<string,mixed> $d */
    private static function isGroupOnlyDerive(array $d): bool
    {
        if (!array_key_exists('group_by', $d)) {
            return false;
        }
        if (is_array($d['join_frame'] ?? null)) {
            return false;
        }
        if (BiDeriveRegistry::expandKeyIn($d) !== null) {
            return false;
        }
        if (($d['filter'] ?? null) !== null) {
            return false;
        }
        if (!empty($d['compute'])) {
            return false;
        }
        $select = $d['select'] ?? ['*'];
        return $select === ['*'] || $select === '*';
    }

    /**
     * Derive pipeline: expand_shift_lanes → join_frame → filter → select/compute →
     * group/aggs → compute_after → window → sort → limit.
     *
     * When join_frame and group_by share a step (or were fused), rows are folded
     * during the join — the cartesian product is never stored.
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

        $expandKey = BiDeriveRegistry::expandKeyIn($derive);
        if ($expandKey !== null) {
            $step = BiDeriveRegistry::get($expandKey);
            if ($step === null || !is_callable($step['handler'] ?? null)) {
                throw new RuntimeException('derive_step_unavailable:' . $expandKey);
            }
            $cfg = is_array($derive[$expandKey] ?? null) ? $derive[$expandKey] : [];
            $rows = call_user_func($step['handler'], $rows, $cfg);
            if (!is_array($rows)) {
                throw new RuntimeException('derive_step_bad_result:' . $expandKey);
            }
            self::tickBudget();
        }

        $bounds = ReportParams::monthBounds((int)$params['year'], (int)$params['month']);
        $periodCtx = [
            '_period_start' => (int)$bounds['start'],
            '_period_end' => (int)$bounds['end'],
            '_period_label' => (string)$bounds['label'],
        ];

        $join = is_array($derive['join_frame'] ?? null) ? $derive['join_frame'] : null;
        $hasGroup = array_key_exists('group_by', $derive);
        $filter = $derive['filter'] ?? null;
        $select = $derive['select'] ?? ['*'];
        $compute = is_array($derive['compute'] ?? null) ? $derive['compute'] : [];

        if ($join !== null && $hasGroup) {
            $groupBy = is_array($derive['group_by']) ? $derive['group_by'] : [];
            $aggs = is_array($derive['aggs'] ?? null) ? $derive['aggs'] : [];
            $rows = self::joinFilterGroup(
                $rows,
                $join,
                $frames,
                $filter,
                $select,
                $compute,
                $groupBy,
                $aggs,
                $periodCtx
            );
            self::tickBudget();
        } else {
            if ($join !== null) {
                $project = self::joinProjectFields($select, $compute);
                $rows = self::joinFrame($rows, $join, $frames, $project);
                self::tickBudget();
            }

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

            $mapped = [];
            $n = 0;
            foreach ($rows as $row) {
                if ((++$n % 500) === 0) {
                    self::tickBudget();
                }
                $mapped[] = self::mapRow($row, $select, $compute, $periodCtx);
            }
            $rows = $mapped;

            if ($hasGroup) {
                $groupBy = is_array($derive['group_by']) ? $derive['group_by'] : [];
                $aggs = is_array($derive['aggs'] ?? null) ? $derive['aggs'] : [];
                $rows = self::groupReduce($rows, $groupBy, $aggs, $periodCtx);
            }
        }

        $computeAfter = is_array($derive['compute_after'] ?? null) ? $derive['compute_after'] : [];
        if ($computeAfter !== []) {
            $n = 0;
            foreach ($rows as $i => $row) {
                if ((++$n % 500) === 0) {
                    self::tickBudget();
                }
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
            $n = 0;
            foreach ($rows as $row) {
                if ((++$n % 500) === 0) {
                    self::tickBudget();
                }
                $ctx = array_merge($periodCtx, $row);
                if (BiExpr::truthy(BiExpr::eval($having, $ctx))) {
                    $filtered[] = $row;
                }
            }
            $rows = $filtered;
        }

        $windows = is_array($derive['window'] ?? null) ? $derive['window'] : [];
        foreach ($windows as $w) {
            self::tickBudget();
            $rows = self::applyWindow($rows, $w);
        }

        $sort = is_array($derive['sort'] ?? null) ? $derive['sort'] : [];
        if ($sort === [] && is_array($derive['order_by'] ?? null)) {
            $sort = $derive['order_by'];
        }
        if ($sort !== []) {
            self::tickBudget();
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
            self::tickBudget();
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
     * @param list<string>|null $project null = keep all columns
     * @return list<array<string,mixed>>
     */
    private static function joinFrame(array $left, array $join, array $frames, ?array $project = null): array
    {
        $out = [];
        $n = 0;
        self::eachJoinMatch($left, $join, $frames, static function (array $merged) use (&$out, &$n, $project): void {
            if ((++$n % 1000) === 0) {
                self::tickBudget();
            }
            if (count($out) >= self::MAX_DERIVE_ROWS) {
                throw new InvalidArgumentException('join_frame_too_large');
            }
            $out[] = $project === null ? $merged : self::projectRow($merged, $project);
        }, true);
        return $out;
    }

    /**
     * Stream join → filter → select/compute → group without storing the join product.
     *
     * @param list<array<string,mixed>> $left
     * @param array<string,mixed> $join
     * @param array<string,list<array<string,mixed>>> $frames
     * @param mixed $filter
     * @param mixed $select
     * @param list<array<string,mixed>> $compute
     * @param list<string> $groupBy
     * @param list<array<string,mixed>> $aggs
     * @param array<string,mixed> $periodCtx
     * @return list<array<string,mixed>>
     */
    private static function joinFilterGroup(
        array $left,
        array $join,
        array $frames,
        $filter,
        $select,
        array $compute,
        array $groupBy,
        array $aggs,
        array $periodCtx
    ): array {
        $acc = self::groupAccInit();
        $emitted = 0;
        self::eachJoinMatch($left, $join, $frames, static function (array $merged) use (
            &$acc,
            &$emitted,
            $filter,
            $select,
            $compute,
            $groupBy,
            $aggs,
            $periodCtx
        ): void {
            if ((++$emitted % 1000) === 0) {
                self::tickBudget();
            }
            // Soft cap: streaming still counts match attempts against derive budget.
            if ($emitted > self::MAX_DERIVE_ROWS * 2) {
                throw new InvalidArgumentException('join_frame_too_large');
            }
            if ($filter !== null) {
                $ctx = array_merge($periodCtx, $merged);
                if (!BiExpr::truthy(BiExpr::eval($filter, $ctx))) {
                    return;
                }
            }
            $row = self::mapRow($merged, $select, $compute, $periodCtx);
            self::groupAccAdd($acc, $row, $groupBy, $aggs, $periodCtx);
        }, true);
        return self::groupAccFinish($acc, $groupBy, $aggs);
    }

    /**
     * @param list<array<string,mixed>> $left
     * @param array<string,mixed> $join
     * @param array<string,list<array<string,mixed>>> $frames
     * @param callable(array<string,mixed>):void $emit
     */
    private static function eachJoinMatch(
        array $left,
        array $join,
        array $frames,
        callable $emit,
        bool $capCross
    ): void {
        $frameName = (string)($join['frame'] ?? '');
        $right = $frames[$frameName] ?? null;
        if ($right === null) {
            throw new InvalidArgumentException('join_frame_missing:' . $frameName);
        }
        $type = strtolower((string)($join['type'] ?? 'left'));
        if ($type === 'cross') {
            if ($right === []) {
                foreach ($left as $lrow) {
                    $emit($lrow);
                }
                return;
            }
            if ($capCross) {
                $cells = count($left) * count($right);
                if ($cells > self::MAX_CROSS_CELLS) {
                    throw new InvalidArgumentException(
                        'cross_join_too_large:' . count($left) . 'x' . count($right)
                    );
                }
            }
            $n = 0;
            foreach ($left as $lrow) {
                foreach ($right as $rrow) {
                    if ((++$n % 1000) === 0) {
                        self::tickBudget();
                    }
                    if (!self::joinRangeOk($lrow, $rrow, $join)) {
                        continue;
                    }
                    $emit(array_merge($rrow, $lrow));
                }
            }
            return;
        }

        $pairs = self::joinKeyPairs($join);
        if ($pairs === []) {
            throw new InvalidArgumentException('join_frame_keys');
        }
        $inner = ($type === 'inner');
        $index = [];
        foreach ($right as $rrow) {
            $key = self::joinKeyValue($rrow, $pairs, 'right');
            $index[$key][] = $rrow;
        }
        $n = 0;
        foreach ($left as $lrow) {
            $key = self::joinKeyValue($lrow, $pairs, 'left');
            $matches = $index[$key] ?? [];
            if ($matches === []) {
                if (!$inner) {
                    $emit($lrow);
                }
                continue;
            }
            foreach ($matches as $rrow) {
                if ((++$n % 1000) === 0) {
                    self::tickBudget();
                }
                if (!self::joinRangeOk($lrow, $rrow, $join)) {
                    continue;
                }
                $emit(array_merge($rrow, $lrow));
            }
        }
    }

    /**
     * Columns to keep after a non-grouped join when select is explicit (no compute).
     *
     * @param mixed $select
     * @param list<array<string,mixed>> $compute
     * @return list<string>|null
     */
    private static function joinProjectFields($select, array $compute): ?array
    {
        if ($compute !== [] || $select === ['*'] || $select === '*') {
            return null;
        }
        $cols = [];
        foreach ((array)$select as $col) {
            $col = (string)$col;
            if ($col !== '') {
                $cols[$col] = true;
            }
        }
        return $cols === [] ? null : array_keys($cols);
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $fields
     * @return array<string,mixed>
     */
    private static function projectRow(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $row)) {
                $out[$f] = $row[$f];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @param mixed $select
     * @param list<array<string,mixed>> $compute
     * @param array<string,mixed> $periodCtx
     * @return array<string,mixed>
     */
    private static function mapRow(array $row, $select, array $compute, array $periodCtx): array
    {
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
        return $new;
    }

    /**
     * Drop frames that no remaining derive/section will read (frees guest×lane joins).
     *
     * @param array<string,list<array<string,mixed>>> $frames
     * @param list<array<string,mixed>> $derive
     * @param list<array<string,mixed>> $sections
     */
    private static function gcUnusedFrames(array &$frames, array $derive, int $doneIndex, array $sections): void
    {
        $need = [];
        for ($i = $doneIndex + 1, $n = count($derive); $i < $n; $i++) {
            $d = $derive[$i];
            if (!is_array($d)) {
                continue;
            }
            $from = (string)($d['from'] ?? '');
            if ($from !== '') {
                $need[$from] = true;
            }
            $jf = $d['join_frame']['frame'] ?? null;
            if (is_string($jf) && $jf !== '') {
                $need[$jf] = true;
            }
        }
        foreach ($sections as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $from = (string)($sec['from'] ?? '');
            if ($from !== '') {
                $need[$from] = true;
            }
            $items = is_array($sec['items'] ?? null) ? $sec['items'] : [];
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $f = (string)($it['from'] ?? '');
                if ($f !== '') {
                    $need[$f] = true;
                }
            }
        }
        foreach (array_keys($frames) as $name) {
            if (!isset($need[$name])) {
                unset($frames[$name]);
            }
        }
    }

    /**
     * @param array<string,mixed> $join
     * @return list<array{left:string,right:string}>
     */
    private static function joinKeyPairs(array $join): array
    {
        $on = $join['on'] ?? null;
        if (is_array($on) && $on !== []) {
            $pairs = [];
            foreach ($on as $pair) {
                if (!is_array($pair)) {
                    continue;
                }
                $l = (string)($pair['left'] ?? '');
                $r = (string)($pair['right'] ?? '');
                if ($l !== '' && $r !== '') {
                    $pairs[] = ['left' => $l, 'right' => $r];
                }
            }
            return $pairs;
        }
        $lk = (string)($join['left'] ?? '');
        $rk = (string)($join['right'] ?? '');
        if ($lk === '' || $rk === '') {
            return [];
        }
        $pairs = [['left' => $lk, 'right' => $rk]];
        $lk2 = (string)($join['left2'] ?? '');
        $rk2 = (string)($join['right2'] ?? '');
        if ($lk2 !== '' && $rk2 !== '') {
            $pairs[] = ['left' => $lk2, 'right' => $rk2];
        }
        return $pairs;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array{left:string,right:string}> $pairs
     */
    private static function joinKeyValue(array $row, array $pairs, string $side): string
    {
        $parts = [];
        foreach ($pairs as $pair) {
            $field = $side === 'right' ? $pair['right'] : $pair['left'];
            $parts[] = (string)($row[$field] ?? '');
        }
        return implode("\0", $parts);
    }

    /**
     * Optional range predicate after equality join / during cross.
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<string,mixed> $join
     */
    private static function joinRangeOk(array $left, array $right, array $join): bool
    {
        $range = $join['range'] ?? null;
        if (!is_array($range)) {
            return true;
        }
        $tsField = (string)($range['left'] ?? '');
        $startField = (string)($range['start'] ?? 'window_start');
        $endField = (string)($range['end'] ?? 'window_end');
        if ($tsField === '') {
            return true;
        }
        $ts = (int)($left[$tsField] ?? 0);
        $start = (int)($right[$startField] ?? 0);
        $end = (int)($right[$endField] ?? 0);
        return $ts >= $start && $ts <= $end;
    }

    /** Milliseconds left in the wall-clock budget (0 = already expired). */
    public static function remainingBudgetMs(): int
    {
        if (self::$budgetStartedAt <= 0.0) {
            return self::MAX_RUNTIME_SEC * 1000;
        }
        $left = (self::MAX_RUNTIME_SEC - (microtime(true) - self::$budgetStartedAt)) * 1000.0;
        return (int)max(0, floor($left));
    }

    private static function tickBudget(): void
    {
        if (self::$budgetStartedAt > 0.0 && self::remainingBudgetMs() <= 0) {
            throw new RuntimeException('recipe_timeout');
        }
    }

    /**
     * Prefer cached dialect, else VERSION() hint, then both backends.
     *
     * @return list<string>
     */
    private static function sqlTimeoutAttemptOrder(PDO $pdo): array
    {
        if (self::$sqlTimeoutDialect === 'mariadb' || self::$sqlTimeoutDialect === 'mysql') {
            $primary = self::$sqlTimeoutDialect;
            return [$primary, $primary === 'mariadb' ? 'mysql' : 'mariadb'];
        }
        try {
            $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            if (stripos($ver, 'mariadb') !== false) {
                return ['mariadb', 'mysql'];
            }
        } catch (Throwable $e) {
            // fall through — try both
        }
        return ['mysql', 'mariadb'];
    }

    /**
     * Cap a single SELECT to remaining wall clock. Verifies the session var stuck.
     * If no backend accepts the limit, abort the run — never execute unbounded SQL.
     */
    private static function applySqlTimeout(PDO $pdo, int $ms): void
    {
        $ms = max(1, $ms);
        self::$sqlTimeoutApplied = null;
        foreach (self::sqlTimeoutAttemptOrder($pdo) as $kind) {
            if (self::tryApplySqlTimeout($pdo, $kind, $ms)) {
                self::$sqlTimeoutApplied = $kind;
                self::$sqlTimeoutDialect = $kind;
                return;
            }
        }
        throw new RuntimeException('sql_timeout_unavailable');
    }

    private static function tryApplySqlTimeout(PDO $pdo, string $kind, int $ms): bool
    {
        try {
            if ($kind === 'mariadb') {
                // MariaDB: seconds (float allowed).
                $sec = max(0.001, round($ms / 1000.0, 3));
                $pdo->exec('SET SESSION max_statement_time=' . $sec);
                $got = (float)$pdo->query('SELECT @@SESSION.max_statement_time')->fetchColumn();
                $ok = $got > 0.0 && abs($got - $sec) < 0.05;
                if (!$ok) {
                    try {
                        $pdo->exec('SET SESSION max_statement_time=0');
                    } catch (Throwable $e) {
                        // ignore
                    }
                }
                return $ok;
            }
            if ($kind === 'mysql') {
                // MySQL 5.7+/8: milliseconds for SELECT.
                $pdo->exec('SET SESSION MAX_EXECUTION_TIME=' . $ms);
                $got = (int)$pdo->query('SELECT @@SESSION.MAX_EXECUTION_TIME')->fetchColumn();
                // Accept exact or a rounded positive value not exceeding the ask.
                $ok = $got > 0 && $got <= $ms;
                if (!$ok) {
                    try {
                        $pdo->exec('SET SESSION MAX_EXECUTION_TIME=0');
                    } catch (Throwable $e) {
                        // ignore
                    }
                }
                return $ok;
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }

    private static function clearSqlTimeout(PDO $pdo): void
    {
        $dialect = self::$sqlTimeoutApplied;
        self::$sqlTimeoutApplied = null;
        if ($dialect !== 'mariadb' && $dialect !== 'mysql') {
            return;
        }
        try {
            if ($dialect === 'mariadb') {
                $pdo->exec('SET SESSION max_statement_time=0');
            } else {
                $pdo->exec('SET SESSION MAX_EXECUTION_TIME=0');
            }
        } catch (Throwable $e) {
            // ignore restore failures
        }
    }

    private static function isSqlTimeoutError(PDOException $e): bool
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'max_statement_time') !== false) {
            return true;
        }
        if (stripos($msg, 'maximum statement execution time exceeded') !== false) {
            return true;
        }
        if (stripos($msg, 'Query execution was interrupted') !== false) {
            return true;
        }
        // MySQL ER_QUERY_TIMEOUT = 3024
        $code = (int)($e->errorInfo[1] ?? 0);
        return $code === 3024 || $code === 1969;
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
     * Streaming group-by (never stores member row lists).
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $groupBy
     * @param list<array<string,mixed>> $aggs
     * @param array<string,mixed> $periodCtx
     * @return list<array<string,mixed>>
     */
    private static function groupReduce(array $rows, array $groupBy, array $aggs, array $periodCtx): array
    {
        self::tickBudget();
        $acc = self::groupAccInit();
        $n = 0;
        foreach ($rows as $row) {
            if ((++$n % 500) === 0) {
                self::tickBudget();
            }
            self::groupAccAdd($acc, $row, $groupBy, $aggs, $periodCtx);
        }
        return self::groupAccFinish($acc, $groupBy, $aggs);
    }

    /**
     * @return array{order:list<string>,groups:array<string,array{base:array<string,mixed>,states:list<array<string,mixed>>}>}
     */
    private static function groupAccInit(): array
    {
        return ['order' => [], 'groups' => []];
    }

    /**
     * @param array{order:list<string>,groups:array<string,array{base:array<string,mixed>,states:list<array<string,mixed>>}>} $acc
     * @param array<string,mixed> $row
     * @param list<string> $groupBy
     * @param list<array<string,mixed>> $aggs
     * @param array<string,mixed> $periodCtx
     */
    private static function groupAccAdd(array &$acc, array $row, array $groupBy, array $aggs, array $periodCtx): void
    {
        $keyParts = [];
        foreach ($groupBy as $g) {
            $keyParts[] = (string)($row[(string)$g] ?? '');
        }
        $key = implode("\0", $keyParts);
        if (!isset($acc['groups'][$key])) {
            $base = [];
            foreach ($groupBy as $g) {
                $g = (string)$g;
                $base[$g] = $row[$g] ?? null;
            }
            $states = [];
            foreach ($aggs as $agg) {
                $states[] = self::aggStateInit($agg);
            }
            $acc['groups'][$key] = ['base' => $base, 'states' => $states];
            $acc['order'][] = $key;
        }
        foreach ($aggs as $i => $agg) {
            self::aggStateAdd($acc['groups'][$key]['states'][$i], $agg, $row, $periodCtx);
        }
    }

    /**
     * @param array{order:list<string>,groups:array<string,array{base:array<string,mixed>,states:list<array<string,mixed>>}>} $acc
     * @param list<string> $groupBy
     * @param list<array<string,mixed>> $aggs
     * @return list<array<string,mixed>>
     */
    private static function groupAccFinish(array $acc, array $groupBy, array $aggs): array
    {
        $out = [];
        $n = 0;
        foreach ($acc['order'] as $key) {
            if ((++$n % 200) === 0) {
                self::tickBudget();
            }
            $g = $acc['groups'][$key];
            $base = $g['base'];
            foreach ($aggs as $i => $agg) {
                $as = (string)($agg['as'] ?? '');
                if ($as === '') {
                    continue;
                }
                $base[$as] = self::aggStateFinish($g['states'][$i], $agg);
            }
            $out[] = $base;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $agg
     * @return array<string,mixed>
     */
    private static function aggStateInit(array $agg): array
    {
        $op = strtolower((string)($agg['op'] ?? 'count'));
        switch ($op) {
            case 'count':
            case 'count_where':
                return ['n' => 0];
            case 'count_distinct':
                return ['seen' => []];
            case 'first':
                return ['set' => false, 'v' => null];
            case 'sum':
                return ['s' => 0.0];
            case 'avg':
                return ['s' => 0.0, 'n' => 0];
            case 'min':
            case 'max':
                return ['set' => false, 'v' => null];
            case 'join':
            case 'collect':
            case 'join_where':
                return ['parts' => []];
            default:
                throw new InvalidArgumentException('bad_group_agg:' . $op);
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $agg
     * @param array<string,mixed> $row
     * @param array<string,mixed> $periodCtx
     */
    private static function aggStateAdd(array &$state, array $agg, array $row, array $periodCtx): void
    {
        $op = strtolower((string)($agg['op'] ?? 'count'));
        $field = (string)($agg['field'] ?? '');
        $where = $agg['where'] ?? null;
        if ($where !== null) {
            $ctx = array_merge($periodCtx, $row);
            if (!BiExpr::truthy(BiExpr::eval($where, $ctx))) {
                return;
            }
        }
        switch ($op) {
            case 'count':
            case 'count_where':
                $state['n']++;
                return;
            case 'count_distinct':
                $v = $row[$field] ?? null;
                if ($v === null || $v === '' || $v === 0 || $v === '0') {
                    return;
                }
                $state['seen'][(string)$v] = true;
                return;
            case 'first':
                if ($state['set']) {
                    return;
                }
                $state['set'] = true;
                $state['v'] = $field !== '' ? ($row[$field] ?? null) : $row;
                return;
            case 'sum':
                $state['s'] += (float)($row[$field] ?? 0);
                return;
            case 'avg':
                $state['s'] += (float)($row[$field] ?? 0);
                $state['n']++;
                return;
            case 'min':
                $v = $row[$field] ?? null;
                if (!$state['set'] || $v < $state['v']) {
                    $state['set'] = true;
                    $state['v'] = $v;
                }
                return;
            case 'max':
                $v = $row[$field] ?? null;
                if (!$state['set'] || $v > $state['v']) {
                    $state['set'] = true;
                    $state['v'] = $v;
                }
                return;
            case 'join':
            case 'collect':
            case 'join_where':
                $v = $row[$field] ?? '';
                if ($v === null || $v === '') {
                    return;
                }
                $state['parts'][] = (string)$v;
                return;
            default:
                throw new InvalidArgumentException('bad_group_agg:' . $op);
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $agg
     * @return mixed
     */
    private static function aggStateFinish(array $state, array $agg)
    {
        $op = strtolower((string)($agg['op'] ?? 'count'));
        switch ($op) {
            case 'count':
            case 'count_where':
                return (int)$state['n'];
            case 'count_distinct':
                return count($state['seen']);
            case 'first':
                return $state['set'] ? $state['v'] : null;
            case 'sum':
                return $state['s'];
            case 'avg':
                return $state['n'] > 0 ? ($state['s'] / $state['n']) : null;
            case 'min':
            case 'max':
                return $state['set'] ? $state['v'] : null;
            case 'join':
            case 'collect':
            case 'join_where':
                $sep = (string)($agg['sep'] ?? ', ');
                return implode($sep, $state['parts']);
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
        self::tickBudget();
        $fn = strtolower((string)($w['fn'] ?? 'rank'));
        $as = (string)($w['as'] ?? $fn);
        $orderBy = is_array($w['order_by'] ?? null) ? $w['order_by'] : [];
        $partition = is_array($w['partition_by'] ?? null) ? $w['partition_by'] : [];

        $groups = [];
        $n = 0;
        foreach ($rows as $i => $row) {
            if ((++$n % 500) === 0) {
                self::tickBudget();
            }
            $key = '';
            foreach ($partition as $p) {
                $key .= '|' . (string)($row[(string)$p] ?? '');
            }
            $groups[$key][] = $i;
        }

        foreach ($groups as $idxs) {
            self::tickBudget();
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
        $extraFields = [];
        foreach ($cols as $c) {
            if (is_array($c)) {
                $id = (string)($c['id'] ?? '');
                $label = (string)($c['label'] ?? $id);
                if ($id === '') {
                    continue;
                }
                $def = ['id' => $id, 'label' => $label];
                $fmt = strtolower((string)($c['format'] ?? ''));
                if ($fmt === 'user_badge') {
                    $def['format'] = 'user_badge';
                    $uf = (string)($c['user_field'] ?? $id);
                    $def['user_field'] = $uf;
                    $extraFields[$uf] = true;
                    if (!empty($c['link'])) {
                        $def['link'] = true;
                    }
                    $sf = (string)($c['suffix_field'] ?? '');
                    if ($sf !== '') {
                        $def['suffix_field'] = $sf;
                        $extraFields[$sf] = true;
                    }
                }
                $colDefs[] = $def;
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
            foreach (array_keys($extraFields) as $ef) {
                if (!array_key_exists($ef, $r) && array_key_exists($ef, $row)) {
                    $r[$ef] = $row[$ef];
                }
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
        self::tickBudget();
        $grain = (string)($sec['grain'] ?? 'month_to_date');
        $slots = self::pivotSlots($params, $grain);
        $metrics = is_array($sec['metrics'] ?? null) ? $sec['metrics'] : [];
        $columns = [];
        $grid = [];
        $metricKeys = [];

        foreach ($slots as $slot) {
            self::tickBudget();
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
